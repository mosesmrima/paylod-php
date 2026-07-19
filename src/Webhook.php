<?php

declare(strict_types=1);

namespace Paylod;

use Paylod\Exceptions\PaylodCredentialCompromiseError;
use Paylod\Exceptions\PaylodSignatureVerificationError;
use Paylod\Support\JsonLexeme;
use Paylod\Support\Redact;
use Paylod\Support\Validate;

/**
 * Webhook signature verification.
 *
 * VERIFIED AGAINST: supabase/functions/_shared/webhooks/sign.ts and the Node/CLI SDK copies.
 *
 *   header:    x-webhook-signature: t=<unix-seconds>,v1=<hex>
 *   signed:    HMAC-SHA256( secret, `${t}.${rawBody}` )   -> lowercase hex
 *   also sent: x-webhook-id, x-webhook-event
 *   tolerance: the worker signs with the event's OWN `created` timestamp so retries are
 *              byte-identical; we reject a `t` more than `toleranceSec` from now (default 300).
 *
 * THE RAW BODY IS LOAD-BEARING. Re-serialising a parsed body is not guaranteed to reproduce the
 * same bytes, so it will fail verification. Always hand verify() the exact bytes that arrived.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'x-webhook-signature';
    public const EVENT_ID_HEADER = 'x-webhook-id';
    public const EVENT_TYPE_HEADER = 'x-webhook-event';

    /** Default anti-replay window, seconds. Mirrors the server's maxSkewSeconds. */
    public const DEFAULT_TOLERANCE_SEC = 300;

    /**
     * The HARD CEILING on the anti-replay window, seconds. One hour.
     *
     * A tolerance had a floor but no ceiling, so `PHP_INT_MAX` (or any absurd value arrived at by a
     * config typo, a milliseconds-for-seconds mix-up, or a caller "turning off the flakiness")
     * passed validation and made `abs($now - $t) <= $tolerance` true for every timestamp that has
     * ever existed. Replay protection was then GONE while every other check still passed - the
     * worst possible failure mode, because the verifier looks like it is working. A correctly
     * signed webhook captured last year would be accepted and re-delivered to the handler, and on a
     * `payment.success` that means fulfilling the same order again.
     *
     * One hour is far beyond any legitimate need: the window exists to absorb clock skew and
     * delivery retries, both of which are seconds-to-minutes. The default is 300s and should stay
     * there; this bound exists so that no configuration can silently mean "forever".
     */
    public const MAX_TOLERANCE_SEC = 3600;

    /**
     * THE PRE-AUTHENTICATION BYTE CEILING on a webhook body. 1 MiB.
     *
     * -- Why this is not "the framework's problem" ---------------------------------------------
     * Every other bounded surface in this SDK bounds a RESPONSE - bytes that arrived because we
     * asked for them, from an origin we pinned, over a connection we opened. This one is different
     * in the only way that matters: it is an INBOUND, INTERNET-FACING, UNAUTHENTICATED endpoint.
     * Anyone who learns the URL can POST to it, and the work below happens BEFORE anything has
     * established that they are paylod:
     *
     *   1. `(string) $payload` materialises a Stringable in full;
     *   2. `hash_hmac()` walks every byte;
     *   3. `json_decode()` builds a PHP value graph out of it.
     *
     * A signature check is not admission control - it cannot be, because you have to have the bytes
     * before you can hash them. So a 500 MB body is 500 MB of memory and CPU spent to conclude "not
     * from paylod", repeatable for free, and the endpoint falls over while the signature verifier
     * reports that it is working perfectly.
     *
     * The framework-integrated path may well have its own limit (`post_max_size`, an nginx
     * `client_max_body_size`). This is the MANUAL surface: `Webhook::verify($request->getContent(),
     * ...)` called from a bare PSR-7 handler, a queue worker replaying a stored body, a Lambda, a
     * long-running Swoole/RoadRunner process - none of which necessarily has any of those. A bound
     * that only exists when someone else configured it is not a bound this SDK provides.
     *
     * 1 MiB is roughly a thousand times the largest real paylod event. It is checked FIRST, before
     * the tolerance, the secret, the header parse and the HMAC, so the expensive work is never
     * reached by an oversized body.
     */
    public const MAX_BODY_BYTES = 1048576;

    /**
     * The pre-authentication ceilings on the SIGNATURE HEADER: bytes, and comma-separated segments.
     *
     * The body had a ceiling; the header did not, and it is parsed on the same unauthenticated
     * surface, before the HMAC. Both are checked before `explode()` ever runs.
     */
    public const MAX_SIGNATURE_HEADER_BYTES = 512;

    public const MAX_SIGNATURE_HEADER_SEGMENTS = 8;

    /**
     * Verify a paylod webhook and return the typed event as an associative array.
     *
     * Throws {@see PaylodSignatureVerificationError} on any failure - never returns a half-trusted
     * value. Respond 400 and drop the request when it throws.
     *
     * @return array<string,mixed> the decoded webhook event
     *
     * @throws PaylodSignatureVerificationError
     */
    public static function verify(
        #[\SensitiveParameter] string|\Stringable $payload,
        ?string $signature,
        #[\SensitiveParameter] string $secret,
        int|float $toleranceSec = self::DEFAULT_TOLERANCE_SEC,
        int|float|null $nowSec = null,
        #[\SensitiveParameter] array $alsoRefuse = [],
    ): array {
        $event = self::parseSignedEnvelope($payload, $signature, $secret, $toleranceSec, $nowSec, $alsoRefuse);
        self::assertEventIsCoherent($event);

        // THE DERIVED FIELDS ARE RE-DERIVED, NEVER FORWARDED.
        return self::withAuthoritativeDerivedFields($event);
    }

    /**
     * The DERIVED fields of a payment event, recomputed locally from {@see DarajaCatalog} and
     * {@see PaymentOutcome}, overwriting whatever the body carried.
     *
     * -- Why verification was not enough -----------------------------------------------------------
     * verify() used to return the event UNCHANGED once the signature and the semantic checks passed.
     * Those checks look at `status`, `mpesaReceipt` and `resultCode`; they say nothing at all about
     * `data.decoded`, `data.retryable`, `data.customerMessage` or `data.category`, which are not
     * evidence but CONCLUSIONS - and they were being taken verbatim from the body.
     *
     * So a `payment.failed` carrying result code 17 (a terminal M-Pesa system error) plus a forged
     * `decoded.retryable = true` passed every check and then told the caller it was safe to charge
     * again. The handler reads the field the SDK handed it; nothing downstream re-derives it. That is
     * a double-charge generator reachable through a body that is, by every check we had, valid.
     *
     * A signature proves ORIGIN. It does not make a conclusion true, and a compromised or merely
     * buggy signer produces correctly-signed conclusions. The rule is therefore simple and total:
     * the SDK trusts the EVIDENCE fields (which the semantic model then judges) and recomputes every
     * CONCLUSION from its own offline catalog. Root level and nested alike, because a handler reading
     * `$event['retryable']` is in exactly as much danger as one reading `$event['data']['retryable']`.
     *
     * An ABSENT `decoded` block is synthesized rather than left out: absence is not safer than a
     * forgery. A handler that does `$event['data']['decoded']['retryable'] ?? true` on a missing
     * block reaches the same double-charge, and one that assumes the key exists fatals instead.
     *
     * @param array<string,mixed> $event
     * @return array<string,mixed> a NEW event; the input is not mutated
     */
    private static function withAuthoritativeDerivedFields(array $event): array
    {
        /** @var array<string,mixed> $data */
        $data = $event['data'];
        $type = (string) $event['type'];

        // THE ROOT IS REBUILT, NOT EDITED. See ROOT_KEYS.
        $out = self::pickTyped($event, self::ROOT_KEYS, self::ROOT_TYPES, 'event');
        $out['type'] = $type;

        // A non-payment event carries no payment claim, so there is nothing to derive FROM - and a
        // derived-looking field on it is therefore unverifiable by construction. It is represented
        // MINIMALLY: the envelope, plus the scalar members of `data` and nothing else. An unknown
        // type exists for forward compatibility, and forward compatibility must not be a channel
        // through which a version that cannot check a claim still forwards one.
        if (!isset(self::PAYMENT_EVENT_STATUS[$type])) {
            $out['data'] = self::scalarsOnly($data);

            return $out;
        }

        $outcome = PaymentOutcome::fromPayment([
            'id' => $data['paymentId'] ?? '',
            'status' => $data['status'] ?? null,
            'mpesaReceipt' => $data['mpesaReceipt'] ?? null,
            'resultCode' => $data['resultCode'] ?? null,
            'resultDesc' => $data['resultDesc'] ?? null,
        ]);

        // Synthesized when the record carries no result code, so `decoded` is ALWAYS a complete,
        // locally-derived block.
        $decoded = $outcome->detail ?? self::synthesizeDecoded($outcome);

        // THE PAYMENT DATA IS REBUILT FROM THE ALLOWLIST, then the derived block is written on top.
        $out['data'] = self::pickTyped($data, self::PAYMENT_DATA_KEYS, self::PAYMENT_DATA_TYPES, 'data') + [
            'decoded' => $decoded,
            'retryable' => $outcome->retryable,
            'customerMessage' => $outcome->message,
            'category' => $decoded['category'],
        ];

        return $out;
    }

    /**
     * The ONLY root-level keys a verified event may carry.
     *
     * Everything else - `retryable`, `decoded`, `category`, an invented `safeToRetry`, a whole
     * nested object - is dropped. Stripping a known-bad LIST was the previous approach and it is
     * the wrong shape of rule: it is a denylist, it has to be kept complete forever, and the very
     * first field an attacker invents that is not on it survives.
     */
    private const ROOT_KEYS = ['type', 'created', 'id', 'apiVersion'];

    /**
     * THE TYPE OF EVERY ALLOWLISTED FIELD. An allowlist of NAMES is only half a schema.
     *
     * -- Why names were not enough ---------------------------------------------------------------
     * `pick()` copied an allowlisted key's value verbatim, whatever it was. So a body could smuggle
     * a payload-supplied CONCLUSION through the allowlist by hiding it inside an allowlisted name:
     *
     *     "applicationId": {"retryable": true}
     *     "amount":        {"decoded": {"retryable": true}}
     *
     * Both names are on the allowlist, so both survived the rebuild - and a handler reading
     * `$event['data']['amount']['decoded']['retryable']`, or merely iterating `data` looking for a
     * retryable flag, is told a re-charge is safe by a field the SDK has just judged NON-retryable.
     * That is the same double-charge generator the allowlist was introduced to close, walking
     * through the allowlist's own front door.
     *
     * The rule that actually closes it: an allowlist must name what may exist AND what it may BE.
     * Every field here is a documented scalar, and a wrong-typed one is not a field we can read - so
     * the event is REFUSED, not silently pruned. Refusing is the fail-closed answer: a body we
     * cannot read is a body we must not act on, and quietly dropping a malformed `status` or
     * `resultCode` would hand the semantic model a record with the evidence removed.
     *
     * `string` / `int` / `number` / `scalar` are checked exactly; a NULL is always permitted,
     * because an absent-but-present optional field is legitimate on every one of these.
     */
    private const ROOT_TYPES = [
        'type' => 'string',
        'created' => 'scalar',
        'id' => 'string',
        'apiVersion' => 'scalar',
    ];

    private const PAYMENT_DATA_TYPES = [
        'paymentId' => 'string',
        'applicationId' => 'string',
        'env' => 'string',
        'status' => 'string',
        'amount' => 'number',
        'phone' => 'string',
        'accountRef' => 'string',
        'mpesaReceipt' => 'string',
        'checkoutRequestId' => 'string',
        // The one field that is legitimately either. The strict-zero rule in DarajaCatalog reads
        // its LEXEME, so both an int and its decimal string are meaningful here - and nothing else.
        'resultCode' => 'code',
        'resultDesc' => 'string',
    ];

    /**
     * The ONLY `data` keys a verified PAYMENT event may carry, before the derived block is written.
     *
     * -- Why an allowlist and not a strip ----------------------------------------------------------
     * The derived fields were removed by name at both levels, and everything else in `data` was
     * forwarded verbatim. So `data.details.retryable = true`, `data.extra.retryable = true`, or any
     * other nesting depth carried a retryability claim straight to a handler, on an event the SDK
     * had just judged NON-retryable. A handler reading `$event['data']['details']['retryable']` -
     * which is exactly the shape a "read the detail object" instinct produces - is told another
     * charge is safe by a field nothing verified. That is a double-charge generator that survives
     * every check we have, because none of them look at fields we never named.
     *
     * The only rule that holds is the one that names what MAY exist. Anything not on this list does
     * not reach the handler, whatever it is called and however deeply it is buried.
     */
    private const PAYMENT_DATA_KEYS = [
        'paymentId',
        'applicationId',
        'env',
        'status',
        'amount',
        'phone',
        'accountRef',
        'mpesaReceipt',
        'checkoutRequestId',
        'resultCode',
        'resultDesc',
    ];

    /**
     * A fresh array carrying only `$keys` that are actually present, in allowlist order.
     *
     * @param array<string,mixed> $source
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private static function pick(array $source, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $out[$key] = $source[$key];
            }
        }

        return $out;
    }

    /**
     * {@see pick()}, with every allowlisted value checked against {@see ROOT_TYPES} /
     * {@see PAYMENT_DATA_TYPES}. A wrong-typed field REFUSES the whole event.
     *
     * @param array<string,mixed> $source
     * @param list<string> $keys
     * @param array<string,string> $types
     * @return array<string,mixed>
     * @throws PaylodSignatureVerificationError
     */
    private static function pickTyped(array $source, array $keys, array $types, string $where): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $value = $source[$key];
            if ($value !== null && !self::isOfType($value, $types[$key])) {
                throw new PaylodSignatureVerificationError(
                    'invalid_payload',
                    "Webhook body is signed correctly but {$where}.{$key} is a "
                    . get_debug_type($value) . ', not the ' . $types[$key] . ' the schema defines. '
                    . 'A structured value in a scalar field is how a payload-supplied conclusion '
                    . '(a `retryable` flag, a forged `decoded` block) gets carried past a '
                    . 'name-only allowlist, so the event is refused rather than forwarded.'
                );
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private static function isOfType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'code' => is_int($value) || is_string($value),
            'scalar' => is_scalar($value),
        };
    }

    /**
     * The SCALAR members of an unknown event's `data`, with any derived-looking name removed.
     *
     * Scalars only, because a nested structure is where an unverifiable claim hides and there is no
     * schema here against which to judge one. Derived names are dropped even as scalars: an unknown
     * type is precisely the case in which the SDK cannot re-derive them.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function scalarsOnly(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (in_array($key, self::DERIVED_KEYS, true)) {
                continue;
            }
            if ($value === null || is_scalar($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** The derived keys. Names are shared with the sibling SDKs. */
    private const DERIVED_KEYS = ['decoded', 'retryable', 'customerMessage', 'category'];

    /**
     * A decoded block for a record that carries no result code. It exists so `decoded` is never
     * absent; it is deliberately NON-RETRYABLE in every branch, because a record with no code proves
     * nothing about whether money moved.
     *
     * @return array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}
     */
    private static function synthesizeDecoded(PaymentOutcome $outcome): array
    {
        if ($outcome->paid) {
            return [
                'code' => '0',
                'title' => 'Payment received',
                'cause' => 'The payment settled successfully.',
                'fix' => 'No action needed.',
                'category' => 'success',
                'retryable' => false,
                'customerMessage' => $outcome->message,
            ];
        }

        return [
            'code' => 'unknown',
            'title' => 'Payment not settled',
            'cause' => 'The event carried no M-Pesa result code, so there is no catalog entry to '
                . 'decode and nothing that establishes what happened to the payment.',
            'fix' => 'Read the payment with GET /status/:id before charging again - without a result '
                . 'code we cannot prove no money moved.',
            'category' => 'unknown',
            'retryable' => false,
            'customerMessage' => $outcome->message,
        ];
    }

    /**
     * SIGNATURE ONLY - and the result is explicitly NOT an event you may act on.
     *
     * -- Why this no longer returns an event -------------------------------------------------------
     * It used to return the decoded event, exactly like {@see verify()}, differing only in that it
     * skipped every semantic check. Two functions with the same return shape, one of which is safe
     * and one of which is not, is a trap rather than an API: the mistake is invisible at the call
     * site, and the one it invites - `$e = Webhook::verifySignature(...); if ($e['data']['status']
     * === 'success') fulfil();` - fulfils an order on an evidence-free `payment.success` that
     * verify() would have refused outright.
     *
     * So the shapes are now different on purpose. This returns a wrapper whose keys say what it is:
     * `signatureValid` (the only thing actually established), `actionable` (always false), and
     * `unverifiedEvent` - a name a reviewer cannot read as approval. There is no path from this
     * value to a fulfilment decision that does not go through renaming it first.
     *
     * It exists for ONE reason: the cross-repo GOLDEN VECTOR pins the SIGNING SCHEME, and its body
     * is a minimal signing fixture rather than a representative event, so verifying it must not
     * require editing literals that several repositories agree on byte-for-byte. Use verify() for
     * anything that will reach a handler.
     *
     * @internal pins the signing scheme; not part of the supported event-handling surface.
     *
     * @return array{signatureValid:bool,actionable:bool,unverifiedEvent:array<string,mixed>}
     *
     * @throws PaylodSignatureVerificationError
     */
    public static function verifySignature(
        #[\SensitiveParameter] string|\Stringable $payload,
        ?string $signature,
        #[\SensitiveParameter] string $secret,
        int|float $toleranceSec = self::DEFAULT_TOLERANCE_SEC,
        int|float|null $nowSec = null,
        #[\SensitiveParameter] array $alsoRefuse = [],
    ): array {
        return [
            'signatureValid' => true,
            'actionable' => false,
            'unverifiedEvent' => self::parseSignedEnvelope(
                $payload,
                $signature,
                $secret,
                $toleranceSec,
                $nowSec,
                $alsoRefuse,
            ),
        ];
    }

    /**
     * Verify the signature and the envelope, and return the decoded event WITHOUT the semantic
     * checks {@see verify()} applies. PRIVATE: an event that has passed only this is not something
     * any caller outside this class should be able to get hold of.
     *
     * @return array<string,mixed>
     *
     * @throws PaylodSignatureVerificationError
     */
    private static function parseSignedEnvelope(
        #[\SensitiveParameter] string|\Stringable $payload,
        ?string $signature,
        #[\SensitiveParameter] string $secret,
        int|float $toleranceSec = self::DEFAULT_TOLERANCE_SEC,
        int|float|null $nowSec = null,
        #[\SensitiveParameter] array $alsoRefuse = [],
    ): array {
        // THE BYTES MUST ALREADY BE BOUNDED WHEN THEY GET HERE.
        //
        // The ceiling below is checked on `strlen($raw)` - which is AFTER `(string) $payload` has
        // already materialised a `\Stringable` in full. For a PSR-7 stream wrapper that is the
        // entire attack: a network-sized body is pulled into memory, unauthenticated, and only then
        // measured. The SDK cannot bound a foreign `__toString()`; there is no hook, no partial
        // read, no way to stop halfway.
        //
        // So it does not pretend to. A non-string payload is REFUSED, with an error that says what
        // to pass instead. The caller's framework already read the body under its own limit
        // (`post_max_size`, `client_max_body_size`, a bounded stream read) - handing this method the
        // resulting string is both trivial and the only version of this that is actually bounded.
        if (!is_string($payload)) {
            throw new PaylodSignatureVerificationError(
                'invalid_payload',
                'Webhook verification needs the raw body as a STRING. A Stringable/stream payload '
                . 'would have to be materialised in full before its size could be checked, and this '
                . 'endpoint is unauthenticated - so that read is the denial of service, not the '
                . 'defence against it. Read the body yourself under your framework\'s own limit and '
                . 'pass the string: Webhook::verify((string) $request->getBody(), ...) is only safe '
                . 'when you have already bounded that read.'
            );
        }

        $raw = $payload;

        // THE CEILING, FIRST. Before the tolerance, before the secret, before the header parse and
        // above all before the HMAC and the JSON parser - the three things an attacker would be
        // paying us to run. See MAX_BODY_BYTES.
        $bytes = strlen($raw);
        if ($bytes > self::MAX_BODY_BYTES) {
            throw new PaylodSignatureVerificationError(
                'invalid_payload',
                "Webhook body is {$bytes} bytes, which exceeds the " . self::MAX_BODY_BYTES
                . '-byte limit. This endpoint is unauthenticated by nature - the signature can only '
                . 'be checked AFTER the bytes are in memory - so an oversized body is refused before '
                . 'it is hashed or parsed. A real paylod event is a few hundred bytes.'
            );
        }

        // Freshness is NOT optional. A zero/negative/NaN tolerance disables replay protection, and
        // there is no caller - test or otherwise - who needs that: a pinned fixture verifies fine
        // with a normal window and an injected $nowSec. Validated FIRST so a misconfigured verifier
        // can never reach the HMAC comparison and return a "valid" verdict.
        $tolerance = self::requireTolerance($toleranceSec);
        $now = $nowSec === null ? time() : self::requirePositiveInt($nowSec, 'nowSec');

        if ($secret === '') {
            throw new PaylodSignatureVerificationError(
                'missing_signature',
                'No webhook signing secret configured. Pass $secret or set PAYLOD_WEBHOOK_SECRET.'
            );
        }
        if ($signature === null || $signature === '') {
            throw new PaylodSignatureVerificationError(
                'missing_signature',
                'Missing ' . self::SIGNATURE_HEADER . ' header.'
            );
        }

        // THE SIGNATURE HEADER IS BOUNDED TOO, before it is split. `explode(',', ...)` on an
        // attacker-supplied header is unbounded work on an unauthenticated surface: a megabyte of
        // commas is a million-element array built to conclude "malformed". A real header is
        // `t=<10 digits>,v1=<64 hex>` - about 80 bytes and two segments.
        $sigBytes = strlen($signature);
        if ($sigBytes > self::MAX_SIGNATURE_HEADER_BYTES) {
            throw new PaylodSignatureVerificationError(
                'malformed_signature',
                "Signature header is {$sigBytes} bytes, which exceeds the "
                . self::MAX_SIGNATURE_HEADER_BYTES . '-byte limit. A real header is around 80 bytes.'
            );
        }
        if (substr_count($signature, ',') + 1 > self::MAX_SIGNATURE_HEADER_SEGMENTS) {
            throw new PaylodSignatureVerificationError(
                'malformed_signature',
                'Signature header has more than ' . self::MAX_SIGNATURE_HEADER_SEGMENTS
                . ' comma-separated segments. A real header has two.'
            );
        }

        $parsed = self::parseHeader($signature);
        if ($parsed === null) {
            throw new PaylodSignatureVerificationError(
                'malformed_signature',
                'Malformed ' . self::SIGNATURE_HEADER . ' header - expected "t=<unix>,v1=<hex>".'
            );
        }

        // `t` must be a LEXICAL decimal integer - digits only. PHP's numeric coercion would otherwise
        // happily read "1e3", "+1000", " 1000" or a hex-ish form as a number, letting an attacker
        // present a timestamp whose textual form (which is what gets HMAC'd) differs from the value
        // we freshness-check. Digits only means the two can never diverge.
        if (preg_match('/^[0-9]{1,19}\z/', $parsed['t']) !== 1
            || filter_var($parsed['t'], FILTER_VALIDATE_INT) === false) {
            throw new PaylodSignatureVerificationError(
                'malformed_signature',
                'Signature timestamp is not a number.'
            );
        }
        $t = (int) $parsed['t'];

        if (abs($now - $t) > $tolerance) {
            throw new PaylodSignatureVerificationError(
                'stale_timestamp',
                "Signature timestamp is outside the {$tolerance}s tolerance (replay?)."
            );
        }

        $expected = hash_hmac('sha256', $parsed['t'] . '.' . $raw, $secret);

        if (!hash_equals($expected, $parsed['v1'])) {
            throw new PaylodSignatureVerificationError(
                'no_match',
                'Webhook signature does not match. Check the signing secret, and make sure you are '
                . 'passing the RAW request body (not a re-serialised object).'
            );
        }

        // THE RAW BYTES, BEFORE THE PARSER. A signature proves these bytes came from paylod; it says
        // nothing about whether they are readable. `{"resultCode":-0}` decodes to the integer 0 -
        // byte-for-byte the same value a genuine settlement produces - so the strict-zero rule in
        // DarajaCatalog is blind to it once decoded. Checked here, after the HMAC (an unsigned body
        // is rejected earlier and never reaches this line) and before json_decode().
        $badToken = JsonLexeme::nonCanonicalResultCodeToken($raw);
        if ($badToken !== null) {
            throw new PaylodSignatureVerificationError(
                'invalid_payload',
                'Webhook body is signed correctly but ' . JsonLexeme::explain($badToken) . '.'
            );
        }

        $event = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($event)) {
            throw new PaylodSignatureVerificationError(
                'invalid_payload',
                'Webhook body is signed correctly but is not valid JSON.'
            );
        }
        if (!isset($event['type']) || !is_string($event['type']) || !isset($event['data']) || !is_array($event['data'])) {
            throw new PaylodSignatureVerificationError(
                'invalid_payload',
                'Webhook body is not a paylod event (missing `type`/`data`).'
            );
        }

        // THE DECODED EVENT IS SCRUBBED BEFORE IT LEAVES THIS METHOD.
        //
        // Every string in this array is SERVER-CONTROLLED and every one of them is about to become
        // a public value: verify() returns it to the handler, verifySignature() returns it as
        // `unverifiedEvent`, and PaymentOutcome is built from a slice of it. `resultDesc` is the
        // gateway's own free text, `accountRef` is echoed back from the request, and the identifier
        // fields are whatever the sender chose to put there.
        //
        // The status path already scrubs (the client redacts its own responses, and PaymentOutcome
        // rebuilds its fields from an allowlist). This path did not, so the SAME misconfiguration -
        // a gateway reflecting the Authorization header into a description field - leaked a live
        // `mp_live_` key through the webhook while being caught on the status read. One surface
        // holding the line is not the line being held.
        //
        // Scrubbed HERE, after the HMAC and the raw-lexeme check (both of which must see the bytes
        // exactly as signed) and before any caller can reach the value. The shape layer only - this
        // is a static method with no process secrets - which is precisely the layer that catches a
        // reflected bearer token. Result codes are integers and are untouched, so nothing the
        // semantic model reads as EVIDENCE can be altered by this.
        // The EXACT secret this call was given is passed in, not just the shape rules. A signed
        // event that echoes the webhook secret back - a debug endpoint, a misconfigured relay, a
        // body built from the request that carried it - would otherwise be handed to the handler
        // verbatim, and `whsec_` shape matching only catches it when it is spelled the way we
        // expect. The one secret we can name, we name.
        //
        // -- BUT ON THE MONEY PATH WE REFUSE RATHER THAN REDACT ------------------------------------
        // Redaction is the right answer for DIAGNOSTICS - an error message, a log line, a dump. It is
        // the wrong answer here, and round 9 showed exactly why: the redactor rewrote an echoed
        // credential to `[redacted]`, the evidence check read `[redacted]` as a non-blank receipt, and
        // a `payment.success` with no result code was delivered as a settled payment. Redacting a
        // credential had turned it into proof of payment.
        //
        // The receipt grammar in {@see \Paylod\Semantics} closes that particular instance. This closes
        // the CLASS: a correctly-signed body that echoes our own webhook secret back is not a body with
        // one bad field, it is output from a server that is compromised or badly misconfigured, and
        // there is no reading under which the REST of it is trustworthy. Sanitising it and handing it
        // to a fulfilment handler is acting on that server's output while knowing it is broken.
        //
        // So we refuse, loudly, with the type that is deliberately NOT retryable. The sibling Node,
        // Python and JVM SDKs made the same call in round 9.
        // -- THE SCAN READS THE DECODED EVENT, NOT ONLY THE RAW BYTES (requirements 4.6, 4.7) ------
        //
        // This was `Redact::contains($raw, [$secret])` - the RAW BODY, and the signing secret alone.
        // Two holes, and the round-10 High was both of them at once:
        //
        //   1. JSON string values may be spelled with `\uXXXX` escapes. A secret written as
        //      "whsec_..." is ABSENT from the raw bytes and PRESENT in the decoded event, so it
        //      slipped the refusal, was rewritten to `[redacted]` by the scrubber below, and was
        //      DELIVERED to the handler. Exactly the same class of defect as the escaped
        //      `resultCode` member name in {@see \Paylod\Support\JsonLexeme}: a guard that reads
        //      bytes while the money logic reads a parsed structure is guarding the wrong value.
        //   2. It knew ONE credential. Requirement 4.7: the refusal scan must know EVERY configured
        //      credential, not a subset. `$alsoRefuse` carries the client's API key in, because
        //      this is a static method and the client is the only object holding both.
        //
        // BOTH values are scanned - the raw bytes AND the decoded structure - because they catch
        // different spellings, and the raw scan additionally covers the parts of the body that never
        // become event fields. The decoded walk is recursive over keys and string leaves and FAILS
        // CLOSED past the parse depth (requirements 4.3, 4.5).
        //
        // This runs BEFORE assertEventIsCoherent() and before the derived fields are rebuilt, so no
        // semantic conclusion is ever drawn from a body we are about to refuse.
        $credentials = array_merge([$secret], array_values($alsoRefuse));
        if (Redact::contains($raw, $credentials) || Redact::containsDeep($event, $credentials)) {
            throw new PaylodCredentialCompromiseError(
                'Webhook body is signed correctly but ECHOES ONE OF YOUR PAYLOD CREDENTIALS back '
                . 'inside the payload. A legitimate paylod event never contains the secret it was '
                . 'signed with, nor your API key, so the sender is compromised or misconfigured - and '
                . 'a body from such a sender cannot be trusted about whether money moved. This event '
                . 'is REFUSED rather than sanitised and delivered. Rotate the credential, then find '
                . 'what is reflecting it into the payload.'
            );
        }

        /** @var array<string,mixed> $scrubbed */
        $scrubbed = Redact::apply($event, $credentials);

        return $scrubbed;
    }

    /**
     * The event types this SDK understands. A payment event is the one that triggers fulfilment, so
     * it is the one that must be proven rather than merely well-formed.
     */
    private const PAYMENT_EVENT_STATUS = ['payment.success' => 'success', 'payment.failed' => 'failed'];

    /**
     * Validate a PAYMENT event beyond the envelope.
     *
     * -- Why a valid signature is not enough --------------------------------------------------
     * The previous version checked that `type` was a string and `data` was an array, and stopped
     * there. Everything else - `data.status`, `data.mpesaReceipt`, `data.resultCode` - was whatever
     * arrived, and a handler written the natural way (`if ($e['data']['status'] === 'success')`)
     * would fulfil an order on a field nothing had checked.
     *
     * A valid signature does NOT make that safe. It proves the body came FROM paylod; it says
     * nothing about whether the body is COHERENT. A bug upstream, a partially-written row, a schema
     * change, or a compromised signing key all produce correctly-signed nonsense, and the handler is
     * the last place that can refuse it.
     *
     * Three layers, matching the status-read path exactly:
     *   1. SHAPE       - every field present is the type the docs promise.
     *   2. CONSISTENCY - `type` and `data.status` must agree. A `payment.success` carrying
     *                    `status: "failed"` is not an event we can act on either way.
     *   3. EVIDENCE    - the event runs through the SAME {@see Semantics::judge()} a status read
     *                    does. Reusing the model rather than re-deriving the rule here is the whole
     *                    point of having one: the webhook path and the polling path cannot drift
     *                    into disagreeing about what proves a payment.
     *
     * @param array<string,mixed> $event
     *
     * @throws PaylodSignatureVerificationError
     */
    private static function assertEventIsCoherent(array $event): void
    {
        $type = (string) $event['type'];
        $expectedStatus = self::PAYMENT_EVENT_STATUS[$type] ?? null;
        if ($expectedStatus === null) {
            // An unknown event type is forward-compatible: it is not a payment event, so there is no
            // payment claim to prove. Handlers are expected to ignore types they do not know.
            return;
        }

        /** @var array<string,mixed> $d */
        $d = $event['data'];

        // 1. SHAPE.
        // REQUIREMENT 3.4 - THE PAYMENT ID GETS THE SHARED IDENTIFIER GRAMMAR, not a blank check.
        //
        // This was `trim($d['paymentId']) !== ''`. A non-emptiness test is not a validator: it
        // accepted an echoed bearer token, a JSON fragment, a multi-kilobyte blob, and - because the
        // decoded event passes through the redactor before a handler sees it - the literal
        // `[redacted]`. The paymentId is the value a handler correlates the order by and the value
        // applications log on every charge, so a placeholder in it means every redacted event
        // correlates to the SAME order. Same rule, same grammar, same marker check as the
        // acknowledgement and status-read paths. See Validate::identifierIsUsable().
        if (!isset($d['paymentId']) || !is_string($d['paymentId']) || trim($d['paymentId']) === '') {
            self::invalidPayload('data.paymentId is missing or empty.');
        }
        // The SHAPE-only redactor is passed in. This is a static method with no process secrets,
        // so the exact-credential layer is not available here - but the shape layer is, and it is
        // precisely the layer that catches a reflected `mp_live_` / `whsec_` token echoed into the
        // id. The client's exact credentials are applied on top by the refusal scan above.
        if (!Validate::identifierIsUsable(
            'data.paymentId',
            $d['paymentId'],
            static fn (mixed $v): mixed => is_string($v) ? Redact::text($v, []) : $v,
        )) {
            self::invalidPayload(
                'data.paymentId is not a well-formed identifier - a paylod payment id is a short '
                . 'opaque token, and a value that is oversized, credential-shaped, or a redaction '
                . 'marker cannot be used to correlate this event with anything.'
            );
        }
        if (!isset($d['status']) || !is_string($d['status'])
            || !in_array($d['status'], Validate::PAYMENT_STATUSES, true)) {
            self::invalidPayload(
                'data.status is missing or not one of ' . implode('/', Validate::PAYMENT_STATUSES) . '.'
            );
        }
        $receipt = $d['mpesaReceipt'] ?? null;
        if ($receipt !== null && !is_string($receipt)) {
            self::invalidPayload('data.mpesaReceipt is present but not a string.');
        }
        $code = $d['resultCode'] ?? null;
        if ($code !== null && !is_int($code) && !is_float($code) && !is_string($code)) {
            self::invalidPayload('data.resultCode is present but is neither a number nor a string.');
        }
        $desc = $d['resultDesc'] ?? null;
        if ($desc !== null && !is_string($desc)) {
            self::invalidPayload('data.resultDesc is present but not a string.');
        }

        // 2. CONSISTENCY. The event type and the record's own status must say the same thing.
        if ($d['status'] !== $expectedStatus) {
            self::invalidPayload(
                "type is \"{$type}\" but data.status is \"{$d['status']}\" - the event contradicts "
                . 'itself, so neither field can be trusted.'
            );
        }

        // 3. EVIDENCE, via the one semantic model.
        $judgement = Semantics::judge([
            'id' => $d['paymentId'],
            'status' => $d['status'],
            'mpesaReceipt' => $receipt,
            'resultCode' => $code,
            'resultDesc' => $desc,
        ]);

        if ($type === 'payment.success' && $judgement->verdict !== Semantics::VERDICT_PAID) {
            self::invalidPayload(
                'The event announces a successful payment but the record does not prove one ('
                . $judgement->reason . '). Refusing to hand your handler an unevidenced success - '
                . 'that is how an order gets fulfilled for a payment that never settled.'
            );
        }
        if ($type === 'payment.failed' && $judgement->verdict !== Semantics::VERDICT_FAILED) {
            self::invalidPayload(
                'The event announces a failed payment but the record does not support that ('
                . $judgement->reason . '). In particular a failure notice carrying a receipt, or one '
                . 'carrying a still-in-flight result code, must not be delivered as a settled failure.'
            );
        }
    }

    /** @throws PaylodSignatureVerificationError */
    private static function invalidPayload(string $detail): never
    {
        throw new PaylodSignatureVerificationError('invalid_payload', $detail);
    }

    /**
     * Verify and return true/false. This is the boolean convenience form matching the documented
     * `verifyWebhook($rawBody, $signatureHeader, $secret)` surface; use {@see verify()} when you
     * want the decoded event (and a typed error explaining *why* it failed).
     */
    public static function isValid(
        #[\SensitiveParameter] string|\Stringable $payload,
        ?string $signature,
        #[\SensitiveParameter] string $secret,
        int|float $toleranceSec = self::DEFAULT_TOLERANCE_SEC,
        int|float|null $nowSec = null,
        #[\SensitiveParameter] array $alsoRefuse = [],
    ): bool {
        try {
            self::verify($payload, $signature, $secret, $toleranceSec, $nowSec, $alsoRefuse);
            return true;
        } catch (PaylodSignatureVerificationError) {
            return false;
        }
    }

    /**
     * The signature-only boolean form. Answers "did paylod send these bytes" and NOTHING else - in
     * particular it does NOT answer "may I act on this event", which is what a `true` from a
     * function named like this reads as at a call site.
     *
     * A bare `true` here approved an evidence-free `payment.success`, so the name now carries its
     * own warning and the doc says what it omits. Use {@see isValid()} to decide anything.
     *
     * @internal pins the signing scheme; not part of the supported event-handling surface.
     */
    public static function isValidSignatureOnlyNotActionable(
        #[\SensitiveParameter] string|\Stringable $payload,
        ?string $signature,
        #[\SensitiveParameter] string $secret,
        int|float $toleranceSec = self::DEFAULT_TOLERANCE_SEC,
        int|float|null $nowSec = null,
        #[\SensitiveParameter] array $alsoRefuse = [],
    ): bool {
        try {
            self::parseSignedEnvelope($payload, $signature, $secret, $toleranceSec, $nowSec, $alsoRefuse);
            return true;
        } catch (PaylodSignatureVerificationError) {
            return false;
        }
    }

    /**
     * Sign a payload the way the paylod webhook worker does. Exported so you can build realistic
     * fixtures in your own tests - you never need this in production code.
     */
    public static function sign(
        #[\SensitiveParameter] string|\Stringable $payload,
        #[\SensitiveParameter] string $secret,
        ?int $timestampSec = null,
    ): string
    {
        $t = $timestampSec ?? time();
        $v1 = hash_hmac('sha256', $t . '.' . (string) $payload, $secret);

        return "t={$t},v1={$v1}";
    }

    /**
     * A tolerance / injected clock must be a FINITE, POSITIVE, WHOLE number of seconds.
     *
     * Rejecting NAN and INF matters: `abs(NAN - $t) > NAN` is false, so a NaN tolerance would make
     * every freshness check pass - a silent, total loss of replay protection that looks like a
     * working verifier. A non-integral value is refused too rather than truncated, so the window a
     * caller asked for is always the window they get.
     *
     * @throws PaylodSignatureVerificationError
     */
    private static function requirePositiveInt(int|float $value, string $label): int
    {
        if (is_float($value) && (!is_finite($value) || floor($value) !== $value)) {
            throw new PaylodSignatureVerificationError(
                'insecure_tolerance',
                "{$label} must be a finite, whole number of seconds (got " . var_export($value, true) . ').'
            );
        }
        if ($value <= 0) {
            throw new PaylodSignatureVerificationError(
                'insecure_tolerance',
                "{$label} must be greater than 0 (got " . var_export($value, true) . '). A zero or '
                . 'negative tolerance disables webhook replay protection entirely. To verify a pinned '
                . 'fixture, keep a normal tolerance and inject the fixture\'s own timestamp as $nowSec.'
            );
        }

        return (int) $value;
    }

    /**
     * The anti-replay window: finite, positive, whole - AND BOUNDED ABOVE.
     *
     * Positivity alone is not a security property. `PHP_INT_MAX` is positive, finite and whole, and
     * it disables replay protection completely. A window is only a window if it has both edges.
     *
     * @throws PaylodSignatureVerificationError
     */
    private static function requireTolerance(int|float $value): int
    {
        // THE CEILING IS CHECKED ON THE RAW VALUE, BEFORE ANY CAST. `(int) (float) PHP_INT_MAX`
        // OVERFLOWS to PHP_INT_MIN, so a ceiling applied after the cast would see a large NEGATIVE
        // number, wave it through, and hand back the very unbounded window it exists to forbid.
        if ($value > self::MAX_TOLERANCE_SEC) {
            self::rejectTolerance($value);
        }

        $seconds = self::requirePositiveInt($value, 'toleranceSec');
        if ($seconds > self::MAX_TOLERANCE_SEC) {
            self::rejectTolerance($value);
        }

        return $seconds;
    }

    /** @throws PaylodSignatureVerificationError */
    private static function rejectTolerance(int|float $value): never
    {
        throw new PaylodSignatureVerificationError(
            'insecure_tolerance',
            'toleranceSec must be at most ' . self::MAX_TOLERANCE_SEC . ' seconds (got '
            . var_export($value, true) . '). A window wider than that is not replay protection: '
            . 'a correctly signed webhook captured arbitrarily long ago would still verify, and '
            . 'a replayed payment.success gets the same order fulfilled twice. The default of '
            . self::DEFAULT_TOLERANCE_SEC . 's already absorbs clock skew and delivery retries. '
            . 'To verify a pinned fixture, keep a normal tolerance and inject the fixture\'s own '
            . 'timestamp as $nowSec.'
        );
    }

    /** A well-formed `v1` is 64 lowercase hex chars (an HMAC-SHA256 digest). */
    private const V1_RE = '/^[0-9a-f]{64}\z/';

    /**
     * Parse the signature header STRICTLY. The header is `t=<unix>,v1=<hex>` and we require EXACTLY
     * ONE `t` and EXACTLY ONE `v1`, rejecting anything else.
     *
     * This closes a last-value-wins hole: two `x-webhook-signature` headers combined into one
     * comma-joined value (`t=1,v1=<real>,t=9999999999,v1=<forged>`) must NOT be accepted by silently
     * taking the last pair. A duplicate of either key is fatal, as is a malformed `v1`.
     *
     * @return array{t:string,v1:string}|null
     */
    private static function parseHeader(string $header): ?array
    {
        $t = null;
        $v1 = null;
        $tCount = 0;
        $v1Count = 0;
        foreach (explode(',', $header) as $seg) {
            $s = trim($seg);
            if ($s === '') {
                continue;
            }
            $idx = strpos($s, '=');
            if ($idx === false || $idx === 0) {
                continue;
            }
            $key = trim(substr($s, 0, $idx));
            $val = trim(substr($s, $idx + 1));
            if ($key === 't') {
                $t = $val;
                $tCount++;
            } elseif ($key === 'v1') {
                $v1 = $val;
                $v1Count++;
            }
            // Unknown keys are ignored for forward-compatibility; a duplicate t/v1 is fatal below.
        }
        if ($tCount !== 1 || $v1Count !== 1 || $t === null || $v1 === null || $t === '' || $v1 === '') {
            return null;
        }
        // `v1` must be exactly one 64-char lowercase-hex digest. `t` is validated (integer) by the
        // caller so the "not a number" diagnostic stays specific.
        if (preg_match(self::V1_RE, $v1) !== 1) {
            return null;
        }

        return ['t' => $t, 'v1' => $v1];
    }
}

<?php

declare(strict_types=1);

namespace Paylod\Support;

use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodInvalidRequestError;

/**
 * The money-path validators, in ONE place so every dispatch surface uses the SAME rules.
 *
 * They used to live as private statics on the client, which meant the simulator - a second public
 * dispatch surface - quietly had none of them: it accepted an idempotency key the production path
 * would have rejected, and turned a malformed 2xx into an empty payment id instead of a keyed
 * indeterminate error. A validator that only one caller uses is not a guard, it is a comment.
 */
final class Validate
{
    /** The payment states the API is allowed to report. Anything else is a malformed body. */
    public const PAYMENT_STATUSES = ['pending', 'success', 'failed', 'cancelled'];

    /**
     * The ONLY status an acknowledgement may carry, and the ONLY HTTP status a dispatched collect
     * answers with.
     *
     * `status` is a HARDCODED LITERAL "pending" on the backend, present on every 202 - including an
     * idempotent REPLAY, which returns the stored original ack rather than the current settled
     * state. So there is no legitimate ack carrying a settled status, and no legitimate ack missing
     * the field either: both are malformed, and requiring the literal cannot break replay.
     *
     * Note the asymmetry with a STATUS read, which legitimately carries terminal states. There,
     * `success` is not trusted from the string - it must be backed by a receipt or result code 0.
     */
    public const ACK_STATUS = 'pending';

    /**
     * POST /collect answers 202 Accepted and nothing else: the STK push has been handed to Daraja
     * and the payment is pending. Accepting ANY 2xx meant a bare 200 - the shape a cache, a proxy, a
     * captive portal, a stubbed endpoint or a rewritten route produces - was read as a successfully
     * dispatched charge. A 200 here is not a successful collect; it is a response from something
     * that is not the collect endpoint, and treating it as an ack invents a payment that may not
     * exist (or hides one that does).
     */
    public const ACK_HTTP_STATUS = 202;

    /**
     * Reject an idempotency key that would silently drop double-charge protection: blank/whitespace
     * keys, keys carrying control characters (which also cannot go in an HTTP header), and absurdly
     * long values. A caller-supplied key is the ONE thing standing between a double-click and a
     * double-charge, so a bad one must fail loudly rather than be quietly accepted.
     */
    public static function idempotencyKey(mixed $key): void
    {
        if (!is_string($key) || trim($key) === '') {
            throw new PaylodInvalidRequestError(
                'idempotencyKey must be a non-empty, non-whitespace string - a blank key silently drops '
                . 'double-charge protection.'
            );
        }
        // Bound the BYTE length: this goes out as an HTTP header value, and header sizes are bytes.
        if (strlen($key) > 255) {
            throw new PaylodInvalidRequestError('idempotencyKey must be 255 bytes or fewer.');
        }
        // The Unicode classes below are only meaningful over well-formed UTF-8. Invalid UTF-8 in a
        // header value is itself a bug, so reject it rather than fall back to a byte-wise check.
        if (preg_match('//u', $key) !== 1) {
            throw new PaylodInvalidRequestError('idempotencyKey must be valid UTF-8.');
        }
        // The FULL control ranges: C0 (U+0000-U+001F), DEL (U+007F) and C1 (U+0080-U+009F). All are
        // illegal in an HTTP header value, and a C1 byte pair sneaking through a byte-only C0 check
        // was how a mangled key used to be accepted silently.
        if (preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $key) === 1) {
            throw new PaylodInvalidRequestError(
                'idempotencyKey must not contain control characters (tabs, newlines, NULs, and the '
                . 'C1 range).'
            );
        }
        // Unicode-only whitespace: NBSP, ideographic space, the line/paragraph separators and the BOM
        // all survive trim() (which only strips ASCII), so "\u{00A0}" used to pass as a "real" key
        // while being invisible - and two visually identical keys would be two different charges.
        if (preg_match('/[^\P{Z}\x{0020}]|[\x{FEFF}\x{180E}]/u', $key) === 1) {
            throw new PaylodInvalidRequestError(
                'idempotencyKey must not contain Unicode-only whitespace (non-breaking space, '
                . 'ideographic space, BOM, line/paragraph separators). Use plain ASCII.'
            );
        }
        // Finally: PRINTABLE ASCII only (0x20-0x7E). HTTP header values are ASCII on the wire
        // (RFC 9110), so a printable non-ASCII character like the accented e in "ordr-cafe-1" is not
        // merely exotic - it is unrepresentable. It either blows up in the transport as an opaque
        // encoding error, or (worse, on a laxer stack) gets silently re-encoded, so two requests that
        // were meant to carry ONE key no longer do and the duplicate-charge guard quietly vanishes.
        if (preg_match('/[^\x20-\x7e]/', $key) === 1) {
            throw new PaylodInvalidRequestError(
                'idempotencyKey must be printable ASCII only (letters, digits and punctuation in the '
                . 'range 0x20-0x7E). HTTP header values are ASCII on the wire, so an accented or '
                . 'non-Latin character cannot be sent reliably - and a silently re-encoded key stops '
                . 'matching the retry it was meant to deduplicate. Derive the key from an id you '
                . 'control (a UUID, or your order id slugged to ASCII).'
            );
        }
    }

    /**
     * Validate the COMPLETE acknowledgement schema of a 2xx from a collect-shaped endpoint.
     *
     * Checking only `paymentId` was not enough. A 2xx with a blank `checkoutRequestId`, a missing
     * `status`, or a `status` of the wrong type is just as malformed - and just as INDETERMINATE:
     * the STK push may already be on a customer's phone. Every one of those must come back as a
     * keyed indeterminate error, never as a "successful" ack the caller will treat as a new payment
     * and retry under a fresh key.
     *
     * -- Why $parsed and $redact are #[\SensitiveParameter] -------------------------------------
     * `$parsed` is a RAW RESPONSE BODY - bytes from the network, attacker-influenced, and the exact
     * thing a misconfigured gateway echoes an Authorization header into. The message and the
     * attached body are both scrubbed, but that was never the whole exposure: PHP records call
     * ARGUMENTS in every stack trace when zend.exception_ignore_args=0 (the development default),
     * and the argument recorded here is the body BEFORE redaction. So a reflected bearer token was
     * scrubbed out of everything a reader looks at and left sitting verbatim in getTrace().
     *
     * `$redact` is marked too: it is a closure BOUND TO THE CLIENT, so a trace that renders its
     * bound scope reaches the credential through it.
     *
     * @param array<string,mixed> $parsed
     * @param ?callable(mixed):mixed $redact applied to the body before it is attached to the error
     */
    public static function collectAck(
        #[\SensitiveParameter] array $parsed,
        int $status,
        ?string $idempotencyKey = null,
        #[\SensitiveParameter] ?callable $redact = null,
    ): void {
        $problem = self::ackProblem($parsed, $status, $redact);
        if ($problem === null) {
            return;
        }

        throw new PaylodApiError(
            "paylod returned a {$status} whose body is not a valid acknowledgement ({$problem}) - the "
            . 'charge state is INDETERMINATE, the STK prompt may already be on the phone. Read the '
            . 'payment with this idempotencyKey before starting any new attempt; do NOT mint a fresh '
            . 'key (that risks a second charge).',
            $status,
            $redact === null ? $parsed : $redact($parsed),
            $idempotencyKey,
            true,
        );
    }

    /**
     * Validate the COMPLETE payment schema of a 2xx status body.
     *
     * This is a SHAPE check plus the binding check below. It deliberately says nothing about
     * whether the payment is paid: that question has exactly one home, {@see \Paylod\Semantics}.
     *
     * -- The binding check (law L1) ------------------------------------------------------------
     * This is the highest-value single check in the SDK. Nothing previously compared the `id` in the
     * response to the id in the request, so ANY mechanism that returned a DIFFERENT payment's record
     * - a cache keyed on the wrong thing, a proxy collapsing concurrent requests, an off-by-one in a
     * routing or authorization layer, a server-side bug, a deliberately crafted response - produced
     * a body the SDK validated happily and then classified on its own merits. If that other payment
     * happened to be settled and paid, the caller was told THEIR payment was paid, and shipped goods
     * for an order nobody had paid for.
     *
     * A response that answers a different question is not a MALFORMED response, it is a WRONG one,
     * and no amount of field-level shape checking can find it - every field is perfectly valid. The
     * request knows which payment it asked about; the answer has to say the same thing, or it is not
     * an answer at all. A mismatch is INDETERMINATE, because that is the honest reading: we now know
     * nothing about the payment we asked about, and "I do not know" must never collapse to "failed"
     * (reported as retryable, so the customer is charged twice) or to "paid".
     *
     * @param array<string,mixed> $parsed
     * @param ?callable(mixed):mixed $redact
     * @param ?string $expectedId the id that was REQUESTED. The body must agree with it.
     */
    public static function paymentBody(
        #[\SensitiveParameter] array $parsed,
        int $status,
        #[\SensitiveParameter] ?callable $redact = null,
        ?string $expectedId = null,
    ): void {
        $problem = self::paymentProblem($parsed, $expectedId, $redact);
        if ($problem === null) {
            return;
        }

        throw new PaylodApiError(
            "paylod returned a {$status} status body that is not a valid payment ({$problem}). The "
            . 'payment state could NOT be established from this response - treat it as unknown and '
            . 're-read it; do not record it as settled either way.',
            $status,
            $redact === null ? $parsed : $redact($parsed),
            null,
            true,
        );
    }

    /**
     * @param array<string,mixed> $parsed
     * @param ?callable(mixed):mixed $redact
     */
    private static function ackProblem(
        #[\SensitiveParameter] array $parsed,
        int $httpStatus,
        #[\SensitiveParameter] ?callable $redact = null,
    ): ?string {
        // THE HTTP STATUS IS PART OF THE CONTRACT, and it is checked FIRST - before any field - so a
        // well-shaped body served by something that is not the collect endpoint cannot pass.
        if ($httpStatus !== self::ACK_HTTP_STATUS) {
            return "HTTP {$httpStatus}, expected " . self::ACK_HTTP_STATUS . ' Accepted - a collect '
                . 'that was genuinely dispatched always answers 202';
        }
        if (!self::isNonBlankString($parsed['paymentId'] ?? null)) {
            return 'no usable paymentId';
        }
        if (!self::isNonBlankString($parsed['checkoutRequestId'] ?? null)) {
            return 'no usable checkoutRequestId';
        }
        // THE IDENTIFIERS ARE SHAPE-CHECKED, not merely non-blank. Both are returned to the caller
        // and both land in ordinary, commonly-logged output.
        foreach (['paymentId', 'checkoutRequestId'] as $field) {
            $problem = self::identifierProblem($field, (string) $parsed[$field], $redact);
            if ($problem !== null) {
                return $problem;
            }
        }
        $status = $parsed['status'] ?? null;
        if ($status !== self::ACK_STATUS) {
            return 'status was ' . json_encode($status) . ', expected the literal "'
                . self::ACK_STATUS . '"';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $parsed
     * @param ?callable(mixed):mixed $redact
     */
    private static function paymentProblem(
        #[\SensitiveParameter] array $parsed,
        ?string $expectedId = null,
        #[\SensitiveParameter] ?callable $redact = null,
    ): ?string {
        if (!self::isNonBlankString($parsed['id'] ?? null)) {
            return 'no usable payment id';
        }

        // THE SAME IDENTIFIER GRAMMAR THE ACKNOWLEDGEMENT ENFORCES, on the status-read path.
        //
        // It was applied to `paymentId` / `checkoutRequestId` on the 202 and to nothing at all on
        // the 200 that a status read, a wait() poll and a simulator settle all go through - so the
        // credential check existed on the surface a caller hits ONCE per payment and was missing
        // from the one they hit on every poll. A gateway echoing the Authorization header into `id`
        // therefore still reached PaymentOutcome::$paymentId, which is the value applications log on
        // every charge and store in a payments table in plaintext.
        //
        // A violation is INDETERMINATE for the same reason it is on the ack: we asked about a real
        // payment and got back something that is not an answer, so we now know nothing about it.
        $idProblem = self::identifierProblem('id', (string) $parsed['id'], $redact);
        if ($idProblem !== null) {
            return $idProblem;
        }

        // L1 BINDING. Checked before anything else about the record's CONTENTS, because if this
        // fails then every remaining field describes some OTHER payment, and reasoning about them is
        // not merely useless but actively misleading.
        if ($expectedId !== null && $parsed['id'] !== $expectedId) {
            return 'the body describes payment ' . json_encode($parsed['id']) . ' but '
                . json_encode($expectedId) . ' was requested - this response answers a different '
                . 'question, so it tells you NOTHING about the payment you asked about';
        }

        $status = $parsed['status'] ?? null;
        if (!is_string($status)) {
            return 'status is missing or not a string';
        }
        if (!in_array($status, self::PAYMENT_STATUSES, true)) {
            return "status \"{$status}\" is not a known payment state";
        }

        $receipt = $parsed['mpesaReceipt'] ?? null;
        if ($receipt !== null && !is_string($receipt)) {
            return 'mpesaReceipt is present but not a string';
        }
        $code = $parsed['resultCode'] ?? null;
        if ($code !== null && !is_int($code) && !is_string($code)) {
            return 'resultCode is present but is neither a number nor a string';
        }
        $desc = $parsed['resultDesc'] ?? null;
        if ($desc !== null && !is_string($desc)) {
            return 'resultDesc is present but not a string';
        }

        // NOTE: the "a success claim needs EVIDENCE" rule (law L2) deliberately does NOT live here
        // any more. It is a SEMANTIC rule, not a shape rule, and it belongs in exactly one place -
        // {@see \Paylod\Semantics::judge()} - so the status-read path, the webhook path and the
        // simulator cannot drift into disagreeing about what proves a payment. Enforcing it here as
        // well would also be actively wrong on the polling path: an evidence-free `success` is
        // INDETERMINATE, and an indeterminate payment must keep being polled so a webhook can settle
        // it, not abort wait() with a throw.

        return null;
    }

    private static function isNonBlankString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * The identifier grammar. Bounded length, a closed character set, and NOT credential-shaped.
     *
     * -- Why an id needs a grammar ------------------------------------------------------------
     * `paymentId` and `checkoutRequestId` were required to be non-blank strings and nothing more,
     * so a 202 was a valid-looking acknowledgement no matter WHAT those fields contained. A server
     * that echoes the Authorization header into either of them - the same misconfigured gateway or
     * debug-echo endpoint the body redaction already exists to defend against - therefore handed the
     * bearer key back through the SUCCESS path, where nothing redacts it. And these two fields are
     * about the worst possible carriers: an id is the value applications log on every charge, put in
     * support tickets, and store in a payments table in plaintext.
     *
     * Redaction on the error path does not help here, because this is not an error path. The answer
     * is to refuse the shape: a real paylod id is a short, opaque token, and a bearer credential is
     * not one.
     *
     * A violation is INDETERMINATE rather than a plain rejection: the 202 means the STK push was
     * dispatched, so a charge may well be live. The caller must re-read it under the same key, never
     * mint a new one.
     */
    private const MAX_IDENTIFIER_BYTES = 128;

    /** Opaque token: alphanumeric, with the separators paylod ids actually use. No spaces, no colons-into-URLs. */
    private const IDENTIFIER_RE = '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,127}\z/';

    /** @param ?callable(mixed):mixed $redact */
    private static function identifierProblem(
        string $field,
        string $value,
        #[\SensitiveParameter] ?callable $redact = null,
    ): ?string {
        if (strlen($value) > self::MAX_IDENTIFIER_BYTES) {
            return "{$field} is " . strlen($value) . ' bytes long, which is not an identifier - a '
                . 'paylod id is a short opaque token, and an oversized value in this field is either '
                . 'a different kind of data or something reflected back at us';
        }
        if (preg_match(self::IDENTIFIER_RE, $value) !== 1) {
            return "{$field} is not a well-formed identifier (expected an opaque token of letters, "
                . 'digits, underscore, dot or hyphen)';
        }

        // THE CREDENTIAL CHECK, expressed through the redactor the client already carries. Redact
        // replaces the exact secrets this process holds AND anything credential-shaped
        // (mp_live_/mp_test_/whsec_), so a value that CHANGES under redaction is, by that same
        // definition, a secret or a credential-shaped token. Reusing it means this check can never
        // drift from what the SDK considers a secret elsewhere.
        if ($redact !== null && $redact($value) !== $value) {
            return "{$field} contains a credential-shaped value or this client's own API key - a "
                . 'server echoing your bearer token into an identifier field would put it straight '
                . 'into your payment logs';
        }

        return null;
    }
}

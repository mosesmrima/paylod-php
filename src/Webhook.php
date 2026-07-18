<?php

declare(strict_types=1);

namespace Paylod;

use Paylod\Exceptions\PaylodSignatureVerificationError;

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
        string|\Stringable $payload,
        ?string $signature,
        #[\SensitiveParameter] string $secret,
        int|float $toleranceSec = self::DEFAULT_TOLERANCE_SEC,
        int|float|null $nowSec = null,
    ): array {
        $raw = (string) $payload;

        // Freshness is NOT optional. A zero/negative/NaN tolerance disables replay protection, and
        // there is no caller - test or otherwise - who needs that: a pinned fixture verifies fine
        // with a normal window and an injected $nowSec. Validated FIRST so a misconfigured verifier
        // can never reach the HMAC comparison and return a "valid" verdict.
        $tolerance = self::requirePositiveInt($toleranceSec, 'toleranceSec');
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
        if (preg_match('/^[0-9]{1,19}$/', $parsed['t']) !== 1
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

        $event = json_decode($raw, true);
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

        return $event;
    }

    /**
     * Verify and return true/false. This is the boolean convenience form matching the documented
     * `verifyWebhook($rawBody, $signatureHeader, $secret)` surface; use {@see verify()} when you
     * want the decoded event (and a typed error explaining *why* it failed).
     */
    public static function isValid(
        string|\Stringable $payload,
        ?string $signature,
        #[\SensitiveParameter] string $secret,
        int|float $toleranceSec = self::DEFAULT_TOLERANCE_SEC,
        int|float|null $nowSec = null,
    ): bool {
        try {
            self::verify($payload, $signature, $secret, $toleranceSec, $nowSec);
            return true;
        } catch (PaylodSignatureVerificationError) {
            return false;
        }
    }

    /**
     * Sign a payload the way the paylod webhook worker does. Exported so you can build realistic
     * fixtures in your own tests - you never need this in production code.
     */
    public static function sign(string|\Stringable $payload, #[\SensitiveParameter] string $secret, ?int $timestampSec = null): string
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

    /** A well-formed `v1` is 64 lowercase hex chars (an HMAC-SHA256 digest). */
    private const V1_RE = '/^[0-9a-f]{64}$/';

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

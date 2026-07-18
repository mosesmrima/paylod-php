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
        int $toleranceSec = self::DEFAULT_TOLERANCE_SEC,
        ?int $nowSec = null,
    ): array {
        $raw = (string) $payload;

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

        // `t` must ALWAYS be an integer, regardless of tolerance - a non-numeric timestamp is
        // malformed. (Node validates this before the tolerance branch for the same reason.)
        if (filter_var($parsed['t'], FILTER_VALIDATE_INT) === false) {
            throw new PaylodSignatureVerificationError(
                'malformed_signature',
                'Signature timestamp is not a number.'
            );
        }
        $t = (int) $parsed['t'];

        if ($toleranceSec > 0) {
            $now = $nowSec ?? time();
            if (abs($now - $t) > $toleranceSec) {
                throw new PaylodSignatureVerificationError(
                    'stale_timestamp',
                    "Signature timestamp is outside the {$toleranceSec}s tolerance (replay?)."
                );
            }
        } elseif ($nowSec === null) {
            // A non-positive tolerance would DISABLE replay protection. That is only ever acceptable
            // with a fixed, injected clock (a pinned test vector). In production - no $nowSec - refuse
            // it loudly rather than silently accept replays of any age.
            throw new PaylodSignatureVerificationError(
                'insecure_tolerance',
                'toleranceSec must be a positive number of seconds. A non-positive tolerance disables '
                . 'webhook replay protection and is only permitted in tests that inject a fixed $nowSec.'
            );
        }
        // else: toleranceSec <= 0 AND a fixed $nowSec was injected - a deterministic fixed-vector
        // test. The freshness window is intentionally skipped; the pinned clock makes replay moot.

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
        int $toleranceSec = self::DEFAULT_TOLERANCE_SEC,
        ?int $nowSec = null,
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

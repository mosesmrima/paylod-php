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
        string $secret,
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

        if ($toleranceSec > 0) {
            if (!is_numeric($parsed['t'])) {
                throw new PaylodSignatureVerificationError(
                    'malformed_signature',
                    'Signature timestamp is not a number.'
                );
            }
            $t = (int) $parsed['t'];
            $now = $nowSec ?? time();
            if (abs($now - $t) > $toleranceSec) {
                throw new PaylodSignatureVerificationError(
                    'stale_timestamp',
                    "Signature timestamp is outside the {$toleranceSec}s tolerance (replay?)."
                );
            }
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
        string $secret,
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
    public static function sign(string|\Stringable $payload, string $secret, ?int $timestampSec = null): string
    {
        $t = $timestampSec ?? time();
        $v1 = hash_hmac('sha256', $t . '.' . (string) $payload, $secret);

        return "t={$t},v1={$v1}";
    }

    /**
     * @return array{t:string,v1:string}|null
     */
    private static function parseHeader(string $header): ?array
    {
        $parts = [];
        foreach (explode(',', $header) as $seg) {
            $idx = strpos($seg, '=');
            if ($idx === false || $idx === 0) {
                continue;
            }
            $parts[trim(substr($seg, 0, $idx))] = trim(substr($seg, $idx + 1));
        }
        if (!isset($parts['t'], $parts['v1']) || $parts['t'] === '' || $parts['v1'] === '') {
            return null;
        }

        return ['t' => $parts['t'], 'v1' => $parts['v1']];
    }
}

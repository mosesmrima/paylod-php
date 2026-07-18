<?php

declare(strict_types=1);

namespace Paylod\Support;

/**
 * Scrub secrets out of anything that is about to become an exception message, an exception body, or
 * a var_dump/print_r of a client.
 *
 * Two layers, because either one alone leaks:
 *
 *  1. The EXACT secrets this process holds (the API key, the webhook secret). A server that echoes
 *     the Authorization header back in an error body - a misconfigured gateway, a debug endpoint,
 *     a 400 that quotes the request - would otherwise put a live money-moving key straight into the
 *     application's error log.
 *  2. Anything SHAPED like a paylod credential (mp_live_/mp_test_/whsec_ followed by key
 *     characters). This catches keys that are not ours: another tenant's key quoted back, or the
 *     same key in a different casing/wrapping than the one we hold.
 */
final class Redact
{
    public const PLACEHOLDER = '[redacted]';

    /** Guard against pathological nesting; response bodies are attacker-influenced. */
    private const MAX_DEPTH = 12;

    /** Credential-shaped tokens, redacted even when they are not the secrets we hold. */
    private const TOKEN_RE = '/\b(?:mp_live_|mp_test_|whsec_)[A-Za-z0-9_\-]+/';

    /**
     * Recursively redact secrets from a value (string, array, or anything else - non-strings that
     * cannot carry a secret are returned untouched).
     *
     * @param list<string|null> $secrets
     */
    public static function apply(mixed $value, array $secrets, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return self::text($value, $secrets);
        }
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return self::PLACEHOLDER;
            }
            $out = [];
            foreach ($value as $k => $v) {
                $key = is_string($k) ? self::text($k, $secrets) : $k;
                $out[$key] = self::apply($v, $secrets, $depth + 1);
            }

            return $out;
        }

        return $value;
    }

    /**
     * Redact a single string: the exact secrets first, then anything credential-shaped.
     *
     * @param list<string|null> $secrets
     */
    public static function text(string $value, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (!is_string($secret) || $secret === '') {
                continue;
            }
            $value = str_replace($secret, self::PLACEHOLDER, $value);
            // Also catch the header form the key travels in.
            $value = str_replace('Bearer ' . $secret, self::PLACEHOLDER, $value);
        }

        return (string) preg_replace(self::TOKEN_RE, self::PLACEHOLDER, $value);
    }

    /**
     * A masked, SAFE-to-print rendering of a secret: enough to tell two keys apart in a log, never
     * enough to use one. Used by __debugInfo(), which is what print_r()/var_dump() actually call.
     */
    public static function mask(?string $secret): ?string
    {
        if ($secret === null) {
            return null;
        }
        if ($secret === '') {
            return '';
        }
        // Keep only the environment-identifying prefix. The entropy is never shown, at any length.
        foreach (['mp_live_', 'mp_test_', 'whsec_'] as $prefix) {
            if (str_starts_with($secret, $prefix)) {
                return $prefix . '***';
            }
        }

        return '***';
    }
}

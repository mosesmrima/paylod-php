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

    /**
     * THE REDACTION DEPTH IS PINNED TO THE PARSE DEPTH.
     *
     * This was 12 while {@see JsonLexeme::MAX_DEPTH} and every `json_decode()` call in the SDK use
     * 512. A redactor that stops shallower than the parser is a redactor with a blind spot the
     * parser can reach: a secret echoed at depth 13 of a signed body was PARSED into the event and
     * then walked past by the scrubber. The sibling Node (8 vs 64), Python (12 vs 64) and JVM
     * (8 vs 64) SDKs all carried the same drift; it is fixed the same way in all four - one
     * constant, and a test asserting the two are equal so they cannot drift again.
     *
     * Beyond the limit the traversal FAILS CLOSED: content the redactor cannot reach is replaced
     * with the placeholder outright, never forwarded on the assumption it is clean.
     */
    public const MAX_DEPTH = JsonLexeme::MAX_DEPTH;

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
     * TRUE when a value carries the redaction marker.
     *
     * -- Why this exists -----------------------------------------------------------------------
     * Redacting a credential turned it into proof of payment. `Semantics::hasReceipt()` asked only
     * "is this a non-blank string?", and an API key echoed into `data.mpesaReceipt` was rewritten to
     * `[redacted]` by the scrubber above - which IS a non-blank string. A `status: "success"` record
     * with no result code therefore came back `paid = true`, and the equivalent `payment.success`
     * webhook was delivered as a settled payment. Neither component was wrong on its own: the
     * redactor believed it was sanitising a string, and the evidence check believed any non-empty
     * string was a receipt. They disagreed about what the placeholder MEANS, and the money path sat
     * in the gap.
     *
     * The rule that closes it, everywhere and permanently: THE REDACTION MARKER IS NEVER EVIDENCE,
     * NEVER AN IDENTIFIER, AND NEVER A CORRELATION VALUE. A field whose content had to be destroyed
     * carries no information, so no decision may be taken from it. Every evidence and identity check
     * in the SDK calls this, and `tests/NinthRoundHardeningTest.php` asserts each one refuses it.
     */
    public static function containsPlaceholder(mixed $value): bool
    {
        return is_string($value) && str_contains($value, self::PLACEHOLDER);
    }

    /**
     * TRUE when a string contains one of the EXACT secrets this process holds, or anything shaped
     * like a paylod credential.
     *
     * Used on the money path, where the right answer is to REFUSE rather than redact-and-deliver: a
     * signed body echoing our own credential back is a compromised or misconfigured server, and
     * continuing to act on its contents is acting on an attacker's or a broken relay's output. The
     * three sibling SDKs made the same call in round 9. Redaction stays where it belongs - in
     * diagnostics - and this predicate is what lets the money path tell the two situations apart.
     *
     * @param list<string|null> $secrets
     */
    public static function contains(string $value, array $secrets): bool
    {
        return self::text($value, $secrets) !== $value;
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

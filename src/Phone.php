<?php

declare(strict_types=1);

namespace Paylod;

use Paylod\Exceptions\PaylodInvalidRequestError;

/**
 * Kenyan MSISDN normalisation - the reference implementation of the canonical spec.
 *
 * Mirrors the server's normalizePhone (supabase/functions/_shared/daraja/primitives.ts) so the
 * SDK rejects a bad number locally instead of burning a round-trip on a 422. Accepts
 * `0712345678`, `+254712345678`, `254712345678`, `712345678`, with or without spaces/dashes.
 * Emits the canonical `2547XXXXXXXX` / `2541XXXXXXXX` form.
 *
 * Kept byte-identical in behaviour with the Node/CLI/MCP copies (a divergence is a real bug).
 */
final class Phone
{
    /** Validates RAW user input in any accepted Kenyan form (before normalization). */
    public const INPUT_RE = '/^(?:\+?254|0)?[17]\d{8}$/';

    /** True if `$input` is an acceptable Kenyan MSISDN form. Does not throw. */
    public static function isValid(string $input): bool
    {
        return preg_match(self::INPUT_RE, trim($input)) === 1;
    }

    public static function normalize(string $input): string
    {
        if (trim($input) === '') {
            throw new PaylodInvalidRequestError('phone is required');
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (str_starts_with($digits, '254')) {
            $msisdn = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $msisdn = '254' . substr($digits, 1);
        } elseif (str_starts_with($digits, '7') || str_starts_with($digits, '1')) {
            $msisdn = '254' . $digits;
        } else {
            throw new PaylodInvalidRequestError("unrecognized Kenyan phone format: {$input}");
        }

        // `\z`, not `$`: PCRE's `$` also matches before a trailing newline, so "254712345678\n"
        // would have passed as a normalised MSISDN.
        if (preg_match('/^254[17]\d{8}\z/', $msisdn) !== 1) {
            throw new PaylodInvalidRequestError("not a valid Kenyan phone number: {$input}");
        }

        return $msisdn;
    }
}

<?php

declare(strict_types=1);

namespace Paylod\Support;

/**
 * RAW-JSON GUARDS, applied BEFORE the parser is allowed to destroy the evidence.
 *
 * -- The problem this exists to solve ----------------------------------------------------------
 * {@see \Paylod\DarajaCatalog} refuses to read a non-canonical ResultCode: `-0`, `0e999`, `00` and
 * `+0` are impostors, not zeroes, and only the exact token `0` proves money moved. That rule is
 * correct and it is enforced on the value's LEXEME.
 *
 * By the time it runs, though, the lexeme is already gone. `json_decode('{"resultCode":-0}')`
 * yields the PHP integer `0` - not "-0", not a float, not a string: the identical value a genuine
 * `0` produces. The two are indistinguishable AFTER parsing, so a `status: "success"` body carrying
 * the raw token `-0` was classified EVIDENCE_SUCCESS and reported PAID. `0e999` decodes to the
 * float `0.0` by the same route. The classifier never had a chance; the parser had already
 * laundered the input.
 *
 * This is the same defect as the trim-before-validate one, one layer lower down: a normalising
 * transform running ahead of the validator that is supposed to reject its input.
 *
 * -- The approach, and its limits ---------------------------------------------------------------
 * The raw bytes are scanned for `resultCode` keys BEFORE `json_decode()` is called, and any
 * ResultCode whose NUMERIC TOKEN is not canonical (`0`, or `[1-9][0-9]*`, optionally negative for a
 * non-zero code) is rejected outright. The body is refused rather than repaired: a legitimate
 * paylod response never contains a non-canonical numeric ResultCode, so there is nothing to
 * preserve, and rejecting is the only answer that cannot be misread downstream.
 *
 * This deliberately does NOT try to map raw tokens back onto decoded values by position. That zip
 * is fragile in exactly the case that matters (a hostile body can desynchronise it), and a guard
 * that is only correct on well-behaved input is not a guard.
 *
 * KNOWN LIMITS, stated plainly:
 *
 *   1. It is a LEXICAL scan, not a parse. A JSON *string* whose contents happen to look like
 *      `"resultCode": -0` - most plausibly inside a `resultDesc` echoing a request - is matched and
 *      the body is refused. That is a FALSE POSITIVE, and it fails CLOSED: the caller gets an
 *      indeterminate error and re-reads the payment. A false positive costs a retry of a read; a
 *      false negative costs a double-charge or an unpaid shipment.
 *   2. It governs `resultCode` ONLY. That is the one field in the schema whose exact numeric
 *      lexeme decides whether money moved. Other numbers (amounts, timestamps) are not evidence and
 *      are not scanned.
 *   3. It says nothing about a resultCode sent as a JSON *string* (`"resultCode": " 0"`). It does
 *      not need to: a string survives `json_decode` byte-for-byte, so the lexeme reaches
 *      {@see \Paylod\DarajaCatalog} intact and is rejected there.
 */
final class JsonLexeme
{
    /**
     * A ResultCode key followed by a JSON numeric token, captured whole.
     *
     * The token grammar is JSON's own (optional minus, integer part, optional fraction, optional
     * exponent) so that the ENTIRE token is captured - otherwise `-0` would be caught but `0e999`
     * would match only its leading `0` and be waved through as canonical.
     */
    private const RESULT_CODE_NUMBER_RE =
        '/"resultCode"\s*:\s*(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/';

    /** The only numeric ResultCode tokens the schema defines: exactly `0`, or an unsigned non-zero. */
    private const CANONICAL_TOKEN_RE = '/^(?:0|[1-9][0-9]*)\z/';

    /**
     * The first non-canonical numeric `resultCode` token in the raw body, or null if there is none.
     *
     * Returned rather than thrown so each caller can raise the error its own surface requires - a
     * keyed indeterminate `PaylodApiError` on the money path, an `invalid_payload` signature error
     * on the webhook path.
     */
    public static function nonCanonicalResultCodeToken(string $raw): ?string
    {
        if (preg_match_all(self::RESULT_CODE_NUMBER_RE, $raw, $matches) === false) {
            return null;
        }

        foreach ($matches[1] as $token) {
            if (preg_match(self::CANONICAL_TOKEN_RE, $token) !== 1) {
                return $token;
            }
        }

        return null;
    }

    /**
     * A human explanation of why a raw token was refused, shared by both callers so the money path
     * and the webhook path describe the same defect the same way.
     */
    public static function explain(string $token): string
    {
        return "the raw JSON carries a non-canonical resultCode token `{$token}`. The schema defines "
            . 'exactly one zero (`0`) and unsigned integers for everything else; `-0`, `0e999`, `00` '
            . 'and `+0` all parse to the very same value a genuine `0` does, so they cannot be told '
            . 'apart once decoded and are refused before decoding';
    }
}

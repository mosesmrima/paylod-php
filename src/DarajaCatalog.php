<?php

declare(strict_types=1);

namespace Paylod;

/**
 * THE single source of truth for Daraja result-code meanings - classification AND decoding.
 *
 * The code table (src/resources/daraja-error-codes.json) is copied verbatim from the paylod
 * monorepo's canonical file (supabase/functions/_shared/daraja/daraja-catalog.ts). Never
 * hand-edit it here.
 *
 * The `retryable` contract: it means SAFE TO CHARGE AGAIN - i.e. we know no money moved and no
 * charge is still in flight. It does NOT mean "the user could try again". Consequences:
 *   - A pending / in-flight payment is NEVER retryable. Retrying it double-charges.
 *   - An indeterminate outcome (unknown code) is NEVER retryable.
 */
final class DarajaCatalog
{
    /** `ResultDesc` phrasings that mean "still processing" - a safety net for unrecognised codes. */
    private const PENDING_DESC_RE =
        '/\b(?:still\s+under\s+processing|is\s+being\s+processed|still\s+processing|being\s+processed)\b/i';

    /**
     * 500.001.1001 is an overloaded Daraja business-error bucket. Under the SAME code it also
     * returns hard, terminal configuration errors. A 500.* whose message matches one of these is
     * NOT treated as pending.
     */
    private const TERMINAL_500_MESSAGE_RE =
        '/\b(?:wrong\s+credentials|merchant\s+does\s+not\s+exist|invalid\s+access\s+token|unable\s+to\s+lock\s+subscriber)\b/i';

    /** @var list<array<string,mixed>>|null */
    private static ?array $entries = null;

    /** @var array<string,true>|null */
    private static ?array $pendingCodes = null;

    /**
     * Every catalog entry, in file order.
     *
     * @return list<array<string,mixed>>
     */
    public static function allEntries(): array
    {
        if (self::$entries === null) {
            $path = __DIR__ . '/resources/daraja-error-codes.json';
            $json = file_get_contents($path);
            if ($json === false) {
                throw new \RuntimeException("Could not read Daraja catalog at {$path}");
            }
            /** @var array{codes: list<array<string,mixed>>} $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::$entries = $data['codes'];
        }

        return self::$entries;
    }

    /**
     * Codes that mean "still processing, poll again", derived FROM the table so the classifier and
     * the decoder can never disagree. Compared as normalized strings so both "4999" and 4999 work.
     *
     * @return array<string,true>
     */
    public static function pendingResultCodes(): array
    {
        if (self::$pendingCodes === null) {
            $set = [];
            foreach (self::allEntries() as $e) {
                if (($e['category'] ?? null) === 'pending') {
                    $set[(string) $e['code']] = true;
                }
            }
            self::$pendingCodes = $set;
        }

        return self::$pendingCodes;
    }

    /** Normalize a ResultCode that Daraja may send as a string OR a number (defensive). */
    private static function normalizeCode(mixed $resultCode): string
    {
        if ($resultCode === null) {
            return '';
        }

        return trim((string) $resultCode);
    }

    /**
     * Classify a synchronous STK Query result. THE authoritative call - the decoder defers to
     * this, so a stale or wrong table entry can never resurrect the 4999 bug.
     *
     * @return "pending"|"success"|"failed"
     */
    public static function classifyStkResult(mixed $resultCode, ?string $resultDesc = null): string
    {
        $raw = self::normalizeCode($resultCode);
        $desc = trim($resultDesc ?? '');

        // A terminal 500.* config error must not be mistaken for "still processing".
        if (str_starts_with($raw, '500.') && preg_match(self::TERMINAL_500_MESSAGE_RE, $desc) === 1) {
            return 'failed';
        }

        if (isset(self::pendingResultCodes()[$raw])) {
            return 'pending';
        }

        if ($raw !== '' && is_numeric($raw)) {
            $n = $raw + 0;
            if ($n === 0 || $n === 0.0) {
                return 'success';
            }
            // A known-numeric, non-zero code is terminal - UNLESS the description says otherwise
            // (guards against a new "still processing" code we haven't catalogued yet).
            return preg_match(self::PENDING_DESC_RE, $desc) === 1 ? 'pending' : 'failed';
        }

        // Blank / non-numeric / unknown -> never force-fail on ambiguity.
        return 'pending';
    }

    /**
     * True when a thrown stkQuery error is really a "still processing" signal rather than a genuine
     * transport/auth failure.
     */
    public static function isPendingError(?string $message): bool
    {
        $s = $message ?? '';
        if (preg_match(self::TERMINAL_500_MESSAGE_RE, $s) === 1) {
            return false;
        }
        if (preg_match(self::PENDING_DESC_RE, $s) === 1) {
            return true;
        }
        foreach (self::pendingResultCodes() as $code => $_) {
            // Bare "4999" is too generic to substring-match; only dotted business codes are safe.
            if (str_contains($code, '.') && str_contains($s, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pick the right entry for a code. The classifier wins: if it says pending, only a pending
     * entry may be used; otherwise a pending entry may NOT be used. Then prefer the caller's family.
     *
     * @return array<string,mixed>|null
     */
    private static function pickEntry(string $code, string $family, string $outcome): ?array
    {
        $matches = array_values(array_filter(
            self::allEntries(),
            static fn (array $e): bool => (string) $e['code'] === $code
        ));
        if ($matches === []) {
            return null;
        }

        $consistent = array_values(array_filter(
            $matches,
            static fn (array $e): bool => $outcome === 'pending'
                ? ($e['category'] ?? null) === 'pending'
                : ($e['category'] ?? null) !== 'pending'
        ));
        if ($consistent === []) {
            return null;
        }

        foreach ($consistent as $e) {
            if (($e['family'] ?? null) === $family) {
                return $e;
            }
        }

        return $consistent[0];
    }

    /**
     * Decode a Daraja ResultCode into a normalized, human-readable error. Defers to the classifier
     * FIRST, so pending/in-flight codes (4999, 500.001.1001) can never decode as a failure and can
     * never be advertised as retryable.
     *
     * @return array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}
     */
    public static function decode(mixed $resultCode, ?string $rawDesc = null, string $family = 'stk_result'): array
    {
        $code = self::normalizeCode($resultCode);

        // An ABSENT code is not evidence of an in-flight payment - it is simply unknown.
        if ($code === '') {
            return self::failedFallback('unknown', $rawDesc);
        }

        $outcome = self::classifyStkResult($code, $rawDesc);
        $entry = self::pickEntry($code, $family, $outcome);

        if ($entry !== null) {
            return [
                'code' => $code,
                'title' => (string) $entry['title'],
                'cause' => (string) $entry['cause'],
                'fix' => (string) $entry['fix'],
                'category' => (string) $entry['category'],
                'retryable' => (bool) $entry['retryable'],
                'customerMessage' => (string) $entry['customerMessage'],
            ];
        }

        if ($outcome === 'pending') {
            return self::pendingFallback($code);
        }

        return self::failedFallback($code !== '' ? $code : 'unknown', $rawDesc);
    }

    /**
     * Catalog entries keyed by ResultCode, STK-first.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function errorCatalog(): array
    {
        $out = [];
        foreach (self::allEntries() as $e) {
            $code = (string) $e['code'];
            $rest = $e;
            unset($rest['code']);
            // STK is the payment path - it wins when a code appears in several families.
            if (!isset($out[$code]) || ($e['family'] ?? null) === 'stk_result') {
                $out[$code] = $rest;
            }
        }

        return $out;
    }

    /**
     * In-flight: NOT a failure, and NOT safe to charge again.
     *
     * @return array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}
     */
    private static function pendingFallback(string $code): array
    {
        return [
            'code' => $code,
            'title' => 'Payment still in progress',
            'cause' => 'M-Pesa is still processing this payment - the customer has most likely not entered their '
                . 'M-Pesa PIN yet. This is NOT a failure: the payment is still live and can still succeed.',
            'fix' => 'Keep polling GET /status/:id (or wait for the webhook). Do NOT retry the charge - a retry '
                . 'sends a second prompt and can double-charge the customer.',
            'category' => 'pending',
            'retryable' => false,
            'customerMessage' => 'Check your phone and enter your M-Pesa PIN to complete this payment.',
        ];
    }

    /**
     * Unknown code. The outcome is INDETERMINATE - we cannot prove no money moved - so it is NOT
     * safely retryable.
     *
     * @return array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}
     */
    private static function failedFallback(string $code, ?string $rawDesc = null): array
    {
        $desc = trim($rawDesc ?? '');

        return [
            'code' => $code,
            'title' => 'Payment failed',
            'cause' => $desc !== '' ? $desc : 'M-Pesa returned a non-zero ResultCode with no further detail.',
            'fix' => 'Check the raw ResultDesc, verify your credentials + shortcode/till pairing, and confirm '
                . 'the payment\'s final state with GET /status/:id before charging again - this code is not '
                . 'in the catalog, so we cannot prove no money moved.',
            'category' => 'mpesa_system',
            'retryable' => false,
            'customerMessage' => 'The payment didn\'t go through. Please try again.',
        ];
    }
}

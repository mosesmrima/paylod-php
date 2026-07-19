<?php

declare(strict_types=1);

namespace Paylod;

use Paylod\Support\Redact;

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
        '/\b(?:wrong\s+credentials|merchant\s+does\s+not\s+exist|invalid\s+access\s+token|unable\s+to\s+lock\s+subscriber|insufficient\s+funds?)\b/i';

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

    /**
     * The ResultCode's LEXEME - its original bytes, with NOTHING normalised away.
     *
     * -- Why this is not a normaliser -----------------------------------------------------------
     * This function used to be `trim((string) $resultCode)`, and that single line defeated every
     * check downstream of it. The exact-zero test below is correct; it was simply never shown the
     * value the server actually sent. `" 0"` was trimmed to `"0"` and classified SUCCESS. The float
     * `0.0` was stringified to `"0"` and classified SUCCESS. `" 1032"` was trimmed to `"1032"`,
     * matched the canonical non-zero regex, and was LAUNDERED into a terminal cancellation - which
     * the catalog marks retryable, i.e. the SDK told a merchant to charge the customer again on the
     * strength of a code it had itself rewritten.
     *
     * A validator that runs after a normaliser does not validate the input; it validates the
     * normaliser's output, and an impostor that the normaliser has already converted into canonical
     * form is indistinguishable from the real thing. So the ORDER is inverted here: the original
     * bytes survive, and every rule below is applied to them.
     *
     *   - An INT is its own canonical decimal form: `(string) $int` invents nothing.
     *   - A STRING is returned VERBATIM. No trim, no case folding, no re-encoding.
     *   - A FLOAT has NO lexeme. There is no lossless, unambiguous rendering of a float back to the
     *     token the sender wrote (`0.0`, `-0.0`, `1032.0` and `1.0e3` all collapse), and the schema
     *     defines no float ResultCode at all. Anything float-typed is therefore unreadable, and
     *     unreadable is `pending` - never success, never terminal.
     *   - Every other type (bool, null, array, object) is likewise unreadable. In particular
     *     `false`, which `(string)` renders as `""` and `true`, which renders as `"1"` - a code
     *     that would otherwise have been read as a real terminal failure.
     */
    private static function lexeme(mixed $resultCode): string
    {
        if (is_int($resultCode)) {
            return (string) $resultCode;
        }
        if (is_string($resultCode)) {
            return $resultCode;
        }

        return '';
    }

    /**
     * THE EXACT overloaded Daraja business code. Not a `500.` prefix - see classifyStkResult().
     */
    private const OVERLOADED_500_CODE = '500.001.1001';

    /**
     * The canonical form of an STK ResultCode, as the schema defines it. TWO shapes and no others:
     *
     *   - a plain unsigned decimal integer with no leading zero, no sign, no exponent, no point;
     *   - a DOTTED business code of at least THREE non-empty all-digit segments (`500.001.1001`).
     *
     * Anchored with `\z`, never `$`: in PCRE `$` also matches before a trailing newline, so a `$`
     * anchor accepts `"500.001.1001\n"` as canonical - a padded code laundered into a real catalog
     * entry by the regex itself.
     */
    private const CANONICAL_INTEGER_RE = '/^(?:0|[1-9][0-9]*)\z/';
    private const CANONICAL_DOTTED_RE = '/^[0-9]+(?:\.[0-9]+){2,}\z/';

    /**
     * Classify a synchronous STK Query result. THE authoritative call - the decoder defers to
     * this, so a stale or wrong table entry can never resurrect the 4999 bug.
     *
     * -- THE ORDER IS THE POINT: VALIDATE THE FORM, THEN CLASSIFY -------------------------------
     * Round 9 found this function normalising before validating for the second time, now in the
     * DESCRIPTION path. The terminal `500.*` branch ran FIRST, ahead of every check on the code's
     * shape, and it matched on a bare `str_starts_with($raw, '500.')`. So `"500.0"`, `"500.x"` and
     * `"500.001.1001\n"` - none of which is a code the schema defines - all became TERMINAL FAILURE
     * evidence the moment the description happened to contain a phrase like "insufficient funds",
     * which is server-controlled free text. A description is not a code, and it must never be able
     * to promote an unreadable code into a terminal verdict.
     *
     * The form is therefore settled BEFORE anything else looks at the value. A code that is not
     * canonically shaped is UNREADABLE, and unreadable is `pending` - never success, never terminal.
     *
     * -- AND AN UNCATALOGUED CODE IS NOT EVIDENCE ------------------------------------------------
     * The other half: every canonically shaped positive integer used to return `failed`, catalogued
     * or not. An unfamiliar code like `87654` therefore made a claimed failure TERMINAL and let a
     * `payment.failed` webhook through as a settled failure - while the decoder, looking at the same
     * code, described the outcome as indeterminate and non-retryable. The two disagreed, and the one
     * that governed the money path was the permissive one.
     *
     * An unknown code proves nothing in either direction, so it now says so: `unknown`, which
     * {@see \Paylod\Semantics} maps to its own evidence kind with its own five table rows, all
     * INDETERMINATE. Terminal failure is reserved for codes the catalog actually knows.
     *
     * -- AND A CATALOGUED CODE THAT DISCLAIMS PROOF IS NOT TERMINAL EITHER (requirement 3.7) ------
     * The round-10 Critical. Being IN the catalog was treated as sufficient for a terminal verdict,
     * but five entries (17, 26, 1001, 1025, 9999) say in their own `fix` text that a debit is not
     * disproven. They now answer `inconclusive`. See {@see NO_DEBIT_PROOF_STK_CODES}.
     *
     * @return "pending"|"success"|"failed"|"inconclusive"|"unknown"
     */
    public static function classifyStkResult(mixed $resultCode, ?string $resultDesc = null): string
    {
        $raw = self::lexeme($resultCode);
        $desc = trim($resultDesc ?? '');

        // STEP 1 - FORM FIRST. Nothing below may look at a value that is not a code.
        if (!self::isCanonicalCode($raw)) {
            return 'pending';
        }

        // STEP 2 - SUCCESS IS MATCHED EXACTLY, NEVER NUMERICALLY. See the lexeme() docblock: the
        // comparison is against the sender's own bytes so no earlier layer can manufacture a match.
        if ($raw === '0') {
            return 'success';
        }

        // STEP 3 - the overloaded business code. 500.001.1001 is a Daraja bucket that carries BOTH
        // "still processing" and hard terminal configuration errors, told apart only by the message.
        // Restricted to that EXACT code: the description may disambiguate a code we know is
        // overloaded, and nothing else.
        if ($raw === self::OVERLOADED_500_CODE && preg_match(self::TERMINAL_500_MESSAGE_RE, $desc) === 1) {
            return 'failed';
        }

        if (isset(self::pendingResultCodes()[$raw])) {
            return 'pending';
        }

        // STEP 4 - the "still processing" description safety net, for a pending code we have not
        // catalogued yet. This runs on a code whose FORM is already settled (step 1), so a
        // description can only ever move a real code towards the CONSERVATIVE answer here. It can
        // never promote an unreadable value, and - since step 3 is pinned to one exact code - it can
        // never push anything towards terminal.
        if (preg_match(self::PENDING_DESC_RE, $desc) === 1) {
            return 'pending';
        }

        // STEP 5 - TERMINAL REQUIRES A CATALOG ENTRY.
        //
        // This used to be `preg_match('/^[1-9][0-9]*\z/', $raw)` -> `failed`, i.e. every canonically
        // shaped positive integer was a terminal failure whether or not the SDK had ever heard of it.
        // `87654` made a claimed failure TERMINAL and let a `payment.failed` webhook through as
        // settled, while decode() described the very same code as indeterminate. Terminal failure is
        // the verdict that tells a merchant it is safe to charge again; it is not something to infer
        // from a number's shape.
        // REQUIREMENT 3.7 - and terminal requires the entry to PROVE no money moved, not merely to
        // exist. See {@see NO_DEBIT_PROOF_STK_CODES}. A catalogued code that disclaims proof (17, 26,
        // 1001, 1025, 9999) is `inconclusive`: not success, not terminal failure, not a live prompt.
        if (isset(self::terminalStkCodes()[$raw])) {
            return 'failed';
        }

        if (isset(self::inconclusiveStkCodes()[$raw])) {
            return 'inconclusive';
        }

        // STEP 6 - canonically shaped, but the catalog has never seen it. This proves NOTHING: not
        // success, not failure, not that the prompt is still live. `unknown` is its own answer, with
        // its own evidence kind and its own five rows in the semantic table, every one of them
        // INDETERMINATE. Saying "pending" here would be a guess in the safe direction; saying
        // "failed" was a guess in the dangerous one. We now say neither.
        return 'unknown';
    }

    /** Is this the canonical form of an STK ResultCode? Form only - says nothing about meaning. */
    private static function isCanonicalCode(string $raw): bool
    {
        return preg_match(self::CANONICAL_INTEGER_RE, $raw) === 1
            || preg_match(self::CANONICAL_DOTTED_RE, $raw) === 1;
    }

    /** @var array<string,true>|null */
    private static ?array $terminalCodes = null;

    /** @var array<string,true>|null */
    private static ?array $inconclusiveCodes = null;

    /**
     * REQUIREMENT 3.7 / 1.5 - THE CODES THAT ACTUALLY PROVE NO DEBIT OCCURRED.
     *
     * PHP IS THE REFERENCE IMPLEMENTATION FOR THIS SET. Node, Python and JVM port it from here.
     * Do NOT "simplify" this back into "every non-pending STK entry is terminal" - that was the
     * round-10 Critical, and it is a money bug. Read the next four paragraphs before touching it.
     *
     * -- What went wrong -------------------------------------------------------------------------
     * {@see terminalStkCodes()} used to be derived by SUBTRACTION: every stk_result entry that was
     * not `pending` and not `0` was terminal. That silently swept in codes 17, 26, 1001, 1025 and
     * 9999 - whose OWN entries in this very table say, in the `fix` prose, that a debit is NOT
     * disproven ("a busy-system rejection is not proof no charge was raised", "the in-flight
     * transaction may be your own earlier push, and charging again could double-charge").
     *
     * The consequences ran the whole length of the money path. classifyStkResult() answered
     * `failed`, so Semantics saw EVIDENCE_FAILURE, so a `failed` claim resolved to VERDICT_FAILED -
     * a TERMINAL failure. That let a signed `payment.failed` webhook through as a settled failure,
     * and it put a "the payment failed" reading in front of a merchant, on a payment where money
     * may well have moved. The SDK's own data contradicted the advice the SDK gave.
     *
     * -- Why the property lives here and not in the JSON ------------------------------------------
     * `src/resources/daraja-error-codes.json` is a VERBATIM copy of the monorepo's canonical table
     * (see the class docblock). Hand-editing it here would be silently undone by the next sync, so
     * the property is asserted in code instead - and {@see \Paylod\Semantics} is not allowed to
     * drift from it because `DarajaCatalogTest` cross-checks this set against the table's own prose:
     * any entry whose text disclaims proof MUST NOT appear below. The test is the coupling.
     *
     * -- Membership rule --------------------------------------------------------------------------
     * A code belongs here ONLY if the catalog affirmatively establishes that no money moved:
     *   1     insufficient balance - M-Pesa declined for want of funds, nothing was debited.
     *   1019  "Transaction expired ... Terminal: no money moved."
     *   1032  "pressed Cancel instead of entering their M-Pesa PIN. No money moved."
     *   1037  prompt unanswered - "Terminal: no money moved."
     *   2001  wrong M-Pesa PIN - the debit is never authorised without a correct PIN.
     *   2028  amount above the customer's M-Pesa limit - refused before any debit.
     *   2029  till sent as a paybill (or the reverse) - Daraja refuses the request outright.
     *
     * Everything else that is not `pending` and not `0` is INCONCLUSIVE, which is its own answer
     * with its own evidence kind and its own five INDETERMINATE rows in the semantic table. It is
     * NOT terminal failure, and it is NOT "pending" either - claiming a live prompt would be just as
     * much of a guess in the other direction.
     */
    private const NO_DEBIT_PROOF_STK_CODES = ['1', '1019', '1032', '1037', '2001', '2028', '2029'];

    /**
     * The STK codes the catalog knows to be TERMINAL, i.e. the catalog PROVES no money moved.
     *
     * Still derived FROM the table - a code that leaves the table leaves this set too, for the same
     * reason {@see pendingResultCodes()} is derived - but INTERSECTED with the no-debit-proof set
     * above, so membership requires the table AND an affirmative statement that nothing was debited.
     *
     * @return array<string,true>
     */
    public static function terminalStkCodes(): array
    {
        if (self::$terminalCodes === null) {
            $set = [];
            foreach (self::nonPendingStkCodes() as $code => $_) {
                // (string) IS LOad-BEARING. PHP silently converts an array key that looks like a
                // decimal integer into an int, so `"1032"` comes back out of the array as int 1032
                // and a STRICT in_array() against a list of strings matches NOTHING. Written without
                // the cast, this loop produced an EMPTY terminal set - every code inconclusive -
                // which the tests caught immediately. A looser comparison would "work" and would
                // also match `"1032 "`-shaped junk, so the cast is the right repair, not `false`.
                if (in_array((string) $code, self::NO_DEBIT_PROOF_STK_CODES, true)) {
                    $set[(string) $code] = true;
                }
            }
            self::$terminalCodes = $set;
        }

        return self::$terminalCodes;
    }

    /**
     * REQUIREMENT 3.7 - the non-pending STK codes that do NOT prove no debit occurred.
     *
     * The exact complement of {@see terminalStkCodes()} within the table, so no code can fall
     * between the two sets and no code can be in both. Exported so the tests can walk it, and so the
     * sibling SDKs can port the partition rather than re-deriving it and getting it wrong again.
     *
     * @return array<string,true>
     */
    public static function inconclusiveStkCodes(): array
    {
        if (self::$inconclusiveCodes === null) {
            $set = [];
            foreach (self::nonPendingStkCodes() as $code => $_) {
                // (string) is load-bearing here for the same reason - see terminalStkCodes().
                if (!in_array((string) $code, self::NO_DEBIT_PROOF_STK_CODES, true)) {
                    $set[(string) $code] = true;
                }
            }
            self::$inconclusiveCodes = $set;
        }

        return self::$inconclusiveCodes;
    }

    /**
     * Every stk_result code that is neither `pending` nor `0`. The universe the terminal /
     * inconclusive partition is taken over.
     *
     * @return array<string,true>
     */
    private static function nonPendingStkCodes(): array
    {
        $set = [];
        foreach (self::allEntries() as $e) {
            if (($e['family'] ?? null) !== 'stk_result') {
                continue;
            }
            if (($e['category'] ?? null) === 'pending') {
                continue;
            }
            if ((string) $e['code'] === '0') {
                continue;
            }
            $set[(string) $e['code']] = true;
        }

        return $set;
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
     * Decode a Daraja ResultCode into a normalized, human-readable error.
     *
     * Family-awareness: the STK "still processing -> pending" semantics (and the blank/unknown-numeric
     * -> pending fallback) apply ONLY to the STK result surface. A dotted `api_error` code (e.g.
     * 400.002.02, 500.001.1001) or an alphanumeric `b2c_c2b_result` code (e.g. C2B00011) is a
     * TERMINAL error; routing it through classifyStkResult used to misclassify it as `pending` and
     * decode it as "payment still in progress", which is wrong. So we select by family:
     *
     *   - STK family: defer to classifyStkResult, so 4999 / 500.001.1001 can never decode as a
     *     failure and can never be advertised as retryable.
     *   - Non-STK families: decode straight from the catalog by family - no pending semantics. This
     *     also disambiguates the OVERLOADED 500.001.1001, whose api_error entry is the terminal
     *     "merchant does not exist / insufficient funds" server error.
     *
     * If the caller asks for the (default) STK family but the code exists ONLY in non-STK families,
     * decode it by its real family rather than letting the STK unknown->pending rule mislabel it.
     *
     * @return array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}
     */
    public static function decode(mixed $resultCode, ?string $rawDesc = null, string $family = 'stk_result'): array
    {
        // The LEXEME, not a normalised form: a catalog lookup that trims (or stringifies a float)
        // would resolve `" 1032"` / `1032.0` to the real 1032 entry and hand back its `retryable`
        // flag, which is the same laundering the classifier refuses. An unreadable code has no
        // entry, and the fallbacks below are non-retryable.
        $code = self::lexeme($resultCode);

        // An ABSENT code is not evidence of an in-flight payment - it is simply unknown.
        if ($code === '') {
            return self::failedFallback('unknown', $rawDesc);
        }

        $matches = array_values(array_filter(
            self::allEntries(),
            static fn (array $e): bool => (string) $e['code'] === $code
        ));
        $hasStk = false;
        foreach ($matches as $e) {
            if (($e['family'] ?? null) === 'stk_result') {
                $hasStk = true;
                break;
            }
        }

        // If STK was requested but the code is not an STK code, decode it by the family it DOES have.
        $effectiveFamily = ($family === 'stk_result' && !$hasStk && $matches !== [])
            ? (string) $matches[0]['family']
            : $family;

        if ($effectiveFamily === 'stk_result') {
            $outcome = self::classifyStkResult($code, $rawDesc);
            $entry = self::pickEntry($code, $effectiveFamily, $outcome);
            if ($entry !== null) {
                return self::decodedFrom($code, $entry);
            }
            if ($outcome === 'pending') {
                return self::pendingFallback($code);
            }

            // `unknown` and `failed` both land on the same fallback, which already says what an
            // uncatalogued code means: indeterminate, and NOT safely retryable. The classifier and
            // this decoder now agree about that - the round-9 High was precisely that they did not,
            // with the classifier calling an unfamiliar code TERMINAL while this text called it
            // unprovable.
            return self::failedFallback($code !== '' ? $code : 'unknown', $rawDesc);
        }

        // Terminal (api_error / b2c_c2b_result): no STK pending semantics, EVER. Pick the entry for
        // this family, else any OTHER non-STK entry for the same code - and nothing else.
        //
        // There is deliberately NO "any match" fallback here. A code can live in several families
        // (4999 is an STK *pending* entry but is also seen on the api_error surface), and falling
        // through to the STK entry would decode an explicitly-non-STK error as "payment still in
        // progress" - the exact 4999 bug this family-awareness exists to kill. When the caller names
        // a non-STK family and the catalog has no non-STK entry for the code, the honest answer is a
        // terminal, non-retryable, indeterminate failure.
        $entry = null;
        foreach ($matches as $e) {
            if (($e['family'] ?? null) === $effectiveFamily && ($e['family'] ?? null) !== 'stk_result') {
                $entry = $e;
                break;
            }
        }
        if ($entry === null) {
            foreach ($matches as $e) {
                if (($e['family'] ?? null) !== 'stk_result') {
                    $entry = $e;
                    break;
                }
            }
        }
        if ($entry !== null) {
            return self::decodedFrom($code, $entry);
        }

        return self::failedFallback($code !== '' ? $code : 'unknown', $rawDesc);
    }

    /**
     * Strip the internal-only fields off a catalog entry to produce a decoded error.
     *
     * @param array<string,mixed> $entry
     * @return array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}
     */
    private static function decodedFrom(string $code, array $entry): array
    {
        $retryable = (bool) $entry['retryable'];

        return [
            'code' => $code,
            'title' => (string) $entry['title'],
            'cause' => (string) $entry['cause'],
            'fix' => (string) $entry['fix'],
            'category' => (string) $entry['category'],
            'retryable' => $retryable,
            'customerMessage' => self::safeCustomerMessage(
                $code,
                (string) $entry['customerMessage'],
                $retryable,
            ),
        ];
    }

    /**
     * REQUIREMENT 3.7 - A CUSTOMER MESSAGE MUST NOT INVITE ANOTHER ATTEMPT WHEN `retryable` IS FALSE.
     *
     * PHP IS THE REFERENCE IMPLEMENTATION FOR THIS CHECK. Do not delete it and do not narrow it to
     * the five STK codes that motivated it - the point is that it is UNIVERSAL, applied at the one
     * place every decoded entry is built, so a table re-sync that adds a new non-retryable entry
     * with "please try again" in it cannot re-open the hole.
     *
     * -- Why the table cannot simply be corrected -------------------------------------------------
     * `retryable` on this SDK means SAFE TO CHARGE AGAIN: we know no money moved and no charge is
     * still in flight (see the class docblock). So `retryable === false` means precisely that we
     * CANNOT establish that, and a message reading "Please try again" is then the SDK telling a
     * customer to do the one thing its own data says it cannot vouch for. Seventeen entries were in
     * that state, four of them the codes named in requirement 3.7. The table is a verbatim copy of
     * the monorepo's canonical file and must not be hand-edited here, so the rule is enforced in
     * code and the overrides live in code beside it.
     *
     * The override map is keyed by code. A non-retryable entry with no override, and no retry
     * invitation in its own text, keeps its own message - most already do. A non-retryable entry
     * that DOES invite a retry and has no override falls back to the generic no-retry message
     * rather than being emitted as written: fail closed, never pass through.
     */
    private const RETRY_INVITATION_RE =
        '/\b(?:try(?:ing)?\s+(?:again|it\s+again)|retry|re-try|again\s+(?:in|shortly|later|whenever))\b/i';

    /**
     * Safe replacements for the non-retryable entries whose catalog copy invites another attempt.
     * Each preserves the actionable content and removes the imperative to pay again.
     */
    private const SAFE_CUSTOMER_MESSAGES = [
        // No proof no debit occurred - these must steer to CONFIRMATION, never to a second attempt.
        '17' => 'M-Pesa had a problem and we could not confirm this payment. Please check your M-Pesa '
            . 'messages before paying again - we will confirm the outcome shortly.',
        '26' => 'M-Pesa is busy and we could not confirm this payment. Please check your M-Pesa '
            . 'messages before paying again - we will confirm the outcome shortly.',
        '1025' => 'We could not reach M-Pesa and cannot confirm this payment. Please check your M-Pesa '
            . 'messages before paying again - we will confirm the outcome shortly.',
        '9999' => 'We could not reach M-Pesa and cannot confirm this payment. Please check your M-Pesa '
            . 'messages before paying again - we will confirm the outcome shortly.',
        '1001' => 'You have another M-Pesa request open. Please finish it, then check your M-Pesa '
            . 'messages - this payment may already have gone through.',
        '500.001.1001' => 'M-Pesa returned an error and we could not confirm this payment. Please check '
            . 'your M-Pesa messages before paying again - we will confirm the outcome shortly.',
        // Setup and configuration errors. The request was refused, so nothing was charged, but the
        // customer cannot fix these and must not be sent round the loop again.
        '2029' => 'We hit a setup error on our side, so this payment was not started. Our team is '
            . 'looking into it - please contact support if you need this urgently.',
        '400.002.02' => 'We hit a setup error on our side, so this payment was not started. Our team is '
            . 'looking into it - please contact support if you need this urgently.',
        '400.002.05' => 'We hit a setup error on our side, so this payment was not started. Our team is '
            . 'looking into it - please contact support if you need this urgently.',
        '404.001.04' => 'We hit an authentication error on our side, so this payment was not started. '
            . 'Our team is looking into it - please contact support if you need this urgently.',
        'Bad Request - Invalid Initiator Information' => 'We hit a setup error on our side, so this '
            . 'payment was not started. Our team is looking into it - please contact support if you '
            . 'need this urgently.',
        'C2B00015' => 'We hit a setup error on our side, so this payment was not started. Our team is '
            . 'looking into it - please contact support if you need this urgently.',
        // Customer-correctable input. State what is wrong; do not command a fresh attempt.
        '400.008.02' => 'That phone number is not valid, so this payment was not started. Please check '
            . 'the number.',
        'C2B00012' => 'That account or reference number was not found, so this payment was not started. '
            . 'Please check it.',
        'C2B00013' => 'That payment amount was not accepted, so this payment was not started. Please '
            . 'check the amount.',
        'C2B00016' => 'We could not accept this payment, so nothing was charged. Please check your '
            . 'payment details.',
        // 2001 on the B2C/C2B surface is an INITIATOR credential failure, not a customer PIN error.
        // (The STK-family 2001 is a wrong customer PIN, is retryable, and never reaches this map.)
        '2001' => 'We hit a setup error on our side, so this payment was not started. Our team is '
            . 'looking into it - please contact support if you need this urgently.',
    ];

    /** The last-resort non-retryable message. Used when an entry invites a retry and has no override. */
    private const GENERIC_NO_RETRY_MESSAGE =
        'We could not confirm this payment. Please check your M-Pesa messages before paying again - '
        . 'we will confirm the outcome shortly.';

    private static function safeCustomerMessage(string $code, string $message, bool $retryable): string
    {
        // A retryable code is one we have PROVEN safe to charge again, so its message may say so.
        if ($retryable) {
            return $message;
        }

        if (isset(self::SAFE_CUSTOMER_MESSAGES[$code])) {
            return self::SAFE_CUSTOMER_MESSAGES[$code];
        }

        // FAIL CLOSED. An uncovered non-retryable entry that invites a retry is replaced wholesale
        // rather than emitted, so adding a row to the table can never add a retry invitation.
        if (preg_match(self::RETRY_INVITATION_RE, $message) === 1) {
            return self::GENERIC_NO_RETRY_MESSAGE;
        }

        return $message;
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
            'code' => self::safeCode($code),
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
     * THE `code` FIELD IS NEVER A VERBATIM COPY OF AN UNRECOGNISED SERVER STRING.
     *
     * Found by the round-9 ADVERSARIAL SWEEP, and by nothing else - no per-field test looked here,
     * because nobody thought to. Both fallbacks copied the raw ResultCode lexeme straight into
     * `code`, which is a PUBLIC field of the decoded array and lands in json_encode(), logs, dumps
     * and support tickets. A gateway echoing the Authorization header into `ResultCode` therefore
     * put a live key into every one of those sinks - through the OFFLINE decoder, on a path that
     * never touches a client and so never met a client redactor.
     *
     * A catalogued code is safe by construction (it matched a table entry). A code we did NOT
     * recognise is server-controlled text of unknown provenance, so it is not echoed at all unless
     * it is canonically shaped and short. Anything else becomes the literal `unknown` - which is
     * exactly as informative, because a code we cannot read tells the reader nothing anyway.
     */
    private static function safeCode(string $code): string
    {
        if ($code === '' || strlen($code) > 32 || !self::isCanonicalCode($code)) {
            return 'unknown';
        }

        return $code;
    }

    /**
     * Unknown code. The outcome is INDETERMINATE - we cannot prove no money moved - so it is NOT
     * safely retryable.
     *
     * @return array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}
     */
    private static function failedFallback(string $code, #[\SensitiveParameter] ?string $rawDesc = null): array
    {
        // THE RAW DESCRIPTION IS SHAPE-SCRUBBED BEFORE IT IS COPIED INTO `cause`.
        //
        // `cause` is a PUBLIC field of the decoded array. It reaches json_encode(), application
        // logs, exception dumps, support tickets and the value merchants paste into chat. This line
        // used to be a bare copy of server-controlled free text - so a gateway echoing the
        // Authorization header into `ResultDesc` put a live `mp_live_` key into every one of those
        // sinks, through the OFFLINE decoder, on a path that never touches a client and therefore
        // never met the client's redactor.
        //
        // This is a static method with no process secrets, so it can apply the SHAPE rules only -
        // which is precisely the layer that catches a reflected bearer token. The client's EXACT
        // credentials are applied on top by {@see \Paylod\Paylod::decodeError()}. Two layers,
        // because either one alone leaks; same argument as {@see \Paylod\Support\Redact}.
        $desc = trim(Redact::text($rawDesc ?? '', []));

        return [
            'code' => self::safeCode($code),
            'title' => 'Payment failed',
            'cause' => $desc !== '' ? $desc : 'M-Pesa returned a non-zero ResultCode with no further detail.',
            'fix' => 'Check the raw ResultDesc, verify your credentials + shortcode/till pairing, and confirm '
                . 'the payment\'s final state with GET /status/:id before charging again - this code is not '
                . 'in the catalog, so we cannot prove no money moved.',
            'category' => 'mpesa_system',
            'retryable' => false,
            // REQUIREMENT 3.7. This said "The payment didn't go through. Please try again." beside
            // `retryable => false` and a `fix` that states, in the same array, that we CANNOT prove
            // no money moved. An uncatalogued code is the case where we know least, so it is the
            // last place that may invite a second charge.
            'customerMessage' => self::GENERIC_NO_RETRY_MESSAGE,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Paylod;

/**
 * THE semantic model for a payment record.
 *
 * -- Why this file exists ----------------------------------------------------------------------
 * Before it, "is this payment paid?" was answered by a scatter of per-field checks spread across
 * {@see PaymentOutcome::fromPayment()} and {@see \Paylod\Support\Validate}. Each check was locally
 * reasonable and the set of them was collectively wrong, because nothing stated the RULES the
 * fields have to satisfy TOGETHER. The holes that survived four rounds of per-finding patching were
 * all of one kind - a body whose fields CONTRADICT each other, resolved in favour of whichever
 * field the code happened to read first:
 *
 *   - ['status' => 'pending', 'resultCode' => 0] was reported PAID, with a null receipt: a payment
 *     the server itself calls unfinished, treated as money in the bank.
 *   - ['status' => 'failed', 'mpesaReceipt' => 'SFF6XYZ123', 'resultCode' => 1032] was reported
 *     `cancelled` AND `retryable: true` - i.e. the SDK told a merchant it was safe to charge again
 *     for a payment that carries an M-Pesa confirmation receipt. That is a double-charge generator,
 *     and it is the single worst defect a payments SDK can have.
 *
 * Shape validation cannot catch either one: both bodies are perfectly well-typed. What was missing
 * is a model of what the fields MEAN together. That is what this file is.
 *
 * -- The model ---------------------------------------------------------------------------------
 * A payment record makes ONE CLAIM (`status`) and carries EVIDENCE (`mpesaReceipt`, `resultCode`).
 * These are separate things and are never allowed to substitute for one another. Evaluation is
 * three stages:
 *
 *   1. EVIDENCE - what the record PROVES, independent of what it claims.
 *   2. VERDICT  - the claim and the evidence resolved together, via one TOTAL table.
 *   3. LAWS     - invariants the table is required to satisfy (asserted in the tests).
 *
 * -- The four laws ------------------------------------------------------------------------------
 * These are the contract. The sibling Node / Python / JVM SDKs mirror THESE, not the code.
 *
 *   L1 BINDING      A record whose `id` is not the id that was requested is never evaluated at all.
 *                   It is a hard error, enforced at the transport boundary in
 *                   {@see \Paylod\Support\Validate::paymentBody()} - a wrong-payment body must not
 *                   even reach this file.
 *   L2 EVIDENCE     `paid` requires SUCCESS evidence. A bare `status: "success"` with no receipt
 *                   and no result code proves nothing and is never paid. The converse is NOT
 *                   required: success WITHOUT a receipt is legitimate, because receipts attach
 *                   asynchronously - result code 0 is equally good evidence. We require evidence of
 *                   ONE kind, never a receipt outright.
 *   L3 CONSISTENCY  A claim that contradicts its evidence is INDETERMINATE - never a failure, and
 *                   in particular never a RETRYABLE failure. We cannot prove money did not move, so
 *                   we must not invite a second charge. EVERY contradiction lands here, including a
 *                   `failed`/`cancelled` claim carrying an in-flight code: earlier rounds resolved
 *                   that one to `in_flight`, which is a guess about which of two contradicting
 *                   fields to believe. INDETERMINATE renders as `pending` just the same, so the
 *                   merchant-visible behaviour is identical - we simply stop claiming to know.
 *   L4 RECEIPT      A receipt is proof money moved. Its presence forces the verdict to `paid` or
 *                   `indeterminate` - never `failed`, never `in_flight`.
 *
 * The asymmetry in L3 is deliberate and is the whole safety argument: an indeterminate payment is
 * rendered as `pending` so wait() keeps polling and lets the webhook settle it, rather than
 * reporting a false success (merchant ships goods that were never paid for) or a false retryable
 * failure (merchant charges the customer twice).
 */
final class Semantics
{
    // -- Stage 1 vocabulary: what the record PROVES -------------------------------------------

    /** Nothing to go on: no receipt, no result code. Proves neither direction. */
    public const EVIDENCE_NONE = 'none';
    /** A receipt, or result code 0. Money moved. */
    public const EVIDENCE_SUCCESS = 'success';
    /** A terminal failure code (1032 cancelled, 2001 wrong PIN, 1 low balance, ...). */
    public const EVIDENCE_FAILURE = 'failure';
    /** A pending code (4999, 500.001.1001), or a code we cannot place. Still on the handset. */
    public const EVIDENCE_IN_FLIGHT = 'in_flight';
    /** The evidence disagrees with ITSELF - e.g. a receipt alongside a cancellation code. */
    public const EVIDENCE_CONFLICT = 'conflict';

    // -- Stage 2 vocabulary: the resolved state -----------------------------------------------

    /** Money moved, and we can prove it. Fulfil the order. */
    public const VERDICT_PAID = 'paid';
    /** Terminal, no money moved. Safe to charge again IF the catalog says the code is retryable. */
    public const VERDICT_FAILED = 'failed';
    /** Still on the handset. Keep polling. NEVER retry - the prompt is live. */
    public const VERDICT_IN_FLIGHT = 'in_flight';
    /** We cannot prove what happened. Never paid, never retryable. Let the webhook settle it. */
    public const VERDICT_INDETERMINATE = 'indeterminate';

    // -- The CLAIM alphabet: the `status` strings, normalised to a CLOSED set ------------------

    public const CLAIM_SUCCESS = 'success';
    public const CLAIM_PENDING = 'pending';
    public const CLAIM_FAILED = 'failed';
    public const CLAIM_CANCELLED = 'cancelled';
    /** Anything else - a missing status, a non-string, a word we do not know. */
    public const CLAIM_UNKNOWN = 'unknown';

    /** The claim alphabet, in table order. Exported so tests can walk the FULL cross-product. */
    public const CLAIMS = [
        self::CLAIM_SUCCESS,
        self::CLAIM_PENDING,
        self::CLAIM_FAILED,
        self::CLAIM_CANCELLED,
        self::CLAIM_UNKNOWN,
    ];

    /** The evidence alphabet, in table order. Exported for the same reason. */
    public const EVIDENCE_KINDS = [
        self::EVIDENCE_SUCCESS,
        self::EVIDENCE_NONE,
        self::EVIDENCE_FAILURE,
        self::EVIDENCE_IN_FLIGHT,
        self::EVIDENCE_CONFLICT,
    ];

    /**
     * Normalise the raw `status` field into the CLOSED claim alphabet.
     *
     * This is the ONLY place a permissive mapping is allowed, and it is total by construction: an
     * unrecognised or non-string status becomes {@see CLAIM_UNKNOWN}, an ordinary member of the
     * alphabet with its own five rows in the table. The verdict table below therefore has no
     * default arm at all - a missing cell is an `\UnhandledMatchError`, not a silent guess.
     *
     * @param array<string,mixed> $payment
     * @return self::CLAIM_*
     */
    public static function claimFor(array $payment): string
    {
        $raw = $payment['status'] ?? null;
        if (!is_string($raw)) {
            return self::CLAIM_UNKNOWN;
        }

        return match ($raw) {
            self::CLAIM_SUCCESS => self::CLAIM_SUCCESS,
            self::CLAIM_PENDING => self::CLAIM_PENDING,
            self::CLAIM_FAILED => self::CLAIM_FAILED,
            self::CLAIM_CANCELLED => self::CLAIM_CANCELLED,
            default => self::CLAIM_UNKNOWN,
        };
    }

    /** A receipt counts only if it is a non-blank string. `""` and `"   "` prove nothing. */
    public static function hasReceipt(array $payment): bool
    {
        $receipt = $payment['mpesaReceipt'] ?? null;

        return is_string($receipt) && trim($receipt) !== '';
    }

    /** A result code is "present" if it is neither null nor absent. `0` is present and meaningful. */
    public static function hasResultCode(array $payment): bool
    {
        return ($payment['resultCode'] ?? null) !== null;
    }

    /**
     * Stage 1 - what the record proves, ignoring what it claims.
     *
     * The receipt and the result code are two independent witnesses. When they agree, or when only
     * one of them speaks, the answer is theirs. When they DISAGREE the answer is `conflict`, which
     * L3 sends straight to `indeterminate`: a receipt beside a cancellation code is not a
     * cancellation with a stray field, it is a record we have no business acting on.
     *
     * @param array<string,mixed> $payment
     * @return self::EVIDENCE_*
     */
    public static function evidenceFor(array $payment): string
    {
        $receiptSaysSuccess = self::hasReceipt($payment);

        // classifyStkResult() is the canonical classifier the payment engine itself uses, so the
        // SDK cannot drift from the backend about what 4999 means. It maps blank/unknown codes to
        // "pending" on purpose - we refuse to force-fail on ambiguity.
        if (self::hasResultCode($payment)) {
            $desc = $payment['resultDesc'] ?? null;
            $classified = DarajaCatalog::classifyStkResult(
                $payment['resultCode'],
                is_string($desc) ? $desc : null,
            );
            $codeEvidence = match ($classified) {
                'success' => self::EVIDENCE_SUCCESS,
                'failed' => self::EVIDENCE_FAILURE,
                'pending' => self::EVIDENCE_IN_FLIGHT,
            };
        } else {
            $codeEvidence = self::EVIDENCE_NONE;
        }

        if (!$receiptSaysSuccess) {
            return $codeEvidence;
        }

        // A receipt is present. It agrees with success evidence and with silence; it CONTRADICTS a
        // terminal failure code and an in-flight code alike - a receipt means M-Pesa has settled,
        // which is incompatible with "still on the handset".
        if ($codeEvidence === self::EVIDENCE_SUCCESS || $codeEvidence === self::EVIDENCE_NONE) {
            return self::EVIDENCE_SUCCESS;
        }

        return self::EVIDENCE_CONFLICT;
    }

    /**
     * Stage 2 - the claim and the evidence resolved together.
     *
     * This table is TOTAL AND EXHAUSTIVE, and it says so structurally: the claim is first
     * normalised into a five-member closed alphabet by {@see claimFor()}, the evidence is already a
     * five-member closed alphabet, and the resolution below is a single `match` over the 25
     * `claim|evidence` pairs WITH NO DEFAULT ARM. A cell that goes missing is an
     * `\UnhandledMatchError` at the point of the omission - it cannot be absorbed by a fallthrough,
     * which is exactly where every previous round of this bug lived. `SemanticsTest` walks the same
     * cross-product, so a missing cell fails a test rather than reaching a caller.
     *
     * Reading the table, the rules are:
     *   - Success evidence beside a non-success claim is never paid and never failed - the two
     *     signals contradict, so it is indeterminate (L3 + L4).
     *   - A success claim needs evidence to be believed (L2).
     *   - A failure claim is believed on failure evidence or on silence: proving a payment did NOT
     *     happen is not something we require evidence for, because the safe action (do not ship, do
     *     not capture) is the same either way.
     *   - EVERY OTHER CONTRADICTION IS INDETERMINATE. In particular `failed`/`cancelled` beside
     *     in-flight evidence is INDETERMINATE, not in-flight: a record that claims to be terminal
     *     while its code says the prompt is live is a record we cannot read, and asserting the
     *     more specific `in_flight` from it is a guess. The practical behaviour a merchant sees is
     *     unchanged - {@see PaymentOutcome} renders indeterminate as `pending`, so wait() keeps
     *     polling and the webhook settles it - but the SDK no longer CLAIMS to know which of the
     *     two contradicting fields was right.
     *
     * `cancelled` is a claim the API may report and Node's status union does not carry; it is
     * enumerated here EXPLICITLY, on its own rows.
     *
     * @param array<string,mixed> $payment
     */
    public static function judge(array $payment): Judgement
    {
        $evidence = self::evidenceFor($payment);
        $claim = self::claimFor($payment);
        $rawClaim = $payment['status'] ?? null;
        $claimed = is_string($rawClaim) ? $rawClaim : '';

        [$verdict, $reason] = self::cell($claim, $evidence);

        return new Judgement($verdict, $evidence, $claimed, $reason);
    }

    /**
     * THE TABLE. 5 claims x 5 evidence kinds = 25 rows, no default arm.
     *
     * @param self::CLAIM_* $claim
     * @param self::EVIDENCE_* $evidence
     * @return array{0:string,1:string}
     */
    private static function cell(string $claim, string $evidence): array
    {
        $conflict = [
            self::VERDICT_INDETERMINATE,
            'the record carries an M-Pesa receipt alongside a result code that is not a success '
            . '- the receipt proves money moved and the code denies it, so neither can be trusted',
        ];
        $contradiction = static fn (string $detail): array => [self::VERDICT_INDETERMINATE, $detail];

        return match ($claim . '|' . $evidence) {
            // -- claim = success -------------------------------------------------------------
            'success|success' => [
                self::VERDICT_PAID,
                'status is success and it is backed by a receipt or result code 0',
            ],
            // L2. This is the "a stubbed endpoint / truncated row / cached proxy envelope can
            // write six characters of JSON" case. A claim with nothing behind it is not money.
            'success|none' => $contradiction(
                'status claims success but the record carries neither a receipt nor a result '
                . 'code, so there is no evidence the payment actually settled'
            ),
            'success|failure' => $contradiction(
                'status claims success but the result code is a terminal failure'
            ),
            'success|in_flight' => $contradiction(
                'status claims success but the result code says the payment is still in flight'
            ),
            'success|conflict' => $conflict,

            // -- claim = pending -------------------------------------------------------------
            // THE named hole. ['status' => 'pending', 'resultCode' => 0] used to come back paid,
            // with a null receipt. A record that simultaneously says "not finished" and
            // "succeeded" is not a success we may act on - it is a record mid-write, or one we
            // are misreading.
            'pending|success' => $contradiction(
                'status says pending while the evidence says the payment succeeded - a pending '
                . 'record must never be reported as paid'
            ),
            'pending|none' => [self::VERDICT_IN_FLIGHT, 'the payment is still on the handset'],
            'pending|failure' => $contradiction(
                'status says pending while the result code is a terminal failure'
            ),
            'pending|in_flight' => [self::VERDICT_IN_FLIGHT, 'the payment is still on the handset'],
            'pending|conflict' => $conflict,

            // -- claim = failed --------------------------------------------------------------
            // L4. Includes the receipt-on-a-failed-row case that used to be rendered as
            // `cancelled, retryable: true` - an explicit invitation to charge twice.
            'failed|success' => $contradiction(
                'status claims failed but the evidence proves the payment succeeded - refusing '
                . 'to report a payment that carries proof of settlement as a failure'
            ),
            'failed|none' => [self::VERDICT_FAILED, 'the payment failed terminally'],
            'failed|failure' => [self::VERDICT_FAILED, 'the payment failed terminally'],
            // L3. The claim is terminal and the code says the prompt is live. We do not get to
            // pick a winner: never failed (that would invite a retry against a live prompt) and
            // never in_flight either (that would assert a state the record does not establish).
            'failed|in_flight' => $contradiction(
                'status claims failed while the result code says the prompt is still live - the '
                . 'record contradicts itself, so neither the failure nor the in-flight reading '
                . 'can be trusted'
            ),
            'failed|conflict' => $conflict,

            // -- claim = cancelled (a terminal failure claim by another name) ------------------
            'cancelled|success' => $contradiction(
                'status claims cancelled but the evidence proves the payment succeeded - refusing '
                . 'to report a payment that carries proof of settlement as a cancellation'
            ),
            'cancelled|none' => [self::VERDICT_FAILED, 'the payment failed terminally'],
            'cancelled|failure' => [self::VERDICT_FAILED, 'the payment failed terminally'],
            'cancelled|in_flight' => $contradiction(
                'status claims cancelled while the result code says the prompt is still live - the '
                . 'record contradicts itself, so neither reading can be trusted'
            ),
            'cancelled|conflict' => $conflict,

            // -- claim = unknown --------------------------------------------------------------
            // A status outside the known set, or no status at all. The shape validators reject
            // these before they reach here; these five rows are the structural backstop, and each
            // one says "we do not know". They are ROWS, not a default: adding a sixth claim to the
            // alphabet without adding its five rows is a hard error, not a silent guess.
            'unknown|success' => $contradiction(self::unrecognised($evidence)),
            'unknown|none' => $contradiction(self::unrecognised($evidence)),
            'unknown|failure' => $contradiction(self::unrecognised($evidence)),
            'unknown|in_flight' => $contradiction(self::unrecognised($evidence)),
            'unknown|conflict' => $contradiction(self::unrecognised($evidence)),
        };
    }

    private static function unrecognised(string $evidence): string
    {
        return "unrecognised payment status (evidence {$evidence}), so the record cannot be judged";
    }
}

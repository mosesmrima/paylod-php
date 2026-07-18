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
 *                   we must not invite a second charge.
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
     * This table is TOTAL: every (claim, evidence) pair has exactly one row, and the pairs are
     * ENUMERATED rather than derived, so there is no default branch to fall through to. The
     * defaults are exactly where the old logic went wrong, so there are none. An unrecognised claim
     * or evidence kind resolves to `indeterminate` - the safe answer is always "we do not know".
     *
     * Reading the table, the rules are:
     *   - Success evidence beside a non-success claim is never paid and never failed - the two
     *     signals contradict, so it is indeterminate (L3 + L4).
     *   - A success claim needs evidence to be believed (L2).
     *   - A failure claim is believed on failure evidence or on silence: proving a payment did NOT
     *     happen is not something we require evidence for, because the safe action (do not ship, do
     *     not capture) is the same either way.
     *   - In-flight evidence outranks a terminal `failed` claim: a `failed` row carrying 4999 means
     *     the prompt is STILL LIVE and the customer is mid-PIN. Reporting that as a failure is the
     *     revenue-losing bug this codebase already shipped twice.
     *
     * `cancelled` is a claim the API may report and Node's status union does not carry; it is
     * enumerated here EXPLICITLY, on its own rows, rather than being folded into a default.
     *
     * @param array<string,mixed> $payment
     */
    public static function judge(array $payment): Judgement
    {
        $evidence = self::evidenceFor($payment);
        $rawClaim = $payment['status'] ?? null;
        $claimed = is_string($rawClaim) ? $rawClaim : '';

        $of = static fn (string $verdict, string $reason): Judgement
            => new Judgement($verdict, $evidence, $claimed, $reason);

        // L3/L4: the two witnesses disagree with each other. Nothing else matters.
        if ($evidence === self::EVIDENCE_CONFLICT) {
            return $of(
                self::VERDICT_INDETERMINATE,
                'the record carries an M-Pesa receipt alongside a result code that is not a success '
                . '- the receipt proves money moved and the code denies it, so neither can be trusted'
            );
        }

        return match ($claimed) {
            'success' => match ($evidence) {
                self::EVIDENCE_SUCCESS => $of(
                    self::VERDICT_PAID,
                    'status is success and it is backed by a receipt or result code 0'
                ),
                // L2. This is the "a stubbed endpoint / truncated row / cached proxy envelope can
                // write six characters of JSON" case. A claim with nothing behind it is not money.
                self::EVIDENCE_NONE => $of(
                    self::VERDICT_INDETERMINATE,
                    'status claims success but the record carries neither a receipt nor a result '
                    . 'code, so there is no evidence the payment actually settled'
                ),
                self::EVIDENCE_FAILURE => $of(
                    self::VERDICT_INDETERMINATE,
                    'status claims success but the result code is a terminal failure'
                ),
                self::EVIDENCE_IN_FLIGHT => $of(
                    self::VERDICT_INDETERMINATE,
                    'status claims success but the result code says the payment is still in flight'
                ),
                default => $of(self::VERDICT_INDETERMINATE, self::unrecognised($claimed, $evidence)),
            },

            'pending' => match ($evidence) {
                // THE named hole. ['status' => 'pending', 'resultCode' => 0] used to come back paid,
                // with a null receipt. A record that simultaneously says "not finished" and
                // "succeeded" is not a success we may act on - it is a record mid-write, or one we
                // are misreading.
                self::EVIDENCE_SUCCESS => $of(
                    self::VERDICT_INDETERMINATE,
                    'status says pending while the evidence says the payment succeeded - a pending '
                    . 'record must never be reported as paid'
                ),
                self::EVIDENCE_FAILURE => $of(
                    self::VERDICT_INDETERMINATE,
                    'status says pending while the result code is a terminal failure'
                ),
                self::EVIDENCE_NONE, self::EVIDENCE_IN_FLIGHT => $of(
                    self::VERDICT_IN_FLIGHT,
                    'the payment is still on the handset'
                ),
                default => $of(self::VERDICT_INDETERMINATE, self::unrecognised($claimed, $evidence)),
            },

            // `cancelled` is a terminal failure claim by another name: the customer chose it. It is
            // enumerated on its own rows so adding it was a deliberate act, not a fallthrough.
            'failed', 'cancelled' => match ($evidence) {
                // L4. Includes the receipt-on-a-failed-row case that used to be rendered as
                // `cancelled, retryable: true` - an explicit invitation to charge twice.
                self::EVIDENCE_SUCCESS => $of(
                    self::VERDICT_INDETERMINATE,
                    'status claims failed but the evidence proves the payment succeeded - refusing '
                    . 'to report a payment that carries proof of settlement as a failure'
                ),
                self::EVIDENCE_IN_FLIGHT => $of(
                    self::VERDICT_IN_FLIGHT,
                    'status says failed but the result code means the prompt is still live and the '
                    . 'customer has not entered their PIN yet'
                ),
                self::EVIDENCE_NONE, self::EVIDENCE_FAILURE => $of(
                    self::VERDICT_FAILED,
                    'the payment failed terminally'
                ),
                default => $of(self::VERDICT_INDETERMINATE, self::unrecognised($claimed, $evidence)),
            },

            // A status string outside the known set. The shape validators reject these before they
            // reach here; this row is the structural backstop, and it says "we do not know".
            default => $of(self::VERDICT_INDETERMINATE, self::unrecognised($claimed, $evidence)),
        };
    }

    private static function unrecognised(string $claimed, string $evidence): string
    {
        return "unrecognised payment state (status \"{$claimed}\", evidence {$evidence})";
    }
}

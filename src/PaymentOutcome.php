<?php

declare(strict_types=1);

namespace Paylod;

/**
 * The renderable payment outcome - the type you build your UI on.
 *
 * ```php
 * echo $outcome->message;
 * if ($outcome->retryable) { // show a retry button }
 * ```
 *
 * The two invariants:
 *   1. `retryable` means SAFE TO CHARGE AGAIN - we know no money moved and nothing is in flight.
 *      A `pending` payment is therefore NEVER retryable: codes 4999 / 500.001.1001 mean the STK
 *      prompt is live and the customer simply has not typed their PIN yet. Retrying double-charges.
 *   2. An indeterminate payment is not a failed payment. wait() THROWS PaylodTimeoutError rather
 *      than folding a timeout into status "failed".
 */
final class PaymentOutcome
{
    /** Shown while the prompt is live but M-Pesa has not given us a code to decode yet. */
    private const WAITING = 'Check your phone and enter your M-Pesa PIN to complete this payment.';

    /**
     * Shown when the record contradicts itself - the raw `status` field says one terminal thing and
     * the decoded result code says another. We CANNOT prove money did or did not move, so this is an
     * indeterminate payment: never `paid`, never `retryable`. It is surfaced as `pending` so wait()
     * lets a webhook settle it (and ultimately throws PaylodTimeoutError) rather than reporting a
     * false success or a false failure.
     */
    private const INDETERMINATE =
        "We couldn't confirm this payment yet. Please wait - do not retry - while it settles.";

    private const CANCELLED_CODE = '1032';

    /**
     * @param "succeeded"|"pending"|"cancelled"|"failed" $status
     * @param array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}|null $detail
     * @param array<string,mixed> $payment
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly bool $retryable,
        public readonly bool $paid,
        public readonly string $paymentId,
        public readonly ?string $receipt,
        public readonly ?string $code,
        public readonly ?array $detail,
        public readonly array $payment,
    ) {
    }

    /**
     * The renderable form of a freshly-sent STK prompt. A prompt sitting on a handset IS a pending
     * payment; render it the same way as every other state.
     */
    public static function pendingFor(string $paymentId): self
    {
        return new self(
            status: 'pending',
            message: self::WAITING,
            retryable: false, // the prompt is live - a second charge is exactly what we must not do
            paid: false,
            paymentId: $paymentId,
            receipt: null,
            code: null,
            detail: null,
            payment: [
                'id' => $paymentId,
                'status' => 'pending',
                'mpesaReceipt' => null,
                'resultCode' => null,
                'resultDesc' => null,
            ],
        );
    }

    /**
     * Build a renderable outcome from a payment record.
     *
     * -- This method no longer DECIDES anything; it RENDERS -----------------------------------
     * It used to carry its own copy of the rules: a `contradictory` boolean, a
     * `$classified ?? $rawStatus` fallback chain, and an evidence check nested inside the success
     * branch. The GAPS BETWEEN those three were the whole problem. `['status' => 'pending',
     * 'resultCode' => 0]` fell through the contradiction test (which only compared two TERMINAL
     * signals), landed on `$classified === 'success'`, and came back PAID with a null receipt. A
     * receipt on a `failed` row came back `cancelled, retryable: true` - the SDK telling a merchant
     * it was safe to charge a second time for a payment carrying an M-Pesa confirmation code.
     *
     * Every one of those decisions now comes from {@see Semantics::judge()}, a single total table.
     * That is also what closes the "fromPayment() bypasses validation" hole: law L2 (`paid` requires
     * a receipt or result code 0) is enforced INSIDE this method now, by construction, so an
     * evidence-free `status: "success"` can no longer be marked paid no matter which surface built
     * the array or whether any validator ran first.
     *
     * @param array<string,mixed> $payment shape: {id, status, mpesaReceipt, resultCode, resultDesc}
     */
    public static function fromPayment(array $payment): self
    {
        $resultCode = $payment['resultCode'] ?? null;
        $resultDesc = $payment['resultDesc'] ?? null;
        $paymentId = (string) ($payment['id'] ?? '');
        $mpesaReceipt = $payment['mpesaReceipt'] ?? null;

        $hasCode = $resultCode !== null;
        $desc = is_string($resultDesc) ? $resultDesc : null;
        $detail = $hasCode ? DarajaCatalog::decode($resultCode, $desc) : null;
        $code = $detail['code'] ?? null;

        // THE WHOLE DECISION, IN ONE CALL.
        $verdict = Semantics::judge($payment)->verdict;

        // EVERY exposed field is reconstructed from an allowlist and scrubbed - see safePayment().
        $safePayment = self::safePayment($payment);
        $paymentId = (string) self::scrub($paymentId);
        $code = $code === null ? null : (string) self::scrub($code);

        // AN EXHAUSTIVE `match` OVER THE FOUR VERDICTS, WITH NO DEFAULT ARM.
        //
        // This used to be three `if` blocks and a fallthrough, so EVERY verdict the first three did
        // not match was rendered as a TERMINAL FAILURE - including any fifth verdict a future change
        // might add, which would arrive silently as `failed`/`retryable` rather than as an error.
        // The verdict alphabet is closed, so the rendering must say so structurally: a missing arm
        // is an \UnhandledMatchError at the point of the omission, never a silent guess. Same rule,
        // and the same reason, as the 30-cell table in {@see Semantics::cell()}.
        return match ($verdict) {
            Semantics::VERDICT_PAID => new self(
                status: 'succeeded',
                message: (string) self::scrub($detail['customerMessage'] ?? 'Payment received - thank you!'),
                retryable: false, // it worked - charging again would be a second charge
                paid: true,
                paymentId: $paymentId,
                receipt: is_string($mpesaReceipt) ? (string) self::scrub($mpesaReceipt) : null,
                code: $code,
                detail: self::nonRetryableDetail($detail),
                payment: $safePayment,
            ),
            // Rendered as `pending` so wait() keeps polling and lets the webhook settle it, rather
            // than reporting a false success (goods shipped for nothing) or a false retryable failure
            // (the customer charged twice). Never paid, never retryable - both unconditional.
            Semantics::VERDICT_INDETERMINATE => new self(
                status: 'pending',
                message: self::INDETERMINATE,
                retryable: false,
                paid: false,
                paymentId: $paymentId,
                receipt: null,
                code: $code,
                detail: self::nonRetryableDetail($detail),
                payment: $safePayment,
            ),

            Semantics::VERDICT_IN_FLIGHT => new self(
                status: 'pending',
                // `detail` is only useful here if it is genuinely a pending code (4999 /
                // 500.001.1001); anything else that classified as pending has no useful message.
                message: ($detail !== null && $detail['category'] === 'pending')
                    ? (string) self::scrub($detail['customerMessage'])
                    : self::WAITING,
                retryable: false, // THE double-charge guard. A live prompt is never safe to re-charge.
                paid: false,
                paymentId: $paymentId,
                receipt: null,
                code: $code,
                detail: self::nonRetryableDetail($detail),
                payment: $safePayment,
            ),

            // Terminal failure. Cancellation gets its own word: the customer chose this, it is not
            // an error, and a UI usually wants to say so more gently. This arm is now reached ONLY
            // by the verdict it names - it is no longer where every unmatched verdict lands.
            Semantics::VERDICT_FAILED => new self(
                status: $code === self::CANCELLED_CODE ? 'cancelled' : 'failed',
                message: (string) self::scrub($detail['customerMessage'] ?? 'The payment didn\'t go through. Please try again.'),
                retryable: $detail['retryable'] ?? false,
                paid: false,
                paymentId: $paymentId,
                receipt: null,
                code: $code,
                // The terminal branch is the ONE place the nested flag may be true, and it is
                // exactly as true as the top-level one - the same value, not two readings.
                detail: ($detail['retryable'] ?? false)
                    ? self::scrubDetail($detail)
                    : self::nonRetryableDetail($detail),
                payment: $safePayment,
            ),
        };
    }

    /**
     * THE NESTED `retryable` CANNOT OUTRANK THE TOP-LEVEL ONE.
     *
     * `retryable` means SAFE TO CHARGE AGAIN, and this object exposes that answer TWICE - once as
     * `$outcome->retryable` and once as `$outcome->detail['retryable']`. The second one was the raw
     * catalog flag for the code, wired straight through, so the two disagreed on precisely the
     * bodies that matter most:
     *
     *   ['status' => 'failed', 'mpesaReceipt' => 'SFF6XYZ123', 'resultCode' => 1032]
     *
     * judges INDETERMINATE (a receipt beside a cancellation code), so the top-level flag was
     * correctly false - while `detail['retryable']` was the 1032 catalog entry's `true`. A handler
     * that reads the decoded block (a natural thing to do: it is where the title, cause and fix
     * live) was still being told it was safe to charge a customer who holds an M-Pesa receipt.
     *
     * A guarantee that only holds at one of the two places it is published is not a guarantee. So
     * every non-retryable verdict forces the nested flag to false as well. The catalog is not
     * being overruled about what the CODE means - the verdict has already established that this
     * RECORD does not license a second charge, and no field of this object may say otherwise.
     *
     * @param array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}|null $detail
     * @return array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}|null
     */
    private static function nonRetryableDetail(?array $detail): ?array
    {
        if ($detail === null) {
            return null;
        }
        $detail = self::scrubDetail($detail);
        $detail['retryable'] = false;

        return $detail;
    }

    /**
     * The decoded block, with credential-shaped tokens scrubbed out of its free-text fields.
     *
     * `cause` is the raw `resultDesc` for an uncatalogued code, and `code` is the sender's own
     * lexeme - both are SERVER-CONTROLLED strings that land in a public property.
     *
     * @param array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string} $detail
     * @return array{code:string,title:string,cause:string,fix:string,category:string,retryable:bool,customerMessage:string}
     */
    private static function scrubDetail(array $detail): array
    {
        foreach (['code', 'title', 'cause', 'fix', 'category', 'customerMessage'] as $field) {
            $detail[$field] = self::scrub($detail[$field]);
        }

        return $detail;
    }

    /**
     * THE RAW RECORD IS REBUILT FROM AN ALLOWLIST, NEVER FORWARDED.
     *
     * `$payment` used to be the parsed body itself, stored verbatim on a PUBLIC readonly property.
     * Two consequences, both reachable through an ordinary `var_dump($outcome)`, `print_r()`,
     * `json_encode($outcome->toArray())` or `var_export()` - i.e. through the things people do while
     * debugging a payment, in exactly the logs a payment record ends up in:
     *
     *   1. EXTRA KEYS SURVIVED. Whatever else the body carried - a `debug` block echoing the request
     *      headers, an `_raw` field, a vendor extension - was published unread.
     *   2. CREDENTIAL-SHAPED VALUES SURVIVED. A gateway that reflects the Authorization header into
     *      `resultDesc` (the same misconfiguration the error-path redaction already exists for) put a
     *      live `mp_live_...` key into this object. The client redacts on its own status path, but
     *      this method is PUBLIC and the webhook path calls it with body-supplied data, so relying on
     *      a caller having redacted first is relying on the caller.
     *
     * So the record is reconstructed field by field, from the five keys the schema defines, and every
     * string in it is scrubbed. Nothing the server invented can reach a public property by default.
     *
     * @param array<string,mixed> $payment
     * @return array<string,mixed>
     */
    private static function safePayment(array $payment): array
    {
        return [
            'id' => self::scrub($payment['id'] ?? null),
            'status' => self::scrub($payment['status'] ?? null),
            'mpesaReceipt' => self::scrub($payment['mpesaReceipt'] ?? null),
            'resultCode' => self::scrub($payment['resultCode'] ?? null),
            'resultDesc' => self::scrub($payment['resultDesc'] ?? null),
        ];
    }

    /**
     * Credential-shape scrubbing, with no process secrets supplied.
     *
     * {@see Redact} already knows what a paylod credential looks like (`mp_live_` / `mp_test_` /
     * `whsec_` + key characters). Reusing it here - rather than writing a second, narrower rule -
     * is what stops this check from drifting away from what the SDK considers a secret elsewhere.
     * This method is static and credential-free by design, so the EXACT-secret layer is not
     * available; the shape layer is, and it is the one that catches a reflected bearer token.
     */
    private static function scrub(mixed $value): mixed
    {
        return \Paylod\Support\Redact::apply($value, []);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'retryable' => $this->retryable,
            'paid' => $this->paid,
            'paymentId' => $this->paymentId,
            'receipt' => $this->receipt,
            'code' => $this->code,
            'detail' => $this->detail,
            'payment' => $this->payment,
        ];
    }
}

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

        if ($verdict === Semantics::VERDICT_PAID) {
            return new self(
                status: 'succeeded',
                message: $detail['customerMessage'] ?? 'Payment received - thank you!',
                retryable: false, // it worked - charging again would be a second charge
                paid: true,
                paymentId: $paymentId,
                receipt: is_string($mpesaReceipt) ? $mpesaReceipt : null,
                code: $code,
                detail: $detail,
                payment: $payment,
            );
        }

        if ($verdict === Semantics::VERDICT_INDETERMINATE) {
            // Rendered as `pending` so wait() keeps polling and lets the webhook settle it, rather
            // than reporting a false success (goods shipped for nothing) or a false retryable failure
            // (the customer charged twice). Never paid, never retryable - both unconditional.
            return new self(
                status: 'pending',
                message: self::INDETERMINATE,
                retryable: false,
                paid: false,
                paymentId: $paymentId,
                receipt: null,
                code: $code,
                detail: $detail,
                payment: $payment,
            );
        }

        if ($verdict === Semantics::VERDICT_IN_FLIGHT) {
            return new self(
                status: 'pending',
                // `detail` is only useful here if it is genuinely a pending code (4999 /
                // 500.001.1001); anything else that classified as pending has no useful message.
                message: ($detail !== null && $detail['category'] === 'pending')
                    ? $detail['customerMessage']
                    : self::WAITING,
                retryable: false, // THE double-charge guard. A live prompt is never safe to re-charge.
                paid: false,
                paymentId: $paymentId,
                receipt: null,
                code: $code,
                detail: $detail,
                payment: $payment,
            );
        }

        // Terminal failure. Cancellation gets its own word: the customer chose this, it is not an
        // error, and a UI usually wants to say so more gently.
        return new self(
            status: $code === self::CANCELLED_CODE ? 'cancelled' : 'failed',
            message: $detail['customerMessage'] ?? 'The payment didn\'t go through. Please try again.',
            retryable: $detail['retryable'] ?? false,
            paid: false,
            paymentId: $paymentId,
            receipt: null,
            code: $code,
            detail: $detail,
            payment: $payment,
        );
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

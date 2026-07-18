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
     * Build a renderable outcome from a payment record. Classification is delegated to the
     * canonical classifier the payment engine itself uses, so the SDK cannot disagree with the
     * backend about whether 4999 is a failure.
     *
     * @param array<string,mixed> $payment shape: {id, status, mpesaReceipt, resultCode, resultDesc}
     */
    public static function fromPayment(array $payment): self
    {
        $resultCode = $payment['resultCode'] ?? null;
        $resultDesc = $payment['resultDesc'] ?? null;
        $paymentId = (string) ($payment['id'] ?? '');
        $rawStatus = (string) ($payment['status'] ?? 'pending');
        $mpesaReceipt = $payment['mpesaReceipt'] ?? null;

        $hasCode = $resultCode !== null;
        $detail = $hasCode
            ? DarajaCatalog::decode($resultCode, is_string($resultDesc) ? $resultDesc : null)
            : null;
        $code = $detail['code'] ?? null;

        if ($hasCode) {
            $outcome = DarajaCatalog::classifyStkResult($resultCode, is_string($resultDesc) ? $resultDesc : null);
        } elseif ($rawStatus === 'success') {
            $outcome = 'success';
        } elseif ($rawStatus === 'failed') {
            $outcome = 'failed';
        } else {
            $outcome = 'pending';
        }

        if ($outcome === 'success' || $rawStatus === 'success') {
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

        if ($outcome === 'pending') {
            return new self(
                status: 'pending',
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

        // Terminal failure. Cancellation gets its own word.
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

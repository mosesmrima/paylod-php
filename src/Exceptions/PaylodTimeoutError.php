<?php

declare(strict_types=1);

namespace Paylod\Exceptions;

/**
 * collectAndWait() gave up before the payment reached a terminal state.
 *
 * This deliberately THROWS rather than returning status "failed". A timeout is not a failed
 * payment - the customer may still be staring at the STK prompt, and may still pay. Folding it
 * into the failure branch would let a merchant cancel an order that is about to settle. Handle it
 * explicitly: keep the order pending and let the webhook settle it.
 */
class PaylodTimeoutError extends PaylodException
{
    public readonly string $paymentId;

    /**
     * The last `pending` snapshot we read before giving up.
     *
     * @var array<string,mixed>
     */
    public readonly array $payment;

    public readonly int $waitedMs;

    /**
     * @param array<string,mixed> $payment
     */
    public function __construct(string $paymentId, array $payment, int $waitedMs)
    {
        parent::__construct(
            "Payment {$paymentId} was still pending after " . (int) round($waitedMs / 1000) . "s. "
            . "It is NOT failed - the customer may still complete it. Leave the order pending and "
            . "let the webhook (or a later status() call) settle it."
        );
        $this->paymentId = $paymentId;
        $this->payment = $payment;
        $this->waitedMs = $waitedMs;
    }
}

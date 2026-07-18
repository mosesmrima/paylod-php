<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\PaymentOutcome;
use PHPUnit\Framework\TestCase;

final class OutcomeTest extends TestCase
{
    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function payment(array $over = []): array
    {
        return array_merge([
            'id' => 'pay_123',
            'status' => 'pending',
            'mpesaReceipt' => null,
            'resultCode' => null,
            'resultDesc' => null,
        ], $over);
    }

    public function testSuccessIsPaidAndNotRetryable(): void
    {
        $o = PaymentOutcome::fromPayment(self::payment([
            'status' => 'success',
            'mpesaReceipt' => 'SFF6XYZ123',
            'resultCode' => 0,
        ]));
        $this->assertSame('succeeded', $o->status);
        $this->assertTrue($o->paid);
        $this->assertFalse($o->retryable);
        $this->assertSame('SFF6XYZ123', $o->receipt);
    }

    public function testCancelledGetsItsOwnStatus(): void
    {
        $o = PaymentOutcome::fromPayment(self::payment([
            'status' => 'failed',
            'resultCode' => 1032,
        ]));
        $this->assertSame('cancelled', $o->status);
        $this->assertFalse($o->paid);
        $this->assertTrue($o->retryable); // customer cancelled, no money moved
    }

    public function testWrongPinIsFailedButRetryable(): void
    {
        $o = PaymentOutcome::fromPayment(self::payment([
            'status' => 'failed',
            'resultCode' => 2001,
        ]));
        $this->assertSame('failed', $o->status);
        $this->assertTrue($o->retryable);
        $this->assertStringContainsStringIgnoringCase('PIN', $o->message);
    }

    public function testPendingCodeOnFailedRowIsReportedPending(): void
    {
        // THE classifier-authoritative case: a row marked `failed` carrying 4999 is really pending.
        $o = PaymentOutcome::fromPayment(self::payment([
            'status' => 'failed',
            'resultCode' => 4999,
        ]));
        $this->assertSame('pending', $o->status);
        $this->assertFalse($o->retryable); // live prompt - never safe to re-charge
    }

    public function testPendingForBuildsRenderableAck(): void
    {
        $o = PaymentOutcome::pendingFor('pay_abc');
        $this->assertSame('pending', $o->status);
        $this->assertFalse($o->retryable);
        $this->assertSame('pay_abc', $o->paymentId);
        $this->assertArrayHasKey('message', $o->toArray());
    }
}

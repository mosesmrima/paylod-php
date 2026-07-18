<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\Exceptions\PaylodSandboxOnlyError;
use Paylod\Paylod;
use Paylod\Tests\Support\MockTransport;
use PHPUnit\Framework\TestCase;

final class SimulatorTest extends TestCase
{
    public function testSimulateModeRefusesLiveKeyAtConstruction(): void
    {
        $this->expectException(PaylodSandboxOnlyError::class);
        new Paylod('mp_live_x', ['simulate' => true, 'transport' => new MockTransport([])]);
    }

    public function testSimulatorMethodRefusesLiveKeyLocally(): void
    {
        $paylod = new Paylod('mp_live_x', ['transport' => new MockTransport([])]);
        $this->expectException(PaylodSandboxOnlyError::class);
        $paylod->simulator->collect(['amount' => 100]);
    }

    public function testSimulateCollectHitsSimulateEndpoint(): void
    {
        $transport = new MockTransport([[
            'status' => 202,
            'json' => [
                'paymentId' => 'pay_sim',
                'checkoutRequestId' => 'ws_sim',
                'status' => 'pending',
                'outcomes' => [],
            ],
        ]]);
        $paylod = new Paylod('mp_test_x', ['transport' => $transport]);

        $created = $paylod->simulator->collect(['amount' => 250, 'idempotencyKey' => 'k1']);
        $this->assertSame('pay_sim', $created['paymentId']);
        $this->assertStringEndsWith('/simulate/collect', $transport->calls[0]['url']);
        $this->assertSame('k1', $transport->calls[0]['headers']['idempotency-key']);
    }

    public function testSimulateOutcomeReturnsDecodedOutcome(): void
    {
        $transport = new MockTransport([[
            'status' => 200,
            'json' => [
                'paymentId' => 'pay_sim',
                'status' => 'failed',
                'resultCode' => 2001,
                'resultDesc' => 'The initiator information is invalid.',
                'mpesaReceipt' => null,
                'webhookQueued' => true,
            ],
        ]]);
        $paylod = new Paylod('mp_test_x', ['transport' => $transport]);

        $result = $paylod->simulator->outcome('pay_sim', 'wrong_pin');
        $this->assertSame('failed', $result['outcome']->status);
        $this->assertTrue($result['outcome']->retryable);
        $this->assertTrue($result['webhookQueued']);
        $this->assertStringContainsStringIgnoringCase('PIN', $result['outcome']->message);
    }

    public function testSimulatePayApproveSucceeds(): void
    {
        $transport = new MockTransport([
            ['status' => 202, 'json' => ['paymentId' => 'pay_sim', 'checkoutRequestId' => 'ws', 'status' => 'pending', 'outcomes' => []]],
            ['status' => 200, 'json' => ['paymentId' => 'pay_sim', 'status' => 'success', 'resultCode' => 0, 'resultDesc' => 'ok', 'mpesaReceipt' => 'SFF6XYZ123', 'webhookQueued' => true]],
        ]);
        $paylod = new Paylod('mp_test_x', ['transport' => $transport]);

        $result = $paylod->simulator->pay(['outcome' => 'approve', 'amount' => 100]);
        $this->assertSame('succeeded', $result['outcome']->status);
        $this->assertTrue($result['outcome']->paid);
        $this->assertSame('SFF6XYZ123', $result['outcome']->receipt);
    }

    public function testClientSimulateModeRoutesCollectThroughSimulator(): void
    {
        $transport = new MockTransport([
            ['status' => 202, 'json' => ['paymentId' => 'pay_sim', 'checkoutRequestId' => 'ws', 'status' => 'pending', 'outcomes' => []]],
        ]);
        $paylod = new Paylod('mp_test_x', ['simulate' => true, 'transport' => $transport]);

        $ack = $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'k']);
        $this->assertSame('pay_sim', $ack['paymentId']);
        $this->assertSame('pending', $ack['status']);
        $this->assertStringEndsWith('/simulate/collect', $transport->calls[0]['url']);
    }

    public function testOutcomesListIsTheFive(): void
    {
        $paylod = new Paylod('mp_test_x', ['transport' => new MockTransport([])]);
        $this->assertSame(
            ['approve', 'wrong_pin', 'insufficient_funds', 'user_cancelled', 'timeout'],
            $paylod->simulator->outcomes()
        );
    }
}

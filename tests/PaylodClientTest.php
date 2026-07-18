<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodConfigError;
use Paylod\Exceptions\PaylodInvalidRequestError;
use Paylod\Exceptions\PaylodTimeoutError;
use Paylod\Paylod;
use Paylod\Tests\Support\MockTransport;
use PHPUnit\Framework\TestCase;

final class PaylodClientTest extends TestCase
{
    private const ACK = [
        'paymentId' => 'pay_123',
        'status' => 'pending',
        'checkoutRequestId' => 'ws_CO_0001',
    ];

    /**
     * @param list<array<string,mixed>> $steps
     */
    private function client(array $steps, array $options = []): array
    {
        $transport = new MockTransport($steps);
        $paylod = new Paylod('mp_test_x', array_merge(['transport' => $transport], $options));

        return [$paylod, $transport];
    }

    public function testConstructorThrowsWithoutKey(): void
    {
        $prev = getenv('PAYLOD_API_KEY');
        putenv('PAYLOD_API_KEY');
        try {
            $this->expectException(PaylodConfigError::class);
            new Paylod(null, ['transport' => new MockTransport([])]);
        } finally {
            if ($prev !== false) {
                putenv('PAYLOD_API_KEY=' . $prev);
            }
        }
    }

    public function testCollectSendsIdempotencyKeyAndAuthHeader(): void
    {
        [$paylod, $transport] = $this->client([['status' => 202, 'json' => self::ACK]]);

        $ack = $paylod->collect([
            'amount' => 100,
            'phone' => '0712345678',
            'idempotencyKey' => 'attempt-1',
        ]);

        $this->assertSame('pay_123', $ack['paymentId']);
        $this->assertSame('attempt-1', $ack['idempotencyKey']);

        $call = $transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/collect', $call['url']);
        $this->assertSame('Bearer mp_test_x', $call['headers']['authorization']);
        $this->assertSame('attempt-1', $call['headers']['idempotency-key']);
        // Phone normalised locally before the request left the process.
        $this->assertSame('254712345678', $call['body']['phone']);
        $this->assertSame(100, $call['body']['amount']);
    }

    public function testCollectGeneratesKeyWhenOmittedAndWarnsOnce(): void
    {
        [$paylod, $transport] = $this->client([['status' => 202, 'json' => self::ACK]]);

        $warned = false;
        set_error_handler(function (int $errno, string $msg) use (&$warned): bool {
            $warned = str_contains($msg, 'idempotencyKey');
            return true;
        }, E_USER_WARNING);

        try {
            $ack = $paylod->collect(['amount' => 100, 'phone' => '0712345678']);
        } finally {
            restore_error_handler();
        }

        $this->assertNotSame('', $ack['idempotencyKey']);
        $this->assertSame($ack['idempotencyKey'], $transport->calls[0]['headers']['idempotency-key']);
    }

    public function testCollectValidatesAmountLocally(): void
    {
        [$paylod] = $this->client([]);
        $this->expectException(PaylodInvalidRequestError::class);
        $paylod->collect(['amount' => 0, 'phone' => '0712345678', 'idempotencyKey' => 'a']);
    }

    public function testCollectRejectsDecimalAmount(): void
    {
        [$paylod] = $this->client([]);
        $this->expectException(PaylodInvalidRequestError::class);
        $paylod->collect(['amount' => 10.5, 'phone' => '0712345678', 'idempotencyKey' => 'a']);
    }

    public function testCollectRejectsAmountOverCeiling(): void
    {
        [$paylod] = $this->client([]);
        $this->expectException(PaylodInvalidRequestError::class);
        $paylod->collect(['amount' => 150001, 'phone' => '0712345678', 'idempotencyKey' => 'a']);
    }

    public function testStatusReadsPayment(): void
    {
        [$paylod, $transport] = $this->client([[
            'status' => 200,
            'json' => ['id' => 'pay_123', 'status' => 'success', 'mpesaReceipt' => 'SFF6XYZ123', 'resultCode' => 0, 'resultDesc' => 'ok'],
        ]]);

        $p = $paylod->status('pay_123');
        $this->assertSame('success', $p['status']);
        $this->assertSame('SFF6XYZ123', $p['mpesaReceipt']);
        $this->assertStringContainsString('/status/pay_123', $transport->calls[0]['url']);
    }

    public function testCheckReturnsDecodedOutcome(): void
    {
        [$paylod] = $this->client([[
            'status' => 200,
            'json' => ['id' => 'pay_123', 'status' => 'failed', 'mpesaReceipt' => null, 'resultCode' => 1032, 'resultDesc' => 'cancelled'],
        ]]);

        $outcome = $paylod->check('pay_123');
        $this->assertSame('cancelled', $outcome->status);
        $this->assertTrue($outcome->retryable);
    }

    public function testWaitPollsUntilTerminal(): void
    {
        [$paylod] = $this->client([
            ['status' => 200, 'json' => ['id' => 'pay_123', 'status' => 'pending', 'mpesaReceipt' => null, 'resultCode' => null, 'resultDesc' => null]],
            ['status' => 200, 'json' => ['id' => 'pay_123', 'status' => 'success', 'mpesaReceipt' => 'SFF6XYZ123', 'resultCode' => 0, 'resultDesc' => 'ok']],
        ]);

        $polls = 0;
        $outcome = $paylod->wait('pay_123', ['timeoutMs' => 5000, 'onPoll' => function () use (&$polls): void {
            $polls++;
        }]);

        $this->assertSame('succeeded', $outcome->status);
        $this->assertTrue($outcome->paid);
        $this->assertSame(1, $polls); // one pending snapshot before the terminal read
    }

    public function testWaitThrowsTimeoutWhenStillPending(): void
    {
        [$paylod] = $this->client([[
            'status' => 200,
            'json' => ['id' => 'pay_123', 'status' => 'pending', 'mpesaReceipt' => null, 'resultCode' => null, 'resultDesc' => null],
        ]]);

        try {
            $paylod->wait('pay_123', ['timeoutMs' => 1]);
            $this->fail('expected a timeout');
        } catch (PaylodTimeoutError $e) {
            $this->assertSame('pay_123', $e->paymentId);
        }
    }

    public function testCollectAndWaitChainsCollectThenWait(): void
    {
        [$paylod] = $this->client([
            ['status' => 202, 'json' => self::ACK],
            ['status' => 200, 'json' => ['id' => 'pay_123', 'status' => 'success', 'mpesaReceipt' => 'SFF6XYZ123', 'resultCode' => 0, 'resultDesc' => 'ok']],
        ]);

        $outcome = $paylod->collectAndWait([
            'amount' => 250,
            'phone' => '0712345678',
            'idempotencyKey' => 'attempt-9',
        ], ['timeoutMs' => 5000]);

        $this->assertTrue($outcome->paid);
        $this->assertSame('SFF6XYZ123', $outcome->receipt);
    }

    public function testApiErrorOn4xxIsThrownWithStatusAndFlags(): void
    {
        [$paylod] = $this->client([[
            'status' => 409,
            'json' => ['error' => 'A previous request with this Idempotency-Key was interrupted while the provider call was in flight'],
        ]]);

        try {
            $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'spent']);
            $this->fail('expected an API error');
        } catch (PaylodApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertTrue($e->isIdempotencyConflict());
            $this->assertTrue($e->isIdempotencyIndeterminate());
            $this->assertSame('spent', $e->idempotencyKey);
        }
    }

    public function testRetriesTransient5xxThenSucceeds(): void
    {
        [$paylod, $transport] = $this->client([
            ['status' => 503, 'json' => ['error' => 'busy']],
            ['status' => 202, 'json' => self::ACK],
        ]);

        $ack = $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'a']);
        $this->assertSame('pay_123', $ack['paymentId']);
        $this->assertSame(2, $transport->count()); // retried once
        // Same idempotency key on the retry - that is what makes a retried POST safe.
        $this->assertSame('a', $transport->calls[0]['headers']['idempotency-key']);
        $this->assertSame('a', $transport->calls[1]['headers']['idempotency-key']);
    }

    public function testRetriesNetworkErrorThenSucceeds(): void
    {
        [$paylod, $transport] = $this->client([
            ['throw' => true],
            ['status' => 202, 'json' => self::ACK],
        ]);

        $ack = $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'a']);
        $this->assertSame('pay_123', $ack['paymentId']);
        $this->assertSame(2, $transport->count());
    }

    public function testDoesNotRetry4xx(): void
    {
        [$paylod, $transport] = $this->client([['status' => 400, 'json' => ['error' => 'bad']]]);

        try {
            $paylod->status('pay_123');
            $this->fail('expected an API error');
        } catch (PaylodApiError $e) {
            $this->assertSame(400, $e->status);
        }
        $this->assertSame(1, $transport->count()); // no retry on a real 4xx answer
    }

    public function testDecodeErrorWorksOffline(): void
    {
        [$paylod] = $this->client([]);
        $d = $paylod->decodeError(1);
        $this->assertSame('balance', $d['category']);
    }
}

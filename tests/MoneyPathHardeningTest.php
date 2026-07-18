<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodConfigError;
use Paylod\Exceptions\PaylodConnectionError;
use Paylod\Exceptions\PaylodException;
use Paylod\Exceptions\PaylodInvalidRequestError;
use Paylod\Exceptions\PaylodTimeoutError;
use Paylod\Paylod;
use Paylod\Tests\Support\MockTransport;
use PHPUnit\Framework\TestCase;

/**
 * Third-round money-path and secret-hygiene regressions.
 *
 * Every test here corresponds to a specific way the SDK could lose money or leak a credential, and
 * every one of them FAILS if its fix is reverted.
 */
final class MoneyPathHardeningTest extends TestCase
{
    private const ACK = [
        'paymentId' => 'pay_123',
        'status' => 'pending',
        'checkoutRequestId' => 'ws_CO_0001',
    ];

    private const PENDING = [
        'id' => 'pay_123',
        'status' => 'pending',
        'mpesaReceipt' => null,
        'resultCode' => null,
        'resultDesc' => null,
    ];

    /**
     * @param list<array<string,mixed>> $steps
     * @return array{0:Paylod,1:MockTransport}
     */
    private function client(array $steps, array $options = [], string $key = 'mp_test_x'): array
    {
        $transport = new MockTransport($steps);

        return [new Paylod($key, array_merge(['transport' => $transport], $options)), $transport];
    }

    private static function invoke(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(Paylod::class, $method);

        return $ref->invoke(null, ...$args);
    }

    // -- 1. collectAndWait() must never lose the idempotency key -------------------

    public function testCollectAndWaitTimeoutCarriesTheIdempotencyKeyAndPaymentId(): void
    {
        // The prompt IS on the phone; the wait then times out. Without the key on the error, the
        // caller's retry mints a fresh one and the customer pays twice.
        [$paylod] = $this->client([
            ['status' => 202, 'json' => self::ACK],
            ['status' => 200, 'json' => self::PENDING],
        ]);

        try {
            $paylod->collectAndWait(
                ['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'attempt-1'],
                ['timeoutMs' => 1],
            );
            $this->fail('expected a timeout');
        } catch (PaylodTimeoutError $e) {
            $this->assertSame('attempt-1', $e->idempotencyKey);
            $this->assertSame('pay_123', $e->paymentId);
        }
    }

    public function testCollectAndWaitTimeoutCarriesAnSdkGeneratedKey(): void
    {
        // The worst case: the SDK minted the key, so if the error drops it the key is IRRECOVERABLE.
        [$paylod] = $this->client([
            ['status' => 202, 'json' => self::ACK],
            ['status' => 200, 'json' => self::PENDING],
        ]);

        set_error_handler(static fn (): bool => true); // silence the one-time no-key warning
        try {
            $paylod->collectAndWait(['amount' => 100, 'phone' => '0712345678'], ['timeoutMs' => 1]);
            $this->fail('expected a timeout');
        } catch (PaylodTimeoutError $e) {
            $this->assertIsString($e->idempotencyKey);
            $this->assertNotSame('', (string) $e->idempotencyKey);
            $this->assertSame('pay_123', $e->paymentId);
        } finally {
            restore_error_handler();
        }
    }

    public function testCollectAndWaitTransportFailureDuringPollCarriesTheKey(): void
    {
        [$paylod] = $this->client([
            ['status' => 202, 'json' => self::ACK],
            ['throw' => true],
        ]);

        try {
            $paylod->collectAndWait(
                ['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'attempt-2'],
                ['timeoutMs' => 50],
            );
            $this->fail('expected a connection error');
        } catch (PaylodException $e) {
            $this->assertInstanceOf(PaylodConnectionError::class, $e);
            $this->assertSame('attempt-2', $e->idempotencyKey);
            $this->assertSame('pay_123', $e->paymentId);
        }
    }

    public function testCollectAndWaitHttpErrorDuringPollCarriesTheKey(): void
    {
        [$paylod] = $this->client([
            ['status' => 202, 'json' => self::ACK],
            ['status' => 404, 'json' => ['error' => 'no such payment']],
        ]);

        try {
            $paylod->collectAndWait(
                ['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'attempt-3'],
                ['timeoutMs' => 5000],
            );
            $this->fail('expected an api error');
        } catch (PaylodApiError $e) {
            $this->assertSame('attempt-3', $e->idempotencyKey);
            $this->assertSame('pay_123', $e->paymentId);
        }
    }

    public function testCollectAndWaitMalformedPollBodyCarriesTheKey(): void
    {
        [$paylod] = $this->client([
            ['status' => 202, 'json' => self::ACK],
            ['status' => 200, 'json' => ['id' => 'pay_123']], // no status at all
        ]);

        try {
            $paylod->collectAndWait(
                ['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'attempt-4'],
                ['timeoutMs' => 5000],
            );
            $this->fail('expected an api error');
        } catch (PaylodApiError $e) {
            $this->assertSame('attempt-4', $e->idempotencyKey);
            $this->assertSame('pay_123', $e->paymentId);
        }
    }

    public function testCollectAndWaitValidatesWaitOptionsBeforeDispatchingTheCharge(): void
    {
        [$paylod, $transport] = $this->client([['status' => 202, 'json' => self::ACK]]);

        try {
            $paylod->collectAndWait(
                ['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'k'],
                ['timeoutMs' => 0],
            );
            $this->fail('expected a config error');
        } catch (PaylodConfigError) {
            // The point of the test: NOTHING was sent. A bad wait option must not ring a phone first.
            $this->assertSame(0, $transport->count());
        }
    }

    // -- 2. Complete acknowledgement / status schema validation --------------------

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function malformedAcks(): array
    {
        return [
            'missing checkoutRequestId' => [['paymentId' => 'pay_1', 'status' => 'pending']],
            'blank checkoutRequestId' => [['paymentId' => 'pay_1', 'status' => 'pending', 'checkoutRequestId' => '   ']],
            'missing status' => [['paymentId' => 'pay_1', 'checkoutRequestId' => 'ws']],
            'status wrong type' => [['paymentId' => 'pay_1', 'checkoutRequestId' => 'ws', 'status' => 7]],
            'status not an ack state' => [['paymentId' => 'pay_1', 'checkoutRequestId' => 'ws', 'status' => 'whatever']],
            'ack claims success' => [['paymentId' => 'pay_1', 'checkoutRequestId' => 'ws', 'status' => 'success']],
        ];
    }

    /**
     * @dataProvider malformedAcks
     * @param array<string,mixed> $ack
     */
    public function testMalformedAckIsIndeterminateAndKeyed(array $ack): void
    {
        [$paylod] = $this->client([['status' => 202, 'json' => $ack]]);

        try {
            $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'k-ack']);
            $this->fail('expected an indeterminate api error');
        } catch (PaylodApiError $e) {
            $this->assertTrue($e->indeterminate);
            $this->assertSame('k-ack', $e->idempotencyKey);
        }
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function malformedPayments(): array
    {
        return [
            'missing status' => [['id' => 'pay_1']],
            'status wrong type' => [['id' => 'pay_1', 'status' => 1]],
            'unknown status' => [['id' => 'pay_1', 'status' => 'weird']],
            'success without evidence' => [['id' => 'pay_1', 'status' => 'success', 'mpesaReceipt' => null, 'resultCode' => null]],
            'success with blank receipt' => [['id' => 'pay_1', 'status' => 'success', 'mpesaReceipt' => '  ', 'resultCode' => null]],
            'receipt wrong type' => [['id' => 'pay_1', 'status' => 'pending', 'mpesaReceipt' => 42]],
            'resultCode wrong type' => [['id' => 'pay_1', 'status' => 'pending', 'resultCode' => ['x']]],
        ];
    }

    /**
     * @dataProvider malformedPayments
     * @param array<string,mixed> $payment
     */
    public function testMalformedStatusBodyIsRejected(array $payment): void
    {
        [$paylod] = $this->client([['status' => 200, 'json' => $payment]]);

        $this->expectException(PaylodApiError::class);
        $paylod->status('pay_1');
    }

    public function testSuccessWithoutEvidenceIsNeverReportedAsPaid(): void
    {
        // The money question, stated plainly: an evidence-free "success" must not ship goods.
        [$paylod] = $this->client([[
            'status' => 200,
            'json' => ['id' => 'pay_1', 'status' => 'success', 'mpesaReceipt' => null, 'resultCode' => null],
        ]]);

        try {
            $outcome = $paylod->check('pay_1');
            $this->fail('a success with no receipt and no result code must not read as paid, got: ' . $outcome->status);
        } catch (PaylodApiError $e) {
            $this->assertTrue($e->indeterminate);
        }
    }

    public function testSuccessWithEvidenceStillPasses(): void
    {
        [$paylod] = $this->client([[
            'status' => 200,
            'json' => ['id' => 'pay_1', 'status' => 'success', 'mpesaReceipt' => 'SFF6XYZ123', 'resultCode' => 0],
        ]]);
        $this->assertTrue($paylod->check('pay_1')->paid);

        [$paylod2] = $this->client([[
            'status' => 200,
            'json' => ['id' => 'pay_1', 'status' => 'success', 'mpesaReceipt' => null, 'resultCode' => 0],
        ]]);
        $this->assertTrue($paylod2->check('pay_1')->paid);
    }

    // -- 3. Secrets never leak -----------------------------------------------------

    public function testDebugOutputDoesNotExposeSecrets(): void
    {
        [$paylod] = $this->client(
            [],
            ['webhookSecret' => 'whsec_supersecretvalue'],
            'mp_test_supersecretkey',
        );

        $dump = print_r($paylod, true);
        $this->assertStringNotContainsString('supersecretkey', $dump);
        $this->assertStringNotContainsString('supersecretvalue', $dump);

        $simDump = print_r($paylod->simulator, true);
        $this->assertStringNotContainsString('supersecretkey', $simDump);
    }

    public function testServerErrorBodyEchoingTheApiKeyIsRedacted(): void
    {
        [$paylod] = $this->client(
            [['status' => 400, 'json' => ['error' => 'bad header: Bearer mp_test_supersecretkey', 'echo' => ['auth' => 'mp_test_supersecretkey']]]],
            [],
            'mp_test_supersecretkey',
        );

        try {
            $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'k']);
            $this->fail('expected an api error');
        } catch (PaylodApiError $e) {
            $this->assertStringNotContainsString('supersecretkey', $e->getMessage());
            $this->assertStringNotContainsString('supersecretkey', json_encode($e->body));
        }
    }

    /**
     * Stack traces are where secrets actually leak in the field: PHP records call arguments in every
     * trace when zend.exception_ignore_args=0 (the development default), so a constructor argument
     * that is not #[\SensitiveParameter] ends up in the error log of any uncaught exception.
     */
    public function testSecretsAreScrubbedFromStackTracesWithExceptionArgsEnabled(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $script = <<<PHP
        require '{$autoload}';
        try {
            new Paylod\Paylod('mp_test_LEAKA', [
                'webhookSecret' => 'whsec_LEAKB',
                'timeoutMs' => -1,
            ]);
        } catch (Throwable \$e) {
            echo \$e->getTraceAsString(), "\n";
        }
        try {
            \$p = new Paylod\Paylod('mp_test_LEAKA');
            \$p->parseWebhook('{}', 't=1,v1=deadbeef', 'whsec_LEAKC');
        } catch (Throwable \$e) {
            echo \$e->getTraceAsString(), "\n";
        }
        PHP;
        $file = sys_get_temp_dir() . '/paylod_trace_probe_' . getmypid() . '.php';
        file_put_contents($file, "<?php\n" . $script);
        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' -d zend.exception_ignore_args=0 -d error_reporting=0 '
                . escapeshellarg($file) . ' 2>&1';
            $out = (string) shell_exec($cmd);
        } finally {
            @unlink($file);
        }

        $this->assertNotSame('', trim($out), 'the probe produced no trace at all');
        // Short secrets on purpose: PHP truncates string arguments in a trace at 15 characters, so a
        // long fixture would "pass" merely by being cut off rather than by being marked sensitive.
        $this->assertStringNotContainsString('LEAKA', $out);
        $this->assertStringNotContainsString('LEAKB', $out);
        $this->assertStringNotContainsString('LEAKC', $out);
    }

    // -- 4. Timeouts must be whole, representable milliseconds ---------------------

    /** @return array<string,array{0:mixed}> */
    public static function badTimeouts(): array
    {
        return [
            'fractional under one' => [0.5],   // truncates to 0 -> DISABLES the cURL timeout
            'fractional' => [1500.75],
            'string fractional' => ['0.5'],
            'tiny' => [0.0001],
            'over the ceiling' => [600001],
            'nan' => [NAN],
            'inf' => [INF],
        ];
    }

    /** @dataProvider badTimeouts */
    public function testFractionalOrOutOfRangeConstructorTimeoutIsRefused(mixed $value): void
    {
        $this->expectException(PaylodConfigError::class);
        new Paylod('mp_test_x', ['transport' => new MockTransport([]), 'timeoutMs' => $value]);
    }

    /** @dataProvider badTimeouts */
    public function testFractionalOrOutOfRangeWaitTimeoutIsRefused(mixed $value): void
    {
        [$paylod] = $this->client([['status' => 200, 'json' => self::PENDING]]);
        $this->expectException(PaylodConfigError::class);
        $paylod->wait('pay_123', ['timeoutMs' => $value]);
    }

    public function testWholeMillisecondTimeoutsAreStillAccepted(): void
    {
        [$paylod, $transport] = $this->client([['status' => 202, 'json' => self::ACK]], ['timeoutMs' => 1000.0]);
        $paylod->collect(['amount' => 1, 'phone' => '0712345678', 'idempotencyKey' => 'k']);
        $this->assertSame(1000, $transport->calls[0]['timeoutMs']);
    }

    // -- 5. Bounded retries and a hard sleep ceiling -------------------------------

    /** @return array<string,array{0:mixed}> */
    public static function badMaxRetries(): array
    {
        return ['negative' => [-1], 'far too many' => [50], 'fractional' => [2.5], 'not a number' => ['lots']];
    }

    /** @dataProvider badMaxRetries */
    public function testMaxRetriesIsBounded(mixed $value): void
    {
        $this->expectException(PaylodConfigError::class);
        new Paylod('mp_test_x', ['transport' => new MockTransport([]), 'maxRetries' => $value]);
    }

    public function testSleepIsClampedToSixtySecondsEvenWithNoDeadline(): void
    {
        // No deadline at all - the case collect() runs in. Without a ceiling an exponential backoff
        // parks the worker for as long as the ramp says.
        $this->assertSame(60000, self::invoke('cappedSleepMs', 3600000, null));
        $this->assertSame(1000, self::invoke('cappedSleepMs', 1000, null));
    }

    // -- 6. Retry-After parsing ----------------------------------------------------

    public function testRetryAfterLookupIsCaseInsensitive(): void
    {
        $this->assertSame(1000, self::invoke('parseRetryAfterMs', ['Retry-After' => '1']));
        $this->assertSame(1000, self::invoke('parseRetryAfterMs', ['RETRY-AFTER' => '1']));
        $this->assertSame(1000, self::invoke('parseRetryAfterMs', ['retry-after' => '1']));
    }

    public function testOversizedRetryAfterSaturatesInsteadOfOverflowing(): void
    {
        // 30 digits: (int) * 1000 used to become a float and raise a TypeError on the int return -
        // a crash on a retryable 429, mid-payment.
        $huge = str_repeat('9', 30);
        $this->assertSame(60000, self::invoke('parseRetryAfterMs', ['retry-after' => $huge]));
        $this->assertSame(60000, self::invoke('parseRetryAfterMs', ['retry-after' => '99999999999999']));
    }

    /** @return array<string,array{0:string}> */
    public static function nonHttpDates(): array
    {
        return [
            'relative' => ['+1 day'],
            'now' => ['now'],
            'tomorrow' => ['tomorrow'],
            'iso' => ['2035-10-21T07:28:00Z'],
            'garbage' => ['soonish'],
            'signed' => ['-5'],
        ];
    }

    /** @dataProvider nonHttpDates */
    public function testNonHttpDateRetryAfterIsRejected(string $value): void
    {
        $this->assertNull(self::invoke('parseRetryAfterMs', ['retry-after' => $value]));
    }

    public function testImfFixdateRetryAfterIsParsedAndClamped(): void
    {
        $past = gmdate('D, d M Y H:i:s \G\M\T', time() - 3600);
        $this->assertSame(0, self::invoke('parseRetryAfterMs', ['retry-after' => $past]));

        $soon = gmdate('D, d M Y H:i:s \G\M\T', time() + 2);
        $this->assertGreaterThan(0, self::invoke('parseRetryAfterMs', ['retry-after' => $soon]));

        $farFuture = gmdate('D, d M Y H:i:s \G\M\T', time() + 86400 * 365);
        $this->assertSame(60000, self::invoke('parseRetryAfterMs', ['retry-after' => $farFuture]));
    }

    // -- 7. The simulator uses the SAME validators --------------------------------

    public function testSimulatorRejectsAnInvalidIdempotencyKeyBeforeDispatch(): void
    {
        [$paylod, $transport] = $this->client([['status' => 202, 'json' => self::ACK]]);

        try {
            $paylod->simulator->collect(['amount' => 100, 'idempotencyKey' => "  \t "]);
            $this->fail('expected an invalid request error');
        } catch (PaylodInvalidRequestError) {
            $this->assertSame(0, $transport->count());
        }
    }

    public function testSimulatorRejectsANonAsciiIdempotencyKey(): void
    {
        [$paylod, $transport] = $this->client([['status' => 202, 'json' => self::ACK]]);

        try {
            $paylod->simulator->collect(['amount' => 100, 'idempotencyKey' => "ordr-caf\u{00e9}-1"]);
            $this->fail('expected an invalid request error');
        } catch (PaylodInvalidRequestError) {
            $this->assertSame(0, $transport->count());
        }
    }

    public function testSimulatorMalformedAckIsAKeyedIndeterminateErrorNotAnEmptyId(): void
    {
        [$paylod] = $this->client([['status' => 202, 'json' => ['status' => 'pending', 'outcomes' => []]]]);

        try {
            $created = $paylod->simulator->collect(['amount' => 100, 'idempotencyKey' => 'sim-1']);
            $this->fail('expected an indeterminate error, got paymentId "' . $created['paymentId'] . '"');
        } catch (PaylodApiError $e) {
            $this->assertTrue($e->indeterminate);
            $this->assertSame('sim-1', $e->idempotencyKey);
        }
    }

    public function testSimulatorOutcomeRejectsAnEvidenceFreeSuccess(): void
    {
        [$paylod] = $this->client([[
            'status' => 200,
            'json' => ['paymentId' => 'pay_sim', 'status' => 'success', 'resultCode' => null, 'mpesaReceipt' => null],
        ]]);

        $this->expectException(PaylodApiError::class);
        $paylod->simulator->outcome('pay_sim', 'approve');
    }
}

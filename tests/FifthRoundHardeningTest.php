<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodConfigError;
use Paylod\Exceptions\PaylodInvalidRequestError;
use Paylod\Exceptions\PaylodSignatureVerificationError;
use Paylod\Http\HttpClient;
use Paylod\Laravel\PaylodServiceProvider;
use Paylod\Paylod;
use Paylod\Tests\Support\MockHttpClient;
use Paylod\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * FIFTH-ROUND money-correctness and disclosure regressions.
 *
 * Each test below corresponds to exactly one defect, and each one FAILS if its fix is reverted -
 * proven mechanically by `scripts/non-vacuity.php`, which reverts the fix in source and requires
 * the named test to go red.
 */
final class FifthRoundHardeningTest extends TestCase
{
    private const ACK = [
        'paymentId' => 'pay_123',
        'status' => 'pending',
        'checkoutRequestId' => 'ws_CO_0001',
    ];

    /**
     * @param list<array<string,mixed>> $steps
     * @return array{0:Paylod,1:MockHttpClient}
     */
    private function client(array $steps, array $options = [], string $key = 'mp_test_x'): array
    {
        $transport = new MockHttpClient($steps);

        return [
            new Paylod($key, array_merge(
                ['httpClient' => $transport, 'allowCustomHttpClient' => true],
                $options,
            )),
            $transport,
        ];
    }

    // == The acknowledged payment's context is AUTHORITATIVE ===================================

    /**
     * An `onPoll` callback throws an error that ALREADY carries an unrelated key and payment id -
     * a stale object from a different charge, or one the callback constructed itself. Under the
     * best-effort `attach*()` semantics those pre-existing values SURVIVED, so the caller read the
     * wrong payment, saw nothing wrong, and re-charged the customer under a fresh key.
     */
    public function testAPostAckFailureCarriesTheAcknowledgedPaymentsOwnContextNotAStaleOne(): void
    {
        [$paylod] = $this->client([
            ['status' => 202, 'json' => self::ACK],
            ['status' => 200, 'json' => [
                'id' => 'pay_123',
                'status' => 'pending',
                'mpesaReceipt' => null,
                'resultCode' => null,
                'resultDesc' => null,
            ]],
        ]);

        $stale = new PaylodApiError('an error from a completely different charge', 500);
        $stale->attachIdempotencyKey('SOMEONE-ELSES-KEY');
        $stale->attachPaymentId('pay_UNRELATED');

        try {
            $paylod->collectAndWait(
                ['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'attempt-real'],
                ['timeoutMs' => 5000, 'onPoll' => static function () use ($stale): void {
                    throw $stale;
                }],
            );
            $this->fail('expected the onPoll error to surface');
        } catch (PaylodApiError $e) {
            $this->assertSame(
                'attempt-real',
                $e->idempotencyKey,
                'the error kept an unrelated key - the caller would read the wrong payment and re-charge'
            );
            $this->assertSame('pay_123', $e->paymentId, 'the error kept an unrelated payment id');
        }
    }

    // == A sanitized cause, never the secret-bearing original ==================================

    /**
     * The wrapper's own message is redacted, but chaining the ORIGINAL as `previous` put the raw
     * text straight back: `getPrevious()->getMessage()` still holds it, and PHP's default
     * `__toString()` WALKS the chain and prints it into the log line the framework writes.
     */
    public function testTheSecretBearingOriginalIsNeverChainedAsThePreviousException(): void
    {
        $leaky = new class implements HttpClient {
            public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
            {
                // The exact posture being defended against: an endpoint (or a client) that echoes
                // the Authorization header back into its own error text.
                throw new \RuntimeException('upstream said: Authorization: Bearer mp_test_supersecret');
            }
        };

        $paylod = new Paylod('mp_test_supersecret', [
            'httpClient' => $leaky,
            'allowCustomHttpClient' => true,
        ]);

        try {
            $paylod->collect(['amount' => 1, 'phone' => '0712345678', 'idempotencyKey' => 'k']);
            $this->fail('expected a wrapped error');
        } catch (PaylodApiError $e) {
            // The whole rendered chain - which is what actually reaches a log - carries no secret.
            $this->assertStringNotContainsString('mp_test_supersecret', (string) $e);
            $this->assertStringNotContainsString('mp_test_supersecret', $e->getMessage());

            $previous = $e->getPrevious();
            $this->assertNotNull($previous, 'diagnostic value must survive; only the secret is dropped');
            $this->assertStringNotContainsString('mp_test_supersecret', (string) $previous->getMessage());
            $this->assertNotInstanceOf(
                \RuntimeException::class,
                $previous,
                'the original throwable must not be chained - its message and trace carry the key'
            );
            // The surrogate still says WHAT went wrong.
            $this->assertStringContainsString('RuntimeException', $previous->getMessage());
        }
    }

    /** The same rule on the post-acknowledgement path, which is the more dangerous of the two. */
    public function testThePostAckWrapperAlsoDropsTheSecretBearingOriginal(): void
    {
        [$paylod] = $this->client([
            ['status' => 202, 'json' => self::ACK],
            ['status' => 200, 'json' => [
                'id' => 'pay_123',
                'status' => 'pending',
                'mpesaReceipt' => null,
                'resultCode' => null,
                'resultDesc' => null,
            ]],
        ], [], 'mp_test_leakme');

        try {
            $paylod->collectAndWait(
                ['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'attempt-1'],
                ['timeoutMs' => 5000, 'onPoll' => static function (): void {
                    throw new \RuntimeException('handler blew up holding mp_test_leakme');
                }],
            );
            $this->fail('expected a wrapped error');
        } catch (PaylodApiError $e) {
            $this->assertStringNotContainsString('mp_test_leakme', (string) $e);
            $this->assertNotInstanceOf(\RuntimeException::class, $e->getPrevious());
            // And it still carries the money context.
            $this->assertSame('attempt-1', $e->idempotencyKey);
            $this->assertSame('pay_123', $e->paymentId);
        }
    }

    // == A 2xx is network data too =============================================================

    /**
     * Redaction used to be an error-path measure only. A 200 is bytes from the network just the
     * same, and `resultDesc` is free text the SDK hands to a caller who logs it, renders it, or
     * pastes it into a support ticket.
     */
    public function testAnEchoedCredentialCannotEscapeThroughASuccessfulStatusRead(): void
    {
        [$paylod] = $this->client([
            ['status' => 200, 'json' => [
                'id' => 'pay_123',
                'status' => 'failed',
                'mpesaReceipt' => null,
                'resultCode' => 1032,
                'resultDesc' => 'Request failed for Authorization: Bearer mp_test_echoed',
            ]],
        ], [], 'mp_test_echoed');

        $payment = $paylod->status('pay_123');

        $this->assertStringNotContainsString('mp_test_echoed', (string) $payment['resultDesc']);
        $this->assertStringNotContainsString('mp_test_echoed', json_encode($payment));
        // The rest of the record is intact - this is redaction, not blanking.
        $this->assertSame(1032, $payment['resultCode']);
        $this->assertSame('failed', $payment['status']);
    }

    /** And it must not escape through a TimeoutError's attached payment either. */
    public function testAnEchoedCredentialCannotEscapeThroughATimeoutErrorsPayment(): void
    {
        [$paylod] = $this->client([
            ['status' => 200, 'json' => [
                'id' => 'pay_123',
                'status' => 'pending',
                'mpesaReceipt' => null,
                'resultCode' => null,
                'resultDesc' => 'still processing; token mp_test_echoed',
            ]],
        ], [], 'mp_test_echoed');

        try {
            $paylod->wait('pay_123', ['timeoutMs' => 1]);
            $this->fail('expected a timeout');
        } catch (\Paylod\Exceptions\PaylodTimeoutError $e) {
            $this->assertStringNotContainsString('mp_test_echoed', json_encode($e->payment));
        }
    }

    // == The anti-replay window has BOTH edges =================================================

    /**
     * THE DEFECT: a tolerance had a floor but no ceiling. `PHP_INT_MAX` is positive, finite and
     * whole, so it passed validation - and made `abs($now - $t) <= $tolerance` true for every
     * timestamp that has ever existed. Replay protection was gone while every other check still
     * passed, which is the worst failure mode available: the verifier looks like it works.
     */
    public function testAnUnboundedToleranceIsRefusedRatherThanDisablingReplayProtection(): void
    {
        $body = self::successEventJson();

        foreach ([PHP_INT_MAX, Webhook::MAX_TOLERANCE_SEC + 1, 86400, 31536000, (float) PHP_INT_MAX] as $absurd) {
            // Signed a year ago, correctly. Under an unbounded tolerance this verifies.
            $ancient = time() - 31_000_000;
            $header = Webhook::sign($body, 'whsec_test', $ancient);

            try {
                Webhook::verify($body, $header, 'whsec_test', $absurd);
                $this->fail('an ancient webhook was accepted with tolerance ' . var_export($absurd, true));
            } catch (PaylodSignatureVerificationError $e) {
                $this->assertSame('insecure_tolerance', $e->reason, 'tolerance ' . var_export($absurd, true));
            }

            $this->assertFalse(
                Webhook::isValid($body, $header, 'whsec_test', $absurd),
                'the boolean form must fail closed for tolerance ' . var_export($absurd, true)
            );
        }
    }

    /** The bound is a ceiling, not a narrowing: everything up to and including it still works. */
    public function testTheToleranceCeilingStillAdmitsEveryLegitimateWindow(): void
    {
        $body = self::successEventJson();
        $header = Webhook::sign($body, 'whsec_test', time());

        foreach ([1, 60, Webhook::DEFAULT_TOLERANCE_SEC, Webhook::MAX_TOLERANCE_SEC] as $ok) {
            $this->assertTrue(
                Webhook::isValid($body, $header, 'whsec_test', $ok),
                'tolerance ' . $ok . ' should be accepted'
            );
        }
        $this->assertLessThanOrEqual(3600, Webhook::MAX_TOLERANCE_SEC, 'the ceiling must stay small');
    }

    /** A minimal, fully evidenced payment.success event - the shape verify() actually requires. */
    private static function successEventJson(): string
    {
        return (string) json_encode([
            'id' => 'evt_1',
            'type' => 'payment.success',
            'data' => [
                'paymentId' => 'pay_123',
                'status' => 'success',
                'mpesaReceipt' => 'SFF6XYZ123',
                'resultCode' => 0,
                'resultDesc' => 'The service request is processed successfully.',
            ],
        ]);
    }

    // == A response body cannot choose how much memory this process spends =====================

    /**
     * `CURLOPT_RETURNTRANSFER` buffered without a bound, so the peer chose the allocation size. An
     * endless body answering a `/collect` killed the process AFTER the charge went out - the worst
     * possible moment, because the natural recovery is to run it again.
     */
    public function testTheResponseBufferHasADocumentedByteCeiling(): void
    {
        $ceiling = \Paylod\Http\CurlHttpClient::MAX_RESPONSE_BYTES;

        $this->assertIsInt($ceiling);
        $this->assertGreaterThan(0, $ceiling, 'a non-positive ceiling is no ceiling');
        $this->assertLessThanOrEqual(
            32 * 1024 * 1024,
            $ceiling,
            'a ceiling this large is not a bound on memory exhaustion'
        );

        $accept = new \ReflectionMethod(\Paylod\Http\CurlHttpClient::class, 'acceptChunk');
        $buffer = '';
        $bytes = 0;

        // Normal traffic passes through untouched.
        $args = [&$buffer, &$bytes, 'hello'];
        $this->assertSame(5, $accept->invokeArgs(null, $args));
        $this->assertSame('hello', $buffer);
        $this->assertSame(5, $bytes);

        // A chunk that would cross the ceiling is REFUSED ENTIRELY - 0 accepted bytes, which is
        // cURL's "abort now" signal - and nothing beyond the ceiling is ever allocated. Partial
        // acceptance is deliberately not an option: a truncated JSON payment record either fails
        // to parse or, far worse, parses into a different record than the server sent.
        $bytes = $ceiling - 1;
        $before = $buffer;
        $args = [&$buffer, &$bytes, str_repeat('x', 64)];
        $this->assertSame(0, $accept->invokeArgs(null, $args), 'an oversized chunk must abort the transfer');
        $this->assertSame($before, $buffer, 'nothing beyond the ceiling may be buffered');
        $this->assertSame($ceiling - 1, $bytes);

        // Exactly at the ceiling is still accepted - this is a bound, not an off-by-one.
        $buffer = '';
        $bytes = 0;
        $args = [&$buffer, &$bytes, str_repeat('y', 16)];
        $this->assertSame(16, $accept->invokeArgs(null, $args));
    }

    /**
     * The HEADERS are bounded too, in aggregate.
     *
     * The body ceiling closed only half the route. libcurl caps each individual header at 100 KiB
     * and hands them over one at a time, which looks like a bound but limits nothing about HOW MANY
     * arrive - and every one was accumulated forever. A peer streaming distinct header names
     * exhausted memory by exactly the path the body ceiling exists to close, with the same
     * consequence: it happens after the collect was dispatched.
     */
    public function testTheResponseHeadersHaveAnAggregateCeiling(): void
    {
        $byteCeiling = \Paylod\Http\CurlHttpClient::MAX_HEADER_BYTES;
        $countCeiling = \Paylod\Http\CurlHttpClient::MAX_HEADER_COUNT;

        $this->assertGreaterThan(0, $byteCeiling);
        $this->assertLessThanOrEqual(1024 * 1024, $byteCeiling, 'not a bound on memory exhaustion');
        $this->assertGreaterThan(0, $countCeiling);
        $this->assertLessThanOrEqual(2000, $countCeiling);

        $accept = new \ReflectionMethod(\Paylod\Http\CurlHttpClient::class, 'acceptHeader');

        // Normal traffic passes through and is stored.
        $headers = [];
        $bytes = 0;
        $args = [&$headers, &$bytes, "Content-Type: application/json\r\n"];
        $this->assertTrue($accept->invokeArgs(null, $args));
        $this->assertSame('application/json', $headers['content-type']);

        // THE COUNT CEILING. Many small, DISTINCT headers cost as much as one enormous one, so a
        // byte ceiling alone would not have closed this.
        $headers = [];
        $bytes = 0;
        for ($i = 0; $i < $countCeiling; $i++) {
            $args = [&$headers, &$bytes, "x-pad-{$i}: v\r\n"];
            $this->assertTrue($accept->invokeArgs(null, $args), "header {$i} should be accepted");
        }
        $args = [&$headers, &$bytes, "x-one-too-many: v\r\n"];
        $this->assertFalse($accept->invokeArgs(null, $args), 'the header count must be bounded');
        $this->assertArrayNotHasKey('x-one-too-many', $headers);

        // A REPEATED name overwrites rather than grows, so it is not charged against the count.
        $headers = [];
        $bytes = 0;
        for ($i = 0; $i < $countCeiling + 50; $i++) {
            $args = [&$headers, &$bytes, "x-same: v{$i}\r\n"];
            $this->assertTrue($accept->invokeArgs(null, $args));
        }
        $this->assertCount(1, $headers);

        // THE AGGREGATE BYTE CEILING, which that repetition eventually trips.
        $headers = [];
        $bytes = $byteCeiling - 4;
        $args = [&$headers, &$bytes, "x-big: " . str_repeat('z', 64) . "\r\n"];
        $this->assertFalse($accept->invokeArgs(null, $args), 'the aggregate header size must be bounded');
        $this->assertSame([], $headers, 'nothing beyond the ceiling may be stored');
    }

    /** A header overflow is the SAME keyed, indeterminate error a body overflow is - never retried. */
    public function testAnOversizedHeaderSetIsIndeterminateAndNotRetryable(): void
    {
        $error = (new \ReflectionMethod(\Paylod\Http\CurlHttpClient::class, 'overflowError'))
            ->invoke(null, 'response headers larger than the ceiling');

        $this->assertInstanceOf(PaylodApiError::class, $error);
        $this->assertNotInstanceOf(\Paylod\Exceptions\PaylodConnectionError::class, $error);
        $this->assertTrue($error->indeterminate);
        $this->assertStringContainsString('response headers', $error->getMessage());
        $this->assertStringContainsString('INDETERMINATE', $error->getMessage());
    }

    /**
     * The abort raises a KEYED, INDETERMINATE PaylodApiError, never a PaylodConnectionError: the
     * client RETRIES connection errors, and re-POSTing a charge because the response was too big
     * is precisely the double-charge this SDK exists to prevent.
     */
    public function testAnOversizedResponseIsIndeterminateAndNotRetryable(): void
    {
        $error = (new \ReflectionMethod(\Paylod\Http\CurlHttpClient::class, 'overflowError'))->invoke(null);

        $this->assertInstanceOf(PaylodApiError::class, $error);
        $this->assertNotInstanceOf(
            \Paylod\Exceptions\PaylodConnectionError::class,
            $error,
            'a connection error would be RETRIED, and a retried charge is a second charge'
        );
        $this->assertTrue($error->indeterminate, 'the request was sent, so the charge state is unknown');
        $this->assertStringContainsString('INDETERMINATE', $error->getMessage());
    }

    // == Laravel config is validated in the form the operator wrote it ==========================

    private function bootContainer(array $config = []): Container
    {
        $app = new Container();
        $app->instance('config', new ConfigRepository(['paylod' => array_merge([
            'api_key' => 'mp_test_laravel',
            'base_url' => Paylod::DEFAULT_BASE_URL,
            'timeout_ms' => 30000,
            'max_retries' => 2,
            'simulate' => false,
        ], $config)]));
        $app->instance('path.base', sys_get_temp_dir());

        (new PaylodServiceProvider($app))->register();

        return $app;
    }

    /**
     * The client refuses a fractional `timeoutMs` because it truncates to 0 and 0 DISABLES cURL's
     * timeout. The provider's `(int)` cast silenced that guard: `1.5` arrived as a well-formed `1`,
     * so the value the operator asked for was neither honoured nor rejected.
     */
    public function testLaravelRefusesAFractionalTimeoutInsteadOfSilentlyTruncatingIt(): void
    {
        // NOTE: an ABSENT key is not an error - it takes the default. What is refused is a
        // key that is PRESENT with a value that would silently become a different number.
        foreach (['1.5', 1.5, '0.5', '30s', '1e3', true, []] as $bad) {
            $app = $this->bootContainer(['timeout_ms' => $bad]);
            try {
                $app->make(Paylod::class);
                $this->fail('timeout_ms ' . var_export($bad, true) . ' was accepted');
            } catch (PaylodConfigError $e) {
                $this->assertStringContainsString('timeout_ms', $e->getMessage());
            }
        }
    }

    public function testLaravelRefusesAFractionalMaxRetriesInsteadOfSilentlyTruncatingIt(): void
    {
        foreach (['2.5', 2.5, 'many'] as $bad) {
            $app = $this->bootContainer(['max_retries' => $bad]);
            try {
                $app->make(Paylod::class);
                $this->fail('max_retries ' . var_export($bad, true) . ' was accepted');
            } catch (PaylodConfigError $e) {
                $this->assertStringContainsString('max_retries', $e->getMessage());
            }
        }
    }

    /** Well-formed values - including the string forms a `.env` file produces - still work. */
    public function testLaravelStillAcceptsWellFormedNumericConfig(): void
    {
        foreach ([['timeout_ms' => '5000'], ['timeout_ms' => 5000], ['timeout_ms' => 5000.0], ['max_retries' => '3']] as $good) {
            $this->assertInstanceOf(Paylod::class, $this->bootContainer($good)->make(Paylod::class));
        }
    }

    // == Wait deadlines run on a MONOTONIC clock ================================================

    /**
     * `microtime()` reads the WALL clock, and the wall clock moves - an NTP step, a DST transition,
     * an administrator running `date -s`. A deadline computed from it moves with it: backwards, a
     * wait() hangs; forwards, it expires INSTANTLY and the SDK throws a timeout on a payment whose
     * prompt is live on the handset - and a caller that treats a timeout as "start again" charges
     * twice.
     */
    public function testWaitDeadlinesUseAMonotonicClockNotTheWallClock(): void
    {
        $ref = new \ReflectionMethod(Paylod::class, 'nowMs');
        $a = $ref->invoke(null);
        usleep(2000);
        $b = $ref->invoke(null);

        $this->assertIsInt($a);
        $this->assertGreaterThanOrEqual($a, $b, 'the clock must never run backwards');
        $this->assertGreaterThan(0, $b - $a, 'the clock must actually advance');

        // The distinguishing property: hrtime()'s origin is arbitrary, so its value bears no
        // relation to the wall clock. A microtime()-based implementation would be within a
        // second of time()*1000 and this assertion would fail.
        $this->assertNotEqualsWithDelta(
            time() * 1000,
            $a,
            60_000,
            'nowMs() tracks the wall clock, so an NTP step or a DST transition moves every deadline'
        );
    }

    // == The simulator agrees with production about what a request IS ===========================

    /**
     * `isset()` is false for a key that is PRESENT with a null value, so `['description' => null]`
     * was silently dropped from the body the idempotency layer fingerprints - letting a reused key
     * with a changed field replay in the simulator while production answers 409.
     */
    public function testTheSimulatorNeitherDropsNorForwardsAnUnvalidatedField(): void
    {
        [$paylod] = $this->client([['status' => 202, 'json' => self::ACK + ['outcomes' => []]]], ['simulate' => true]);

        foreach (['description', 'accountReference'] as $field) {
            try {
                $paylod->simulator->collect(['amount' => 100, $field => null, 'idempotencyKey' => 'k']);
                $this->fail("{$field} => null was silently dropped instead of being rejected");
            } catch (PaylodInvalidRequestError $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }

        try {
            $paylod->simulator->collect(['amount' => 100, 'metadata' => 'not-an-array', 'idempotencyKey' => 'k']);
            $this->fail('a non-array metadata was forwarded unvalidated');
        } catch (PaylodInvalidRequestError $e) {
            $this->assertStringContainsString('metadata', $e->getMessage());
        }
    }
}

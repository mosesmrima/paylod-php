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
use Paylod\Tests\Support\MockHttpClient;
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
     * @return array{0:Paylod,1:MockHttpClient}
     */
    private function client(array $steps, array $options = [], string $key = 'mp_test_x'): array
    {
        $transport = new MockHttpClient($steps);

        return [new Paylod($key, array_merge(['httpClient' => $transport, 'allowCustomHttpClient' => true], $options)), $transport];
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

        set_error_handler(static fn (): bool => true); // silence the unsafe-generated-key warning
        try {
            $paylod->collectAndWait(
                ['amount' => 100, 'phone' => '0712345678', 'unsafeGeneratedIdempotencyKey' => true],
                ['timeoutMs' => 1],
            );
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
            // NOTE: an evidence-free `success` is NOT listed here. It is a well-SHAPED body that
            // makes an unsupported CLAIM, so it is not a validator's business - it is law L2, and it
            // resolves to INDETERMINATE in Semantics::judge(). See the two tests below.
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

    /**
     * L2, on the read path. The money question, stated plainly: an evidence-free "success" must not
     * ship goods.
     *
     * It resolves to INDETERMINATE rather than throwing, and that difference is deliberate: an
     * indeterminate payment must keep being POLLED so a webhook can settle it. Throwing here would
     * abort wait() on a payment that is very likely about to succeed.
     */
    public function testSuccessWithoutEvidenceIsNeverReportedAsPaid(): void
    {
        foreach ([null, '  '] as $receipt) {
            [$paylod] = $this->client([[
                'status' => 200,
                'json' => ['id' => 'pay_1', 'status' => 'success', 'mpesaReceipt' => $receipt, 'resultCode' => null],
            ]]);

            $outcome = $paylod->check('pay_1');
            $this->assertFalse($outcome->paid, 'an evidence-free success must never read as paid');
            $this->assertFalse($outcome->retryable, 'an indeterminate payment is never safe to re-charge');
            $this->assertSame('pending', $outcome->status);
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
     *
     * -- Why this probe now asserts its own machinery ---------------------------------------------
     * It used to ignore whether the temp file was actually written and whether the subprocess ran.
     * A failed write left PHP printing "Could not open input file", which is non-empty and contains
     * none of the leak markers - so the probe PASSED without ever executing the code under test. A
     * test whose failure mode is a silent pass is worse than no test. The file, the exit status and
     * the presence of a real trace are all asserted before any leak assertion is trusted.
     */
    public function testSecretsAreScrubbedFromStackTracesWithExceptionArgsEnabled(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $this->assertFileExists($autoload, 'the probe cannot run without a composer autoloader');

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
        // The RESPONSE-BODY path: a server reflecting the bearer token into an error body used to
        // leave it verbatim in the validator's recorded call arguments, even though the message and
        // the attached body were both redacted.
        try {
            \$client = new class implements Paylod\Http\HttpClient {
                public function send(string \$m, string \$u, array \$h, ?string \$b, int \$t): array
                {
                    return [
                        'status' => 202,
                        'headers' => [],
                        'body' => json_encode(['paymentId' => 'mp_test_LEAKA', 'status' => 'pending']),
                        'effectiveUrl' => null,
                        'redirectCount' => 0,
                    ];
                }
            };
            \$p2 = new Paylod\Paylod('mp_test_LEAKA', ['httpClient' => \$client, 'allowCustomHttpClient' => true]);
            \$p2->collect(['amount' => 1, 'phone' => '0712345678', 'idempotencyKey' => 'k']);
        } catch (Throwable \$e) {
            echo \$e->getTraceAsString(), "\n";
            // The COMPLETE structured trace, not just the rendered string: getTraceAsString()
            // abbreviates arguments, so a secret can survive in getTrace()['args'] while the
            // string form looks clean.
            echo print_r(\$e->getTrace(), true), "\n";
        }
        PHP;

        $file = sys_get_temp_dir() . '/paylod_trace_probe_' . getmypid() . '.php';
        $written = file_put_contents($file, "<?php\n" . $script);

        try {
            // THE PROBE'S OWN MACHINERY, asserted before its result is trusted.
            $this->assertNotFalse($written, 'could not write the probe script');
            $this->assertFileExists($file);
            $this->assertGreaterThan(0, (int) $written);

            $cmd = escapeshellarg(PHP_BINARY) . ' -d zend.exception_ignore_args=0 -d error_reporting=0 '
                . escapeshellarg($file) . ' 2>&1';
            $out = [];
            $exitCode = 0;
            exec($cmd, $out, $exitCode);
            $out = implode("\n", $out);
        } finally {
            @unlink($file);
        }

        $this->assertSame(0, $exitCode, "the probe subprocess failed:\n{$out}");
        $this->assertStringNotContainsString('Could not open input file', $out);
        $this->assertStringNotContainsString('Fatal error', $out);
        $this->assertNotSame('', trim($out), 'the probe produced no trace at all');

        // It really ran the code under test: a genuine trace names this SDK's own frames.
        $this->assertStringContainsString('Paylod', $out, 'the output is not a paylod stack trace');
        $this->assertMatchesRegularExpression('/#\d+ /', $out, 'the output contains no stack frames');
        $this->assertStringContainsString('collect', $out, 'the collect() probe frame is missing');

        // Short secrets on purpose: PHP truncates string arguments in a trace at 15 characters, so a
        // long fixture would "pass" merely by being cut off rather than by being marked sensitive.
        $this->assertStringNotContainsString('LEAKA', $out);
        $this->assertStringNotContainsString('LEAKB', $out);
        $this->assertStringNotContainsString('LEAKC', $out);
    }

    /**
     * The baseUrl VALIDATION ERRORS must not quote the credential they just caught.
     *
     * `https://mp_live_realkey@paylod.dev/...` is refused for carrying userinfo - correctly - but
     * the message interpolated the URL verbatim, so the diagnostic printed the live key into the
     * caller's log. A check that leaks the secret it detects is not a protection.
     */
    public function testBaseUrlValidationErrorsDoNotQuoteTheCredential(): void
    {
        $cases = [
            'userinfo carrying the key' => 'https://mp_test_supersecretkey@evil.example/v1',
            'userinfo carrying a live key' => 'https://mp_live_anotherkey@evil.example/v1',
            'key in a query string' => 'https://paylod.dev/v1?token=mp_test_supersecretkey',
        ];

        foreach ($cases as $label => $url) {
            try {
                new Paylod('mp_test_supersecretkey', ['baseUrl' => $url]);
                $this->fail("expected {$label} to be refused");
            } catch (PaylodConfigError $e) {
                $this->assertStringNotContainsString('supersecretkey', $e->getMessage(), $label);
                $this->assertStringNotContainsString('mp_live_anotherkey', $e->getMessage(), $label);
                $this->assertStringContainsString('[redacted]', $e->getMessage(), $label);
            }
        }
    }

    /** And the same string must not survive in the structured trace either. */
    public function testBaseUrlIsNotLeftInTheStackTrace(): void
    {
        try {
            new Paylod('mp_test_supersecretkey', ['baseUrl' => 'https://mp_test_supersecretkey@evil.example/v1']);
            $this->fail('expected a config error');
        } catch (PaylodConfigError $e) {
            $dump = print_r($e->getTrace(), true);
            $this->assertStringNotContainsString('supersecretkey', $dump);
        }
    }

    /**
     * THE STATUS-READ PATH must refuse a laundered zero too. `{"resultCode":-0}` decodes to the
     * integer 0 - indistinguishable from a genuine settlement - so a `status: "success"` body
     * carrying it would otherwise be reported PAID and a merchant would ship goods.
     */
    public function testARawZeroLexemeOnTheStatusPathIsRefusedAsIndeterminate(): void
    {
        foreach (['-0', '0e999', '0.0'] as $token) {
            $raw = '{"id":"pay_123","status":"success","mpesaReceipt":null,"resultCode":'
                . $token . ',"resultDesc":null}';

            [$paylod] = $this->client([['status' => 200, 'raw' => $raw]]);

            try {
                $paylod->status('pay_123');
                $this->fail("raw token {$token} was accepted on the status path");
            } catch (PaylodApiError $e) {
                $this->assertTrue($e->indeterminate, $token);
                $this->assertStringContainsString('non-canonical resultCode token', $e->getMessage());
            }
        }
    }

    /** The genuine canonical zero still reads as paid - the guard must not break real settlements. */
    public function testTheCanonicalZeroStillReadsAsPaidOnTheStatusPath(): void
    {
        $raw = '{"id":"pay_123","status":"success","mpesaReceipt":"SFF6XYZ123","resultCode":0,'
            . '"resultDesc":"Success"}';
        [$paylod] = $this->client([['status' => 200, 'raw' => $raw]]);

        $this->assertTrue($paylod->check('pay_123')->paid);
    }

    // -- 3b. Identifier shape on the acknowledgement path --------------------------

    /**
     * A 202 whose identifiers carry the bearer token is NOT a usable acknowledgement. Both fields
     * are returned to the caller and land in ordinary logs, where nothing redacts them - so the
     * shape is refused, and refused as INDETERMINATE, because the STK push was dispatched.
     *
     * @dataProvider credentialShapedAcks
     */
    public function testAnAckWhoseIdentifiersCarryTheApiKeyIsRefusedAsIndeterminate(array $ack): void
    {
        [$paylod] = $this->client([['status' => 202, 'json' => $ack]], [], 'mp_test_supersecretkey');

        try {
            $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'k']);
            $this->fail('expected a keyed indeterminate error');
        } catch (PaylodApiError $e) {
            $this->assertTrue($e->indeterminate);
            $this->assertSame('k', $e->idempotencyKey);
            $this->assertStringNotContainsString('supersecretkey', $e->getMessage());
            $this->assertStringNotContainsString('supersecretkey', (string) json_encode($e->body));
        }
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function credentialShapedAcks(): array
    {
        return [
            'key echoed into paymentId' => [[
                'paymentId' => 'mp_test_supersecretkey',
                'status' => 'pending',
                'checkoutRequestId' => 'ws_CO_0001',
            ]],
            'key echoed into checkoutRequestId' => [[
                'paymentId' => 'pay_123',
                'status' => 'pending',
                'checkoutRequestId' => 'mp_test_supersecretkey',
            ]],
            // Credential-SHAPED, but not this client's key: another tenant's live key reflected back.
            'foreign live key in paymentId' => [[
                'paymentId' => 'mp_live_someoneelseskey',
                'status' => 'pending',
                'checkoutRequestId' => 'ws_CO_0001',
            ]],
            'bearer header echoed' => [[
                'paymentId' => 'Bearer mp_test_supersecretkey',
                'status' => 'pending',
                'checkoutRequestId' => 'ws_CO_0001',
            ]],
        ];
    }

    /** Malformed identifier shapes are refused too - an id is a short opaque token, nothing else. */
    public function testAnAckWithAMalformedIdentifierIsRefused(): void
    {
        $bad = [
            'oversized' => str_repeat('a', 129),
            'with spaces' => 'pay 123',
            'with newline' => "pay_123\n",
            'url' => 'https://evil.example/pay_123',
            'leading separator' => '-pay_123',
        ];

        foreach ($bad as $label => $id) {
            [$paylod] = $this->client([['status' => 202, 'json' => [
                'paymentId' => $id,
                'status' => 'pending',
                'checkoutRequestId' => 'ws_CO_0001',
            ]]]);

            try {
                $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'k']);
                $this->fail("expected {$label} to be refused");
            } catch (PaylodApiError $e) {
                $this->assertTrue($e->indeterminate, $label);
                $this->assertSame('k', $e->idempotencyKey, $label);
            }
        }
    }

    /** And a perfectly ordinary ack still works - the grammar must not reject real identifiers. */
    public function testOrdinaryIdentifiersStillPass(): void
    {
        foreach (['pay_123', 'ws_CO_00012345', 'pay-123.v2', 'A1'] as $id) {
            [$paylod] = $this->client([['status' => 202, 'json' => [
                'paymentId' => $id,
                'status' => 'pending',
                'checkoutRequestId' => 'ws_CO_0001',
            ]]]);

            $ack = $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'k']);
            $this->assertSame($id, $ack['paymentId']);
        }
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
        new Paylod('mp_test_x', ['httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true, 'timeoutMs' => $value]);
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
        new Paylod('mp_test_x', ['httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true, 'maxRetries' => $value]);
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

    /** L2 on the simulator's settle path: the same rule, reached through the same judge(). */
    public function testSimulatorOutcomeNeverReportsAnEvidenceFreeSuccessAsPaid(): void
    {
        [$paylod] = $this->client([[
            'status' => 200,
            'json' => ['paymentId' => 'pay_sim', 'status' => 'success', 'resultCode' => null, 'mpesaReceipt' => null],
        ]]);

        $res = $paylod->simulator->outcome('pay_sim', 'approve');
        $this->assertFalse($res['outcome']->paid);
        $this->assertFalse($res['outcome']->retryable);
    }
}

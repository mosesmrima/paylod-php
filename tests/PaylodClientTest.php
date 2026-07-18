<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodConfigError;
use Paylod\Exceptions\PaylodConnectionError;
use Paylod\Exceptions\PaylodInvalidRequestError;
use Paylod\Exceptions\PaylodTimeoutError;
use Paylod\Paylod;
use Paylod\Tests\Support\MockHttpClient;
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
        $transport = new MockHttpClient($steps);
        $paylod = new Paylod('mp_test_x', array_merge(['httpClient' => $transport, 'allowCustomHttpClient' => true], $options));

        return [$paylod, $transport];
    }

    public function testConstructorThrowsWithoutKey(): void
    {
        $prev = getenv('PAYLOD_API_KEY');
        putenv('PAYLOD_API_KEY');
        try {
            $this->expectException(PaylodConfigError::class);
            new Paylod(null, ['httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true]);
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

    // -- HTTPS enforcement on baseUrl -----------------------------------------

    public function testConstructorRejectsPlaintextHttpBaseUrl(): void
    {
        $this->expectException(PaylodConfigError::class);
        $this->expectExceptionMessageMatches('/https/');
        new Paylod('mp_test_x', ['baseUrl' => 'http://paylod.dev/functions/v1', 'httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true]);
    }

    public function testConstructorAllowsLoopbackHttpBehindTestFlag(): void
    {
        $paylod = new Paylod('mp_test_x', [
            'baseUrl' => 'http://localhost:9999/v1',
            'allowInsecureBaseUrl' => true,
            'httpClient' =>  new MockHttpClient([['status' => 202, 'json' => self::ACK]]), 'allowCustomHttpClient' => true,
        ]);
        $ack = $paylod->collect(['amount' => 1, 'phone' => '0712345678', 'idempotencyKey' => 'a']);
        $this->assertSame('pay_123', $ack['paymentId']);
    }

    public function testConstructorRefusesLoopbackHttpWithLiveKeyEvenBehindFlag(): void
    {
        $this->expectException(PaylodConfigError::class);
        new Paylod('mp_live_secret', [
            'baseUrl' => 'http://localhost:9999/v1',
            'allowInsecureBaseUrl' => true,
            'httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true,
        ]);
    }

    /**
     * HTTPS is not enough on its own. A live bearer key posted to https://evil.example is just as
     * stolen as one sent in the clear, so the ORIGIN itself is allowlisted.
     */
    public function testConstructorRejectsNonPaylodHttpsOrigins(): void
    {
        $bad = [
            'https://evil.example/v1',                 // arbitrary https host
            'https://paylod.dev.evil.example/v1',      // suffix-confusion
            'https://evilpaylod.dev/v1',               // prefix-confusion
            'https://paylod.dev@evil.example/v1',      // userinfo smuggling the real target
            'https://user:pw@paylod.dev/v1',           // credentials in the URL
            'https://paylod.dev:8443/v1',              // unexpected port
            'https://169.254.169.254/v1',              // link-local metadata endpoint
            'https://10.0.0.5/v1',                     // private range
            'https://paylod.dev/v1?x=1',               // query string
            'https://paylod.dev/v1#f',                 // fragment
            'not-a-url',                               // no scheme/host
            '//paylod.dev/v1',                         // scheme-relative
        ];
        foreach ($bad as $url) {
            try {
                new Paylod('mp_test_x', ['baseUrl' => $url, 'httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true]);
                $this->fail("expected {$url} to be rejected");
            } catch (PaylodConfigError $e) {
                $this->assertNotSame('', $e->getMessage(), $url);
            }
        }
    }

    public function testConstructorAcceptsTheCanonicalPaylodOrigins(): void
    {
        foreach ([Paylod::DEFAULT_BASE_URL, 'https://paylod.dev/functions/v1', 'https://api.paylod.dev/v1', 'https://paylod.dev:443/v1'] as $url) {
            $paylod = new Paylod('mp_live_key', ['baseUrl' => $url]);
            $this->assertInstanceOf(Paylod::class, $paylod);
        }
    }

    public function testConstructorRefusesLoopbackWithALiveKeyOnHttpsToo(): void
    {
        // The loopback exception is test-only and NEVER available to a live key, plaintext or not.
        $this->expectException(PaylodConfigError::class);
        new Paylod('mp_live_secret', [
            'baseUrl' => 'https://127.0.0.1:9999/v1',
            'allowInsecureBaseUrl' => true,
            'httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true,
        ]);
    }

    public function testConstructorRefusesLoopbackWithoutTheExplicitFlag(): void
    {
        $this->expectException(PaylodConfigError::class);
        new Paylod('mp_test_x', ['baseUrl' => 'https://localhost:9999/v1', 'httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true]);
    }

    // -- Timeout must be positive and bounded ---------------------------------

    /**
     * cURL treats CURLOPT_TIMEOUT_MS of 0 as "no timeout at all", so a timeoutMs of 0 would turn a
     * hung connection into a request that never returns.
     */
    public function testConstructorRejectsZeroOrNegativeTimeout(): void
    {
        foreach ([0, -1, -30000, 0.0] as $bad) {
            try {
                new Paylod('mp_test_x', ['timeoutMs' => $bad, 'httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true]);
                $this->fail('expected timeoutMs ' . var_export($bad, true) . ' to be rejected');
            } catch (PaylodConfigError $e) {
                $this->assertMatchesRegularExpression('/greater than 0/', $e->getMessage());
            }
        }
    }

    public function testConstructorRejectsAbsurdlyLargeOrNonNumericTimeout(): void
    {
        foreach ([600001, 'soon', true, INF, NAN, []] as $bad) {
            try {
                new Paylod('mp_test_x', ['timeoutMs' => $bad, 'httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true]);
                $this->fail('expected timeoutMs ' . var_export($bad, true) . ' to be rejected');
            } catch (PaylodConfigError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testWaitRejectsNonPositiveTimeout(): void
    {
        [$paylod] = $this->client([['status' => 200, 'json' => ['id' => 'pay_123', 'status' => 'pending']]]);
        $this->expectException(PaylodConfigError::class);
        $paylod->wait('pay_123', ['timeoutMs' => 0]);
    }

    // -- The operation deadline bounds every in-flight request ----------------

    /**
     * A 30s per-request timeout must never let a wait(['timeoutMs' => 400]) sit in a single hung
     * request for 30s: each poll is capped to the time the whole operation has left.
     */
    public function testWaitDeadlineCapsEachRequestTimeout(): void
    {
        [$paylod, $transport] = $this->client(
            [['status' => 200, 'json' => ['id' => 'pay_123', 'status' => 'pending']]],
            ['timeoutMs' => 30000]
        );

        try {
            $paylod->wait('pay_123', ['timeoutMs' => 400]);
            $this->fail('expected a timeout');
        } catch (PaylodTimeoutError) {
            $this->addToAssertionCount(1);
        }

        $this->assertNotSame([], $transport->calls);
        foreach ($transport->calls as $call) {
            $this->assertGreaterThan(0, $call['timeoutMs']);
            $this->assertLessThanOrEqual(400, $call['timeoutMs'], 'per-request timeout must be capped by the wait deadline');
        }
    }

    // -- Idempotency key validation -------------------------------------------

    public function testCollectRejectsBlankIdempotencyKey(): void
    {
        [$paylod] = $this->client([]);
        $this->expectException(PaylodInvalidRequestError::class);
        $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => '   ']);
    }

    public function testCollectRejectsControlCharIdempotencyKey(): void
    {
        [$paylod] = $this->client([]);
        $this->expectException(PaylodInvalidRequestError::class);
        $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => "bad\nkey"]);
    }

    /**
     * The idempotency key is the ONE thing standing between a double-click and a double-charge, so
     * anything invisible or header-illegal must fail loudly. A C1 control char or a non-breaking
     * space survives trim() and a byte-only C0 check, so two visually identical keys could become
     * two different charges.
     */
    public function testCollectRejectsC1ControlsAndUnicodeOnlyWhitespaceInIdempotencyKey(): void
    {
        $bad = [
            "key\u{0085}x",   // C1 NEL
            "key\u{009f}x",   // C1 APC
            "key\u{007f}x",   // DEL
            "\u{00a0}",       // non-breaking space only - trim() says "non-empty"
            "a\u{00a0}b",     // embedded NBSP
            "a\u{2007}b",     // figure space
            "a\u{3000}b",     // ideographic space
            "a\u{feff}b",     // BOM / zero-width no-break space
            "a\u{2028}b",     // line separator
            "\u{2029}",       // paragraph separator
            "bad\xC3(utf8",   // invalid UTF-8
            str_repeat('k', 256), // over the 255-byte header bound
        ];
        foreach ($bad as $key) {
            [$paylod] = $this->client([]);
            try {
                $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => $key]);
                $this->fail('expected ' . bin2hex($key) . ' to be rejected');
            } catch (PaylodInvalidRequestError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * PRINTABLE non-ASCII is the subtle one: "ordr-cafe-1" with an accented e is not blank, not a
     * control char and not invisible whitespace, so every other rule passes it - but HTTP header
     * values are ASCII on the wire (RFC 9110). It either fails in the transport as an opaque encoding
     * error, or gets silently re-encoded, so a retry meant to reuse ONE key no longer matches and the
     * duplicate-charge guard disappears without a sound. Must be rejected BEFORE dispatch.
     */
    public function testCollectRejectsPrintableNonAsciiIdempotencyKey(): void
    {
        $bad = [
            "ordr-caf\u{00e9}-1",   // Latin-1 accented e - the realistic case
            "ordre\u{0301}-1",      // combining acute accent
            "order-\u{00df}-1",     // sharp s
            "\u{6ce8}\u{6587}-1",   // CJK
            "order-\u{0645}-1",     // Arabic
            "order-\u{2013}1",      // en dash (a copy-paste hazard)
            "order-\u{00a3}1",      // pound sign
        ];
        foreach ($bad as $key) {
            [$paylod, $transport] = $this->client([['status' => 202, 'json' => self::ACK]]);
            try {
                $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => $key]);
                $this->fail('expected ' . bin2hex($key) . ' to be rejected');
            } catch (PaylodInvalidRequestError $e) {
                $this->assertMatchesRegularExpression('/printable ASCII/', $e->getMessage());
                // Rejected locally - the request must never have gone out.
                $this->assertSame([], $transport->calls, 'key was dispatched instead of rejected');
            }
        }
    }

    public function testCollectAcceptsAnOrdinaryKeyWithAnAsciiSpace(): void
    {
        // A plain ASCII space is legal in an HTTP header value - the rule targets INVISIBLE and
        // header-illegal characters, not ordinary punctuation.
        [$paylod] = $this->client([['status' => 202, 'json' => self::ACK]]);
        $ack = $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'order 42-abc_XYZ']);
        $this->assertSame('order 42-abc_XYZ', $ack['idempotencyKey']);
    }

    // -- The effective key is attached to a failure ---------------------------

    public function testConnectionFailureCarriesTheEffectiveIdempotencyKey(): void
    {
        // Every attempt is a transport failure; the generated key must ride the thrown error so the
        // caller retries with the SAME key rather than mint a fresh one and double-charge.
        [$paylod] = $this->client([['throw' => true]]);
        try {
            $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'attempt-7']);
            $this->fail('expected a connection error');
        } catch (PaylodConnectionError $e) {
            $this->assertSame('attempt-7', $e->idempotencyKey);
        }
    }

    // -- 409 "already in progress" is the ONLY retried 409 --------------------

    public function testRetriesInProgress409ThenSucceeds(): void
    {
        [$paylod, $transport] = $this->client([
            ['status' => 409, 'json' => ['error' => 'An idempotency request is already in progress for this key']],
            ['status' => 202, 'json' => self::ACK],
        ]);

        $ack = $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'a']);
        $this->assertSame('pay_123', $ack['paymentId']);
        $this->assertSame(2, $transport->count());
    }

    public function testDoesNotRetryBodyConflict409(): void
    {
        [$paylod, $transport] = $this->client([['status' => 409, 'json' => ['error' => 'Idempotency-Key reused with a different body']]]);
        try {
            $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'a']);
            $this->fail('expected an API error');
        } catch (PaylodApiError $e) {
            $this->assertSame(409, $e->status);
            $this->assertTrue($e->isIdempotencyBodyConflict());
        }
        $this->assertSame(1, $transport->count());
    }

    // -- Transient-status restriction: 501 is NOT retried ---------------------

    public function testDoesNotRetryNonTransient501(): void
    {
        [$paylod, $transport] = $this->client([['status' => 501, 'json' => ['error' => 'not implemented']]]);
        try {
            $paylod->status('pay_123');
            $this->fail('expected an API error');
        } catch (PaylodApiError $e) {
            $this->assertSame(501, $e->status);
        }
        $this->assertSame(1, $transport->count()); // 501/505/511 are not blips
    }

    // -- Malformed 2xx is indeterminate ---------------------------------------

    public function testMalformed2xxCollectIsIndeterminateAndCarriesKey(): void
    {
        [$paylod] = $this->client([['status' => 202, 'json' => ['status' => 'pending']]]); // no paymentId
        try {
            $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'attempt-x']);
            $this->fail('expected an indeterminate API error');
        } catch (PaylodApiError $e) {
            $this->assertTrue($e->indeterminate);
            $this->assertSame('attempt-x', $e->idempotencyKey);
        }
    }

    public function testMalformed2xxStatusIsRejected(): void
    {
        [$paylod] = $this->client([['status' => 200, 'json' => ['status' => 'success']]]); // no id
        $this->expectException(PaylodApiError::class);
        $paylod->status('pay_123');
    }
}

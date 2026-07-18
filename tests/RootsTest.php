<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodConfigError;
use Paylod\Exceptions\PaylodConnectionError;
use Paylod\Exceptions\PaylodSignatureVerificationError;
use Paylod\Http\HttpClient;
use Paylod\Http\Transport;
use Paylod\Paylod;
use Paylod\Tests\Support\MockHttpClient;
use Paylod\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * The two ARCHITECTURAL roots, and the PHP-specific findings that sit alongside them.
 *
 * Every test here is referenced by scripts/non-vacuity.php, which reverts the protection in source
 * and requires the named test to FAIL. A test in this file that cannot fail is worse than no test.
 */
final class RootsTest extends TestCase
{
    private const ACK = ['paymentId' => 'pay_123', 'status' => 'pending', 'checkoutRequestId' => 'ws_CO_1'];

    /**
     * @param list<array<string,mixed>> $steps
     * @return array{0:Paylod,1:MockHttpClient}
     */
    private function client(array $steps, array $options = [], string $key = 'mp_test_x'): array
    {
        $http = new MockHttpClient($steps);

        return [
            new Paylod($key, array_merge(
                ['httpClient' => $http, 'allowCustomHttpClient' => true],
                $options,
            )),
            $http,
        ];
    }

    // == ROOT 1 - the transport owns the credential ===========================================

    /**
     * ROOT 1 - a custom HTTP client is a gated, test-only seam.
     *
     * Supplying one without the explicit opt-in is refused, because it receives the Authorization
     * header on every request and decides for itself whether to follow a redirect.
     */
    public function testRootOneACustomHttpClientIsAGatedTestOnlySeam(): void
    {
        $this->expectException(PaylodConfigError::class);
        $this->expectExceptionMessageMatches('/allowCustomHttpClient/');
        new Paylod('mp_test_x', ['httpClient' => new MockHttpClient([])]);
    }

    /** ROOT 1 - refuses an injected HTTP client with a LIVE key, opt-in or no opt-in. */
    public function testRootOneRefusesAnInjectedHttpClientWithALiveKey(): void
    {
        $this->expectException(PaylodConfigError::class);
        $this->expectExceptionMessageMatches('/mp_live_/');
        new Paylod('mp_live_key', ['httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true]);
    }

    /**
     * The SAME rule, asserted directly against Transport with the client's gate bypassed entirely.
     * The client is not the only thing standing between a production credential and caller code:
     * the transport refuses on its own terms, so a future caller cannot reintroduce the hole.
     */
    public function testRootOneTheTransportItselfRefusesALiveKeyWithACustomClient(): void
    {
        $this->expectException(PaylodConfigError::class);
        $this->expectExceptionMessageMatches('/bearer credential/');
        new Transport(
            static fn (): string => 'mp_live_key',
            'https://paylod.dev/functions/v1',
            static fn (string $s): string => $s,
            new MockHttpClient([]),
        );
    }

    /** ROOT 1 - the caller never supplies headers, and never sees the credential. */
    public function testRootOneTheCallerNeverConstructsTheCredentialedHeaders(): void
    {
        [$paylod, $http] = $this->client([['status' => 202, 'json' => self::ACK]]);
        $paylod->collect(['amount' => 1, 'phone' => '0712345678', 'idempotencyKey' => 'k1']);

        // The transport built these, from a key the caller handed to the constructor and can no
        // longer reach. There is no public surface on Paylod that returns it.
        $this->assertSame('Bearer mp_test_x', $http->calls[0]['headers']['authorization']);
        $this->assertSame('k1', $http->calls[0]['headers']['idempotency-key']);
        $this->assertSame('https://paylod.dev/functions/v1/collect', $http->calls[0]['url']);
        // The credential is not reachable as a value on the object either: casting to an array
        // exposes private properties, and none of them is the key.
        $this->assertNotContains('mp_test_x', array_values((array) $paylod));
    }

    /** ROOT 1 - REFUSES A 3xx rather than following it. */
    public function testRootOneRefusesARedirectStatus(): void
    {
        [$paylod] = $this->client([['status' => 302, 'json' => [], 'headers' => ['location' => 'https://evil.example/']]]);

        $this->expectException(PaylodConnectionError::class);
        $this->expectExceptionMessageMatches('/redirect/i');
        $paylod->status('pay_1');
    }

    /**
     * ROOT 1 - REFUSES A 2xx THAT THE HTTP CLIENT REACHED BY FOLLOWING A REDIRECT.
     *
     * By this point the credential has already been replayed, so this is a DETECTION rather than a
     * prevention - it exists so the failure is loud and the caller learns their key is burned.
     */
    public function testRootOneRefusesATwoHundredReachedByFollowingARedirect(): void
    {
        [$paylod] = $this->client([[
            'status' => 200,
            'json' => ['id' => 'pay_1', 'status' => 'pending'],
            'redirectCount' => 1,
        ]]);

        $this->expectException(PaylodConnectionError::class);
        $this->expectExceptionMessageMatches('/FOLLOWED/');
        $paylod->status('pay_1');
    }

    /** ROOT 1 - refuses a 2xx whose final URL is off the pinned origin. */
    public function testRootOneRefusesAResponseFromOffThePinnedOrigin(): void
    {
        [$paylod] = $this->client([[
            'status' => 200,
            'json' => ['id' => 'pay_1', 'status' => 'pending'],
            'effectiveUrl' => 'https://evil.example/functions/v1/status/pay_1',
        ]]);

        $this->expectException(PaylodConnectionError::class);
        $this->expectExceptionMessageMatches('/pinned paylod origin/');
        $paylod->status('pay_1');
    }

    /** A response from the pinned origin, with the default port written explicitly, is fine. */
    public function testRootOneAcceptsTheSameOriginWithAnExplicitDefaultPort(): void
    {
        [$paylod] = $this->client([[
            'status' => 200,
            'json' => ['id' => 'pay_1', 'status' => 'pending'],
            'effectiveUrl' => 'https://paylod.dev:443/functions/v1/status/pay_1',
        ]]);

        $this->assertSame('pending', $paylod->status('pay_1')['status']);
    }

    // == ROOT 2 - the semantic model, at the boundaries ========================================

    /**
     * ROOT 2 - ID BINDING (law L1). A body describing a DIFFERENT payment answers a different
     * question, so it tells us nothing about the one we asked about.
     */
    public function testRootTwoIdBindingRejectsABodyDescribingADifferentPayment(): void
    {
        [$paylod] = $this->client([[
            'status' => 200,
            'json' => ['id' => 'pay_OTHER', 'status' => 'success', 'mpesaReceipt' => 'SFF6XYZ123', 'resultCode' => 0],
        ]]);

        try {
            $paylod->status('pay_1');
            $this->fail('a wrong-payment body must never be evaluated');
        } catch (PaylodApiError $e) {
            $this->assertTrue($e->indeterminate);
            $this->assertStringContainsString('different question', $e->getMessage());
        }
    }

    /** ROOT 2 - a collect ack requires HTTP 202. A bare 200 is not a dispatched charge. */
    public function testRootTwoACollectAckRequiresHttpTwoHundredAndTwo(): void
    {
        [$paylod] = $this->client([['status' => 200, 'json' => self::ACK]]);

        try {
            $paylod->collect(['amount' => 1, 'phone' => '0712345678', 'idempotencyKey' => 'k1']);
            $this->fail('a 200 is not a collect acknowledgement');
        } catch (PaylodApiError $e) {
            $this->assertTrue($e->indeterminate);
            $this->assertSame('k1', $e->idempotencyKey);
            $this->assertStringContainsString('expected 202', $e->getMessage());
        }
    }

    /** ROOT 2 - a collect ack must carry the LITERAL status "pending". */
    public function testRootTwoACollectAckRequiresTheLiteralPendingStatus(): void
    {
        [$paylod] = $this->client([['status' => 202, 'json' => ['paymentId' => 'p', 'checkoutRequestId' => 'c', 'status' => 'success']]]);

        $this->expectException(PaylodApiError::class);
        $this->expectExceptionMessageMatches('/expected the literal "pending"/');
        $paylod->collect(['amount' => 1, 'phone' => '0712345678', 'idempotencyKey' => 'k1']);
    }

    /**
     * The RENDERING half of the same rule. judge() saying "indeterminate" is only useful if
     * PaymentOutcome renders it as one - this is the exact shape that used to come back as
     * `cancelled, retryable: true` and invite a second charge for a payment carrying a receipt.
     */
    public function testChangedAFailedRowCarryingAReceiptIsNeverRetryable(): void
    {
        [$paylod] = $this->client([[
            'status' => 200,
            'json' => [
                'id' => 'pay_1', 'status' => 'failed',
                'mpesaReceipt' => 'SFF6XYZ123', 'resultCode' => 1032,
            ],
        ]]);

        $outcome = $paylod->check('pay_1');
        $this->assertFalse($outcome->retryable, 'THE double-charge bug: never invite a second charge');
        $this->assertFalse($outcome->paid);
        $this->assertSame('pending', $outcome->status, 'indeterminate renders as pending so wait() keeps polling');
    }

    // == The PHP-specific findings =============================================================

    /**
     * var_export() ignores __debugInfo() entirely and walks the REAL properties, so it exposed both
     * the API key and the webhook secret. Holding them in closures is what closes that.
     */
    public function testVarExportNeverExposesTheApiKeyOrTheWebhookSecret(): void
    {
        [$paylod] = $this->client([], ['webhookSecret' => 'whsec_super_secret_value']);

        $exported = var_export($paylod, true);
        $this->assertStringNotContainsString('mp_test_x', $exported);
        $this->assertStringNotContainsString('whsec_super_secret_value', $exported);

        // And the dump functions, which __debugInfo() covers, stay covered.
        foreach ([print_r($paylod, true), self::varDump($paylod)] as $dumped) {
            $this->assertStringNotContainsString('mp_test_x', $dumped);
            $this->assertStringNotContainsString('whsec_super_secret_value', $dumped);
        }
    }

    private static function varDump(object $o): string
    {
        ob_start();
        var_dump($o);

        return (string) ob_get_clean();
    }

    /**
     * A NON-PAYLOD throwable used to escape collect() bare - no idempotency key, no indeterminate
     * classification - so the caller's natural retry minted a FRESH key and charged twice.
     */
    public function testANonPaylodThrowableFromCollectIsWrappedWithTheKeyAndMarkedIndeterminate(): void
    {
        $exploding = new class implements HttpClient {
            public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
            {
                throw new \RuntimeException('the stubbed client exploded');
            }
        };

        $paylod = new Paylod('mp_test_x', ['httpClient' => $exploding, 'allowCustomHttpClient' => true]);

        try {
            $paylod->collect(['amount' => 1, 'phone' => '0712345678', 'idempotencyKey' => 'k-boom']);
            $this->fail('expected the throwable to be wrapped');
        } catch (PaylodApiError $e) {
            $this->assertTrue($e->indeterminate, 'the charge state is unknown, so it must say so');
            $this->assertSame('k-boom', $e->idempotencyKey, 'without the key the caller double-charges');
            // The ORIGINAL throwable is deliberately NOT chained. Keeping it as `previous` kept an
            // un-redacted copy of its message, its stack trace and (with the development default
            // zend.exception_ignore_args=0) its recorded call arguments reachable - and PHP's
            // default __toString() walks the chain and prints all of it. What survives is a
            // sanitized surrogate naming the original's class and its redacted message.
            $previous = $e->getPrevious();
            $this->assertNotInstanceOf(\RuntimeException::class, $previous);
            $this->assertInstanceOf(\Paylod\Exceptions\PaylodConnectionError::class, $previous);
            $this->assertStringContainsString('RuntimeException', (string) $previous?->getMessage());
            $this->assertStringContainsString('the stubbed client exploded', (string) $previous?->getMessage());
        }
    }

    /** The key must not survive into the wrapped message either. */
    public function testTheWrappedCollectErrorIsRedacted(): void
    {
        $leaky = new class implements HttpClient {
            public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
            {
                throw new \RuntimeException('boom while sending mp_test_x');
            }
        };

        $paylod = new Paylod('mp_test_x', ['httpClient' => $leaky, 'allowCustomHttpClient' => true]);

        try {
            $paylod->collect(['amount' => 1, 'phone' => '0712345678', 'idempotencyKey' => 'k']);
            $this->fail('expected a wrapped error');
        } catch (PaylodApiError $e) {
            $this->assertStringNotContainsString('mp_test_x', $e->getMessage());
        }
    }

    // == The webhook, through the same model ===================================================

    /** @param array<string,mixed> $data */
    private static function signed(string $type, array $data): array
    {
        $body = json_encode(['type' => $type, 'created' => 1700000000, 'data' => $data]);

        return [$body, Webhook::sign($body, 'whsec_t', 1700000000)];
    }

    /** REJECTS a signed payment.success with NO evidence - a signature is not proof of payment. */
    public function testWebhookRejectsASignedPaymentSuccessWithNoEvidence(): void
    {
        [$body, $sig] = self::signed('payment.success', [
            'paymentId' => 'pay_1', 'status' => 'success', 'amount' => 100,
        ]);

        $this->expectException(PaylodSignatureVerificationError::class);
        $this->expectExceptionMessageMatches('/does not prove one/');
        Webhook::verify($body, $sig, 'whsec_t', 300, 1700000000);
    }

    /** A signed success WITH evidence is delivered. The rule requires evidence, not a receipt. */
    public function testWebhookAcceptsASignedSuccessBackedByResultCodeZeroAlone(): void
    {
        [$body, $sig] = self::signed('payment.success', [
            'paymentId' => 'pay_1', 'status' => 'success', 'amount' => 100, 'resultCode' => 0,
        ]);

        $event = Webhook::verify($body, $sig, 'whsec_t', 300, 1700000000);
        $this->assertSame('pay_1', $event['data']['paymentId']);
    }

    /** Rejects a signed success whose data.status contradicts the event type. */
    public function testWebhookRejectsASignedSuccessWhoseDataStatusContradictsTheType(): void
    {
        [$body, $sig] = self::signed('payment.success', [
            'paymentId' => 'pay_1', 'status' => 'failed', 'amount' => 100, 'resultCode' => 0,
        ]);

        $this->expectException(PaylodSignatureVerificationError::class);
        $this->expectExceptionMessageMatches('/contradicts itself/');
        Webhook::verify($body, $sig, 'whsec_t', 300, 1700000000);
    }

    /**
     * A failure notice carrying a RECEIPT must not be delivered as a settled failure - that is the
     * double-charge shape, arriving through the channel that most often triggers a refund or retry.
     */
    public function testWebhookRejectsAFailureNoticeCarryingAReceipt(): void
    {
        [$body, $sig] = self::signed('payment.failed', [
            'paymentId' => 'pay_1', 'status' => 'failed', 'amount' => 100,
            'mpesaReceipt' => 'SFF6XYZ123', 'resultCode' => 1032,
        ]);

        $this->expectException(PaylodSignatureVerificationError::class);
        $this->expectExceptionMessageMatches('/does not support that/');
        Webhook::verify($body, $sig, 'whsec_t', 300, 1700000000);
    }
}

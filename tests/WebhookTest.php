<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\DarajaCatalog;
use Paylod\Exceptions\PaylodSignatureVerificationError;
use Paylod\Paylod;
use Paylod\Tests\Support\MockHttpClient;
use Paylod\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    /** @return array<string,mixed> */
    private static function event(): array
    {
        return [
            'type' => 'payment.success',
            'created' => 1700000000,
            'data' => [
                'paymentId' => 'pay_123',
                'applicationId' => 'app_1',
                'env' => 'sandbox',
                'status' => 'success',
                'amount' => 100,
                'phone' => '254712345678',
                'accountRef' => 'order-42',
                'mpesaReceipt' => 'SFF6XYZ123',
                'checkoutRequestId' => 'ws_CO_0001',
                'resultCode' => 0,
                'resultDesc' => 'The service request is processed successfully.',
                'decoded' => null,
            ],
        ];
    }

    private static function raw(): string
    {
        return json_encode(self::event());
    }

    private static function now(): int
    {
        return 1700000000;
    }

    private function client(): Paylod
    {
        return new Paylod('mp_test_x', [
            'webhookSecret' => self::SECRET,
            'httpClient' => new MockHttpClient([]), 'allowCustomHttpClient' => true,
        ]);
    }

    public function testProducesExactSchemeInHeader(): void
    {
        $header = Webhook::sign(self::raw(), self::SECRET, self::now());
        $expected = hash_hmac('sha256', self::now() . '.' . self::raw(), self::SECRET);
        $this->assertSame('t=' . self::now() . ',v1=' . $expected, $header);
    }

    public function testSignatureHeaderName(): void
    {
        $this->assertSame('x-webhook-signature', Webhook::SIGNATURE_HEADER);
    }

    /**
     * SHARED GOLDEN VECTOR - the SAME secret+timestamp+body+expected-hex is pinned, byte-for-byte,
     * in paylod-sdk (Node) and paylod-cli, and mirrors the backend signer. If any signing/verifying
     * impl drifts, its copy of this vector fails. DO NOT edit these literals to "fix" a failure.
     */
    public function testMatchesSharedGoldenVector(): void
    {
        $goldenSecret = 'whsec_golden_vector_v1';
        $goldenT = 1700000000;
        $goldenBody = '{"type":"payment.success","created":1700000000,"data":{"paymentId":"pay_golden","amount":100,"phone":"254712345678"}}';
        $goldenHeader = 't=1700000000,v1=3afe38e4c11734c84fad70dd16bbaeec6057ca998236f253be6bfa09ad2c2eb7';

        $this->assertSame($goldenHeader, Webhook::sign($goldenBody, $goldenSecret, $goldenT));

        // And the verifier accepts its own signer's golden output. The fixture pins the clock via
        // $nowSec while keeping a NORMAL positive window - the sanctioned way to verify an ancient
        // fixture. Replay protection is never switched off, not even here.
        // The vector pins the SIGNING SCHEME. Its body is a minimal signing fixture rather than a
        // representative event, so it is verified at the SIGNATURE layer - verify() additionally
        // enforces the event schema and the semantic model, which are covered by their own tests
        // instead of by editing these cross-repo-pinned literals.
        $result = Webhook::verifySignature($goldenBody, $goldenHeader, $goldenSecret, 300, $goldenT);
        $this->assertTrue($result['signatureValid']);
        // The signature layer establishes ORIGIN ONLY, and the result says so in its own keys.
        $this->assertFalse($result['actionable']);
        $this->assertSame('pay_golden', $result['unverifiedEvent']['data']['paymentId']);

        // The boolean convenience form agrees.
        $this->assertTrue(
            Webhook::isValidSignatureOnlyNotActionable($goldenBody, $goldenHeader, $goldenSecret, 300, $goldenT)
        );
    }

    public function testAcceptsValidSignatureAndReturnsEvent(): void
    {
        $event = Webhook::verify(self::raw(), Webhook::sign(self::raw(), self::SECRET, self::now()), self::SECRET, 300, self::now());
        $this->assertSame('payment.success', $event['type']);
        $this->assertSame('SFF6XYZ123', $event['data']['mpesaReceipt']);
    }

    public function testRejectsTamperedBody(): void
    {
        $header = Webhook::sign(self::raw(), self::SECRET, self::now());
        $tampered = str_replace('"amount":100', '"amount":1', self::raw());
        $this->assertNotSame(self::raw(), $tampered);

        try {
            Webhook::verify($tampered, $header, self::SECRET, 300, self::now());
            $this->fail('expected a signature error');
        } catch (PaylodSignatureVerificationError $e) {
            $this->assertSame('no_match', $e->reason);
        }
    }

    public function testRejectsWrongSecret(): void
    {
        $header = Webhook::sign(self::raw(), 'whsec_attacker', self::now());
        $this->expectException(PaylodSignatureVerificationError::class);
        $this->expectExceptionMessageMatches('/does not match/');
        Webhook::verify(self::raw(), $header, self::SECRET, 300, self::now());
    }

    public function testRejectsStaleTimestamp(): void
    {
        $header = Webhook::sign(self::raw(), self::SECRET, self::now());
        try {
            Webhook::verify(self::raw(), $header, self::SECRET, 300, self::now() + 301);
            $this->fail('expected a stale timestamp error');
        } catch (PaylodSignatureVerificationError $e) {
            $this->assertSame('stale_timestamp', $e->reason);
        }
    }

    public function testRejectsFutureDatedTimestamp(): void
    {
        $header = Webhook::sign(self::raw(), self::SECRET, self::now() + 3600);
        $this->expectException(PaylodSignatureVerificationError::class);
        $this->expectExceptionMessageMatches('/tolerance/');
        Webhook::verify(self::raw(), $header, self::SECRET, 300, self::now());
    }

    public function testAcceptsTimestampJustInsideTolerance(): void
    {
        $header = Webhook::sign(self::raw(), self::SECRET, self::now());
        $event = Webhook::verify(self::raw(), $header, self::SECRET, 300, self::now() + 299);
        $this->assertSame('pay_123', $event['data']['paymentId']);
    }

    public function testRejectsMissingHeader(): void
    {
        $this->expectException(PaylodSignatureVerificationError::class);
        $this->expectExceptionMessageMatches('/Missing x-webhook-signature/');
        Webhook::verify(self::raw(), null, self::SECRET);
    }

    public function testRejectsMalformedHeader(): void
    {
        $this->expectException(PaylodSignatureVerificationError::class);
        $this->expectExceptionMessageMatches('/Malformed/');
        Webhook::verify(self::raw(), 'deadbeef', self::SECRET);
    }

    public function testRefusesWhenNoSecretConfigured(): void
    {
        $this->expectException(PaylodSignatureVerificationError::class);
        $this->expectExceptionMessageMatches('/signing secret/');
        Webhook::verify(self::raw(), Webhook::sign(self::raw(), self::SECRET, self::now()), '');
    }

    public function testRejectsCorrectlySignedNonJson(): void
    {
        $raw = 'not json at all';
        try {
            Webhook::verify($raw, Webhook::sign($raw, self::SECRET, self::now()), self::SECRET, 300, self::now());
            $this->fail('expected invalid_payload');
        } catch (PaylodSignatureVerificationError $e) {
            $this->assertSame('invalid_payload', $e->reason);
        }
    }

    public function testRejectsCorrectlySignedNonEvent(): void
    {
        $raw = json_encode(['hello' => 'world']);
        $this->expectException(PaylodSignatureVerificationError::class);
        $this->expectExceptionMessageMatches('/not a paylod event/');
        Webhook::verify($raw, Webhook::sign($raw, self::SECRET, self::now()), self::SECRET, 300, self::now());
    }

    public function testRejectsNonNumericTimestamp(): void
    {
        $good = Webhook::sign(self::raw(), self::SECRET, self::now());
        $bad = preg_replace('/^t=\d+/', 't=abc', $good);
        $this->expectException(PaylodSignatureVerificationError::class);
        $this->expectExceptionMessageMatches('/not a number/');
        Webhook::verify(self::raw(), $bad, self::SECRET);
    }

    public function testVerifiesAncientFixtureByPinningClockWithAPositiveWindow(): void
    {
        // The supported way to verify a pinned fixture: keep a normal tolerance, inject the fixture's
        // own timestamp as the clock. Freshness stays enforced, the result stays deterministic.
        $header = Webhook::sign(self::raw(), self::SECRET, 1_000_000); // ancient
        $event = Webhook::verify(self::raw(), $header, self::SECRET, 300, 1_000_000);
        $this->assertSame('pay_123', $event['data']['paymentId']);

        // ...and the pinned clock still enforces the window, it does not bypass it.
        try {
            Webhook::verify(self::raw(), $header, self::SECRET, 300, 1_000_000 + 301);
            $this->fail('expected a stale_timestamp error');
        } catch (PaylodSignatureVerificationError $e) {
            $this->assertSame('stale_timestamp', $e->reason);
        }
    }

    /**
     * A zero / negative / non-finite tolerance disables replay protection outright. It is refused
     * UNCONDITIONALLY - injecting a fixed $nowSec no longer buys an exemption, because a pinned
     * fixture verifies perfectly well with a normal positive window (see the test above).
     */
    public function testRefusesNonPositiveToleranceEvenWithAFixedClock(): void
    {
        $header = Webhook::sign(self::raw(), self::SECRET, self::now());
        foreach ([0, -5, 0.0, -0.5] as $bad) {
            foreach ([null, self::now()] as $now) {
                try {
                    Webhook::verify(self::raw(), $header, self::SECRET, $bad, $now);
                    $this->fail('expected an insecure_tolerance error for tolerance ' . var_export($bad, true));
                } catch (PaylodSignatureVerificationError $e) {
                    $this->assertSame('insecure_tolerance', $e->reason);
                }
            }
        }
        // The boolean form fails closed rather than reporting a valid signature.
        $this->assertFalse(Webhook::isValid(self::raw(), $header, self::SECRET, 0, self::now()));
    }

    public function testRefusesNonFiniteTolerance(): void
    {
        // NAN is the dangerous one: `abs($now - $t) > NAN` is FALSE, so every stale signature would
        // sail through a naive comparison. INF and a fractional window are refused too.
        $header = Webhook::sign(self::raw(), self::SECRET, self::now());
        foreach ([NAN, INF, -INF, 300.5] as $bad) {
            try {
                Webhook::verify(self::raw(), $header, self::SECRET, $bad, self::now());
                $this->fail('expected an insecure_tolerance error for ' . var_export($bad, true));
            } catch (PaylodSignatureVerificationError $e) {
                $this->assertSame('insecure_tolerance', $e->reason);
            }
        }
    }

    public function testValidatesTheInjectedClock(): void
    {
        // An unusable clock (0, negative, NaN) must not be silently accepted either - it would make
        // the freshness comparison meaningless in exactly the same way.
        $header = Webhook::sign(self::raw(), self::SECRET, self::now());
        foreach ([0, -1, NAN, INF] as $bad) {
            try {
                Webhook::verify(self::raw(), $header, self::SECRET, 300, $bad);
                $this->fail('expected an insecure_tolerance error for nowSec ' . var_export($bad, true));
            } catch (PaylodSignatureVerificationError $e) {
                $this->assertSame('insecure_tolerance', $e->reason);
            }
        }
    }

    /**
     * `t` is validated LEXICALLY - decimal digits only. PHP would otherwise coerce "1e3", "+1000" or
     * " 1000" to a number, so the value we freshness-check could differ from the text that was HMAC'd.
     */
    public function testRejectsNonDecimalTimestampForms(): void
    {
        $v1 = str_repeat('a', 64);
        // (Surrounding OWS is legal HTTP and is trimmed by the header parser, so it is not listed.)
        foreach (['1e3', '+1000', '0x3e8', '1_000', '1.0', '-1000', '0b1', '1e400'] as $bad) {
            try {
                Webhook::verify(self::raw(), "t={$bad},v1={$v1}", self::SECRET, 300, self::now());
                $this->fail("expected a malformed_signature error for t={$bad}");
            } catch (PaylodSignatureVerificationError $e) {
                $this->assertSame('malformed_signature', $e->reason, "t={$bad}");
            }
        }
    }

    public function testRejectsCommaCombinedTwoSignatureHeader(): void
    {
        // Two x-webhook-signature values joined by a comma: a forged pair appended after a real one.
        // Last-value-wins must NOT accept it.
        $goodV1 = explode('v1=', Webhook::sign(self::raw(), self::SECRET, self::now()))[1];
        $combined = 't=' . self::now() . ',v1=' . $goodV1 . ',t=9999999999,v1=' . str_repeat('0', 64);
        try {
            Webhook::verify(self::raw(), $combined, self::SECRET, 300, self::now());
            $this->fail('expected a malformed_signature error');
        } catch (PaylodSignatureVerificationError $e) {
            $this->assertSame('malformed_signature', $e->reason);
        }
    }

    public function testRejectsDuplicatedV1(): void
    {
        $goodV1 = explode('v1=', Webhook::sign(self::raw(), self::SECRET, self::now()))[1];
        $dup = 't=' . self::now() . ',v1=' . $goodV1 . ',v1=' . $goodV1;
        $this->expectException(PaylodSignatureVerificationError::class);
        $this->expectExceptionMessageMatches('/Malformed/');
        Webhook::verify(self::raw(), $dup, self::SECRET, 300, self::now());
    }

    public function testRejectsV1ThatIsNotSixtyFourLowercaseHex(): void
    {
        $t = self::now();
        $short = "t={$t},v1=deadbeef";
        $upper = 't=' . $t . ',v1=' . strtoupper(explode('v1=', Webhook::sign(self::raw(), self::SECRET, $t))[1]);
        foreach ([$short, $upper] as $bad) {
            try {
                Webhook::verify(self::raw(), $bad, self::SECRET, 300, $t);
                $this->fail('expected a malformed_signature error');
            } catch (PaylodSignatureVerificationError $e) {
                $this->assertSame('malformed_signature', $e->reason);
            }
        }
    }

    public function testReSerialisedBodyFails(): void
    {
        $spaced = json_encode(self::event(), JSON_PRETTY_PRINT);
        $header = Webhook::sign(self::raw(), self::SECRET, self::now()); // signed over COMPACT bytes
        $this->expectException(PaylodSignatureVerificationError::class);
        Webhook::verify($spaced, $header, self::SECRET, 300, self::now());
    }

    public function testInstanceVerifyWebhookReturnsBool(): void
    {
        $paylod = $this->client();
        $header = Webhook::sign(self::raw(), self::SECRET, time());
        $this->assertTrue($paylod->verifyWebhook(self::raw(), $header));
        $this->assertFalse($paylod->verifyWebhook(self::raw(), Webhook::sign(self::raw(), 'wrong', time())));
    }

    public function testInstanceParseWebhookReturnsEvent(): void
    {
        $paylod = $this->client();
        $header = Webhook::sign(self::raw(), self::SECRET, time());
        $event = $paylod->parseWebhook(self::raw(), $header);
        $this->assertSame('pay_123', $event['data']['paymentId']);
    }

    /**
     * The decoded block a handler reads must come from the LOCAL catalog.
     *
     * This test used to build `data.decoded` from the very same catalog it then asserted against, so
     * it passed whether the verifier re-derived the block or forwarded it untouched - it could not
     * tell the two apart, which is precisely the defect it was supposed to guard. It now supplies
     * DELIBERATELY FALSE derived fields and requires them to be overwritten.
     */
    public function testFailedEventDecodedIsReDerivedAndNeverTakenFromTheBody(): void
    {
        $failed = self::event();
        $failed['type'] = 'payment.failed';
        $failed['data']['status'] = 'failed';
        $failed['data']['mpesaReceipt'] = null;
        $failed['data']['resultCode'] = 1037;
        $failed['data']['resultDesc'] = 'DS timeout';
        // THE FORGERY. Server-supplied conclusions, all of them wrong, at both levels.
        $failed['data']['decoded'] = [
            'code' => '1037',
            'title' => 'Totally fine',
            'cause' => 'nothing happened',
            'fix' => 'charge again immediately',
            'category' => 'customer',
            'retryable' => true,
            'customerMessage' => 'Please pay again.',
        ];
        $failed['data']['retryable'] = true;
        $failed['data']['customerMessage'] = 'Please pay again.';
        $failed['data']['category'] = 'customer';
        $failed['retryable'] = true;
        $failed['decoded'] = ['retryable' => true];

        $raw = json_encode($failed);
        $event = Webhook::verify($raw, Webhook::sign($raw, self::SECRET, self::now()), self::SECRET, 300, self::now());

        // The catalog's real text for 1037, not the body's.
        $this->assertSame(
            'The M-Pesa prompt expired before it was answered. Check your phone is on, then try again '
            . 'and enter your PIN when it appears.',
            $event['data']['decoded']['customerMessage']
        );
        $this->assertNotSame('Totally fine', $event['data']['decoded']['title']);
        $this->assertSame($event['data']['decoded']['customerMessage'], $event['data']['customerMessage']);
        $this->assertSame($event['data']['decoded']['category'], $event['data']['category']);

        // And the ROOT-level forgeries are gone - a handler reading either level is safe.
        $this->assertArrayNotHasKey('retryable', $event);
        $this->assertArrayNotHasKey('decoded', $event);
    }

    /**
     * THE DOUBLE-CHARGE CASE. A signed `payment.failed` with a terminal, NON-retryable code and a
     * forged `retryable = true` must never reach the handler saying it is safe to charge again.
     */
    public function testAForgedRetryableOnATerminalFailureIsOverwritten(): void
    {
        $failed = self::event();
        $failed['type'] = 'payment.failed';
        $failed['data']['status'] = 'failed';
        $failed['data']['mpesaReceipt'] = null;
        $failed['data']['resultCode'] = 17; // an M-Pesa system error - NOT safe to charge again
        $failed['data']['resultDesc'] = 'Rule limited';
        $failed['data']['decoded'] = ['retryable' => true, 'category' => 'customer'];
        $failed['data']['retryable'] = true;

        $raw = json_encode($failed);
        $event = Webhook::verify($raw, Webhook::sign($raw, self::SECRET, self::now()), self::SECRET, 300, self::now());

        $this->assertFalse($event['data']['retryable'], 'a forged retryable survived verification');
        $this->assertFalse($event['data']['decoded']['retryable']);
        $this->assertSame(DarajaCatalog::decode(17)['retryable'], $event['data']['decoded']['retryable']);
    }

    /**
     * An ABSENT decoded block is SYNTHESIZED, never left missing: a handler doing
     * `$decoded['retryable'] ?? true` on a missing block reaches the same double-charge a forged
     * `true` does.
     */
    public function testAMissingDecodedBlockIsSynthesizedAndNonRetryable(): void
    {
        // A settlement whose RECEIPT is the evidence and which carries no result code - the one
        // real shape that reaches synthesizeDecoded() through verify(). (An evidence-free
        // `payment.failed` no longer gets this far at all: law L2' makes it INDETERMINATE, and the
        // coherence check refuses it. That is asserted separately.)
        $paidNoCode = self::event();
        $paidNoCode['type'] = 'payment.success';
        $paidNoCode['data']['status'] = 'success';
        $paidNoCode['data']['mpesaReceipt'] = 'SFF6XYZ123';
        $paidNoCode['data']['resultCode'] = null;
        $paidNoCode['data']['resultDesc'] = null;
        unset($paidNoCode['data']['decoded']);

        $raw = json_encode($paidNoCode);
        $event = Webhook::verify($raw, Webhook::sign($raw, self::SECRET, self::now()), self::SECRET, 300, self::now());

        $this->assertArrayHasKey('decoded', $event['data']);
        $this->assertFalse($event['data']['decoded']['retryable']);
        $this->assertFalse($event['data']['retryable']);
        $this->assertIsString($event['data']['decoded']['customerMessage']);
    }

    /**
     * A derived field on an UNKNOWN event type is unverifiable by construction, so it is stripped
     * rather than forwarded - a future field must not smuggle a retryability claim through a version
     * that cannot check it.
     */
    public function testDerivedFieldsAreStrippedFromUnknownEventTypes(): void
    {
        $other = ['type' => 'payout.settled', 'created' => self::now(), 'data' => [
            'payoutId' => 'po_1',
            'retryable' => true,
            'decoded' => ['retryable' => true],
        ]];

        $raw = json_encode($other);
        $event = Webhook::verify($raw, Webhook::sign($raw, self::SECRET, self::now()), self::SECRET, 300, self::now());

        $this->assertArrayNotHasKey('retryable', $event['data']);
        $this->assertArrayNotHasKey('decoded', $event['data']);
        // The non-derived payload is untouched.
        $this->assertSame('po_1', $event['data']['payoutId']);
    }
}

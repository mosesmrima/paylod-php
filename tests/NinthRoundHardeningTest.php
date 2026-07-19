<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\DarajaCatalog;
use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodCredentialCompromiseError;
use Paylod\Exceptions\PaylodInvalidRequestError;
use Paylod\Exceptions\PaylodSignatureVerificationError;
use Paylod\PaymentOutcome;
use Paylod\Paylod;
use Paylod\Semantics;
use Paylod\Support\JsonLexeme;
use Paylod\Support\Redact;
use Paylod\Support\Validate;
use Paylod\Tests\Support\MockHttpClient;
use Paylod\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * ROUND 9. The theme is one sentence:
 *
 *   TWO INDIVIDUALLY CORRECT COMPONENTS CAN COMPOSE INTO A MONEY BUG.
 *
 * The Critical was exactly that. `Redact` rewrote an echoed credential to `[redacted]`, which is
 * correct sanitisation. `Semantics::hasReceipt()` accepted any non-blank string as an M-Pesa
 * receipt, which was locally defensible. Composed, they produced a FORGED SUCCESS PATH: redacting a
 * credential turned it into proof of payment. Neither component was wrong on its own; they
 * disagreed about what the placeholder MEANS, and the money path sat in the gap.
 *
 * So the tests here are mostly about DISAGREEMENTS rather than about single functions.
 */
final class NinthRoundHardeningTest extends TestCase
{
    private const KEY = 'mp_test_ninthroundkey0123456789';
    private const SECRET = 'whsec_ninthroundsecret0123456789';

    // == THE CRITICAL ==========================================================================

    /**
     * THE COMPOSED BUG, END TO END. An API key echoed into `mpesaReceipt` is redacted to
     * `[redacted]`, and `[redacted]` used to pass as a receipt - so a `status: "success"` record
     * with NO result code came back `paid = true`.
     *
     * nv:r9-receipt-grammar-paid
     */
    public function testARedactedCredentialIsNotProofOfPayment(): void
    {
        $outcome = PaymentOutcome::fromPayment([
            'id' => 'pay_123',
            'status' => 'success',
            'mpesaReceipt' => Redact::PLACEHOLDER,
            'resultCode' => null,
        ]);

        $this->assertFalse($outcome->paid, 'a redaction marker must never establish payment');
        $this->assertSame('pending', $outcome->status);
        $this->assertNull($outcome->receipt);
        $this->assertFalse($outcome->retryable);
    }

    /**
     * The same body as a SIGNED `payment.success` webhook - the other surface the Critical reached.
     *
     * nv:r9-receipt-grammar-webhook
     */
    public function testARedactedCredentialIsNotProofOfPaymentOnTheWebhookPath(): void
    {
        $now = 1700000000;
        $raw = json_encode([
            'type' => 'payment.success',
            'created' => $now,
            'data' => [
                'paymentId' => 'pay_123',
                'status' => 'success',
                'mpesaReceipt' => Redact::PLACEHOLDER,
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);
            $this->fail('a payment.success backed only by a redaction marker must be refused');
        } catch (PaylodSignatureVerificationError $e) {
            $this->assertSame('invalid_payload', $e->reason);
        }
    }

    /**
     * EVIDENCE HAS A POSITIVE GRAMMAR. The grammar is derived from this repo's own fixtures - every
     * `mpesaReceipt` in src/ and tests/ is `SFF6XYZ123`, ten bytes of uppercase alphanumerics.
     * Anything that does not match is NOT evidence, whatever it is.
     *
     * nv:r9-receipt-grammar
     */
    public function testTheReceiptGrammarAcceptsOnlyReceiptShapedValues(): void
    {
        $this->assertTrue(Semantics::isReceipt('SFF6XYZ123'), 'the repo fixture must be a receipt');
        $this->assertTrue(Semantics::isReceipt('QWE4RTY789'));

        foreach ([
            Redact::PLACEHOLDER,                 // THE round-9 Critical
            'Bearer ' . self::KEY,
            self::KEY,
            'sff6xyz123',                        // lowercase
            'SFF6XYZ12',                         // nine bytes
            'SFF6XYZ1234',                       // eleven bytes
            'SFF6XYZ123 ',                       // trailing space
            "SFF6XYZ123\n",                      // `$`-anchor trap: `\z` refuses this
            'SFF6-YZ123',                        // punctuation
            'null',
            '{"retryable":true}',
            '   ',
            '',
        ] as $impostor) {
            $this->assertFalse(
                Semantics::isReceipt($impostor),
                json_encode($impostor) . ' must not be readable as an M-Pesa receipt'
            );
        }
    }

    // == THE REDACTION-MARKER AUDIT ============================================================

    /**
     * THE PERMANENT AUDIT. The redaction marker must satisfy NO evidence, identifier or correlation
     * check anywhere in this SDK. Every such check is enumerated here; a new one added without a
     * line in this test is the shape of the next round-9 Critical.
     *
     * nv:r9-marker-audit
     */
    public function testTheRedactionMarkerSatisfiesNoEvidenceIdentifierOrCorrelationCheck(): void
    {
        $marker = Redact::PLACEHOLDER;

        // 1. EVIDENCE - the receipt.
        $this->assertFalse(Semantics::isReceipt($marker));
        $this->assertFalse(Semantics::hasReceipt(['mpesaReceipt' => $marker]));

        // 2. EVIDENCE - the result code. A marker is not canonically shaped, so it is unreadable,
        //    and unreadable is never success and never terminal.
        $this->assertSame('pending', DarajaCatalog::classifyStkResult($marker));
        $this->assertFalse(DarajaCatalog::decode($marker)['retryable']);

        // 3. IDENTIFIERS - paymentId / checkoutRequestId on the acknowledgement, and `id` on a
        //    status read. `usableIdentifier()` is the shared gate for all three.
        foreach (['paymentId', 'checkoutRequestId', 'id'] as $field) {
            $this->assertNull(
                Validate::usableIdentifier([$field => $marker], $field),
                "{$field} must refuse a redaction marker"
            );
        }

        // 4. CORRELATION - the idempotency key.
        try {
            Validate::idempotencyKey($marker);
            $this->fail('an idempotency key must refuse a redaction marker');
        } catch (PaylodInvalidRequestError $e) {
            $this->assertStringContainsString('redaction marker', $e->getMessage());
        }

        // 5. THE COMPOSED CHECK - a whole payment record whose every string field is the marker
        //    establishes NOTHING, under every claim in the alphabet.
        foreach (Semantics::CLAIMS as $claim) {
            $judgement = Semantics::judge([
                'id' => $marker,
                'status' => $claim === Semantics::CLAIM_UNKNOWN ? 'settled' : $claim,
                'mpesaReceipt' => $marker,
                'resultCode' => $marker,
                'resultDesc' => $marker,
            ]);
            $this->assertNotSame(Semantics::VERDICT_PAID, $judgement->verdict, "claim {$claim}");
            $this->assertNotSame(Semantics::VERDICT_FAILED, $judgement->verdict, "claim {$claim}");
        }
    }

    /**
     * REFUSE, DO NOT REDACT-AND-DELIVER, ON THE MONEY PATH. A correctly-signed body echoing the
     * webhook secret is a compromised or misconfigured sender, so the WHOLE body is refused.
     *
     * nv:r9-webhook-refuses-echoed-secret
     */
    public function testASignedBodyEchoingTheWebhookSecretIsRefusedNotSanitised(): void
    {
        $now = 1700000000;
        $raw = json_encode([
            'type' => 'payment.success',
            'created' => $now,
            'data' => [
                'paymentId' => 'pay_123',
                'status' => 'success',
                'mpesaReceipt' => 'SFF6XYZ123',
                'resultCode' => 0,
                'resultDesc' => 'echoed ' . self::SECRET,
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);
            $this->fail('expected the echoed secret to be REFUSED');
        } catch (PaylodCredentialCompromiseError $e) {
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    // == THE DEPTH INVARIANT ====================================================================

    /**
     * THE REDACTOR MUST REACH EVERYTHING THE PARSER CAN.
     *
     * Redact stopped at depth 12 while every parse in the SDK uses 512, so a secret echoed at
     * depth 13 of a signed body was PARSED into the event and then walked past by the scrubber.
     * Node (8 vs 64), Python (12 vs 64) and JVM (8 vs 64) all carried the same drift.
     *
     * nv:r9-depth-invariant
     */
    public function testTheRedactionDepthIsPinnedToTheParseDepth(): void
    {
        $this->assertSame(
            JsonLexeme::MAX_DEPTH,
            Redact::MAX_DEPTH,
            'the redactor must reach every depth the parser can - a shallower redactor is a blind spot'
        );

        // And it holds in practice, not merely as a constant.
        $deep = self::KEY;
        for ($i = 0; $i < 60; $i++) {
            $deep = ['nested' => $deep];
        }
        $this->assertStringNotContainsString(self::KEY, json_encode(Redact::apply($deep, [self::KEY])));
    }

    /**
     * Beyond the ceiling the traversal FAILS CLOSED: content the redactor cannot reach is replaced,
     * never forwarded on the assumption that it is clean.
     *
     * nv:r9-depth-invariant
     */
    public function testTheRedactorFailsClosedBeyondItsCeiling(): void
    {
        $deep = self::KEY;
        for ($i = 0; $i < Redact::MAX_DEPTH + 5; $i++) {
            $deep = [$deep];
        }
        $this->assertStringNotContainsString(self::KEY, json_encode(Redact::apply($deep, [self::KEY])));
    }

    // == THE CATALOG HIGHS ======================================================================

    /**
     * VALIDATE THE FORM, THEN CLASSIFY. The terminal `500.*` description branch used to run BEFORE
     * any check on the code's shape, so a server-controlled description could promote an unreadable
     * code into TERMINAL FAILURE evidence.
     *
     * nv:r9-500-exact-code
     */
    public function testAMalformed500CodeIsNotPromotedToTerminalByItsDescription(): void
    {
        foreach (['500.0', '500.x', "500.001.1001\n", '500.', '500.001', '500.001.1001.'] as $code) {
            $this->assertNotSame(
                'failed',
                DarajaCatalog::classifyStkResult($code, 'insufficient funds'),
                "code {$code} is not canonically shaped and must never be terminal"
            );
        }

        // The EXACT documented code still resolves, so the overload handling is not merely disabled.
        $this->assertSame('failed', DarajaCatalog::classifyStkResult('500.001.1001', 'insufficient funds'));
        $this->assertSame('pending', DarajaCatalog::classifyStkResult('500.001.1001'));
    }

    /**
     * AN UNKNOWN CODE IS NOT EVIDENCE OF ANYTHING. Every canonically shaped positive integer used to
     * be classified as terminal failure whether the catalog knew it or not - so `87654` made a
     * claimed failure terminal and let a `payment.failed` webhook through as settled.
     *
     * This is the strengthened version of the round-8 test the reviewer flagged as vacuous: it now
     * asserts the CLASSIFIER, the SEMANTIC VERDICT, the RENDERED OUTCOME and the WEBHOOK REFUSAL,
     * not merely the decoder's category and retryable flag.
     *
     * nv:r9-unknown-code-indeterminate
     */
    public function testAnUncataloguedCanonicalCodeIsIndeterminateEverywhere(): void
    {
        $code = 87654;

        // 1. THE CLASSIFIER.
        $this->assertSame('unknown', DarajaCatalog::classifyStkResult($code));

        // 2. THE DECODER (the round-8 assertions, kept).
        $decoded = DarajaCatalog::decode($code, 'some novel failure');
        $this->assertSame('87654', $decoded['code']);
        $this->assertFalse($decoded['retryable']);

        // 3. THE SEMANTIC VERDICT - never a terminal failure, under any claim.
        $judgement = Semantics::judge(['status' => 'failed', 'resultCode' => $code]);
        $this->assertSame(Semantics::EVIDENCE_UNKNOWN, $judgement->evidence);
        $this->assertSame(Semantics::VERDICT_INDETERMINATE, $judgement->verdict);

        // 4. THE RENDERED OUTCOME - pending, never retryable, both nested and top-level.
        $outcome = PaymentOutcome::fromPayment([
            'id' => 'pay_123',
            'status' => 'failed',
            'mpesaReceipt' => null,
            'resultCode' => $code,
        ]);
        $this->assertSame('pending', $outcome->status);
        $this->assertFalse($outcome->paid);
        $this->assertFalse($outcome->retryable);
        $this->assertFalse($outcome->detail['retryable'] ?? true);

        // 5. THE WEBHOOK - a `payment.failed` on an unknown code is REFUSED, not delivered as settled.
        $now = 1700000000;
        $raw = json_encode([
            'type' => 'payment.failed',
            'created' => $now,
            'data' => ['paymentId' => 'pay_123', 'status' => 'failed', 'resultCode' => $code],
        ], JSON_THROW_ON_ERROR);

        try {
            Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);
            $this->fail('a payment.failed carrying an uncatalogued code must not be delivered as settled');
        } catch (PaylodSignatureVerificationError $e) {
            $this->assertSame('invalid_payload', $e->reason);
        }
    }

    // == THE WEBHOOK ALLOWLIST HIGH =============================================================

    /**
     * AN ALLOWLIST OF NAMES IS HALF A SCHEMA. Allowlisted keys were copied without checking their
     * value TYPES, so a payload-supplied retry conclusion could ride through the allowlist hidden
     * inside an allowlisted name.
     *
     * nv:r9-allowlist-types
     */
    public function testAStructuredValueInAScalarAllowlistedFieldIsRefused(): void
    {
        $now = 1700000000;

        foreach ([
            'applicationId' => ['retryable' => true],
            'amount' => ['decoded' => ['retryable' => true]],
            'phone' => ['retryable' => true],
            'accountRef' => ['x' => ['y' => ['retryable' => true]]],
            'checkoutRequestId' => ['retryable' => true],
            'resultDesc' => ['retryable' => true],
        ] as $field => $smuggled) {
            $raw = json_encode([
                'type' => 'payment.failed',
                'created' => $now,
                'data' => [
                    'paymentId' => 'pay_123',
                    'status' => 'failed',
                    // Requirement 3.7: 2028 PROVES no debit (over-limit is refused before any
                    // debit), so it is a real terminal failure. Code 17 used to be used here and is
                    // now INCONCLUSIVE - it can no longer support a `payment.failed` event.
                    'resultCode' => 2028,
                    $field => $smuggled,
                ],
            ], JSON_THROW_ON_ERROR);

            try {
                $event = Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);
                $this->fail(
                    "data.{$field} carried a structured value and was not refused; the event came "
                    . 'back as ' . json_encode($event)
                );
            } catch (PaylodSignatureVerificationError $e) {
                $this->assertSame('invalid_payload', $e->reason, $field);
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    /** A well-typed event of the same shape still verifies, so the rule is not merely "refuse all". */
    public function testAWellTypedPaymentEventStillVerifies(): void
    {
        $now = 1700000000;
        $raw = json_encode([
            'type' => 'payment.failed',
            'created' => $now,
            'data' => [
                'paymentId' => 'pay_123',
                'applicationId' => 'app_1',
                'status' => 'failed',
                'amount' => 100,
                'phone' => '254712345678',
                // Requirement 3.7 - see above. A conclusive terminal code, not an inconclusive one.
                'resultCode' => 2028,
            ],
        ], JSON_THROW_ON_ERROR);

        $event = Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);
        $this->assertSame('app_1', $event['data']['applicationId']);
        $this->assertFalse($event['data']['retryable']);
    }

    // == THE CLIENT HIGHS =======================================================================

    /**
     * A malformed 202 that nonetheless carries a usable `paymentId` must SURRENDER IT. The error
     * says "a charge may be live, go and read it"; without the id the caller cannot.
     *
     * nv:r9-collect-binds-payment-id
     */
    public function testAMalformedAcknowledgementStillBindsAUsablePaymentId(): void
    {
        $client = new Paylod(self::KEY, [
            'allowCustomHttpClient' => true,
            'httpClient' => new MockHttpClient([
                // A valid paymentId, but the ack is malformed: `status` is not the literal "pending".
                ['status' => 202, 'json' => [
                    'paymentId' => 'pay_live_one',
                    'checkoutRequestId' => 'ws_CO_1',
                    'status' => 'success',
                ]],
            ]),
            'maxRetries' => 0,
        ]);

        try {
            $client->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'attempt-1']);
            $this->fail('expected a malformed acknowledgement to be refused');
        } catch (PaylodApiError $e) {
            $this->assertSame('attempt-1', $e->idempotencyKey);
            $this->assertSame(
                'pay_live_one',
                $e->paymentId,
                'the id was in the body; failure handling must not throw it away'
            );
        }
    }

    /**
     * `decodeError()` applies the CLIENT'S redactor. The catalog scrubs credential SHAPES on its own
     * (it is static and holds no secrets); the exact secrets are only knowable on the client.
     *
     * nv:r9-decode-error-redacts
     */
    public function testDecodeErrorRedactsTheClientsOwnCredentials(): void
    {
        $client = new Paylod(self::KEY, ['webhookSecret' => self::SECRET]);

        // A key that is NOT credential-shaped, so only the exact-secret layer can catch it.
        $client2 = new Paylod('mp_test_plain', ['webhookSecret' => 'not-credential-shaped-secret']);
        $decoded = $client2->decodeError(87654, 'server echoed: not-credential-shaped-secret');
        $this->assertStringNotContainsString('not-credential-shaped-secret', json_encode($decoded));

        // And the SHAPE layer works with no client secrets involved at all.
        $offline = DarajaCatalog::decode(87654, 'server echoed: ' . self::KEY);
        $this->assertStringNotContainsString(self::KEY, json_encode($offline));
        $this->assertStringNotContainsString(self::KEY, json_encode($client->decodeError(87654, self::KEY)));
    }

    // == THE FAIL-CLOSED CROSS-CHECK (round-8 Low) ==============================================

    /**
     * THE DIVERGENCE BRANCH, ACTUALLY REACHED. The round-8 test supplied only JSON that BOTH parsers
     * reject, so it never entered the branch where the scanner fails and `json_decode()` succeeds -
     * it would have passed with the whole cross-check deleted. A narrowed scanner limit produces a
     * genuine divergence, so the fail-closed behaviour is now observed rather than assumed.
     *
     * nv:r9-scanner-divergence
     */
    public function testABodyTheScannerCannotReadButPhpCanIsRefused(): void
    {
        $deep = json_encode(self::nest(20), JSON_THROW_ON_ERROR);

        // PHP reads it happily.
        json_decode($deep, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        // The scanner, narrowed, cannot - and the answer is REFUSE, not "no finding".
        $this->assertSame(
            JsonLexeme::UNREADABLE,
            JsonLexeme::nonCanonicalResultCodeToken($deep, 5),
            'a body the guard cannot read must fail CLOSED'
        );

        // Unnarrowed, the same body is perfectly readable - the seam does not weaken production.
        $this->assertNull(JsonLexeme::nonCanonicalResultCodeToken($deep));
    }

    /** @return array<string,mixed> */
    private static function nest(int $depth): array
    {
        $v = ['resultCode' => 0];
        for ($i = 0; $i < $depth; $i++) {
            $v = ['nested' => $v];
        }

        return $v;
    }

    // == THE ADVERSARIAL SWEEP ==================================================================

    /**
     * THE PERMANENT ADVERSARIAL SWEEP.
     *
     * Construct every public object and exception this SDK can produce from a HOSTILE response that
     * echoes BOTH credentials in EVERY string field at SEVERAL DEPTHS, then assert that neither
     * credential appears anywhere in any output - message, trace, dump, JSON encoding, or the
     * returned value itself.
     *
     * The sibling SDKs added exactly this sweep in round 9. Python's found six unreported leaks the
     * moment it existed; the JVM's found one. Both were found ONLY because the sweep existed - no
     * per-field test would have looked in those places, because nobody knew to look there. It is
     * kept as a standing net rather than a one-off audit for that reason.
     *
     * nv:r9-adversarial-sweep-code-field
     * nv:r9-adversarial-sweep-claimed
     */
    public function testNoPublicObjectOrExceptionEverCarriesACredential(): void
    {
        $key = self::KEY;
        $secret = self::SECRET;
        $poison = "prefix {$key} middle {$secret} suffix";

        $hostilePayment = [
            'id' => $poison,
            'status' => $poison,
            'mpesaReceipt' => $poison,
            'resultCode' => $poison,
            'resultDesc' => $poison,
            'nested' => ['deep' => ['deeper' => ['deepest' => $poison]]],
            $poison => $poison,
        ];

        $sinks = [];

        // REQUIREMENT 8.6 - THE SELF-CHECK. Every public object the sweep constructs is recorded
        // here, parent classes included, and the list is asserted against the SDK's public surface
        // at the end. Without it "the sweep covers every public type" is a claim in a comment.
        $constructed = [];
        $note = static function (object $o) use (&$constructed): object {
            for ($c = get_class($o); $c !== false; $c = get_parent_class($c)) {
                $constructed[$c] = true;
            }

            return $o;
        };

        // 1. PaymentOutcome, from a hostile record.
        $outcome = $note(PaymentOutcome::fromPayment($hostilePayment));
        $sinks['PaymentOutcome json'] = json_encode($outcome);
        $sinks['PaymentOutcome print_r'] = print_r($outcome, true);
        $sinks['PaymentOutcome var_export'] = var_export($outcome, true);

        // 2. The offline decoder, from a hostile description.
        $sinks['decode'] = json_encode(DarajaCatalog::decode($poison, $poison));
        $sinks['decode int code'] = json_encode(DarajaCatalog::decode(87654, $poison));

        // 3. The judgement and its reason string.
        $sinks['Judgement'] = json_encode($note(Semantics::judge($hostilePayment)));

        // 4. The client itself, in every dump form.
        $client = $note(new Paylod($key, ['webhookSecret' => $secret]));
        $note($client->simulator);
        $sinks['client print_r'] = print_r($client, true);
        $sinks['client var_export'] = var_export($client, true);
        $sinks['client var_dump'] = self::varDump($client);
        $sinks['client decodeError'] = json_encode($client->decodeError($poison, $poison));

        // 5. Every exception the dispatch surfaces can raise from a hostile response, in full -
        //    message, string form, and the argument list PHP records in the trace.
        foreach (self::hostileResponses($poison) as $label => $steps) {
            $c = new Paylod($key, [
                'webhookSecret' => $secret,
                'allowCustomHttpClient' => true,
                'httpClient' => new MockHttpClient($steps),
                'maxRetries' => 0,
            ]);
            try {
                $c->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'sweep-1']);
                $this->fail("hostile response {$label} was not refused");
            } catch (\Throwable $e) {
                $note($e);
                $sinks["collect {$label} message"] = $e->getMessage();
                $sinks["collect {$label} string"] = (string) $e;
                $sinks["collect {$label} trace"] = print_r($e->getTrace(), true);
                $sinks["collect {$label} print_r"] = print_r($e, true);
            }
        }

        // 6. The webhook path, on a body that is refused, and on one that is not.
        $now = 1700000000;
        $rawPoison = json_encode([
            'type' => 'payment.failed',
            'created' => $now,
            'data' => [
                'paymentId' => 'pay_123',
                'status' => 'failed',
                'resultCode' => 1032,
                'resultDesc' => $poison,
            ],
        ], JSON_THROW_ON_ERROR);
        // EVERY BRANCH IS REQUIRED TO BE REACHED, and a SUCCESSFUL return is a sink too.
        //
        // Round 10 found this section unable to detect the very defect it was meant to cover. It
        // caught \Throwable and treated ANY exception as adequate, so a body refused for the wrong
        // reason - or refused before the interesting code ran - counted as a pass. It DISCARDED
        // successful parseWebhook() results, which is the single most important sink on this path:
        // a delivered event is a value the handler reads. And it took no sink at all from the
        // BOOLEAN surface, so verifyWebhook() returning `true` about a compromised body was
        // invisible. `$reached` records which arms actually ran and is asserted below.
        $reached = [];

        $poisonSig = Webhook::sign($rawPoison, $secret, $now);

        try {
            $event = Webhook::verify($rawPoison, $poisonSig, $secret, 300, $now);
            $sinks['webhook event'] = json_encode($event);
            $reached['webhook delivered'] = true;
        } catch (\Throwable $e) {
            $note($e);
            $sinks['webhook message'] = $e->getMessage();
            $sinks['webhook trace'] = print_r($e->getTrace(), true);
            $sinks['webhook string'] = (string) $e;
            $reached['webhook refused'] = true;
        }
        try {
            // THE RETURN VALUE IS CAPTURED. It used to be thrown away.
            $sinks['parseWebhook event'] = json_encode($client->parseWebhook($rawPoison, $poisonSig, $secret, 300));
            $reached['parseWebhook delivered'] = true;
        } catch (\Throwable $e) {
            $note($e);
            $sinks['parseWebhook message'] = $e->getMessage();
            $sinks['parseWebhook trace'] = print_r($e->getTrace(), true);
            $sinks['parseWebhook string'] = (string) $e;
            $reached['parseWebhook refused'] = true;
        }
        try {
            $client->parseWebhook($rawPoison, 'garbage', $secret, 300);
            $this->fail('a garbage signature was accepted');
        } catch (\Throwable $e) {
            $note($e);
            $sinks['parseWebhook bad sig trace'] = print_r($e->getTrace(), true);
            $sinks['parseWebhook bad sig string'] = (string) $e;
            $reached['bad signature refused'] = true;
        }

        // 7. THE BOOLEAN SURFACE. A `true` about a body echoing a credential is itself the leak -
        //    the credential does not have to appear in a string for the answer to be wrong - so the
        //    boolean is recorded AND asserted, not merely rendered into a sink.
        try {
            $verdict = $client->verifyWebhook($rawPoison, $poisonSig, $secret);
            $sinks['verifyWebhook verdict'] = var_export($verdict, true);
            $reached['verifyWebhook returned'] = true;
            $this->assertFalse(
                $verdict,
                'verifyWebhook() answered TRUE about a body echoing a configured credential'
            );
        } catch (PaylodCredentialCompromiseError $e) {
            // Refusing outright is the stronger answer and is equally acceptable.
            $note($e);
            $sinks['verifyWebhook refusal'] = (string) $e;
            $reached['verifyWebhook refused'] = true;
        }
        try {
            $client->verifyWebhook($rawPoison, 'garbage', $secret);
            $reached['verifyWebhook bad sig returned'] = true;
        } catch (\Throwable $e) {
            $note($e);
            $sinks['verifyWebhook trace'] = print_r($e->getTrace(), true);
            $reached['verifyWebhook bad sig threw'] = true;
        }

        // 8. THE EXACT CREDENTIAL, SPELLED WITH JSON ESCAPES. A literal-bytes fixture cannot detect
        //    the escaped-credential delivery defect: the value is absent from the raw body and
        //    present in the decoded event. Both spellings are swept.
        $escaped = '';
        foreach (str_split($secret) as $ch) {
            $escaped .= sprintf('\u%04x', ord($ch));
        }
        $rawEscaped = '{"type":"payment.failed","created":' . $now . ',"data":{"paymentId":"pay_123",'
            . '"status":"failed","resultCode":1032,"resultDesc":"' . $escaped . '"}}';
        $this->assertStringNotContainsString($secret, $rawEscaped, 'the escaped fixture is not escaped');
        try {
            $sinks['escaped webhook event'] = json_encode(
                Webhook::verify($rawEscaped, Webhook::sign($rawEscaped, $secret, $now), $secret, 300, $now)
            );
            $reached['escaped delivered'] = true;
        } catch (\Throwable $e) {
            $note($e);
            $sinks['escaped webhook message'] = $e->getMessage();
            $sinks['escaped webhook string'] = (string) $e;
            $reached['escaped refused'] = true;
        }
        $this->assertArrayHasKey(
            'escaped refused',
            $reached,
            'a signed body echoing the webhook secret in JSON-escaped form was DELIVERED'
        );

        // 9. A NONSTANDARD ACCEPTED KEY - one the credential SHAPE rules cannot match, so only the
        //    exact-secret layer can catch it. A sweep built entirely on mp_/whsec_-shaped values
        //    proves the shape layer works and says nothing about the exact layer.
        $plainSecret = 'plain-secret-with-no-recognisable-shape';
        $plainClient = new Paylod($key, ['webhookSecret' => $plainSecret]);
        $rawPlain = json_encode([
            'type' => 'payment.failed',
            'created' => $now,
            'data' => [
                'paymentId' => 'pay_123',
                'status' => 'failed',
                'resultCode' => 1032,
                'resultDesc' => $plainSecret,
            ],
        ], JSON_THROW_ON_ERROR);
        try {
            $sinks['plain secret event'] = json_encode(
                $plainClient->parseWebhook($rawPlain, Webhook::sign($rawPlain, $plainSecret, $now), $plainSecret, 300)
            );
            $reached['plain secret delivered'] = true;
        } catch (\Throwable $e) {
            $note($e);
            $sinks['plain secret message'] = $e->getMessage();
            $sinks['plain secret string'] = (string) $e;
            $reached['plain secret refused'] = true;
        }
        $this->assertArrayHasKey(
            'plain secret refused',
            $reached,
            'a body echoing a NON-credential-shaped configured secret was delivered'
        );
        foreach ($sinks as $label => $text) {
            $this->assertStringNotContainsString($plainSecret, (string) $text, "PLAIN SECRET leaked in: {$label}");
        }

        foreach ($sinks as $label => $text) {
            $this->assertStringNotContainsString($key, (string) $text, "API KEY leaked in: {$label}");
            $this->assertStringNotContainsString($secret, (string) $text, "WEBHOOK SECRET leaked in: {$label}");
        }

        // 10. THE REMAINING PUBLIC TYPES, constructed from poisoned input so the self-check below
        //     is satisfied by real coverage rather than by shrinking the list. Each of these is a
        //     type a caller can be handed, so each is a place a credential could surface.
        try {
            new Paylod('   ');
        } catch (\Throwable $e) {
            $note($e);
            $sinks['config error'] = (string) $e;
        }
        try {
            // A live key on the simulator: refused locally, before any dispatch.
            (new Paylod('mp_live_' . $secret, ['webhookSecret' => $secret]))->simulator->collect([
                'idempotencyKey' => 'sweep-sandbox',
            ]);
        } catch (\Throwable $e) {
            $note($e);
            $sinks['sandbox only'] = (string) $e;
            $sinks['sandbox only trace'] = print_r($e->getTrace(), true);
        }
        try {
            $client->collect(['amount' => $poison, 'phone' => '0712345678', 'idempotencyKey' => 'k']);
        } catch (\Throwable $e) {
            $note($e);
            $sinks['invalid request'] = (string) $e;
            $sinks['invalid request trace'] = print_r($e->getTrace(), true);
        }
        $timeoutError = $note(new \Paylod\Exceptions\PaylodTimeoutError($poison, $hostilePayment, 1));
        $sinks['timeout error'] = (string) $timeoutError;
        $sinks['timeout error print_r'] = print_r($timeoutError, true);
        try {
            $c = new Paylod($key, [
                'webhookSecret' => $secret,
                'allowCustomHttpClient' => true,
                // A transport-layer failure: the mock raises PaylodConnectionError, which the
                // client then retries and finally surfaces. `$poison` rides in through the URL the
                // error quotes and through the retry bookkeeping.
                'httpClient' => new MockHttpClient([['throw' => true]]),
                'maxRetries' => 0,
            ]);
            $c->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'sweep-conn']);
        } catch (\Throwable $e) {
            $note($e);
            $sinks['connection error'] = (string) $e;
            $sinks['connection error trace'] = print_r($e->getTrace(), true);
        }

        // THE SELF-CHECK (requirement 8.6). "The sweep collected more than twenty sinks" is not the
        // same claim as "every public type was constructed", and only the second one is what this
        // test asserts in its own docblock. Every public class this SDK can hand a caller is
        // enumerated here and each must have been instantiated at least once during the sweep;
        // adding a new public type without sweeping it is a hard failure, not a silent gap.
        $publicTypes = [
            \Paylod\Paylod::class,
            \Paylod\Simulator::class,
            \Paylod\PaymentOutcome::class,
            \Paylod\Judgement::class,
            \Paylod\Exceptions\PaylodException::class,
            \Paylod\Exceptions\PaylodApiError::class,
            \Paylod\Exceptions\PaylodConfigError::class,
            \Paylod\Exceptions\PaylodConnectionError::class,
            \Paylod\Exceptions\PaylodCredentialCompromiseError::class,
            \Paylod\Exceptions\PaylodInvalidRequestError::class,
            \Paylod\Exceptions\PaylodSandboxOnlyError::class,
            \Paylod\Exceptions\PaylodSignatureVerificationError::class,
            \Paylod\Exceptions\PaylodTimeoutError::class,
        ];
        foreach ($publicTypes as $type) {
            $this->assertArrayHasKey(
                $type,
                $constructed,
                "the adversarial sweep never constructed {$type}, so it cannot claim to cover it"
            );
        }

        // And every branch the sweep is built around really ran.
        foreach (['bad signature refused', 'escaped refused', 'plain secret refused'] as $branch) {
            $this->assertArrayHasKey($branch, $reached, "the sweep never reached: {$branch}");
        }

        $this->assertGreaterThan(30, count($sinks), 'the sweep collected suspiciously few sinks');
    }

    /** @return array<string,list<array<string,mixed>>> */
    private static function hostileResponses(string $poison): array
    {
        return [
            'echoing 400' => [['status' => 400, 'json' => [
                'error' => $poison,
                'detail' => ['deep' => ['deeper' => ['deepest' => $poison]]],
            ]]],
            'echoing 500' => [['status' => 500, 'raw' => $poison]],
            'malformed 202' => [['status' => 202, 'json' => [
                'paymentId' => $poison, 'checkoutRequestId' => $poison, 'status' => $poison,
            ]]],
            'non-json 202' => [['status' => 202, 'raw' => $poison]],
            'wrong 2xx' => [['status' => 200, 'json' => [
                'paymentId' => 'pay_1', 'checkoutRequestId' => 'ws_1', 'status' => 'pending', 'note' => $poison,
            ]]],
        ];
    }

    private static function varDump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }
}

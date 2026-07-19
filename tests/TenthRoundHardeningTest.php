<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\DarajaCatalog;
use Paylod\Exceptions\PaylodCredentialCompromiseError;
use Paylod\Exceptions\PaylodSignatureVerificationError;
use Paylod\Paylod;
use Paylod\PaymentOutcome;
use Paylod\Semantics;
use Paylod\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * TENTH-ROUND regressions - REQUIREMENT 3.7 / 1.5.
 *
 * THE DEFECT. {@see DarajaCatalog::terminalStkCodes()} was derived by SUBTRACTION: every
 * `stk_result` entry that was not `pending` and not `0` was a terminal failure. That swept in codes
 * 17, 26, 1001, 1025 and 9999, whose OWN entries in the catalog say - in the `fix` text the SDK
 * shows the merchant - that a debit is NOT disproven:
 *
 *     "a busy-system rejection is not proof no charge was raised"
 *     "a delivery failure is not proof no charge was raised"
 *     "the in-flight transaction may be your own earlier push, and charging again could double-charge"
 *
 * The SDK's own data contradicted the verdict the SDK reached from it. `classifyStkResult()`
 * answered `failed`, so `Semantics` saw EVIDENCE_FAILURE, so a `failed` claim resolved to
 * VERDICT_FAILED - and a terminal failure is the verdict that admits a signed `payment.failed`
 * webhook as settled and tells a merchant the payment is over. On a payment that may have been
 * charged. The customer-facing message then said "Please try again."
 *
 * These tests pin the repair from three independent directions, because a single one of them is
 * satisfiable by a mistake:
 *
 *   1. The partition is COUPLED TO THE CATALOG'S OWN PROSE. A code whose text disclaims proof may
 *      not be terminal, and a code that is terminal may not disclaim proof. Neither the set nor the
 *      table can be edited into disagreement without this failing.
 *   2. The CONTROLS (requirement 8.5 / 3.5). An over-corrected model that calls every failure
 *      indeterminate passes tests 1 and 3 and is just as non-conformant. Real terminal codes must
 *      still be terminal.
 *   3. Requirement 3.7's customer-message rule, applied to EVERY entry in the table rather than to
 *      the four codes that happened to be reported.
 */
final class TenthRoundHardeningTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    /**
     * The catalog's own way of saying "this does not prove no debit occurred". Matched against each
     * entry's `cause` + `fix`, which is where the SDK states what a code does and does not settle.
     */
    private const DISCLAIMER_RE = '/not proof|not proven|could double-charge|may be your own earlier push/i';

    /** @return array<string,mixed>|null */
    private static function stkEntry(string $code): ?array
    {
        foreach (DarajaCatalog::allEntries() as $e) {
            if ((string) $e['code'] === $code && ($e['family'] ?? null) === 'stk_result') {
                return $e;
            }
        }

        return null;
    }

    // == 1. The partition is coupled to the catalog's own prose ==================================

    /**
     * THE COUPLING. This is the test that stops the set from being hand-edited back.
     *
     * nv:r10-terminal-by-subtraction
     */
    public function testEveryInconclusiveCodeDisclaimsProofAndNoTerminalCodeDoes(): void
    {
        $inconclusive = array_keys(DarajaCatalog::inconclusiveStkCodes());
        $terminal = array_keys(DarajaCatalog::terminalStkCodes());

        // The sets are non-empty, or the two loops below are vacuous (requirement 8.3's sibling:
        // a loop over nothing passes and proves nothing).
        $this->assertNotEmpty($inconclusive, 'no inconclusive codes - the partition is vacuous');
        $this->assertNotEmpty($terminal, 'no terminal codes - the partition is vacuous');

        foreach ($inconclusive as $code) {
            $entry = self::stkEntry((string) $code);
            $this->assertNotNull($entry, "inconclusive code {$code} is not in the catalog at all");
            $prose = $entry['cause'] . ' ' . $entry['fix'];
            $this->assertMatchesRegularExpression(
                self::DISCLAIMER_RE,
                $prose,
                "code {$code} is classified INCONCLUSIVE but its catalog entry does not disclaim "
                . 'proof that no debit occurred - either the classification or the table is wrong'
            );
        }

        foreach ($terminal as $code) {
            $entry = self::stkEntry((string) $code);
            $this->assertNotNull($entry, "terminal code {$code} is not in the catalog at all");
            $prose = $entry['cause'] . ' ' . $entry['fix'];
            $this->assertDoesNotMatchRegularExpression(
                self::DISCLAIMER_RE,
                $prose,
                "code {$code} is classified TERMINAL - i.e. the SDK will tell a merchant the payment "
                . 'is settled and may admit a signed payment.failed event for it - but its own '
                . 'catalog entry says a debit is not disproven. This is the round-10 Critical.'
            );
        }
    }

    /**
     * The two sets PARTITION the non-pending STK codes: no code is in both, and none is in neither.
     * A code that fell between them would be classified by neither branch of the classifier.
     *
     * nv:r10-partition-total
     */
    public function testTheTerminalAndInconclusiveSetsPartitionTheNonPendingStkCodes(): void
    {
        $terminal = array_map('strval', array_keys(DarajaCatalog::terminalStkCodes()));
        $inconclusive = array_map('strval', array_keys(DarajaCatalog::inconclusiveStkCodes()));

        $this->assertSame([], array_intersect($terminal, $inconclusive), 'the sets overlap');

        $universe = [];
        foreach (DarajaCatalog::allEntries() as $e) {
            if (($e['family'] ?? null) !== 'stk_result' || ($e['category'] ?? null) === 'pending') {
                continue;
            }
            if ((string) $e['code'] === '0') {
                continue;
            }
            $universe[(string) $e['code']] = true;
        }

        $covered = array_merge($terminal, $inconclusive);
        sort($covered);
        $expected = array_map('strval', array_keys($universe));
        sort($expected);
        $this->assertSame($expected, $covered, 'a non-pending STK code is in neither set');
    }

    // == 2. The classification, the evidence and the verdict =====================================

    /**
     * @return array<string,array{0:int|string}>
     */
    public static function inconclusiveCodes(): array
    {
        return [
            'M-Pesa system internal error (17)' => [17],
            'M-Pesa system busy (26)' => [26],
            'transaction already in process (1001)' => [1001],
            'error sending the STK prompt (1025)' => [1025],
            'error sending the STK prompt (9999)' => [9999],
        ];
    }

    /**
     * @dataProvider inconclusiveCodes
     * nv:r10-inconclusive-not-terminal
     */
    public function testACodeThatDoesNotProveNoDebitIsNeverATerminalFailure(int|string $code): void
    {
        $this->assertSame(
            'inconclusive',
            DarajaCatalog::classifyStkResult($code),
            "code {$code} must not classify as a terminal failure"
        );

        $payment = [
            'id' => 'pay_1',
            'status' => 'failed',
            'mpesaReceipt' => null,
            'resultCode' => $code,
            'resultDesc' => 'x',
        ];

        $judgement = Semantics::judge($payment);
        $this->assertSame(Semantics::EVIDENCE_INCONCLUSIVE, $judgement->evidence, (string) $code);
        $this->assertSame(
            Semantics::VERDICT_INDETERMINATE,
            $judgement->verdict,
            "a `failed` claim on code {$code} must be INDETERMINATE, not a settled failure"
        );

        // And the merchant-visible rendering: `pending`, never retryable. An indeterminate payment
        // keeps being polled so the webhook can settle it.
        $outcome = PaymentOutcome::fromPayment($payment);
        $this->assertSame('pending', $outcome->status, (string) $code);
        $this->assertFalse($outcome->retryable, (string) $code);
    }

    /**
     * THE CONTROLS (requirements 8.5 and 3.5). Without these, a model that answered "indeterminate"
     * to absolutely everything would pass every other test in this file - and would be exactly as
     * non-conformant, because a merchant who is never told a payment failed cannot ever close one.
     *
     * 2028 and 2029 are the sharpest controls available: they are NON-RETRYABLE and TERMINAL, so
     * they prove the two properties are independent rather than the classifier having simply been
     * rewired to mirror the `retryable` flag.
     *
     * @return array<string,array{0:int,1:bool}>
     */
    public static function conclusiveCodes(): array
    {
        return [
            'insufficient balance (1)' => [1, true],
            'transaction expired (1019)' => [1019, true],
            'cancelled by customer (1032)' => [1032, true],
            'prompt unanswered (1037)' => [1037, true],
            'wrong M-Pesa PIN (2001)' => [2001, true],
            'over the M-Pesa limit (2028)' => [2028, false],
            'till sent as paybill (2029)' => [2029, false],
        ];
    }

    /**
     * @dataProvider conclusiveCodes
     * nv:r10-over-correction-control
     */
    public function testACodeThatProvesNoDebitIsStillATerminalFailure(int $code, bool $retryable): void
    {
        $this->assertSame('failed', DarajaCatalog::classifyStkResult($code), (string) $code);

        $payment = [
            'id' => 'pay_1',
            'status' => 'failed',
            'mpesaReceipt' => null,
            'resultCode' => $code,
            'resultDesc' => 'x',
        ];

        $judgement = Semantics::judge($payment);
        $this->assertSame(Semantics::EVIDENCE_FAILURE, $judgement->evidence, (string) $code);
        $this->assertSame(Semantics::VERDICT_FAILED, $judgement->verdict, (string) $code);

        // Requirement 3.6 - the exposed retryable flag agrees with the catalog at this level too.
        $outcome = PaymentOutcome::fromPayment($payment);
        $this->assertSame($retryable, $outcome->retryable, "retryable disagrees for code {$code}");
    }

    // == 3. Requirement 3.8 - the webhook path ===================================================

    /**
     * A signed `payment.failed` event carrying an inconclusive code must be REFUSED, not delivered
     * as a settled failure. This is where the defect cost the most: a handler that cancels an order
     * and refunds - or re-charges - on a `payment.failed` event was doing so on a payment that may
     * have been debited.
     *
     * nv:r10-inconclusive-webhook
     */
    public function testASignedFailedWebhookOnAnInconclusiveCodeIsRefused(): void
    {
        foreach ([17, 26, 1001, 1025, 9999] as $code) {
            $now = 1700000000;
            $raw = json_encode([
                'type' => 'payment.failed',
                'created' => $now,
                'data' => [
                    'paymentId' => 'pay_123',
                    'status' => 'failed',
                    'mpesaReceipt' => null,
                    'resultCode' => $code,
                    'resultDesc' => 'x',
                ],
            ], JSON_THROW_ON_ERROR);

            try {
                Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);
                $this->fail("a payment.failed event on inconclusive code {$code} was delivered as settled");
            } catch (PaylodSignatureVerificationError $e) {
                $this->assertSame('invalid_payload', $e->reason, (string) $code);
            }
        }
    }

    /**
     * THE CONTROL for the test above: a conclusive terminal code must still produce a deliverable
     * `payment.failed` event, or the refusal is just an outage.
     *
     * nv:r10-conclusive-webhook-delivered
     */
    public function testASignedFailedWebhookOnAConclusiveCodeIsStillDelivered(): void
    {
        $now = 1700000000;
        $raw = json_encode([
            'type' => 'payment.failed',
            'created' => $now,
            'data' => [
                'paymentId' => 'pay_123',
                'status' => 'failed',
                'mpesaReceipt' => null,
                'resultCode' => 1032,
                'resultDesc' => 'Request cancelled by user',
            ],
        ], JSON_THROW_ON_ERROR);

        // The refusal must surface as an ASSERTION failure, never as an escaping exception: the
        // non-vacuity harness reports CAUGHT only on a genuine assertion failure (requirement 8.4),
        // so a control that dies with an uncaught throwable proves nothing about this guarantee.
        try {
            $event = Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);
        } catch (PaylodSignatureVerificationError $e) {
            $this->fail(
                'a genuine 1032 cancellation event was REFUSED (' . $e->reason . ') - the '
                . 'inconclusive-code refusal has become an outage on real terminal failures'
            );
        }

        $this->assertSame('payment.failed', $event['type']);
    }

    // == 4. Requirement 3.7 - the customer-facing message ========================================

    /**
     * REQUIREMENT 3.7, APPLIED TO EVERY ENTRY IN THE TABLE.
     *
     * Not to the four codes that were reported - to all of them, plus both fallbacks. Seventeen
     * entries carried "please try again" beside `retryable => false`, which is the SDK telling a
     * customer to do the one thing its own data says it cannot vouch for. Enforced centrally in
     * {@see DarajaCatalog::safeCustomerMessage()} so a table re-sync cannot re-introduce one.
     *
     * nv:r10-retry-invitation
     */
    public function testNoNonRetryableDecodedEntryEverInvitesAnotherAttempt(): void
    {
        $invitation = '/\b(?:try(?:ing)?\s+(?:again|it\s+again)|retry|re-try|again\s+(?:in|shortly|later|whenever))\b/i';
        $checked = 0;

        foreach (DarajaCatalog::allEntries() as $entry) {
            $decoded = DarajaCatalog::decode(
                (string) $entry['code'],
                null,
                (string) ($entry['family'] ?? 'stk_result'),
            );
            if ($decoded['retryable']) {
                continue;
            }
            $checked++;
            $this->assertDoesNotMatchRegularExpression(
                $invitation,
                $decoded['customerMessage'],
                "code {$entry['code']} ({$entry['family']}) is NOT retryable - we cannot establish "
                . 'that no money moved - yet its customer message invites another payment attempt'
            );
        }

        // Requirement 8.3's principle: a loop that inspected nothing must not read as a pass.
        $this->assertGreaterThan(10, $checked, 'the sweep inspected almost nothing');

        // The FALLBACKS too. The uncatalogued-code fallback said "The payment didn't go through.
        // Please try again." beside retryable => false and a `fix` stating we cannot prove no money
        // moved - in the same array. It is the case where the SDK knows LEAST.
        foreach ([DarajaCatalog::decode('87654'), DarajaCatalog::decode('')] as $fallback) {
            $this->assertFalse($fallback['retryable']);
            $this->assertDoesNotMatchRegularExpression($invitation, $fallback['customerMessage']);
        }
    }

    /**
     * The message rule is not satisfied by blanking every message - the replacements still have to
     * say something a customer can act on, and the retryable ones must be left alone.
     *
     * nv:r10-retryable-message-preserved
     */
    public function testRetryableEntriesKeepTheirOwnCustomerMessage(): void
    {
        // 1032 is retryable and its message legitimately offers a retry - no money moved.
        $decoded = DarajaCatalog::decode(1032);
        $this->assertTrue($decoded['retryable']);
        $this->assertStringContainsString('try again', strtolower($decoded['customerMessage']));

        // And a non-retryable replacement is a real sentence, not an empty string.
        $this->assertGreaterThan(40, strlen(DarajaCatalog::decode(26)['customerMessage']));
    }

    // == 5. Requirements 4.6 / 4.7 - the credential refusal reads the DECODED structure ===========

    /** JSON `\uXXXX`-escape every character of $s, so it is absent from the raw bytes. */
    private static function jsonEscape(string $s): string
    {
        $out = '';
        foreach (str_split($s) as $ch) {
            $out .= sprintf('\u%04x', ord($ch));
        }

        return $out;
    }

    /**
     * A secret spelled with JSON escapes is ABSENT from the raw body and PRESENT in the decoded
     * event. The refusal scanned the raw bytes, so it missed this, and the scrubber then rewrote the
     * value to `[redacted]` and DELIVERED the event - a body from a compromised sender, sanitised
     * and handed to a fulfilment handler.
     *
     * nv:r10-escaped-credential-refused
     */
    public function testAnEscapedWebhookSecretInASignedBodyIsRefused(): void
    {
        $now = 1700000000;
        $raw = '{"type":"payment.success","created":' . $now . ',"data":{"paymentId":"pay_1",'
            . '"status":"success","mpesaReceipt":"SFF6XYZ123","resultCode":0,'
            . '"resultDesc":"' . self::jsonEscape(self::SECRET) . '"}}';

        // The escaped spelling really is absent from the bytes, or this test proves nothing.
        $this->assertStringNotContainsString(self::SECRET, $raw, 'the fixture is not actually escaped');
        // ...and really is present once decoded.
        $decoded = json_decode($raw, true);
        $this->assertSame(self::SECRET, $decoded['data']['resultDesc']);

        $this->expectException(PaylodCredentialCompromiseError::class);
        Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);
    }

    /**
     * REQUIREMENT 4.7 on the two CLIENT surfaces - AND THE FIXTURE HAS TO DISCRIMINATE (8.5).
     *
     * The obvious fixture does NOT. A paylod API key always begins `mp_live_` / `mp_test_`, so it
     * is caught by the credential-SHAPE layer no matter which exact secrets the scan was given -
     * a test built on one passes with `$alsoRefuse` removed and proves nothing about it.
     *
     * What `$alsoRefuse` actually buys is the credential the static verifier CANNOT name: a
     * caller who passes an explicit per-endpoint `$secret` (a multi-endpoint setup, a rotation
     * window) leaves the client's CONFIGURED webhook secret unknown to Webhook::verify(). Give
     * that configured secret a value that is not credential-shaped, echo it into the body with
     * JSON escapes, and nothing but the client's own credential list can see it.
     *
     * nv:r10-client-surfaces-know-both-credentials
     */
    public function testBothClientWebhookSurfacesRefuseTheConfiguredSecretUnderAnExplicitOne(): void
    {
        $configured = 'configured-secret-not-credential-shaped';
        $signing = 'per-endpoint-signing-secret';
        $client = new Paylod('mp_test_abc123', ['webhookSecret' => $configured]);

        // The client surfaces take no injectable clock, so sign against the real one.
        $now = time();
        $raw = '{"type":"payment.success","created":' . $now . ',"data":{"paymentId":"pay_1",'
            . '"status":"success","mpesaReceipt":"SFF6XYZ123","resultCode":0,'
            . '"resultDesc":"' . self::jsonEscape($configured) . '"}}';

        // The fixture really is invisible to a raw-bytes scan and to the shape rules.
        $this->assertStringNotContainsString($configured, $raw, 'the fixture is not escaped');
        $this->assertSame(
            $configured,
            \Paylod\Support\Redact::text($configured, []),
            'the fixture IS credential-shaped, so the shape layer would catch it and the test '
            . 'would pass with the fix removed'
        );

        $sig = Webhook::sign($raw, $signing, $now);

        foreach (['parseWebhook', 'verifyWebhook'] as $method) {
            try {
                $client->{$method}($raw, $sig, $signing, 300);
                $this->fail("{$method}() accepted a body echoing the CONFIGURED webhook secret");
            } catch (PaylodCredentialCompromiseError $e) {
                $this->assertStringContainsString('REFUSED', $e->getMessage(), $method);
                // And the refusal does not quote the credential back (requirement 4.1).
                $this->assertStringNotContainsString($configured, $e->getMessage(), $method);
            }
        }
    }

    /**
     * THE CONTROL: a clean signed body must still verify on both surfaces, or the refusal above is
     * an outage rather than a guard.
     *
     * nv:r10-clean-body-still-verifies
     */
    public function testACleanBodyStillVerifiesOnBothClientSurfaces(): void
    {
        $client = new Paylod('mp_test_abc123', ['webhookSecret' => self::SECRET]);
        // The client surfaces take no injectable clock, so sign against the real one.
        $now = time();
        $raw = json_encode([
            'type' => 'payment.success',
            'created' => $now,
            'data' => [
                'paymentId' => 'pay_1',
                'status' => 'success',
                'mpesaReceipt' => 'SFF6XYZ123',
                'resultCode' => 0,
            ],
        ], JSON_THROW_ON_ERROR);
        $sig = Webhook::sign($raw, self::SECRET, $now);

        // Both calls must surface a refusal as an ASSERTION failure, never as an escaping
        // throwable - the non-vacuity harness reports CAUGHT only on a genuine assertion failure
        // (requirement 8.4), so a control that dies with an uncaught exception proves nothing.
        try {
            $this->assertTrue($client->verifyWebhook($raw, $sig, self::SECRET, 300));
            $this->assertSame(
                'payment.success',
                $client->parseWebhook($raw, $sig, self::SECRET, 300)['type']
            );
        } catch (PaylodCredentialCompromiseError $e) {
            $this->fail('a clean signed body was refused as a credential compromise: ' . $e->getMessage());
        }
    }

    // == 6. Requirement 3.4 - the webhook payment id gets the shared identifier grammar ===========

    /** @return array<string,array{0:string}> */
    public static function unusableWebhookPaymentIds(): array
    {
        return [
            // THE redaction marker. The decoded event passes through the redactor before a handler
            // sees it, so this is not hypothetical - it is what an echoed credential BECOMES.
            'the redaction marker' => ['[redacted]'],
            'a marker inside a longer value' => ['pay_[redacted]'],
            'an oversized blob' => [str_repeat('a', 200)],
            'a JSON fragment' => ['{"id":"pay_1"}'],
            'a value with a space' => ['pay 1'],
            'a value starting with a separator' => ['-pay_1'],
        ];
    }

    /**
     * @dataProvider unusableWebhookPaymentIds
     * nv:r10-webhook-payment-id-grammar
     */
    public function testAWebhookPaymentIdMustMatchTheSharedIdentifierGrammar(string $paymentId): void
    {
        $now = 1700000000;
        $raw = json_encode([
            'type' => 'payment.success',
            'created' => $now,
            'data' => [
                'paymentId' => $paymentId,
                'status' => 'success',
                'mpesaReceipt' => 'SFF6XYZ123',
                'resultCode' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);
            $this->fail('a webhook carrying an unusable paymentId was delivered: ' . $paymentId);
        } catch (PaylodSignatureVerificationError $e) {
            $this->assertSame('invalid_payload', $e->reason, $paymentId);
        }
    }

    /**
     * A CREDENTIAL-SHAPED payment id is refused even harder - by the compromise scan, which runs
     * before the coherence rules, so it never reaches the identifier grammar at all. Asserted
     * separately rather than folded into the grammar table above, because the two guards give
     * different (and differently-named) refusals and a test that accepted either would not notice
     * if the stronger one silently stopped firing.
     *
     * nv:r10-credential-shaped-payment-id
     */
    public function testACredentialShapedWebhookPaymentIdIsRefusedAsACompromise(): void
    {
        $now = 1700000000;
        $raw = json_encode([
            'type' => 'payment.success',
            'created' => $now,
            'data' => [
                'paymentId' => 'mp_live_abcdefghijk',
                'status' => 'success',
                'mpesaReceipt' => 'SFF6XYZ123',
                'resultCode' => 0,
            ],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(PaylodCredentialCompromiseError::class);
        Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);
    }

    /**
     * THE CONTROL for the grammar: real paylod ids must still pass.
     *
     * nv:r10-webhook-payment-id-control
     */
    public function testOrdinaryWebhookPaymentIdsAreStillAccepted(): void
    {
        foreach (['pay_1', 'pay_live_abc123', 'PAY-2024.01', 'a'] as $id) {
            $now = 1700000000;
            $raw = json_encode([
                'type' => 'payment.success',
                'created' => $now,
                'data' => [
                    'paymentId' => $id,
                    'status' => 'success',
                    'mpesaReceipt' => 'SFF6XYZ123',
                    'resultCode' => 0,
                ],
            ], JSON_THROW_ON_ERROR);

            try {
                $event = Webhook::verify(
                    $raw,
                    Webhook::sign($raw, self::SECRET, $now),
                    self::SECRET,
                    300,
                    $now,
                );
            } catch (PaylodSignatureVerificationError $e) {
                // Requirement 8.4: a refusal here must be an ASSERTION failure, not an escaping
                // throwable, or the non-vacuity harness cannot tell a caught mutation from a crash.
                $this->fail("an ordinary payment id was refused: {$id} ({$e->reason})");
            }

            $this->assertSame($id, $event['data']['paymentId']);
        }
    }
}

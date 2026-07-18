<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\DarajaCatalog;
use PHPUnit\Framework\TestCase;

final class DarajaCatalogTest extends TestCase
{
    public function testDecodesCancelledByUser(): void
    {
        $d = DarajaCatalog::decode(1032);
        $this->assertSame('1032', $d['code']);
        $this->assertSame('Payment cancelled by the customer', $d['title']);
        $this->assertSame('customer', $d['category']);
        $this->assertTrue($d['retryable']);
        // Catalog strings are copied verbatim from the canonical source, em-dashes and all.
        $this->assertSame("Payment cancelled \u{2014} you can try again whenever you're ready.", $d['customerMessage']);
    }

    public function testDecodesWrongPinAsCustomerNotCredentials(): void
    {
        // 2001 on the STK path is a WRONG PIN, not an initiator-credential failure.
        $d = DarajaCatalog::decode(2001);
        $this->assertSame('customer', $d['category']);
        $this->assertTrue($d['retryable']);
        $this->assertStringContainsStringIgnoringCase('PIN', $d['customerMessage']);
    }

    public function testDecodesInsufficientBalance(): void
    {
        $d = DarajaCatalog::decode(1);
        $this->assertSame('balance', $d['category']);
        $this->assertTrue($d['retryable']);
    }

    public function testDecodesSuccess(): void
    {
        $d = DarajaCatalog::decode(0);
        $this->assertSame('success', $d['category']);
        $this->assertFalse($d['retryable']);
    }

    public function testPendingCode4999IsNeverRetryable(): void
    {
        // 4999 = customer has not typed their PIN yet. NOT a failure, NOT safe to charge again.
        $d = DarajaCatalog::decode(4999);
        $this->assertSame('pending', $d['category']);
        $this->assertFalse($d['retryable']);
        $this->assertSame('pending', DarajaCatalog::classifyStkResult(4999));
    }

    public function testOverloaded500CodeIsPendingByDefault(): void
    {
        $this->assertSame('pending', DarajaCatalog::classifyStkResult('500.001.1001'));
    }

    public function testOverloaded500CodeIsTerminalWhenMessageSaysMerchantMissing(): void
    {
        $this->assertSame('failed', DarajaCatalog::classifyStkResult('500.001.1001', 'merchant does not exist'));
    }

    public function testUnknownCodeIsIndeterminateAndNotRetryable(): void
    {
        $d = DarajaCatalog::decode(87654, 'some novel failure');
        $this->assertSame('87654', $d['code']);
        $this->assertFalse($d['retryable']); // indeterminate -> never safe to re-charge
        $this->assertSame('mpesa_system', $d['category']);
    }

    public function testAbsentCodeDecodesAsUnknown(): void
    {
        $d = DarajaCatalog::decode(null);
        $this->assertSame('unknown', $d['code']);
        $this->assertFalse($d['retryable']);
    }

    public function testStringAndNumericCodesAgree(): void
    {
        $this->assertEquals(DarajaCatalog::decode('1032'), DarajaCatalog::decode(1032));
    }

    public function testErrorCatalogPrefersStkFamily(): void
    {
        $catalog = DarajaCatalog::errorCatalog();
        // 2001 appears in both stk_result (wrong PIN / customer) and b2c_c2b_result (credentials).
        // STK is the payment path, so it wins.
        $this->assertSame('customer', $catalog['2001']['category']);
    }

    public function testDescriptionSafetyNetOverridesUnknownNumericCode(): void
    {
        // A numeric code we don't know, but the description says it's still processing -> pending.
        $this->assertSame('pending', DarajaCatalog::classifyStkResult(123456, 'The transaction is being processed'));
    }

    /**
     * Owner-approved catalog correction: 17 / 26 / 1025 / 9999 were flipped retryable true -> false.
     * "Safe to charge again" was set on non-authoritative evidence; until no-debit is proven, false
     * is the safe money call.
     */
    public function testCorrectedCatalogCodesAreNotRetryable(): void
    {
        foreach (['17', '26', '1025', '9999'] as $code) {
            $d = DarajaCatalog::decode($code);
            $this->assertFalse($d['retryable'], "code {$code} must not be retryable");
        }
    }

    public function testApiErrorFamilyDecodesTerminallyNotPending(): void
    {
        // A dotted api_error code has no STK entry; it must decode terminally by its real family
        // rather than fall through the STK unknown -> pending rule.
        $d = DarajaCatalog::decode('400.002.02');
        $this->assertSame('credentials', $d['category']);
        $this->assertNotSame('pending', $d['category']);
    }

    public function testB2cC2bFamilyDecodesTerminally(): void
    {
        $d = DarajaCatalog::decode('C2B00011');
        $this->assertSame('customer', $d['category']);
        $this->assertNotSame('pending', $d['category']);
    }

    public function testOverloaded500DecodesTerminallyOnApiErrorSurface(): void
    {
        // Same code, different surface: on the api_error family 500.001.1001 is the terminal server
        // error, NOT "still processing".
        $d = DarajaCatalog::decode('500.001.1001', null, 'api_error');
        $this->assertSame('mpesa_system', $d['category']);

        // On the STK surface it is still pending.
        $stk = DarajaCatalog::decode('500.001.1001');
        $this->assertSame('pending', $stk['category']);
    }

    public function testInsufficientFundsMessageMakes500Terminal(): void
    {
        $this->assertSame('failed', DarajaCatalog::classifyStkResult('500.001.1001', 'Insufficient funds'));
    }

    /**
     * An EXPLICITLY non-STK family must never fall back to an STK entry. 4999 exists in the catalog
     * ONLY as an STK `pending` entry, so the old "any match" fallback decoded 4999+api_error as
     * "payment still in progress" - telling a caller to keep polling an error that will never settle.
     * With no non-STK entry for the code the honest answer is a terminal, non-retryable failure.
     */
    public function testNonStkFamilyNeverFallsBackToAnStkPendingEntry(): void
    {
        foreach (['api_error', 'b2c_c2b_result'] as $family) {
            $d = DarajaCatalog::decode(4999, null, $family);
            $this->assertNotSame('pending', $d['category'], $family);
            $this->assertFalse($d['retryable'], $family);
            $this->assertSame('4999', $d['code'], $family);
            $this->assertSame('Payment failed', $d['title'], $family);
        }

        // The STK surface is untouched: there, 4999 really is an in-flight payment.
        $stk = DarajaCatalog::decode(4999);
        $this->assertSame('pending', $stk['category']);
        $this->assertFalse($stk['retryable']);
    }

    public function testNonStkFamilyPrefersItsOwnEntryOverAnotherNonStkOne(): void
    {
        // 500.001.1001 lives on both the STK and api_error surfaces; asking for api_error must pick
        // the api_error entry, never the STK pending one.
        $d = DarajaCatalog::decode('500.001.1001', null, 'api_error');
        $this->assertNotSame('pending', $d['category']);

        // And an unknown code on a non-STK surface is terminal, not pending.
        $unknown = DarajaCatalog::decode('999999', null, 'api_error');
        $this->assertNotSame('pending', $unknown['category']);
        $this->assertFalse($unknown['retryable']);
    }

    // -- STRICT ZERO EVIDENCE -------------------------------------------------------------------

    /**
     * The only representations of "money moved" the schema defines: the JSON number 0, and the
     * string "0" Daraja sometimes sends instead.
     *
     * @return array<string,array{0:mixed}>
     */
    public static function schemaZeroProvider(): array
    {
        return ['int zero' => [0], 'float zero' => [0.0], 'string zero' => ['0'], 'padded string zero' => ['  0  ']];
    }

    /** @dataProvider schemaZeroProvider */
    public function testOnlyTheSchemaApprovedZeroRepresentationsAreSuccess(mixed $code): void
    {
        $this->assertSame('success', DarajaCatalog::classifyStkResult($code));
    }

    /**
     * Every one of these is `is_numeric()` and loosely equal to zero in PHP, and the old classifier
     * therefore called each of them `success`. A malformed, truncated or hostile record carrying
     * any of them became PAID and a merchant shipped goods on it. None of them is a representation
     * the schema defines, so none of them may prove a payment settled.
     *
     * @return array<string,array{0:mixed}>
     */
    public static function fakeZeroProvider(): array
    {
        return [
            'exponent zero' => ['0e999'],
            'exponent zero uppercase' => ['0E5'],
            'signed zero' => ['+0'],
            'negative zero' => ['-0'],
            'negative float zero' => ['-0.0'],
            'leading zero' => ['00'],
            'decimal zero' => ['0.0'],
            'decimal zero long' => ['0.00000'],
            'hex-ish zero' => ['0x0'],
            'float negative zero' => [-0.0],
        ];
    }

    /** @dataProvider fakeZeroProvider */
    public function testNoOtherZeroLikeRepresentationIsEverSuccess(mixed $code): void
    {
        $this->assertNotSame(
            'success',
            DarajaCatalog::classifyStkResult($code),
            'a non-schema zero representation was accepted as evidence money moved'
        );
    }

    /**
     * And the conservative half of the rule: a representation we do not recognise is classified
     * `pending`, never `failed`. A `failed` classification is what tells a merchant it is safe to
     * charge again, and we have no basis for saying that about a record we cannot read.
     *
     * @dataProvider fakeZeroProvider
     */
    public function testAnUnrecognisedRepresentationIsPendingNotTerminal(mixed $code): void
    {
        $this->assertSame('pending', DarajaCatalog::classifyStkResult($code));
    }

    /** Non-canonical NON-zero numerics are equally unreadable, and equally not terminal. */
    public function testNonCanonicalNonZeroCodesAreNotTerminalEither(): void
    {
        foreach (['+1032', '01032', '1032.0', '1.032e3', ' -1032'] as $code) {
            $this->assertSame('pending', DarajaCatalog::classifyStkResult($code), "code {$code}");
        }
        // The canonical form still classifies exactly as it always did.
        $this->assertSame('failed', DarajaCatalog::classifyStkResult('1032'));
        $this->assertSame('failed', DarajaCatalog::classifyStkResult(1032));
    }

    /**
     * The whole point, end to end: a fake zero must never reach a PAID verdict through the semantic
     * model, under ANY claim - which is the path by which it would have become real money.
     */
    public function testAFakeZeroCanNeverProduceAPaidVerdict(): void
    {
        foreach (self::fakeZeroProvider() as $name => [$code]) {
            foreach (['success', 'pending', 'failed', 'cancelled'] as $claim) {
                $j = \Paylod\Semantics::judge([
                    'id' => 'pay_1',
                    'status' => $claim,
                    'mpesaReceipt' => null,
                    'resultCode' => $code,
                    'resultDesc' => null,
                ]);
                $this->assertNotSame(
                    \Paylod\Semantics::VERDICT_PAID,
                    $j->verdict,
                    "{$name} under claim {$claim} was judged PAID"
                );
            }
        }
    }
}

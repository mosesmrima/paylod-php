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
}

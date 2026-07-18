<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\Exceptions\PaylodInvalidRequestError;
use Paylod\Phone;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function validForms(): array
    {
        return [
            'leading zero' => ['0712345678', '254712345678'],
            'plus 254' => ['+254712345678', '254712345678'],
            '254 prefix' => ['254712345678', '254712345678'],
            'bare 7' => ['712345678', '254712345678'],
            'spaces and dashes' => ['0712 345-678', '254712345678'],
            'safaricom 01 range' => ['0112345678', '254112345678'],
        ];
    }

    /**
     * @dataProvider validForms
     */
    public function testNormalizesEveryAcceptedForm(string $input, string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($input));
    }

    public function testIsValidAcceptsCleanForms(): void
    {
        // isValid checks RAW input shape - spaces/dashes are stripped only by normalize(), so the
        // raw spaced form is (correctly) not itself "valid", exactly as in the Node reference.
        $this->assertTrue(Phone::isValid('0712345678'));
        $this->assertTrue(Phone::isValid('+254712345678'));
        $this->assertTrue(Phone::isValid('712345678'));
        $this->assertFalse(Phone::isValid('0712 345-678'));
    }

    public function testRejectsEmpty(): void
    {
        $this->expectException(PaylodInvalidRequestError::class);
        Phone::normalize('   ');
    }

    public function testRejectsNonKenyan(): void
    {
        $this->expectException(PaylodInvalidRequestError::class);
        Phone::normalize('+1 415 555 0100');
    }

    public function testRejectsTooShort(): void
    {
        $this->expectException(PaylodInvalidRequestError::class);
        Phone::normalize('0712');
    }

    public function testIsValidReturnsFalseForGarbage(): void
    {
        $this->assertFalse(Phone::isValid('not-a-phone'));
    }
}

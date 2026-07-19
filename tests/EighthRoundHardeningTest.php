<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodSignatureVerificationError;
use Paylod\Paylod;
use Paylod\Support\JsonLexeme;
use Paylod\Tests\Support\MockHttpClient;
use Paylod\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * EIGHTH-ROUND regressions.
 *
 * The headline defect: the raw result-code guard was a REGEX over the literal bytes `"resultCode"`,
 * so `{"resultCode":-0}` - the same key, spelled with a legal JSON escape - was never scanned,
 * decoded to the PHP integer `0`, and was reported PAID on the status path and accepted on the
 * signed-webhook path. Every case below is an attack that the literal-bytes guard waved through.
 */
final class EighthRoundHardeningTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    /**
     * Every spelling of the SAME member name, each carrying an impostor zero.
     *
     * The point of the table is that no finite pattern can enumerate it: `C` is one escape of
     * one character, and the same trick applies to every character, in every position, in upper or
     * lower hex, in any combination.
     *
     * @return array<string,array{0:string,1:string}> [raw body, the token that must be reported]
     */
    public static function escapedKeyAttacks(): array
    {
        $k = static fn (array $positions, bool $upper = false): string
            => self::spell('resultCode', $positions, $upper);

        return [
            'literal key, impostor -0' => ['{"resultCode":-0}', '-0'],
            'one escaped char (C)' => ['{"' . $k([6]) . '":-0}', '-0'],
            'lowercase hex escape' => ['{"' . $k([6]) . '":-0}', '-0'],
            'uppercase hex escape' => ['{"' . $k([6], true) . '":-0}', '-0'],
            'escape in first position' => ['{"' . $k([0]) . '":-0}', '-0'],
            'escape in last position' => ['{"' . $k([9]) . '":-0}', '-0'],
            'escape in the middle' => ['{"' . $k([4]) . '":-0}', '-0'],
            'every char escaped' => ['{"' . $k(range(0, 9)) . '":-0}', '-0'],
            'alternating escapes' => ['{"' . $k([0, 2, 4, 6, 8]) . '":-0}', '-0'],
            'escaped key, 0e999' => ['{"' . $k([6]) . '":0e999}', '0e999'],
            'escaped key, 0.0' => ['{"' . $k([6]) . '":0.0}', '0.0'],
            'escaped key, 00' => ['{"' . $k([6]) . '":00}', '00'],
            'escaped key, +0' => ['{"' . $k([6]) . '":+0}', '+0'],
            'escaped key, -0.0e0' => ['{"' . $k([6]) . '":-0.0e0}', '-0.0e0'],
            'duplicate keys, impostor last' => ['{"resultCode":1032,"resultCode":-0}', '-0'],
            'duplicate keys, impostor first' => ['{"resultCode":-0,"resultCode":1032}', '-0'],
            'duplicate keys, escaped last' => ['{"resultCode":1032,"' . $k([6]) . '":-0}', '-0'],
            'duplicate keys, escaped first' => ['{"' . $k([6]) . '":-0,"resultCode":1032}', '-0'],
            'duplicate keys, both escaped' => ['{"' . $k([0]) . '":1032,"' . $k([9]) . '":-0}', '-0'],
            'nested one level' => ['{"data":{"' . $k([6]) . '":-0}}', '-0'],
            'nested three levels' => ['{"a":{"b":{"c":{"' . $k([6]) . '":-0}}}}', '-0'],
            'inside an array element' => ['{"items":[1,2,{"' . $k([6]) . '":-0}]}', '-0'],
            'array of objects, second' => ['{"i":[{"resultCode":1},{"' . $k([6]) . '":-0}]}', '-0'],
            'whitespace around the colon' => ['{"' . $k([6]) . '"' . "\n" . ':' . "\t" . '-0}', '-0'],
            'escaped solidus elsewhere' => ['{"path":"a\/b","' . $k([6]) . '":-0}', '-0'],
            'escaped solidus in the key' => ['{"' . $k([6]) . '":-0,"a\/b":1}', '-0'],
            'unicode escape elsewhere too' => ['{"desc":"é","' . $k([6]) . '":-0}', '-0'],
            'surrogate pair elsewhere' => ['{"e":"😀","' . $k([6]) . '":-0}', '-0'],
            'short escapes elsewhere' => ['{"d":"a\tb\nc","' . $k([6]) . '":-0}', '-0'],
        ];
    }

    /**
     * Spell `$name` with the characters at `$positions` written as JSON `\uXXXX` escapes.
     *
     * Built rather than typed, because the whole point is that the spellings are UNBOUNDED: a test
     * that pins a handful of literals is testing a handful of literals, not the property.
     *
     * @param list<int> $positions
     */
    private static function spell(string $name, array $positions, bool $upper = false): string
    {
        $out = '';
        foreach (str_split($name) as $i => $ch) {
            $out .= in_array($i, $positions, true)
                ? sprintf($upper ? '\u%04X' : '\u%04x', ord($ch))
                : $ch;
        }

        return $out;
    }

    /**
     * @dataProvider escapedKeyAttacks
     * nv:r8-escaped-result-code-key
     */
    public function testEveryEscapedSpellingOfResultCodeIsScanned(string $raw, string $token): void
    {
        // The key really does decode to `resultCode` - i.e. these are not exotic strings, they are
        // the SAME field the money logic reads.
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $this->assertNotSame(
                [],
                $decoded,
                'the attack body must decode to something a handler would read'
            );
        }

        $this->assertSame($token, JsonLexeme::nonCanonicalResultCodeToken($raw), $raw);
    }

    /**
     * The guard must not fire on bodies that are genuinely fine, or a real settlement becomes an
     * outage. These are the false-positive controls for the table above.
     *
     * @return array<string,array{0:string}>
     */
    public static function legitimateBodies(): array
    {
        return [
            'canonical zero' => ['{"resultCode":0}'],
            'canonical non-zero' => ['{"resultCode":1032}'],
            'escaped key, canonical zero' => ['{"' . self::spell('resultCode', [6]) . '":0}'],
            'escaped key, canonical non-zero' => ['{"' . self::spell('resultCode', [0, 9]) . '":1032}'],
            'escaped key that is NOT resultCode' => ['{"' . self::spell('resultDesc', [6]) . '":"-0"}'],
            'string result code' => ['{"resultCode":" 0"}'],
            'null result code' => ['{"resultCode":null}'],
            'absent result code' => ['{"id":"pay_1","status":"pending"}'],
            'other numbers may be anything' => ['{"resultCode":0,"amount":-0,"fee":0e9}'],
            'a similar but different key' => ['{"resultCodeX":-0}'],
            'a shorter prefix key' => ['{"result":-0}'],
            'the token only inside a string' => ['{"resultDesc":"resultCode: -0"}'],
            'nested canonical zeroes' => ['{"data":{"resultCode":0},"b":[{"resultCode":0}]}'],
            'empty object' => ['{}'],
            'empty array' => ['[]'],
            'not JSON at all' => ['<html>gateway error</html>'],
            'empty string' => [''],
        ];
    }

    /**
     * @dataProvider legitimateBodies
     * nv:r8-escaped-result-code-key-controls
     */
    public function testLegitimateBodiesAreNotRefused(string $raw): void
    {
        $this->assertNull(JsonLexeme::nonCanonicalResultCodeToken($raw), $raw);
    }

    /**
     * A body this scanner cannot read but `json_decode()` CAN would be an unexamined path straight
     * to the money logic. There must not be one - and if one ever appears, it must FAIL CLOSED.
     */
    public function testAScanFailureOnADecodableBodyIsRefusedRatherThanWavedThrough(): void
    {
        // Bodies neither can read are simply not JSON - the callers' ordinary handling applies.
        foreach (['{', '{"a":}', 'nul', '{"a":1}trailing'] as $garbage) {
            $this->assertNull(JsonLexeme::nonCanonicalResultCodeToken($garbage), $garbage);
            $this->assertNotSame(JSON_ERROR_NONE, (static function () use ($garbage): int {
                json_decode($garbage, true);

                return json_last_error();
            })(), $garbage);
        }

        // And the sentinel, when it is produced, names no attacker byte.
        $this->assertStringNotContainsString('<script', JsonLexeme::explain(JsonLexeme::UNREADABLE));
        $this->assertStringContainsString('refused', JsonLexeme::explain(JsonLexeme::UNREADABLE));
    }

    // == The same attacks, on the two surfaces that decide about money =============================

    /**
     * @param list<array<string,mixed>> $steps
     */
    private function client(array $steps): Paylod
    {
        return new Paylod('mp_test_x', [
            'httpClient' => new MockHttpClient($steps),
            'allowCustomHttpClient' => true,
            'webhookSecret' => self::SECRET,
        ]);
    }

    /**
     * A `status: "success"` body whose result code is an escaped-key impostor zero must NOT read as
     * paid. Before the fix, `check()` returned `paid = true` here and a merchant shipped goods.
     *
     * nv:r8-status-path-escaped-key
     */
    public function testTheStatusPathRefusesAnEscapedKeyImpostorZero(): void
    {
        foreach (self::escapedKeyAttacks() as $name => [$body, $_token]) {
            $inner = substr($body, 1, -1); // splice the attack's members into a real payment record
            $raw = '{"id":"pay_123","status":"success","mpesaReceipt":"SFF6XYZ123",' . $inner . '}';

            $paylod = $this->client([['status' => 200, 'raw' => $raw]]);

            try {
                $outcome = $paylod->check('pay_123');
                $this->fail("{$name}: accepted, paid=" . var_export($outcome->paid, true));
            } catch (PaylodApiError $e) {
                $this->assertTrue($e->indeterminate, $name);
                $this->assertStringContainsString('resultCode', $e->getMessage(), $name);
            }
        }
    }

    /**
     * The SIGNED webhook path is the other way in, and a valid signature proves only that the bytes
     * came from the signer - not that they are readable. The same table must be refused there.
     *
     * nv:r8-webhook-path-escaped-key
     */
    public function testTheSignedWebhookPathRefusesAnEscapedKeyImpostorZero(): void
    {
        $now = 1700000000;

        foreach (self::escapedKeyAttacks() as $name => [$body, $_token]) {
            $inner = substr($body, 1, -1);
            $raw = '{"type":"payment.success","created":' . $now . ',"data":{"paymentId":"pay_123",'
                . '"status":"success","mpesaReceipt":"SFF6XYZ123",' . $inner . '}}';

            $header = Webhook::sign($raw, self::SECRET, $now);

            try {
                Webhook::verify($raw, $header, self::SECRET, 300, $now);
                $this->fail("{$name}: a correctly-signed impostor zero was accepted");
            } catch (PaylodSignatureVerificationError $e) {
                $this->assertSame('invalid_payload', $e->reason, $name);
            }
        }
    }

    /** A genuine signed settlement still verifies - the guard must not break real webhooks. */
    public function testAGenuineSignedSettlementStillVerifies(): void
    {
        $now = 1700000000;
        $raw = '{"type":"payment.success","created":' . $now . ',"data":{"paymentId":"pay_123",'
            . '"status":"success","mpesaReceipt":"SFF6XYZ123","resultCode":0,"resultDesc":"ok"}}';

        $event = Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);

        $this->assertSame('payment.success', $event['type']);
        $this->assertSame('SFF6XYZ123', $event['data']['mpesaReceipt']);
    }
}

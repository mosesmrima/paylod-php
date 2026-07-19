<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\Exceptions\PaylodApiError;
use Paylod\Exceptions\PaylodCredentialCompromiseError;
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

    // == The verified event is REBUILT from an allowlist ==========================================

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $extraRoot
     * @return array<string,mixed>
     */
    private function verifiedEvent(array $data, array $extraRoot = [], string $type = 'payment.failed'): array
    {
        $now = 1700000000;
        $raw = json_encode($extraRoot + [
            'type' => $type,
            'created' => $now,
            'data' => $data,
        ], JSON_THROW_ON_ERROR);

        return Webhook::verify($raw, Webhook::sign($raw, self::SECRET, $now), self::SECRET, 300, $now);
    }

    /**
     * A NON-retryable failure carrying forged retryability claims at every depth an instinctive
     * handler might read. Every one of them must be gone: result code 17 is a terminal M-Pesa
     * system error, and a `true` on any of these fields tells the caller to charge again.
     *
     * nv:r8-webhook-data-allowlist
     */
    public function testNestedRetryabilityClaimsDoNotSurviveVerification(): void
    {
        $event = $this->verifiedEvent([
            'paymentId' => 'pay_123',
            'status' => 'failed',
            'mpesaReceipt' => null,
            'resultCode' => 17,
            'resultDesc' => 'system error',
            // Every one of these was forwarded verbatim before the allowlist.
            'details' => ['retryable' => true],
            'extra' => ['retryable' => true, 'deep' => ['retryable' => true]],
            'decoded' => ['retryable' => true, 'category' => 'network'],
            'retryable' => true,
            'safeToRetry' => true,
            'meta' => ['anything' => 'at all'],
        ], ['retryable' => true, 'decoded' => ['retryable' => true]]);

        $this->assertFalse($event['data']['retryable']);
        $this->assertFalse($event['data']['decoded']['retryable']);
        $this->assertArrayNotHasKey('retryable', $event);
        $this->assertArrayNotHasKey('decoded', $event);

        foreach (['details', 'extra', 'safeToRetry', 'meta'] as $key) {
            $this->assertArrayNotHasKey($key, $event['data'], $key);
        }

        // And nothing anywhere in the returned graph still claims retryability.
        $this->assertStringNotContainsString('"retryable":true', json_encode($event));
    }

    /**
     * The allowlist is a list of what MAY exist, so an invented field is gone whatever it is called.
     *
     * nv:r8-webhook-root-allowlist
     */
    public function testArbitraryRootAndDataFieldsAreDropped(): void
    {
        $event = $this->verifiedEvent([
            'paymentId' => 'pay_123',
            'status' => 'success',
            'mpesaReceipt' => 'SFF6XYZ123',
            'resultCode' => 0,
            'resultDesc' => 'ok',
            'somethingNobodyNamed' => 'x',
            'nested' => ['a' => ['b' => 'c']],
        ], ['rootJunk' => 'x', 'alsoJunk' => ['deep' => true]], 'payment.success');

        $this->assertSame(['type', 'created', 'data'], array_keys($event));
        $this->assertArrayNotHasKey('somethingNobodyNamed', $event['data']);
        $this->assertArrayNotHasKey('nested', $event['data']);

        // The legitimate payload survives intact.
        $this->assertSame('pay_123', $event['data']['paymentId']);
        $this->assertSame('SFF6XYZ123', $event['data']['mpesaReceipt']);
        $this->assertTrue($event['data']['decoded']['retryable'] === false);
    }

    /**
     * An UNKNOWN event type is forward-compatible, but forward compatibility must not be a channel
     * for an unverifiable claim: nested structures and derived names are both gone.
     *
     * nv:r8-webhook-unknown-type
     */
    public function testAnUnknownEventTypeIsRepresentedMinimally(): void
    {
        $event = $this->verifiedEvent([
            'payoutId' => 'po_1',
            'amount' => 100,
            'details' => ['retryable' => true],
            'retryable' => true,
            'decoded' => ['retryable' => true],
        ], [], 'payout.something.new');

        $this->assertSame('payout.something.new', $event['type']);
        $this->assertSame(['payoutId' => 'po_1', 'amount' => 100], $event['data']);
        $this->assertArrayNotHasKey('retryable', $event);
    }

    // == Diagnostics, secrets and bounds ==========================================================

    /**
     * A malformed 2xx quotes the offending value back at the reader. When a gateway echoes the
     * bearer token into that value, the diagnostic put a live key into the exception message and
     * from there into the application's error log.
     *
     * nv:r8-diagnostic-redaction
     */
    public function testMalformedBodyDiagnosticsAreRedacted(): void
    {
        $key = 'mp_test_supersecretkey';

        // (a) the acknowledgement path: `status` is quoted verbatim.
        $paylod = new Paylod($key, [
            'httpClient' => new MockHttpClient([['status' => 202, 'json' => [
                'paymentId' => 'pay_123',
                'checkoutRequestId' => 'ws_CO_1',
                'status' => $key,
            ]]]),
            'allowCustomHttpClient' => true,
        ]);

        try {
            $paylod->collect(['amount' => 100, 'phone' => '0712345678', 'idempotencyKey' => 'k-1']);
            $this->fail('expected a malformed-ack error');
        } catch (PaylodApiError $e) {
            $this->assertStringNotContainsString('supersecretkey', $e->getMessage());
        }

        // (b) the status path: an unknown `status` string is quoted verbatim.
        $paylod = new Paylod($key, [
            'httpClient' => new MockHttpClient([['status' => 200, 'json' => [
                'id' => 'pay_123',
                'status' => $key,
                'mpesaReceipt' => null,
                'resultCode' => null,
                'resultDesc' => null,
            ]]]),
            'allowCustomHttpClient' => true,
        ]);

        try {
            $paylod->status('pay_123');
            $this->fail('expected a malformed-payment error');
        } catch (PaylodApiError $e) {
            $this->assertStringNotContainsString('supersecretkey', $e->getMessage());
        }
    }

    /**
     * A correctly-signed event that echoes the WEBHOOK SECRET back must not hand it to the handler.
     *
     * SUPERSEDED BY ROUND 9, which is why this carries no `nv:` tag of its own any more: the
     * round-8 property was "redact the secret out of the delivered event", and the round-9 rule is
     * strictly stronger - REFUSE the event outright. The mutation case that proves the stronger rule
     * load-bearing is `r9-webhook-refuses-echoed-secret`. Reverting the round-8 redaction line no
     * longer changes this test's outcome, because the body never reaches it.
     */
    public function testTheSuppliedWebhookSecretIsRedactedOutOfTheDecodedEvent(): void
    {
        $secret = 'a-secret-that-is-not-credential-shaped';
        $now = 1700000000;
        $raw = json_encode([
            'type' => 'payment.failed',
            'created' => $now,
            'data' => [
                'paymentId' => 'pay_123',
                'status' => 'failed',
                'resultCode' => 1032,
                'resultDesc' => 'echoed: ' . $secret,
            ],
        ], JSON_THROW_ON_ERROR);

        // ROUND 9 STRENGTHENED THIS FROM REDACT-AND-DELIVER TO REFUSE.
        //
        // It used to assert only that the secret was absent from the returned event. That is a
        // strictly weaker property, and round 9 showed why it is not enough: the redactor's own
        // output, `[redacted]`, was then read as a valid M-Pesa receipt, so redacting a credential
        // turned it into proof of payment. A signed body that echoes our secret comes from a
        // compromised or misconfigured sender, and the rest of it is not trustworthy either. It is
        // now refused outright.
        try {
            Webhook::verify($raw, Webhook::sign($raw, $secret, $now), $secret, 300, $now);
            $this->fail('expected the echoed webhook secret to be REFUSED, not sanitised');
        } catch (PaylodCredentialCompromiseError $e) {
            $this->assertStringNotContainsString($secret, $e->getMessage());
            $this->assertStringContainsString('ECHOES YOUR WEBHOOK SIGNING SECRET', $e->getMessage());
        }
    }

    /**
     * The body ceiling is checked on `strlen()` AFTER `(string) $payload` has already materialised a
     * Stringable in full - so on an unauthenticated endpoint the read IS the denial of service. A
     * non-string payload is refused rather than measured.
     *
     * nv:r8-webhook-stringable-refused
     */
    public function testANonStringPayloadIsRefusedRatherThanMaterialised(): void
    {
        $materialised = false;
        $payload = new class ($materialised) implements \Stringable {
            public function __construct(private bool &$flag)
            {
            }

            public function __toString(): string
            {
                $this->flag = true;

                return '{"type":"payment.success","created":1,"data":{}}';
            }
        };

        try {
            Webhook::verify($payload, 't=1,v1=' . str_repeat('a', 64), self::SECRET, 300, 1);
            $this->fail('a Stringable payload was accepted');
        } catch (PaylodSignatureVerificationError $e) {
            $this->assertSame('invalid_payload', $e->reason);
        }

        $this->assertFalse($materialised, '__toString() ran before the payload was refused');
    }

    /**
     * The signature header was exploded without any ceiling: a megabyte of commas is a million-entry
     * array built, unauthenticated, to conclude "malformed".
     *
     * nv:r8-signature-header-bounds
     */
    public function testTheSignatureHeaderIsBoundedBeforeItIsParsed(): void
    {
        $raw = '{"type":"payment.success","created":1,"data":{}}';

        // THE MESSAGE IS THE DISCRIMINATING ASSERTION, not the reason code. An unbounded
        // parseHeader() reaches the SAME `malformed_signature` verdict by simply failing to find a
        // `t`/`v1` pair - so asserting only the reason passes with the ceilings removed, which is
        // exactly the vacuous test this suite exists to refuse. The ceiling must be what refused it.
        try {
            Webhook::verify($raw, str_repeat(',', 200000), self::SECRET, 300, 1);
            $this->fail('an oversized signature header was parsed');
        } catch (PaylodSignatureVerificationError $e) {
            $this->assertSame('malformed_signature', $e->reason);
            $this->assertStringContainsString('exceeds the', $e->getMessage());
            $this->assertStringContainsString((string) Webhook::MAX_SIGNATURE_HEADER_BYTES, $e->getMessage());
        }

        // Short but with too many segments: bounded on segment count, not only on bytes.
        try {
            Webhook::verify($raw, str_repeat('a=b,', 50), self::SECRET, 300, 1);
            $this->fail('a many-segment signature header was parsed');
        } catch (PaylodSignatureVerificationError $e) {
            $this->assertSame('malformed_signature', $e->reason);
            $this->assertStringContainsString('comma-separated segments', $e->getMessage());
        }
    }

    /**
     * PCRE's `$` matches before a trailing newline, so a money-path validator accepted
     * `"0712345678\n"`.
     *
     * nv:r8-phone-anchor
     */
    public function testAPhoneWithATrailingNewlineIsNotValid(): void
    {
        $this->assertSame(1, preg_match(\Paylod\Phone::INPUT_RE, '0712345678'));
        $this->assertSame(0, preg_match(\Paylod\Phone::INPUT_RE, "0712345678\n"));
    }

    // == The simulator is the PRODUCTION path with the handset removed, and nothing else ==========

    /** @param list<array<string,mixed>> $steps */
    private function sim(array $steps): \Paylod\Simulator
    {
        return (new Paylod('mp_test_x', [
            'httpClient' => new MockHttpClient($steps),
            'allowCustomHttpClient' => true,
        ]))->simulator;
    }

    private const ACK_STEP = ['status' => 202, 'json' => [
        'paymentId' => 'pay_sim',
        'checkoutRequestId' => 'ws_sim',
        'status' => 'pending',
        'outcomes' => [],
    ]];

    /**
     * The simulator is what a developer uses to convince themselves a double-click cannot charge
     * twice. It was the one surface that silently generated a key when none was passed.
     *
     * nv:r8-simulator-key-required
     */
    public function testTheSimulatorRequiresAnIdempotencyKeyJustLikeProduction(): void
    {
        $this->expectException(\Paylod\Exceptions\PaylodInvalidRequestError::class);
        $this->expectExceptionMessageMatches('/requires an idempotencyKey/');

        $this->sim([self::ACK_STEP])->collect(['amount' => 100]);
    }

    /**
     * The production ceiling applies here too: the simulator required only "a positive int", so an
     * amount production refuses dispatched from the surface that stands in for production.
     *
     * nv:r8-simulator-amount-ceiling
     */
    public function testTheSimulatorEnforcesTheProductionAmountCeiling(): void
    {
        $this->expectException(\Paylod\Exceptions\PaylodInvalidRequestError::class);
        $this->expectExceptionMessageMatches('/between 1 and 150000/');

        $this->sim([self::ACK_STEP])->collect(['amount' => 10_000_000, 'idempotencyKey' => 'k']);
    }

    /**
     * A transport failure must leave the caller holding the EFFECTIVE key - a fresh one is a second
     * charge. The simulator dropped it.
     *
     * nv:r8-simulator-failure-context
     */
    public function testASimulatorDispatchFailureCarriesTheEffectiveKey(): void
    {
        try {
            $this->sim([['throw' => true]])->collect(['amount' => 100, 'idempotencyKey' => 'k-real']);
            $this->fail('expected a dispatch failure');
        } catch (\Paylod\Exceptions\PaylodException $e) {
            $this->assertSame('k-real', $e->idempotencyKey);
        }
    }

    /**
     * `outcomes` is returned into ordinary, logged output. It was forwarded from the server
     * unvalidated and unredacted, so an echoed API key rode out through a non-error path.
     *
     * nv:r8-simulator-outcomes-allowlist
     */
    public function testTheAcknowledgedOutcomesAreRebuiltFromTheClosedSet(): void
    {
        $created = $this->sim([['status' => 202, 'json' => [
            'paymentId' => 'pay_sim',
            'checkoutRequestId' => 'ws_sim',
            'status' => 'pending',
            'outcomes' => ['approve', 'mp_test_supersecretkey', ['nested' => true], 'not_an_outcome', 'timeout'],
        ]]])->collect(['amount' => 100, 'idempotencyKey' => 'k']);

        $this->assertSame(['approve', 'timeout'], $created['outcomes']);
        $this->assertStringNotContainsString('supersecretkey', json_encode($created));
    }

    /**
     * `pay()` validated its outcome only AFTER collect() had created a payment, so a typo left a
     * stranded pending payment behind. Nothing may be created on the way to rejecting the request.
     *
     * nv:r8-simulator-validate-before-mutate
     */
    public function testPayValidatesTheOutcomeBeforeCreatingAnything(): void
    {
        $transport = new MockHttpClient([self::ACK_STEP]);
        $paylod = new Paylod('mp_test_x', [
            'httpClient' => $transport,
            'allowCustomHttpClient' => true,
        ]);

        try {
            $paylod->simulator->pay(['outcome' => 'nope', 'amount' => 100, 'idempotencyKey' => 'k']);
            $this->fail('expected an invalid-outcome error');
        } catch (\Paylod\Exceptions\PaylodInvalidRequestError $e) {
            $this->assertStringContainsString('outcome must be one of', $e->getMessage());
        }

        $this->assertSame(0, $transport->count(), 'a payment was created before the request was refused');
    }

    /**
     * THE SURROGATE'S OWN TRACE. sanitizedCause() drops the original throwable from the `previous`
     * chain precisely so its message, its trace and its recorded call arguments cannot travel - and
     * then handed it back anyway, because with `zend.exception_ignore_args=0` PHP records CALL
     * ARGUMENTS in every frame, and the surrogate's own trace contains the sanitizedCause() frame
     * whose arguments are the discarded throwable and the client-bound redactor.
     *
     * Run in a SUBPROCESS with argument recording ON, and the COMPLETE trace graph is inspected -
     * getTraceAsString() abbreviates arguments, so a secret survives there while the string looks
     * clean.
     *
     * nv:r8-sanitized-cause-trace
     */
    public function testTheSanitizedSurrogateDoesNotCarryTheOriginalInItsOwnTraceArguments(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $this->assertFileExists($autoload, 'the probe cannot run without a composer autoloader');

        $script = <<<PHP
        require '{$autoload}';
        \$client = new class implements Paylod\Http\HttpClient {
            public function send(string \$m, string \$u, array \$h, ?string \$b, int \$t): array
            {
                // A NON-paylod throwable carrying the credential - the case sanitizedCause() exists
                // for. It is caught, redacted, and replaced by a surrogate.
                throw new RuntimeException('transport blew up with mp_test_LEAKTRACE in hand');
            }
        };
        try {
            \$p = new Paylod\Paylod('mp_test_LEAKTRACE', ['httpClient' => \$client, 'allowCustomHttpClient' => true]);
            \$p->collect(['amount' => 1, 'phone' => '0712345678', 'idempotencyKey' => 'k']);
        } catch (Throwable \$e) {
            echo "MSG:", \$e->getMessage(), "\n";
            echo "TRACE:", print_r(\$e->getTrace(), true), "\n";
            for (\$prev = \$e->getPrevious(); \$prev !== null; \$prev = \$prev->getPrevious()) {
                echo "PREV:", \$prev->getMessage(), "\n";
                echo "PREVTRACE:", print_r(\$prev->getTrace(), true), "\n";
            }
        }
        PHP;

        $file = sys_get_temp_dir() . '/paylod_r8_trace_probe_' . getmypid() . '.php';
        $written = file_put_contents($file, "<?php\n" . $script);

        try {
            // The probe's own machinery, asserted before its verdict is trusted - a failed write
            // prints "Could not open input file", which contains no leak marker and would otherwise
            // read as a pass.
            $this->assertNotFalse($written, 'could not write the probe script');
            $this->assertGreaterThan(0, (int) $written);

            $cmd = escapeshellarg(PHP_BINARY) . ' -d zend.exception_ignore_args=0 -d error_reporting=0 '
                . escapeshellarg($file) . ' 2>&1';
            $out = (string) shell_exec($cmd);

            $this->assertStringContainsString('TRACE:', $out, "the probe did not run: {$out}");
            $this->assertStringContainsString('Array', $out, 'no structured trace was produced');
            $this->assertStringNotContainsString('LEAKTRACE', $out, "the credential survived: {$out}");
        } finally {
            @unlink($file);
        }
    }

    // == The cross-SDK checks =====================================================================

    /**
     * EVERY EXPOSED `retryable`, ACROSS THE WHOLE SEMANTIC CROSS-PRODUCT.
     *
     * `retryable` means SAFE TO CHARGE AGAIN and this object publishes it TWICE - as
     * `$outcome->retryable` and as `$outcome->detail['retryable']`. A guarantee that holds at only
     * one of the two places it is published is not a guarantee, and the dangerous cell is the one
     * nothing covered: an INDETERMINATE record whose CODE is retryable in the catalog. A handler
     * reading the decoded block - where the title, cause and fix live, so a natural thing to read -
     * would be told to re-charge a customer who is holding an M-Pesa receipt.
     *
     * @dataProvider retryableCrossProduct
     * nv:r8-retryable-cross-product
     */
    public function testEveryExposedRetryableFieldAgreesAcrossTheCrossProduct(
        array $payment,
        string $expectedStatus,
        bool $expectedRetryable,
    ): void {
        $outcome = \Paylod\PaymentOutcome::fromPayment($payment);

        $this->assertSame($expectedStatus, $outcome->status);
        $this->assertSame($expectedRetryable, $outcome->retryable);

        if ($outcome->detail !== null) {
            // The nested flag is the SAME value, never an independent second reading.
            $this->assertSame(
                $expectedRetryable,
                $outcome->detail['retryable'],
                'the nested detail disagrees with the top-level verdict'
            );
        }

        // And nothing anywhere in the rendered object claims retryability when the verdict does not.
        if (!$expectedRetryable) {
            $this->assertStringNotContainsString('"retryable":true', json_encode($outcome->toArray()));
        }
    }

    /** @return array<string,array{0:array<string,mixed>,1:string,2:bool}> */
    public static function retryableCrossProduct(): array
    {
        $record = static fn (string $status, ?string $receipt, mixed $code): array => [
            'id' => 'pay_1',
            'status' => $status,
            'mpesaReceipt' => $receipt,
            'resultCode' => $code,
            'resultDesc' => 'x',
        ];

        return [
            // PAID - never retryable, charging again is a second charge.
            'paid' => [$record('success', 'SFF6XYZ123', 0), 'succeeded', false],
            // INDETERMINATE with a catalog-RETRYABLE code. THE cell the round-7 audit named.
            'indeterminate, retryable code' => [$record('failed', 'SFF6XYZ123', 1032), 'pending', false],
            'indeterminate, cancelled+receipt' => [$record('cancelled', 'SFF6XYZ123', 1032), 'pending', false],
            // IN FLIGHT - a live prompt is never safe to re-charge.
            'in flight, pending code' => [$record('pending', null, '500.001.1001'), 'pending', false],
            'in flight, no code' => [$record('pending', null, null), 'pending', false],
            'in flight, code 4999' => [$record('pending', null, 4999), 'pending', false],
            // TERMINAL FAILURE - the ONE place the nested flag may be true, and only in step.
            'terminal, retryable code' => [$record('failed', null, 1032), 'cancelled', true],
            'terminal, non-retryable code 17' => [$record('failed', null, 17), 'failed', false],
            'terminal, non-retryable code 26' => [$record('failed', null, 26), 'failed', false],
            'terminal, non-retryable code 1025' => [$record('failed', null, 1025), 'failed', false],
            'terminal, non-retryable code 9999' => [$record('failed', null, 9999), 'failed', false],
        ];
    }

    /**
     * THE DECOMPRESSION-BEFORE-CAP TRAP, pinned shut.
     *
     * A response-size ceiling applied AFTER automatic decompression is no ceiling: a few kilobytes
     * of gzip expand to gigabytes in memory before the check ever runs. cURL only decompresses when
     * it was asked to - via CURLOPT_ENCODING / CURLOPT_ACCEPT_ENCODING, or an explicit
     * Accept-Encoding request header - and this client sets neither, so its WRITEFUNCTION ceiling
     * counts bytes as they arrive on the wire.
     *
     * That is a property of what the transport does NOT do, which is exactly the kind of thing that
     * gets undone by a well-meaning "let's enable compression" patch. Pinned here so it cannot be.
     *
     * nv:r8-no-decompression-before-cap
     */
    public function testTheTransportNeverAsksForAutomaticDecompression(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Http/CurlHttpClient.php');

        $this->assertStringNotContainsString('CURLOPT_ENCODING', $source);
        $this->assertStringNotContainsString('CURLOPT_ACCEPT_ENCODING', $source);

        // And no layer above it asks for compression in the request headers either.
        foreach (['src/Http/Transport.php', 'src/Paylod.php'] as $file) {
            $this->assertStringNotContainsStringIgnoringCase(
                'Accept-Encoding',
                (string) file_get_contents(dirname(__DIR__) . '/' . $file),
                $file
            );
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

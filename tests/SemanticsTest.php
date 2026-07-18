<?php

declare(strict_types=1);

namespace Paylod\Tests;

use Paylod\Judgement;
use Paylod\Semantics;
use PHPUnit\Framework\TestCase;

/**
 * ROOT 2 - the semantic model.
 *
 * These tests assert the LAWS, not the implementation. They are the contract the sibling SDKs
 * mirror, so a change here is a change to what the SDK promises about money.
 */
final class SemanticsTest extends TestCase
{
    /** @param array<string,mixed> $over */
    private static function payment(array $over = []): array
    {
        return $over + [
            'id' => 'pay_1',
            'status' => 'pending',
            'mpesaReceipt' => null,
            'resultCode' => null,
            'resultDesc' => null,
        ];
    }

    private static function verdict(array $over = []): string
    {
        return Semantics::judge(self::payment($over))->verdict;
    }

    // -- Stage 1: EVIDENCE ---------------------------------------------------------------------

    public function testEvidenceIsDerivedWithoutLookingAtTheClaim(): void
    {
        // The SAME evidence, under three different claims, must classify identically. A claim is
        // never evidence for itself.
        foreach (['pending', 'success', 'failed'] as $claim) {
            self::assertSame(
                Semantics::EVIDENCE_SUCCESS,
                Semantics::evidenceFor(self::payment(['status' => $claim, 'resultCode' => 0])),
                "evidence leaked the claim for status={$claim}"
            );
        }
    }

    public function testEvidenceForEnumeratesTheFiveKinds(): void
    {
        self::assertSame(Semantics::EVIDENCE_NONE, Semantics::evidenceFor(self::payment()));
        self::assertSame(
            Semantics::EVIDENCE_SUCCESS,
            Semantics::evidenceFor(self::payment(['resultCode' => 0]))
        );
        self::assertSame(
            Semantics::EVIDENCE_SUCCESS,
            Semantics::evidenceFor(self::payment(['mpesaReceipt' => 'SFF6XYZ123']))
        );
        self::assertSame(
            Semantics::EVIDENCE_FAILURE,
            Semantics::evidenceFor(self::payment(['resultCode' => 1032]))
        );
        self::assertSame(
            Semantics::EVIDENCE_IN_FLIGHT,
            Semantics::evidenceFor(self::payment(['resultCode' => 4999]))
        );
        self::assertSame(
            Semantics::EVIDENCE_CONFLICT,
            Semantics::evidenceFor(self::payment(['mpesaReceipt' => 'SFF6XYZ123', 'resultCode' => 1032]))
        );
    }

    public function testABlankReceiptIsNotEvidence(): void
    {
        self::assertSame(
            Semantics::EVIDENCE_NONE,
            Semantics::evidenceFor(self::payment(['mpesaReceipt' => '   ']))
        );
    }

    // -- Stage 2: the TOTAL table --------------------------------------------------------------

    /**
     * THE PINNED VERDICT TABLE, keyed `claim|evidence`.
     *
     * Every one of the 25 cells of `Semantics::CLAIMS x Semantics::EVIDENCE_KINDS` appears here
     * exactly once. It is NOT a hand-written list of interesting cases: the provider below walks
     * the full cross-product and looks each pair up in this map, so an omission anywhere - here or
     * in the implementation - is a hard failure rather than a cell that quietly falls through to a
     * permissive default. That is the whole point: the defect class this file exists to prevent has
     * always taken the shape of an unenumerated pair resolved by a default branch.
     *
     * @return array<string,string>
     */
    private const EXPECTED = [
        // claim = success
        'success|success' => Semantics::VERDICT_PAID,
        'success|none' => Semantics::VERDICT_INDETERMINATE,
        'success|failure' => Semantics::VERDICT_INDETERMINATE,
        'success|in_flight' => Semantics::VERDICT_INDETERMINATE,
        'success|conflict' => Semantics::VERDICT_INDETERMINATE,

        // claim = pending
        'pending|success' => Semantics::VERDICT_INDETERMINATE,
        'pending|none' => Semantics::VERDICT_IN_FLIGHT,
        'pending|failure' => Semantics::VERDICT_INDETERMINATE,
        'pending|in_flight' => Semantics::VERDICT_IN_FLIGHT,
        'pending|conflict' => Semantics::VERDICT_INDETERMINATE,

        // claim = failed. Note `failed|in_flight`: the claim is terminal, the code says the prompt
        // is live, and the SDK refuses to pick a winner. Never failed (that invites a retry against
        // a live prompt) and no longer in_flight either (that asserts a state the record does not
        // establish). Indeterminate still renders as `pending`, so wait() keeps polling.
        'failed|success' => Semantics::VERDICT_INDETERMINATE,
        'failed|none' => Semantics::VERDICT_FAILED,
        'failed|failure' => Semantics::VERDICT_FAILED,
        'failed|in_flight' => Semantics::VERDICT_INDETERMINATE,
        'failed|conflict' => Semantics::VERDICT_INDETERMINATE,

        // claim = cancelled (enumerated explicitly, never a default)
        'cancelled|success' => Semantics::VERDICT_INDETERMINATE,
        'cancelled|none' => Semantics::VERDICT_FAILED,
        'cancelled|failure' => Semantics::VERDICT_FAILED,
        'cancelled|in_flight' => Semantics::VERDICT_INDETERMINATE,
        'cancelled|conflict' => Semantics::VERDICT_INDETERMINATE,

        // claim = unknown - a status outside the closed set. Five rows, not one default.
        'unknown|success' => Semantics::VERDICT_INDETERMINATE,
        'unknown|none' => Semantics::VERDICT_INDETERMINATE,
        'unknown|failure' => Semantics::VERDICT_INDETERMINATE,
        'unknown|in_flight' => Semantics::VERDICT_INDETERMINATE,
        'unknown|conflict' => Semantics::VERDICT_INDETERMINATE,
    ];

    /**
     * A record whose EVIDENCE classifies as the requested kind. These are the witnesses; the claim
     * is supplied separately by the provider.
     *
     * @return array<string,mixed>
     */
    private static function fieldsFor(string $evidence): array
    {
        return match ($evidence) {
            Semantics::EVIDENCE_SUCCESS => ['resultCode' => 0],
            Semantics::EVIDENCE_NONE => [],
            Semantics::EVIDENCE_FAILURE => ['resultCode' => 1032],
            Semantics::EVIDENCE_IN_FLIGHT => ['resultCode' => 4999],
            Semantics::EVIDENCE_CONFLICT => ['mpesaReceipt' => 'SFF6XYZ123', 'resultCode' => 1032],
        };
    }

    /** The `status` string that normalises to a given claim. */
    private static function statusFor(string $claim): string
    {
        // `unknown` is reached through a status the SDK does not know, not through a magic word.
        return $claim === Semantics::CLAIM_UNKNOWN ? 'settled' : $claim;
    }

    /**
     * The FULL cross-product, generated - never hand-listed.
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function tableProvider(): array
    {
        $cases = [];
        foreach (Semantics::CLAIMS as $claim) {
            foreach (Semantics::EVIDENCE_KINDS as $evidence) {
                $cases["{$claim} + {$evidence} evidence"] = [$claim, $evidence, "{$claim}|{$evidence}"];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider tableProvider
     */
    public function testTheJudgeTableIsTotalAndPinned(string $claim, string $evidence, string $key): void
    {
        // A pair with no pinned expectation is a HOLE in this test, and must fail loudly rather
        // than be skipped - a missing cell is precisely the bug being guarded against.
        self::assertArrayHasKey($key, self::EXPECTED, "no pinned verdict for the pair {$key}");

        $payment = self::payment(self::fieldsFor($evidence) + ['status' => self::statusFor($claim)]);
        $judgement = Semantics::judge($payment);

        // The witness really does produce the evidence kind this row is about, so the row is
        // testing the cell it claims to test.
        self::assertSame($evidence, $judgement->evidence, "wrong evidence witness for {$key}");
        self::assertSame(self::EXPECTED[$key], $judgement->verdict, "wrong verdict for {$key}");
    }

    /** The cross-product is COMPLETE: 5 claims x 5 evidence kinds, no more and no fewer. */
    public function testTheTableCoversEveryClaimTimesEveryEvidenceKind(): void
    {
        $expectedPairs = count(Semantics::CLAIMS) * count(Semantics::EVIDENCE_KINDS);
        self::assertSame(25, $expectedPairs, 'the alphabets changed - the table must change with them');
        self::assertCount($expectedPairs, self::EXPECTED);
        self::assertCount($expectedPairs, self::tableProvider());
        self::assertSame(array_keys(self::EXPECTED), array_keys(array_flip(array_map(
            static fn (array $c): string => $c[2],
            array_values(self::tableProvider()),
        ))), 'the generated pairs and the pinned pairs are not the same set');
    }

    /**
     * The claim alphabet is CLOSED: anything outside it - an unknown word, an empty string, a
     * non-string, a missing key - normalises to `unknown` and gets the unknown rows. There is no
     * default arm in the verdict table for it to fall into.
     */
    public function testAnUnknownClaimIsIndeterminateAndNeverFallsThroughToAPermissiveDefault(): void
    {
        foreach (['settled', '', 'SUCCESS', 'paid', 'complete'] as $status) {
            self::assertSame(
                Semantics::CLAIM_UNKNOWN,
                Semantics::claimFor(['status' => $status]),
                "status {$status} should not be a recognised claim"
            );
            self::assertSame(Semantics::VERDICT_INDETERMINATE, self::verdict(['status' => $status, 'resultCode' => 0]));
        }
        foreach ([null, 0, 1, [], true] as $status) {
            self::assertSame(Semantics::CLAIM_UNKNOWN, Semantics::claimFor(['status' => $status]));
        }
        self::assertSame(Semantics::CLAIM_UNKNOWN, Semantics::claimFor([]));
    }

    // -- The LAWS ------------------------------------------------------------------------------

    /**
     * L2: paid ALWAYS has success evidence. Asserted over the WHOLE table rather than on one
     * example, so no future row can introduce an evidence-free `paid`.
     */
    public function testLawL2PaidAlwaysHasSuccessEvidence(): void
    {
        foreach (self::tableProvider() as $name => [$claim, $evidence, $_key]) {
            $j = Semantics::judge(self::payment(self::fieldsFor($evidence) + ["status" => self::statusFor($claim)]));
            if ($j->verdict === Semantics::VERDICT_PAID) {
                self::assertSame(Semantics::EVIDENCE_SUCCESS, $j->evidence, "L2 violated by: {$name}");
            }
        }
    }

    /**
     * L2, the converse: success WITHOUT a receipt is legitimate. Receipts attach asynchronously, so
     * requiring one outright would report real money as unproven.
     */
    public function testLawL2SuccessWithoutAReceiptIsStillPaid(): void
    {
        $j = Semantics::judge(self::payment(['status' => 'success', 'resultCode' => 0, 'mpesaReceipt' => null]));
        self::assertSame(Semantics::VERDICT_PAID, $j->verdict);
    }

    /** L3: a contradiction is INDETERMINATE - never a failure, and never a retryable one. */
    public function testLawL3AContradictionIsIndeterminateAndNeverAFailure(): void
    {
        foreach (self::tableProvider() as $name => [$claim, $evidence, $_key]) {
            $j = Semantics::judge(self::payment(self::fieldsFor($evidence) + ["status" => self::statusFor($claim)]));
            if ($j->evidence === Semantics::EVIDENCE_CONFLICT) {
                self::assertSame(Semantics::VERDICT_INDETERMINATE, $j->verdict, "L3 violated by: {$name}");
            }
        }
    }

    /** L4: a receipt present forces paid-or-indeterminate. Never failed, never in flight. */
    public function testLawL4AReceiptForcesPaidOrIndeterminate(): void
    {
        foreach (['pending', 'success', 'failed', 'cancelled'] as $claim) {
            foreach ([null, 0, 1032, 4999, 2001, 1, '500.001.1001'] as $code) {
                $j = Semantics::judge(self::payment([
                    'status' => $claim,
                    'mpesaReceipt' => 'SFF6XYZ123',
                    'resultCode' => $code,
                ]));
                self::assertContains(
                    $j->verdict,
                    [Semantics::VERDICT_PAID, Semantics::VERDICT_INDETERMINATE],
                    "L4 violated by status={$claim} code=" . var_export($code, true)
                );
            }
        }
    }

    // -- The three behaviours that CHANGED ------------------------------------------------------

    /**
     * Was: PAID. A record that simultaneously says "not finished" and "succeeded" is not money in
     * the bank - it is a row mid-write, or one we are misreading.
     */
    public function testChangedAPendingRowCarryingCodeZeroIsNoLongerPaid(): void
    {
        $j = Semantics::judge(self::payment(['status' => 'pending', 'resultCode' => 0]));
        self::assertSame(Semantics::VERDICT_INDETERMINATE, $j->verdict);
        self::assertNotSame(Semantics::VERDICT_PAID, $j->verdict);
    }

    /**
     * Was: `cancelled`, retryable TRUE - the SDK told a merchant it was safe to charge again for a
     * payment carrying an M-Pesa receipt. This is the double-charge generator.
     */
    public function testChangedAFailedRowCarryingAReceiptAndCode1032IsIndeterminateNotRetryable(): void
    {
        $j = Semantics::judge(self::payment([
            'status' => 'failed',
            'mpesaReceipt' => 'SFF6XYZ123',
            'resultCode' => 1032,
        ]));
        self::assertSame(Semantics::VERDICT_INDETERMINATE, $j->verdict);
        self::assertNotSame(Semantics::VERDICT_FAILED, $j->verdict);
    }

    /** Was: paid (the receipt was read as proof while the row said pending). */
    public function testChangedAPendingRowCarryingAReceiptIsIndeterminate(): void
    {
        $j = Semantics::judge(self::payment(['status' => 'pending', 'mpesaReceipt' => 'SFF6XYZ123']));
        self::assertSame(Semantics::VERDICT_INDETERMINATE, $j->verdict);
    }

    public function testAJudgementCarriesTheClaimTheEvidenceAndAReason(): void
    {
        $j = Semantics::judge(self::payment(['status' => 'pending', 'resultCode' => 0]));
        self::assertInstanceOf(Judgement::class, $j);
        self::assertSame('pending', $j->claimed);
        self::assertSame(Semantics::EVIDENCE_SUCCESS, $j->evidence);
        self::assertNotSame('', $j->reason);
        self::assertTrue($j->isIndeterminate());
        self::assertFalse($j->isPaid());
    }
}

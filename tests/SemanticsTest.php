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
     * The judge table is TOTAL over (claim, evidence). Every cell is pinned here, so a change to
     * any one of them is a deliberate edit to this table and not an accident in a default branch.
     *
     * @return array<string,array{0:string,1:array<string,mixed>,2:string}>
     */
    public static function tableProvider(): array
    {
        $receipt = 'SFF6XYZ123';

        return [
            // claim = success
            'success + success evidence (receipt)' => ['success', ['mpesaReceipt' => $receipt], Semantics::VERDICT_PAID],
            'success + success evidence (code 0)' => ['success', ['resultCode' => 0], Semantics::VERDICT_PAID],
            'success + no evidence' => ['success', [], Semantics::VERDICT_INDETERMINATE],
            'success + failure evidence' => ['success', ['resultCode' => 1032], Semantics::VERDICT_INDETERMINATE],
            'success + in-flight evidence' => ['success', ['resultCode' => 4999], Semantics::VERDICT_INDETERMINATE],
            'success + conflict' => ['success', ['mpesaReceipt' => $receipt, 'resultCode' => 1032], Semantics::VERDICT_INDETERMINATE],

            // claim = pending
            'pending + success evidence' => ['pending', ['resultCode' => 0], Semantics::VERDICT_INDETERMINATE],
            'pending + receipt' => ['pending', ['mpesaReceipt' => $receipt], Semantics::VERDICT_INDETERMINATE],
            'pending + no evidence' => ['pending', [], Semantics::VERDICT_IN_FLIGHT],
            'pending + in-flight evidence' => ['pending', ['resultCode' => 4999], Semantics::VERDICT_IN_FLIGHT],
            'pending + failure evidence' => ['pending', ['resultCode' => 1032], Semantics::VERDICT_INDETERMINATE],
            'pending + conflict' => ['pending', ['mpesaReceipt' => $receipt, 'resultCode' => 1032], Semantics::VERDICT_INDETERMINATE],

            // claim = failed
            'failed + success evidence' => ['failed', ['resultCode' => 0], Semantics::VERDICT_INDETERMINATE],
            'failed + receipt' => ['failed', ['mpesaReceipt' => $receipt], Semantics::VERDICT_INDETERMINATE],
            'failed + no evidence' => ['failed', [], Semantics::VERDICT_FAILED],
            'failed + failure evidence' => ['failed', ['resultCode' => 1032], Semantics::VERDICT_FAILED],
            'failed + in-flight evidence' => ['failed', ['resultCode' => 4999], Semantics::VERDICT_IN_FLIGHT],
            'failed + conflict' => ['failed', ['mpesaReceipt' => $receipt, 'resultCode' => 1032], Semantics::VERDICT_INDETERMINATE],

            // claim = cancelled (enumerated explicitly, never a default)
            'cancelled + failure evidence' => ['cancelled', ['resultCode' => 1032], Semantics::VERDICT_FAILED],
            'cancelled + no evidence' => ['cancelled', [], Semantics::VERDICT_FAILED],
            'cancelled + receipt' => ['cancelled', ['mpesaReceipt' => $receipt], Semantics::VERDICT_INDETERMINATE],
            'cancelled + in-flight evidence' => ['cancelled', ['resultCode' => 4999], Semantics::VERDICT_IN_FLIGHT],
            'cancelled + success evidence' => ['cancelled', ['resultCode' => 0], Semantics::VERDICT_INDETERMINATE],
            'cancelled + conflict' => ['cancelled', ['mpesaReceipt' => $receipt, 'resultCode' => 1032], Semantics::VERDICT_INDETERMINATE],
        ];
    }

    /**
     * @dataProvider tableProvider
     * @param array<string,mixed> $fields
     */
    public function testTheJudgeTableIsTotalAndPinned(string $claim, array $fields, string $expected): void
    {
        self::assertSame($expected, self::verdict($fields + ['status' => $claim]));
    }

    public function testAnUnknownClaimIsIndeterminateAndNeverFalsThroughToAPermissiveDefault(): void
    {
        self::assertSame(Semantics::VERDICT_INDETERMINATE, self::verdict(['status' => 'settled', 'resultCode' => 0]));
        self::assertSame(Semantics::VERDICT_INDETERMINATE, self::verdict(['status' => '']));
    }

    // -- The LAWS ------------------------------------------------------------------------------

    /**
     * L2: paid ALWAYS has success evidence. Asserted over the WHOLE table rather than on one
     * example, so no future row can introduce an evidence-free `paid`.
     */
    public function testLawL2PaidAlwaysHasSuccessEvidence(): void
    {
        foreach (self::tableProvider() as $name => [$claim, $fields, $_expected]) {
            $j = Semantics::judge(self::payment($fields + ['status' => $claim]));
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
        foreach (self::tableProvider() as $name => [$claim, $fields, $_expected]) {
            $j = Semantics::judge(self::payment($fields + ['status' => $claim]));
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

<?php

declare(strict_types=1);

namespace Paylod;

/**
 * The result of running a payment record through {@see Semantics::judge()}.
 *
 * `verdict` is the only thing a caller should branch on. `evidence` and `claimed` are kept apart
 * on purpose: they are the two halves the verdict was derived from, and being able to see them
 * separately is what makes an `indeterminate` diagnosable rather than mysterious.
 */
final class Judgement
{
    /**
     * @param Semantics::VERDICT_*  $verdict
     * @param Semantics::EVIDENCE_* $evidence
     * @param string $claimed the record's own `status` field - what it CLAIMED, for diagnostics
     * @param string $reason why this verdict, in one human sentence. Goes into logs and error
     *   messages; never shown to a customer.
     */
    public function __construct(
        public readonly string $verdict,
        public readonly string $evidence,
        public readonly string $claimed,
        public readonly string $reason,
    ) {
    }

    public function isPaid(): bool
    {
        return $this->verdict === Semantics::VERDICT_PAID;
    }

    public function isIndeterminate(): bool
    {
        return $this->verdict === Semantics::VERDICT_INDETERMINATE;
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'evidence' => $this->evidence,
            'claimed' => $this->claimed,
            'reason' => $this->reason,
        ];
    }
}

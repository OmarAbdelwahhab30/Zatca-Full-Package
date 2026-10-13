<?php

namespace Zatca\Services\CreditNote;

/**
 * What one Credit Note request resolved to, per selected line: the gross amount
 * to credit and how much document-level discount that line consumed to get there.
 *
 * Pure value object. CreditNoteIssuer persists it; nothing here writes anything.
 */
class CreditNoteDiscountAllocation
{
    /**
     * @param  array<int, float>  $creditInclVat  line index => gross amount to credit
     * @param  array<int, float>  $discountApplied  line index => discount consumed (0.0 when none)
     * @param  array<int, string>  $poolKeys  line index => pool the line belongs to
     */
    public function __construct(
        public readonly array $creditInclVat,
        public readonly array $discountApplied,
        public readonly array $poolKeys,
    ) {}

    public function creditFor(int $lineIndex): ?float
    {
        return $this->creditInclVat[$lineIndex] ?? null;
    }

    public function discountFor(int $lineIndex): float
    {
        return $this->discountApplied[$lineIndex] ?? 0.0;
    }

    public function poolFor(int $lineIndex): ?string
    {
        return $this->poolKeys[$lineIndex] ?? null;
    }

    public function totalInclVat(): float
    {
        return round(array_sum($this->creditInclVat), 2);
    }
}

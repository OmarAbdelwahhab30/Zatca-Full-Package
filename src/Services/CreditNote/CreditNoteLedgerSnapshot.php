<?php

namespace Zatca\Services\CreditNote;

/**
 * Per-source and aggregate credit state for one original invoice, as computed by
 * CreditNoteLedger. Read-only value object — nothing here writes anything.
 *
 * Two kinds of creditable source live side by side: the original invoice's own
 * lines (by index, exactly as before) and the lines of accepted Debit Notes raised
 * against it (by debit note line id). "Fully credited" is derived from both every
 * time it is asked, so a Debit Note accepted after a full credit reopens the
 * invoice for exactly the amount it added — and crediting that closes it again.
 */
class CreditNoteLedgerSnapshot
{
    /**
     * @param  array<int, string>  $lineStates  original_line_index => 'available' | 'reserved' | 'credited'.
     *   A line with a price adjustment still in flight is 'reserved' as well, though nothing claims it.
     * @param  int[]  $availableIndexes  indexes neither reserved nor credited — what a NEW request may select
     * @param  array<string, array{total: float, consumed: float, reserved: float, available: float}>  $discountPools
     *   pool key => how much of that document-level discount is spent and how much a new
     *   note may still draw on. Empty for every invoice without an allowance.
     * @param  array<int, CreditSource>  $debitSources  debit note line id => source, accepted debit notes only
     * @param  array<int, string>  $debitLineStates  debit note line id => 'available' | 'reserved' | 'credited'
     * @param  float|null  $creditableTotalInclVat  original total plus every accepted debit note line;
     *   null means no debit note sources, i.e. the original total
     * @param  array<int, float>  $lineAdjustments  original_line_index => price adjustments still standing
     *   against that line (accepted or pending), gross
     * @param  array<int, float>  $debitLineAdjustments  debit note line id => the same, for a debit note course
     * @param  array<int, float>  $adjustableLines  original_line_index => the amount a new price adjustment
     *   must stay strictly below. Only lines that are still available appear.
     * @param  array<int, float>  $adjustableDebitLines  debit note line id => the same, for available
     *   Additional Course lines only
     * @param  array<string, int[]>  $attachedDebitLineIds  source key => the debit note lines that only
     *   raised that source's price, whatever state they are in. Crediting the source credits these
     *   too — see CreditSource::attachedToKey().
     * @param  array<string, array<int, array{document_type: string, document_number: string,
     *   direction: string, amount_incl_vat: float, accepted: bool}>>  $sourceAdjustments
     *   source key => the Credit Note price adjustments raised against it, oldest first. Debit
     *   Note ones are not repeated here: they are sources in their own right, read back from
     *   $debitSources through $attachedDebitLineIds.
     * @param  array<int, array{document_number: string, accepted: bool}>  $debitLineReversals
     *   debit note line id => the Credit Note giving it back, accepted or still in flight.
     *   Only debit note lines with an active claim appear.
     */
    public function __construct(
        public readonly array $lineStates,
        public readonly array $availableIndexes,
        public readonly int $totalLines,
        public readonly float $creditedTotalInclVat,
        public readonly float $pendingTotalInclVat,
        public readonly float $remainingCreditableInclVat,
        public readonly float $originalTotalInclVat,
        public readonly array $discountPools = [],
        public readonly array $debitSources = [],
        public readonly array $debitLineStates = [],
        public readonly ?float $creditableTotalInclVat = null,
        public readonly array $lineAdjustments = [],
        public readonly array $debitLineAdjustments = [],
        public readonly array $adjustableLines = [],
        public readonly array $adjustableDebitLines = [],
        public readonly array $attachedDebitLineIds = [],
        public readonly array $sourceAdjustments = [],
        public readonly array $debitLineReversals = [],
    ) {}

    /**
     * The debit note lines that raised this source's price and are still available to
     * credit — what a note crediting the source must take with it.
     *
     * One already credited has nothing left to give back, and one another note is
     * holding is that note's to credit; neither is pulled in here.
     *
     * @return int[]
     */
    public function attachedAvailableDebitLineIds(string $sourceKey): array
    {
        return array_values(array_filter(
            $this->attachedDebitLineIds[$sourceKey] ?? [],
            fn (int $id) => ($this->debitLineStates[$id] ?? null) === 'available'
        ));
    }

    /**
     * What ACCEPTED Debit Notes added to this source's price: the price increases that
     * ride on it rather than standing alone, which $attachedDebitLineIds names.
     *
     * Only accepted debit notes ever become sources, so there is nothing pending to
     * leave out here — see CreditSourceResolver.
     */
    public function debitUpliftFor(string $sourceKey): float
    {
        $total = 0.0;

        foreach ($this->attachedDebitLineIds[$sourceKey] ?? [] as $id) {
            $total += ($this->debitSources[$id] ?? null)?->grossInclVat() ?? 0.0;
        }

        return round($total, 2);
    }

    /**
     * What one course is worth RIGHT NOW across both note kinds: what the invoice charged
     * for it, plus every accepted Debit Note price increase on it, minus every Credit Note
     * price adjustment standing against it.
     *
     * This is a figure to show an admin, so that neither flow presents a course at a price
     * the other flow has already changed. It is deliberately NOT the cap a new adjustment
     * is held to: adjustableFor() stays per-source, because each source is still credited
     * on its own and folding them together would let one be credited twice.
     *
     * @param  float  $originalGross  the source's own frozen value, which only a caller
     *   holding the invoice or debit note snapshot can supply
     */
    public function currentValueFor(string $sourceKey, float $originalGross): float
    {
        return round($originalGross + $this->debitUpliftFor($sourceKey) - $this->creditReductionFor($sourceKey), 2);
    }

    /**
     * Everything Credit Notes have taken off this course's value: its price adjustments,
     * plus every Debit Note increase on it that a Credit Note has since given back. Without
     * the second part a reversed increase would still count in currentValueFor(), because
     * debitUpliftFor() lists every increase ever accepted.
     *
     * Display only, like currentValueFor(). adjustmentFor() stays the price adjustments
     * alone: a reversed increase is credited as its own source, never against the course.
     */
    public function creditReductionFor(string $sourceKey): float
    {
        $total = $this->adjustmentFor($sourceKey);

        foreach ($this->attachedDebitLineIds[$sourceKey] ?? [] as $id) {
            if (isset($this->debitLineReversals[$id])) {
                $total += ($this->debitSources[$id] ?? null)?->grossInclVat() ?? 0.0;
            }
        }

        return round($total, 2);
    }

    /**
     * Every adjustment ever raised against ONE source, from both note kinds, as rows an
     * admin screen can list: the debit increases first, then the credit reductions.
     *
     * @return array<int, array{document_type: string, document_number: string, direction: string, amount_incl_vat: float, accepted: bool}>
     */
    public function adjustmentHistoryFor(string $sourceKey): array
    {
        $rows = [];
        $reversals = [];

        foreach ($this->attachedDebitLineIds[$sourceKey] ?? [] as $id) {
            $source = $this->debitSources[$id] ?? null;

            if ($source === null) {
                continue;
            }

            $rows[] = [
                'document_type' => 'debit_note',
                'document_number' => $source->debitNoteNumber,
                'direction' => 'debit',
                'amount_incl_vat' => round($source->grossInclVat(), 2),
                'accepted' => true,
            ];

            // An increase a Credit Note gave back is listed as that note's reduction, so
            // the rows still add up to currentValueFor().
            if (isset($this->debitLineReversals[$id])) {
                $reversals[] = [
                    'document_type' => 'credit_note',
                    'document_number' => $this->debitLineReversals[$id]['document_number'],
                    'direction' => 'credit',
                    'amount_incl_vat' => round($source->grossInclVat(), 2),
                    'accepted' => $this->debitLineReversals[$id]['accepted'],
                ];
            }
        }

        return [...$rows, ...($this->sourceAdjustments[$sourceKey] ?? []), ...$reversals];
    }

    /** Price adjustments already standing against a source, gross — what a later cancellation must not credit again. */
    public function adjustmentFor(string $sourceKey): float
    {
        [$kind, $id] = CreditSource::parseKey($sourceKey) ?? [null, null];

        return match ($kind) {
            'line' => $this->lineAdjustments[$id] ?? 0.0,
            'dn_line' => $this->debitLineAdjustments[$id] ?? 0.0,
            default => 0.0,
        };
    }

    /**
     * The amount a new price adjustment of this source must stay strictly below, or null
     * when the source cannot be price-adjusted right now (not available, not a course,
     * or not a source of this invoice).
     */
    public function adjustableFor(string $sourceKey): ?float
    {
        [$kind, $id] = CreditSource::parseKey($sourceKey) ?? [null, null];

        return match ($kind) {
            'line' => $this->adjustableLines[$id] ?? null,
            'dn_line' => $this->adjustableDebitLines[$id] ?? null,
            default => null,
        };
    }

    /**
     * The amount a new price adjustment of this source must stay strictly below once the
     * Debit Note increases riding on it are counted in — what the course is actually worth
     * to correct, not just what the invoice's own line says.
     *
     * adjustableFor() is the source's own room; the increases attached to it are worth
     * their full value on top, because reversing one is simply crediting it. Null for a
     * source that cannot be price-adjusted at all.
     *
     * An increase is indivisible, so not every amount below this is reachable when the
     * source's own room is smaller than one of them — planAdjustment() is the authority,
     * and this is the ceiling it can never pass.
     */
    public function mergedAdjustableFor(string $sourceKey): ?float
    {
        $own = $this->adjustableFor($sourceKey);

        if ($own === null) {
            return null;
        }

        $attached = 0.0;

        foreach ($this->attachedAvailableDebitLineIds($sourceKey) as $id) {
            $attached += ($this->debitSources[$id] ?? null)?->grossInclVat() ?? 0.0;
        }

        return round($own + $attached, 2);
    }

    /**
     * How a price adjustment of this source is paid for: which attached Debit Note
     * increases it has to reverse, and how much is then taken off the source itself.
     *
     * An amount that fits in the source's own room takes nothing else — which is every
     * ordinary adjustment, unchanged. Only one that would reduce the course below what it
     * is worth on its own reaches for the increases, and it takes each one WHOLE, because
     * a Debit Note line is credited or it is not; there is no half of one. Whatever is
     * left afterwards must still fit in the source's own room, and must not be nothing:
     * an amount that only reverses increases is a credit of those increases, which is
     * issued by naming them, not by adjusting a course this takes nothing off.
     *
     * Taking them in the same note is what keeps the books straight: an increase this
     * adjustment has already drawn on is credited by it, so nothing can credit it twice.
     *
     * @return array{debitLineIds: int[], ownAmount: float}|null  null when no combination
     *   of whole increases leaves a remainder the source can absorb
     */
    public function planAdjustment(string $sourceKey, float $gross): ?array
    {
        $own = $this->adjustableFor($sourceKey);

        if ($own === null) {
            return null;
        }

        $remaining = round($gross, 2);
        $taken = [];

        foreach ($this->attachedAvailableDebitLineIds($sourceKey) as $id) {
            if ($remaining < $own) {
                break; // What is left already fits the course itself.
            }

            $value = round(($this->debitSources[$id] ?? null)?->grossInclVat() ?? 0.0, 2);

            // Skipped rather than broken on: a smaller increase further down the list may
            // still be one this amount can pay for in full.
            if ($value <= 0.0 || $value > $remaining) {
                continue;
            }

            $taken[] = $id;
            $remaining = round($remaining - $value, 2);
        }

        if ($remaining >= $own || $remaining <= 0.0) {
            return null;
        }

        return ['debitLineIds' => $taken, 'ownAmount' => $remaining];
    }

    /**
     * Discount still unspent per pool — what CreditNoteDiscountAllocator draws down.
     *
     * @return array<string, float>
     */
    public function availableDiscountByPool(): array
    {
        return array_map(static fn (array $pool) => $pool['available'], $this->discountPools);
    }

    /** @return int[] debit note line ids a new request may select */
    public function availableDebitLineIds(): array
    {
        return array_keys(array_filter($this->debitLineStates, static fn (string $state) => $state === 'available'));
    }

    /** Original total plus accepted debit note lines. */
    public function creditableTotal(): float
    {
        return $this->creditableTotalInclVat ?? $this->originalTotalInclVat;
    }

    /** BR-12/AC-16: every source has been ACCEPTED (not merely reserved) — no further note may be issued. */
    public function isFullyCredited(): bool
    {
        foreach ([...array_values($this->lineStates), ...array_values($this->debitLineStates)] as $state) {
            if ($state !== 'credited') {
                return false;
            }
        }

        return $this->totalLines + count($this->debitLineStates) > 0;
    }

    public function isPartiallyCredited(): bool
    {
        return $this->creditedTotalInclVat > 0 && ! $this->isFullyCredited();
    }

    public function isNotCredited(): bool
    {
        return $this->creditedTotalInclVat <= 0;
    }

    /** Whether anything at all — an original line or an accepted debit note line — may still be credited. */
    public function hasAvailableSources(): bool
    {
        return $this->availableIndexes !== [] || $this->availableDebitLineIds() !== [];
    }
}

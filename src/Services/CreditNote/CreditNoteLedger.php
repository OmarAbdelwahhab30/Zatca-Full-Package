<?php

namespace Zatca\Services\CreditNote;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Exceptions\CreditNoteException;
use Zatca\Models\ZatcaCreditNoteLine;
use Zatca\Models\ZatcaInvoice;
use Zatca\Support\InvoiceDataSnapshot;

/**
 * Computes what has been credited, reserved and is still available on ONE original
 * invoice — the single source of truth CreditNoteEligibilityPolicy, CreditNoteIssuer
 * and the host's list/details resources all read instead of each recomputing it.
 *
 * The whole computation rests on one column: zatca_credit_note_lines.active_line_key.
 * It is unique and non-null exactly while a source is reserved or credited, and is
 * cleared to NULL only by CreditNoteCanceller — so "the currently active claim on a
 * source" is always at most one row, found by that key, regardless of how many Credit
 * Notes have ever touched it (cancelled ones leave the key null and are simply history).
 *
 * A source is either a line of the original invoice or a line of an ACCEPTED Debit
 * Note raised against it (see CreditSourceResolver). Both are read on every call, so
 * nothing here is ever stale: an invoice fully credited before a debit note was
 * accepted is simply no longer fully credited once it has been.
 */
class CreditNoteLedger
{
    public function __construct(
        private readonly CreditSourceResolver $sources = new CreditSourceResolver,
    ) {}

    public function forOriginal(ZatcaInvoice $original): CreditNoteLedgerSnapshot
    {
        $originalData = InvoiceDataSnapshot::fromArray($original->invoice_data_snapshot);
        $totalLines = count($originalData->lines);

        $active = ZatcaCreditNoteLine::query()
            ->where('original_zatca_invoice_id', $original->getKey())
            ->whereNotNull('active_line_key')
            ->with('creditNote')
            ->get();

        // Split by kind BEFORE keying: a debit-note-sourced row has no
        // original_line_index, and keying it by one would let it collide with, or be
        // mistaken for, a claim on a real invoice line. A price adjustment names the
        // source it adjusts in those same columns but claims none of it, so it is kept
        // out of both maps — the course it adjusts stays available.
        $activeLines = $active
            ->filter(fn (ZatcaCreditNoteLine $line) => $line->isInvoiceLineSource())
            ->keyBy('original_line_index');

        $activeDebitLines = $active
            ->filter(fn (ZatcaCreditNoteLine $line) => $line->isDebitNoteSource())
            ->keyBy('source_debit_note_line_id');

        // Everything still standing against each source's value, and which sources have
        // an adjustment still in flight. A cancelled adjustment is released by the same
        // nulled key as every other claim.
        //
        // A source with an adjustment in flight is held — 'reserved', exactly like one
        // with a cancellation in flight — until that note is accepted or cancelled.
        // Otherwise a course could be cancelled for what is left after an adjustment
        // ZATCA later rejects, and the rejected amount would never be creditable again:
        // only an ACCEPTED adjustment may ever reduce what a later note credits (BR-09).
        $lineAdjustments = [];
        $debitLineAdjustments = [];
        $heldLines = [];
        $heldDebitLines = [];
        $sourceAdjustments = [];

        foreach ($active->filter(fn (ZatcaCreditNoteLine $line) => $line->isPriceAdjustment()) as $adjustment) {
            $amount = (float) $adjustment->line_total_incl_vat;
            $inFlight = ! $adjustment->creditNote?->isAccepted();

            if ($adjustment->source_debit_note_line_id !== null) {
                $id = (int) $adjustment->source_debit_note_line_id;
                $debitLineAdjustments[$id] = round(($debitLineAdjustments[$id] ?? 0.0) + $amount, 2);
                $heldDebitLines[$id] = ($heldDebitLines[$id] ?? false) || $inFlight;
                $key = CreditSource::debitNoteLineKey($id);
            } elseif ($adjustment->original_line_index !== null) {
                $index = (int) $adjustment->original_line_index;
                $lineAdjustments[$index] = round(($lineAdjustments[$index] ?? 0.0) + $amount, 2);
                $heldLines[$index] = ($heldLines[$index] ?? false) || $inFlight;
                $key = CreditSource::invoiceLineKey($index);
            } else {
                continue;
            }

            // Kept per source so an admin screen can show ONE course's own history,
            // rather than leaving the admin to pick it out of the whole invoice's.
            $sourceAdjustments[$key][] = [
                'document_type' => 'credit_note',
                'document_number' => (string) ($adjustment->creditNote?->credit_note_number ?? ''),
                'direction' => 'credit',
                'amount_incl_vat' => round($amount, 2),
                'accepted' => ! $inFlight,
            ];
        }

        // Every active claim counts toward the totals, whichever kind of source it is on.
        $creditedTotalInclVat = 0.0;
        $pendingTotalInclVat = 0.0;

        foreach ($active as $line) {
            if ($line->creditNote?->isAccepted()) {
                $creditedTotalInclVat += (float) $line->line_total_incl_vat;
            } else {
                $pendingTotalInclVat += (float) $line->line_total_incl_vat;
            }
        }

        $lineStates = [];
        $availableIndexes = [];

        // Discount consumed/reserved per pool. A line contributes on exactly the same
        // terms as its amount does — accepted means consumed, anything else active
        // means reserved, and a cancelled note's line is neither, because cancelling
        // nulls active_line_key and the row never reaches this loop. So the discount
        // is released by the same single mechanism that releases the line itself.
        $discountPools = array_map(
            static fn (float $total) => ['total' => $total, 'consumed' => 0.0, 'reserved' => 0.0, 'available' => $total],
            CreditNoteDiscountAllocator::poolTotals($originalData)
        );

        for ($index = 0; $index < $totalLines; $index++) {
            /** @var ZatcaCreditNoteLine|null $claim */
            $claim = $activeLines->get($index);

            if (! $claim) {
                if ($heldLines[$index] ?? false) {
                    $lineStates[$index] = 'reserved';

                    continue;
                }

                $lineStates[$index] = 'available';
                $availableIndexes[] = $index;

                continue;
            }

            $accepted = (bool) $claim->creditNote?->isAccepted();
            $bucket = $accepted ? 'consumed' : 'reserved';
            $lineStates[$index] = $accepted ? 'credited' : 'reserved';

            $pool = $claim->discount_pool_key;

            if ($pool !== null && isset($discountPools[$pool])) {
                $discountPools[$pool][$bucket] = round(
                    $discountPools[$pool][$bucket] + (float) $claim->discount_applied_incl_vat,
                    2
                );
            }
        }

        foreach ($discountPools as $key => $pool) {
            $discountPools[$key]['available'] = round(
                max(0.0, $pool['total'] - $discountPools[$key]['consumed'] - $discountPools[$key]['reserved']),
                2
            );
        }

        // Accepted debit note lines. They never belong to a discount pool, so they are
        // credited at their own signed value and never touch $discountPools.
        $debitSources = $this->sources->debitNoteSources($original);
        $debitLineStates = [];
        $attachedDebitLineIds = [];
        $debitLineReversals = [];
        $creditableTotalInclVat = $originalData->totalInclVat;

        foreach ($debitSources as $debitNoteLineId => $source) {
            $creditableTotalInclVat += $source->grossInclVat();

            $attachedTo = $source->attachedToKey();

            if ($attachedTo !== null) {
                $attachedDebitLineIds[$attachedTo][] = $debitNoteLineId;
            }

            /** @var ZatcaCreditNoteLine|null $claim */
            $claim = $activeDebitLines->get($debitNoteLineId);

            $debitLineStates[$debitNoteLineId] = match (true) {
                $claim === null => ($heldDebitLines[$debitNoteLineId] ?? false) ? 'reserved' : 'available',
                (bool) $claim->creditNote?->isAccepted() => 'credited',
                default => 'reserved',
            };

            // The note giving this line back, so a course's history can show the increase
            // reversed rather than still standing. In flight counts too, on the same terms
            // as a price adjustment in flight: it already holds the line.
            if ($claim !== null) {
                $debitLineReversals[$debitNoteLineId] = [
                    'document_number' => (string) ($claim->creditNote?->credit_note_number ?? ''),
                    'accepted' => (bool) $claim->creditNote?->isAccepted(),
                ];
            }
        }

        $creditedTotalInclVat = round($creditedTotalInclVat, 2);
        $pendingTotalInclVat = round($pendingTotalInclVat, 2);
        $creditableTotalInclVat = round($creditableTotalInclVat, 2);

        [$adjustableLines, $adjustableDebitLines] = $this->adjustableValues(
            $originalData,
            $availableIndexes,
            $discountPools,
            $lineAdjustments,
            $debitSources,
            $debitLineStates,
            $debitLineAdjustments,
        );

        return new CreditNoteLedgerSnapshot(
            lineStates: $lineStates,
            availableIndexes: $availableIndexes,
            totalLines: $totalLines,
            creditedTotalInclVat: $creditedTotalInclVat,
            pendingTotalInclVat: $pendingTotalInclVat,
            remainingCreditableInclVat: round($creditableTotalInclVat - $creditedTotalInclVat - $pendingTotalInclVat, 2),
            originalTotalInclVat: $originalData->totalInclVat,
            discountPools: $discountPools,
            debitSources: $debitSources,
            debitLineStates: $debitLineStates,
            creditableTotalInclVat: $creditableTotalInclVat,
            lineAdjustments: $lineAdjustments,
            debitLineAdjustments: $debitLineAdjustments,
            adjustableLines: $adjustableLines,
            adjustableDebitLines: $adjustableDebitLines,
            attachedDebitLineIds: $attachedDebitLineIds,
            sourceAdjustments: $sourceAdjustments,
            debitLineReversals: $debitLineReversals,
        );
    }

    /**
     * How much of each available course a NEW price adjustment may still take — the
     * amount it must stay strictly below, so the course always keeps a value a later
     * cancellation can credit. A course held by an adjustment in flight is not
     * available, so by the time a second one is taken the first has been accepted.
     *
     * An original line on a bundle is valued at its worst case: its own price minus
     * every unit of the pool's discount it could still end up absorbing. Discount that
     * accepted notes consumed is gone for good, but discount a pending note merely
     * reserves comes back if that note is cancelled, so only the consumed part counts
     * as spent. Anything less cautious could let a later cancellation credit a negative
     * amount once the discount lands on this line.
     *
     * @param  int[]  $availableIndexes
     * @param  array<string, array{total: float, consumed: float, reserved: float, available: float}>  $discountPools
     * @param  array<int, float>  $lineAdjustments
     * @param  array<int, CreditSource>  $debitSources
     * @param  array<int, string>  $debitLineStates
     * @param  array<int, float>  $debitLineAdjustments
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    private function adjustableValues(
        ZatcaInvoiceData $originalData,
        array $availableIndexes,
        array $discountPools,
        array $lineAdjustments,
        array $debitSources,
        array $debitLineStates,
        array $debitLineAdjustments,
    ): array {
        $poolOfLine = CreditNoteDiscountAllocator::poolByLineIndex($originalData);
        $lines = [];

        foreach ($availableIndexes as $index) {
            $gross = $originalData->lines[$index]->lineTotalInclVat();
            $pool = $poolOfLine[$index] ?? null;

            $worstCaseDiscount = $pool !== null && isset($discountPools[$pool])
                ? min(max($discountPools[$pool]['total'] - $discountPools[$pool]['consumed'], 0.0), $gross)
                : 0.0;

            $lines[$index] = round(max(0.0, $gross - $worstCaseDiscount - ($lineAdjustments[$index] ?? 0.0)), 2);
        }

        $debitLines = [];

        foreach ($debitSources as $debitNoteLineId => $source) {
            if (($debitLineStates[$debitNoteLineId] ?? null) !== 'available' || ! $source->canBePriceAdjusted()) {
                continue;
            }

            $debitLines[$debitNoteLineId] = round(
                max(0.0, $source->grossInclVat() - ($debitLineAdjustments[$debitNoteLineId] ?? 0.0)),
                2
            );
        }

        return [$lines, $debitLines];
    }

    /**
     * Resolves what a request actually selects: every available line for Full, or the
     * requested subset for Partial — intersected with what is actually available, never
     * trusting the caller's own claim about what is free.
     *
     * @param  int[]  $requestedIndexes  ignored for ZatcaCreditNoteType::Full
     * @return int[]
     */
    public function resolveSelection(CreditNoteLedgerSnapshot $ledger, ZatcaCreditNoteType $type, array $requestedIndexes): array
    {
        if ($type === ZatcaCreditNoteType::Full) {
            return $ledger->availableIndexes;
        }

        $requestedIndexes = array_values(array_unique($requestedIndexes));

        $notAvailable = array_diff($requestedIndexes, $ledger->availableIndexes);

        if ($notAvailable !== [] || $requestedIndexes === []) {
            return [];
        }

        return $requestedIndexes;
    }

    /**
     * Both kinds of selection at once, with every rule a request must satisfy — the
     * one method the issuer (under its lock) and the host's preview both go through,
     * so a preview can never promise a selection the issuer would refuse.
     *
     * A debit note line that only raised a selected source's price is added to the
     * selection here, whether or not the caller asked for it: it is part of what that
     * course cost, so crediting the course has to hand it back too. Full already takes
     * every available line, so this only ever adds to a Partial one.
     *
     * @param  int[]  $lineIndexes  ignored for ZatcaCreditNoteType::Full
     * @param  int[]  $debitNoteLineIds  ignored for ZatcaCreditNoteType::Full
     * @return array{0: int[], 1: int[]}  [original line indexes, debit note line ids], both ascending
     *
     * @throws CreditNoteException when nothing is selected, a named source is not available, or a
     *   source that must be credited alone is combined with anything else.
     */
    public function resolveSources(CreditNoteLedgerSnapshot $ledger, ZatcaCreditNoteType $type, array $lineIndexes, array $debitNoteLineIds): array
    {
        if ($type === ZatcaCreditNoteType::Full) {
            $selected = $ledger->availableIndexes;
            $selectedDebit = $this->resolveDebitSelection($ledger, $type, []) ?? [];
        } else {
            $lineIndexes = array_values(array_unique(array_map('intval', $lineIndexes)));
            $selected = $lineIndexes === [] ? [] : $this->resolveSelection($ledger, $type, $lineIndexes);

            if ($lineIndexes !== [] && $selected === []) {
                throw CreditNoteException::exceedsRemaining();
            }

            $selectedDebit = $this->resolveDebitSelection($ledger, $type, $debitNoteLineIds);

            if ($selectedDebit === null) {
                throw CreditNoteException::exceedsRemaining();
            }
        }

        if ($selected === [] && $selectedDebit === []) {
            throw CreditNoteException::exceedsRemaining();
        }

        $selectedDebit = $this->withAttachedAdjustments($ledger, $selected, $selectedDebit);

        sort($selected);

        $count = count($selected) + count($selectedDebit);

        foreach ($selectedDebit as $id) {
            if ($count > 1 && $ledger->debitSources[$id]->mustBeCreditedAlone()) {
                throw CreditNoteException::mustBeCreditedAlone();
            }
        }

        return [$selected, $selectedDebit];
    }

    /**
     * The selected debit note lines plus every available one that merely raised the
     * price of something already being credited — the rule an admin should not have to
     * remember (a course credited without its price increase leaves that increase
     * standing against an invoice the student no longer has).
     *
     * Walked as a queue: a price adjustment on a course an earlier debit note added is
     * attached to that debit note line, so selecting the course has to reach it too.
     *
     * @param  int[]  $selected
     * @param  int[]  $selectedDebit
     * @return int[] ascending, without duplicates
     */
    private function withAttachedAdjustments(CreditNoteLedgerSnapshot $ledger, array $selected, array $selectedDebit): array
    {
        if ($ledger->attachedDebitLineIds === []) {
            return $selectedDebit;
        }

        $result = $selectedDebit;
        $pending = array_map(CreditSource::invoiceLineKey(...), $selected);

        foreach ($selectedDebit as $id) {
            $pending[] = CreditSource::debitNoteLineKey($id);
        }

        while (($sourceKey = array_shift($pending)) !== null) {
            foreach ($ledger->attachedAvailableDebitLineIds($sourceKey) as $id) {
                if (in_array($id, $result, true)) {
                    continue;
                }

                $result[] = $id;
                $pending[] = CreditSource::debitNoteLineKey($id);
            }
        }

        sort($result);

        return $result;
    }

    /**
     * The debit note lines a request actually selects — the counterpart of
     * resolveSelection(), with the same rule: a Partial request must name only lines
     * that are available right now.
     *
     * Full takes every available debit note line EXCEPT those that must be credited
     * alone (VAT Adjustment); those are only ever issued as a Partial note of their own.
     *
     * @param  int[]  $requestedDebitNoteLineIds  ignored for ZatcaCreditNoteType::Full
     * @return int[]|null  null when a Partial request names a line that is not available
     */
    public function resolveDebitSelection(CreditNoteLedgerSnapshot $ledger, ZatcaCreditNoteType $type, array $requestedDebitNoteLineIds): ?array
    {
        $available = $ledger->availableDebitLineIds();

        if ($type === ZatcaCreditNoteType::Full) {
            return array_values(array_filter(
                $available,
                fn (int $id) => ! $ledger->debitSources[$id]->mustBeCreditedAlone()
            ));
        }

        $requested = array_values(array_unique(array_map('intval', $requestedDebitNoteLineIds)));

        if (array_diff($requested, $available) !== []) {
            return null;
        }

        sort($requested);

        return $requested;
    }
}

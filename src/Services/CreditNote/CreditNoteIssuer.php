<?php

namespace Zatca\Services\CreditNote;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaCreditNoteReason;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Exceptions\CreditNoteException;
use Zatca\Jobs\GenerateZatcaCreditNoteJob;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaCreditNoteEvent;
use Zatca\Models\ZatcaCreditNoteLine;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Invoice\InvoiceDataValidator;
use Zatca\Services\Tax\InvoiceCalculator;
use Zatca\Support\Money;
use Zatca\Support\InvoiceDataSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the zatca_credit_notes business record and its lines under a lock, then
 * queues generation. This is the ONE place a Credit Note is ever created — the admin
 * controller, and nothing else, calls into here.
 *
 * Two kinds of note, one set of guarantees (see ZatcaCreditNoteReason):
 *
 *  - issue(): an item cancellation, crediting whole sources.
 *  - issuePriceAdjustment(): part of one item's value, leaving the item creditable
 *    for whatever is left of it.
 *
 * Concurrency (scenarios 6, 19, 20, 21; BR-06, BR-11, AC-09, AC-21):
 *
 *  1. Idempotency replay is checked first, outside any lock — the common case (a
 *     retried request with the same key) never needs to lock anything.
 *  2. `SELECT ... FOR UPDATE` on the ORIGINAL invoice row serializes every issuer
 *     racing for the same invoice's lines, replay included (checked again inside the
 *     lock in case two different keys both raced in before either committed).
 *  3. Eligibility and line availability are recomputed under that lock — never trusted
 *     from a caller-supplied snapshot, which could be stale by the time this runs.
 *  4. zatca_credit_note_lines.active_line_key's unique index is the backstop if this
 *     lock is ever bypassed (defense in depth, not the primary guarantee).
 */
class CreditNoteIssuer
{
    public function __construct(
        private readonly CreditNoteEligibilityPolicy $eligibilityPolicy = new CreditNoteEligibilityPolicy,
        private readonly CreditNoteLedger $ledger = new CreditNoteLedger,
        private readonly CreditNoteDataFactory $dataFactory = new CreditNoteDataFactory,
        private readonly InvoiceDataValidator $validator = new InvoiceDataValidator,
        private readonly CreditNoteDiscountAllocator $allocator = new CreditNoteDiscountAllocator,
        private readonly InvoiceCalculator $calculator = new InvoiceCalculator,
    ) {}

    /**
     * @param  ZatcaInvoice  $original  any document in the chain — the authoritative head is
     *   resolved and locked inside the transaction, not trusted from the caller.
     * @param  int[]  $lineIndexes  ignored for ZatcaCreditNoteType::Full.
     * @param  array{type: string, id: int|string}|null  $requestedBy  null means no human did —
     *   never guessed.
     * @param  float|null  $expectedTotalInclVat  the amount the admin actually confirmed. When the
     *   figure computed under the lock differs — because another note consumed bundle discount in
     *   between — the request is refused rather than silently issued for a different amount.
     *
     * @param  int[]  $debitNoteLineIds  lines of ACCEPTED debit notes against this original to credit
     *   as well. Ignored for ZatcaCreditNoteType::Full, which takes every available one that may be
     *   credited alongside others.
     *
     * @throws CreditNoteException when the original is not eligible or the selection is empty
     *   once resolved against what is actually available.
     */
    public function issue(
        ZatcaInvoice $original,
        ZatcaCreditNoteType $type,
        array $lineIndexes,
        string $reason,
        string $idempotencyKey,
        ?array $requestedBy = null,
        ?float $expectedTotalInclVat = null,
        array $debitNoteLineIds = [],
    ): ZatcaCreditNote {
        $existing = ZatcaCreditNote::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        $creditNote = DB::transaction(function () use ($original, $type, $lineIndexes, $reason, $idempotencyKey, $requestedBy, $expectedTotalInclVat, $debitNoteLineIds) {
            // Re-checked inside the lock: two requests with different keys could both have
            // passed the fast-path check above before either committed.
            $existing = ZatcaCreditNote::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing) {
                return $existing;
            }

            $authoritative = $this->lockEligibleOriginal($original);

            $ledgerSnapshot = $this->ledger->forOriginal($authoritative);
            // Invoice line order, then debit note line order — never the order the admin
            // ticked the boxes in, so the order a bundle discount is consumed in cannot
            // depend on it.
            [$selected, $selectedDebit] = $this->ledger->resolveSources($ledgerSnapshot, $type, $lineIndexes, $debitNoteLineIds);

            /** @var CreditSource[] $debitSources */
            $debitSources = array_map(fn (int $id) => $ledgerSnapshot->debitSources[$id], $selectedDebit);

            $originalData = InvoiceDataSnapshot::fromArray($authoritative->invoice_data_snapshot);

            // Bundle invoices state each item at its ORIGINAL price and carry the
            // discount once, at document level. Crediting such a line verbatim would
            // hand back more than was ever charged, so the discount still unspent is
            // drawn down here — inside the lock, against committed rows, so a second
            // admin cannot spend the same discount concurrently. Debit note lines are
            // never part of a pool and never reach the allocator.
            //
            $allocation = $selected === [] ? null : $this->allocateDiscount($originalData, $ledgerSnapshot, $selected, $selectedDebit);

            // An item priced up by a debit note draws its discount against its whole
            // value; what the line itself cannot absorb comes off its price increase.
            $overflow = $allocation === null ? [] : CreditNoteDiscountAllocator::overflowOntoDebitLines(
                $originalData, $ledgerSnapshot, $allocation, $selected, $selectedDebit
            );

            if ($allocation !== null) {
                $this->assertNoBundlePoolDrainedForFree($allocation, $selected, $ledgerSnapshot, $debitSources, $overflow, CreditNoteDiscountAllocator::freePools($originalData));
            }

            // An item whose price was already adjusted is worth that much less than its
            // line says. What cancelling it credits is what is left of it — the
            // adjustment was credited once already and must never be credited again.
            $lineCredits = $this->lineCredits($originalData, $ledgerSnapshot, $allocation, $selected);

            $creditNote = $this->createNote(
                $authoritative,
                $ledgerSnapshot,
                $originalData,
                $type,
                ZatcaCreditNoteReason::ItemCancellation,
                $reason,
                $idempotencyKey,
                $requestedBy,
            );

            $debitLines = array_map(
                fn (CreditSource $source) => $this->debitLineCredit($ledgerSnapshot, $source, $creditNote->credit_note_number, $overflow[$source->debitNoteLineId]['amount'] ?? 0.0),
                $debitSources
            );

            $creditNoteData = $this->dataFactory->make(
                $originalData,
                $selected,
                $creditNote->credit_note_number,
                $authoritative->source_reference,
                $reason,
                $creditNote->issued_at->toDateTimeImmutable(),
                $lineCredits,
                $debitLines,
            );

            // We do not file documents a discount made worth nothing. Reachable when a
            // bundle discount covers the whole selection (a 30.00 discount against a
            // 20.00 item), and the discount that would have been consumed is left
            // intact for the next note by rolling this transaction back. A selection
            // worth nothing WITHOUT any discount — an item that was free — is issued:
            // there is nothing to protect, and it is the only way to take it back.
            if (round($creditNoteData->totalInclVat, 2) <= 0.0
                && $this->drawsOnDiscount($allocation, $selected, CreditNoteDiscountAllocator::freePools($originalData))) {
                throw CreditNoteException::zeroValue();
            }

            $this->assertExpectedTotal($expectedTotalInclVat, $creditNoteData);

            // Arithmetic + KSA-10/BR-KSA-56/BR-KSA-F-06-C13 — the same gate a plain invoice
            // passes through before consuming an ICV. Throwing here rolls back everything
            // above; nothing is left half-created.
            $this->validator->validate($creditNoteData);

            $this->assertWithinInvoicedAmount($originalData, $ledgerSnapshot, $allocation, $selected, $lineCredits, $debitLines);

            foreach ($selected as $position => $index) {
                // The credited line, which for a bundle item is its original value
                // minus the discount this note consumed — not the line as invoiced.
                $line = $creditNoteData->lines[$position];

                ZatcaCreditNoteLine::create([
                    'credit_note_id' => $creditNote->getKey(),
                    'original_zatca_invoice_id' => $authoritative->getKey(),
                    'original_line_index' => $index,
                    'source_type' => CreditSource::TYPE_INVOICE_LINE,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->netUnitPrice,
                    'vat_rate' => $line->vatRate,
                    'line_total_excl_vat' => $line->lineTotalExclVat,
                    'vat_amount' => $line->vatAmount,
                    'line_total_incl_vat' => $line->lineTotalInclVat(),
                    // How much discount this line consumed, and from which pool. Read back
                    // by CreditNoteLedger to decide what a LATER note may still draw on —
                    // and released automatically when active_line_key is nulled, because
                    // the ledger only counts rows that still hold their claim.
                    'discount_pool_key' => $allocation?->poolFor($index),
                    'discount_applied_incl_vat' => $allocation?->discountFor($index),
                    // The concurrency backstop — see class docblock. A collision here, despite
                    // the row lock above, means something bypassed it; surfacing that as a
                    // loud query exception is correct, there is no silent-retry story for it.
                    'active_line_key' => ZatcaCreditNoteLine::activeLineKey($authoritative->getKey(), $index),
                ]);
            }

            // Debit note lines follow the original's lines on the document, in the same
            // order they were passed to the data factory.
            foreach ($debitSources as $offset => $source) {
                $line = $creditNoteData->lines[count($selected) + $offset];

                ZatcaCreditNoteLine::create([
                    'credit_note_id' => $creditNote->getKey(),
                    'original_zatca_invoice_id' => $authoritative->getKey(),
                    'original_line_index' => null,
                    'source_type' => CreditSource::TYPE_DEBIT_NOTE_LINE,
                    'source_debit_note_id' => $source->debitNoteId,
                    'source_debit_note_line_id' => $source->debitNoteLineId,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->netUnitPrice,
                    'vat_rate' => $line->vatRate,
                    'line_total_excl_vat' => $line->lineTotalExclVat,
                    'vat_amount' => $line->vatAmount,
                    'line_total_incl_vat' => $line->lineTotalInclVat(),
                    'item_id' => $source->itemId,
                    'active_line_key' => ZatcaCreditNoteLine::debitNoteLineKey($source->debitNoteLineId),
                ]);
            }

            return $this->finish($creditNote, $creditNoteData, $requestedBy, [
                'type' => $type->value,
                'reason_type' => ZatcaCreditNoteReason::ItemCancellation->value,
                'line_indexes' => $selected,
                'debit_note_line_ids' => $selectedDebit,
                'total_incl_vat' => $creditNoteData->totalInclVat,
            ]);
        });

        return $creditNote;
    }

    /**
     * Issues a price adjustment: a Credit Note for the difference when items were
     * charged more than their correct price.
     *
     * The items are not taken back. Each keeps its line (or debit note line), stays
     * creditable for whatever is left of it, and acceptance changes nobody's access —
     * so what must hold instead is that an item always keeps some value: its adjustment
     * has to stay strictly below what is left of it. Reducing an item to nothing is a
     * cancellation, and is issued as one.
     *
     * "What is left of it" spans both note kinds. A Debit Note that raised an item's
     * price charged for that item, so it is part of what may be corrected, and the
     * adjustment may reach up to the two together. Reaching past the item's own value
     * REVERSES the increases it needs, in this same note and under this same lock: each
     * one is credited in full beside the adjustment's own line, so the note's total is
     * exactly the amount asked for and an increase this adjustment has already drawn on
     * can never be credited a second time. An adjustment that fits inside the item's own
     * value — every ordinary one — touches none of them.
     *
     * SEVERAL items may be corrected in one note. Each carries its own amount and is
     * measured against its own item's cap; nothing is pooled between them, and the note
     * is worth their sum. One item refused takes the whole note with it — a partially
     * applied correction is not something an admin can reason about, and the transaction
     * rolls back rather than issuing a note that says less than was asked for.
     *
     * @param  PriceAdjustmentInput[]  $adjustments  one per item, at most one entry per item
     * @param  array{type: string, id: int|string}|null  $requestedBy
     *
     * @throws CreditNoteException when the original is not eligible, a source cannot be
     *   adjusted, or an amount is not below what is left of its item.
     */
    public function issuePriceAdjustments(
        ZatcaInvoice $original,
        array $adjustments,
        string $reason,
        string $idempotencyKey,
        ?array $requestedBy = null,
        ?float $expectedTotalInclVat = null,
    ): ZatcaCreditNote {
        $existing = ZatcaCreditNote::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($original, $adjustments, $reason, $idempotencyKey, $requestedBy, $expectedTotalInclVat) {
            $existing = ZatcaCreditNote::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing) {
                return $existing;
            }

            if ($adjustments === []) {
                throw CreditNoteException::zeroValue();
            }

            $authoritative = $this->lockEligibleOriginal($original);
            $ledgerSnapshot = $this->ledger->forOriginal($authoritative);
            $originalData = InvoiceDataSnapshot::fromArray($authoritative->invoice_data_snapshot);

            /** @var array<string, array<string, mixed>> $resolved */
            $resolved = [];
            $claimedIncreases = [];
            $total = 0.0;

            foreach ($adjustments as $adjustment) {
                // One item, one amount. Two entries for the same item would each be
                // measured against a cap the other has already spent.
                if (isset($resolved[$adjustment->sourceKey])) {
                    throw CreditNoteException::notAdjustable();
                }

                [$kind, $id] = CreditSource::parseKey($adjustment->sourceKey) ?? [null, null];
                $adjustable = $ledgerSnapshot->mergedAdjustableFor($adjustment->sourceKey);

                if ($kind === null || $adjustable === null) {
                    throw CreditNoteException::notAdjustable();
                }

                $source = $kind === 'dn_line' ? $ledgerSnapshot->debitSources[$id] : null;
                $adjustedLine = $source?->line ?? $originalData->lines[$id];

                // The amount is split at the adjusted item's own frozen rate and VAT
                // category, and read in the price mode that item was invoiced in — never the
                // tenant's current settings. A line recorded before price modes were
                // snapshotted falls back to the configured mode.
                $amount = Money::halalas($adjustment->amount, 'price adjustment amount');

                if ($amount <= 0) {
                    throw CreditNoteException::zeroValue();
                }

                [$net, $vat] = InvoiceCalculator::split(
                    $amount,
                    (int) round($adjustedLine->vatRate * 100),
                    $adjustedLine->priceMode ?? $this->calculator->settings()->mode,
                );
                $netAmount = Money::amount($net);
                $vatAmount = Money::amount($vat);
                $gross = Money::amount($net + $vat);

                // Strictly below the item AND the increases on it: see the method docblock.
                if ($gross >= $adjustable) {
                    throw CreditNoteException::adjustmentExceedsValue();
                }

                // Which increases this amount has to reverse, and what is then taken off the
                // item itself. Refused when no combination of whole increases leaves a
                // remainder the item can absorb — an increase cannot be credited by halves.
                $plan = $ledgerSnapshot->planAdjustment($adjustment->sourceKey, $gross);

                if ($plan === null) {
                    throw CreditNoteException::adjustmentExceedsValue();
                }

                // An increase rides on exactly one item, so two items cannot reach for
                // the same one. Refused rather than trusted: claiming it twice would credit
                // it twice, and the unique active_line_key would only catch it on insert.
                foreach ($plan['debitLineIds'] as $increaseId) {
                    if (isset($claimedIncreases[$increaseId])) {
                        throw CreditNoteException::notAdjustable();
                    }

                    $claimedIncreases[$increaseId] = true;
                }

                $resolved[$adjustment->sourceKey] = [
                    'input' => $adjustment,
                    'kind' => $kind,
                    'id' => $id,
                    'source' => $source,
                    'adjustedLine' => $adjustedLine,
                    'net' => $netAmount,
                    'vat' => $vatAmount,
                    'gross' => $gross,
                    'plan' => $plan,
                ];

                $total = round($total + $gross, 2);
            }

            // Implied by the per-item caps, re-checked because it is the invariant every
            // other note is held to as well.
            if ($total > round($ledgerSnapshot->remainingCreditableInclVat, 2)) {
                throw CreditNoteException::exceedsRemaining();
            }

            $creditNote = $this->createNote(
                $authoritative,
                $ledgerSnapshot,
                $originalData,
                ZatcaCreditNoteType::Partial,
                ZatcaCreditNoteReason::PriceAdjustment,
                $reason,
                $idempotencyKey,
                $requestedBy,
            );

            $adjustmentLines = [];
            $reversedLines = [];
            /** @var CreditSource[] $reversedSources */
            $reversedSources = [];

            foreach ($resolved as $entry) {
                // The adjusted item's own frozen rate and VAT category, never the tenant's
                // current ones. Quantity is always 1, so unit price and line net are the same figure.
                $line = $entry['adjustedLine']->withAmounts(
                    quantity: 1,
                    netUnitPrice: $entry['net'],
                    lineTotalExclVat: $entry['net'],
                    vatAmount: $entry['vat'],
                    description: $entry['input']->description,
                );

                // The WHOLE amount for this item was split above. When part of it is paid
                // by reversing increases — which carry their own signed split — the
                // adjustment's own line is restated at what is actually taken off the item,
                // in the same proportion of net to VAT, so the lines still add up to the amount.
                $adjustmentLines[] = $entry['plan']['ownAmount'] === $entry['gross']
                    ? $line
                    : $this->dataFactory->reprice($line, $entry['plan']['ownAmount'], $creditNote->credit_note_number);

                foreach ($entry['plan']['debitLineIds'] as $increaseId) {
                    $increase = $ledgerSnapshot->debitSources[$increaseId];

                    $reversedSources[] = $increase;
                    $reversedLines[] = $this->debitLineCredit($ledgerSnapshot, $increase, $creditNote->credit_note_number);
                }
            }

            $creditNoteData = $this->dataFactory->makeAdjustments(
                $originalData,
                $adjustmentLines,
                $reversedLines,
                $creditNote->credit_note_number,
                $authoritative->source_reference,
                $reason,
                $creditNote->issued_at->toDateTimeImmutable(),
            );

            $this->assertExpectedTotal($expectedTotalInclVat, $creditNoteData);

            $this->validator->validate($creditNoteData);

            foreach (array_values($resolved) as $position => $entry) {
                $line = $creditNoteData->lines[$position];

                ZatcaCreditNoteLine::create([
                    'credit_note_id' => $creditNote->getKey(),
                    'original_zatca_invoice_id' => $authoritative->getKey(),
                    'original_line_index' => $entry['kind'] === 'line' ? $entry['id'] : null,
                    'source_type' => CreditSource::TYPE_PRICE_ADJUSTMENT,
                    'source_debit_note_id' => $entry['source']?->debitNoteId,
                    'source_debit_note_line_id' => $entry['source']?->debitNoteLineId,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->netUnitPrice,
                    'vat_rate' => $line->vatRate,
                    'line_total_excl_vat' => $line->lineTotalExclVat,
                    'vat_amount' => $line->vatAmount,
                    'line_total_incl_vat' => $line->lineTotalInclVat(),
                    'item_id' => $entry['source']?->itemId,
                    'active_line_key' => ZatcaCreditNoteLine::priceAdjustmentKey(
                        $creditNote->getKey(),
                        $entry['input']->sourceKey,
                    ),
                ]);
            }

            // The reversed increases follow every adjustment line, in the order they were
            // passed to the data factory. Each claims its debit note line exactly as a
            // cancellation would, which is what stops anything crediting it again.
            foreach ($reversedSources as $offset => $reversed) {
                $reversedLine = $creditNoteData->lines[count($resolved) + $offset];

                ZatcaCreditNoteLine::create([
                    'credit_note_id' => $creditNote->getKey(),
                    'original_zatca_invoice_id' => $authoritative->getKey(),
                    'original_line_index' => null,
                    'source_type' => CreditSource::TYPE_DEBIT_NOTE_LINE,
                    'source_debit_note_id' => $reversed->debitNoteId,
                    'source_debit_note_line_id' => $reversed->debitNoteLineId,
                    'description' => $reversedLine->description,
                    'quantity' => $reversedLine->quantity,
                    'unit_price' => $reversedLine->netUnitPrice,
                    'vat_rate' => $reversedLine->vatRate,
                    'line_total_excl_vat' => $reversedLine->lineTotalExclVat,
                    'vat_amount' => $reversedLine->vatAmount,
                    'line_total_incl_vat' => $reversedLine->lineTotalInclVat(),
                    'item_id' => $reversed->itemId,
                    'active_line_key' => ZatcaCreditNoteLine::debitNoteLineKey($reversed->debitNoteLineId),
                ]);
            }

            return $this->finish($creditNote, $creditNoteData, $requestedBy, [
                'type' => ZatcaCreditNoteType::Partial->value,
                'reason_type' => ZatcaCreditNoteReason::PriceAdjustment->value,
                'source_keys' => array_keys($resolved),
                'debit_note_line_ids' => array_map(
                    static fn (CreditSource $reversed) => $reversed->debitNoteLineId,
                    $reversedSources
                ),
                'total_incl_vat' => $creditNoteData->totalInclVat,
            ]);
        });
    }

    /**
     * The one-item form of issuePriceAdjustments(), which is what it was before several
     * items could share a note. Every rule above applies to it unchanged.
     *
     * @param  string  $sourceKey  the item being adjusted: "line:{index}" for a line of the
     *   original invoice, "dn_line:{id}" for an Additional Item line of an accepted debit note.
     * @param  int|float|string  $amount  the difference, in the price mode the item was invoiced in
     *   (VAT-inclusive by default). The package splits it into net and VAT at the item's own frozen
     *   rate and VAT category.
     * @param  string  $description  the line text that reaches the PDF and the XML.
     * @param  array{type: string, id: int|string}|null  $requestedBy
     *
     * @throws CreditNoteException
     */
    public function issuePriceAdjustment(
        ZatcaInvoice $original,
        string $sourceKey,
        int|float|string $amount,
        string $description,
        string $reason,
        string $idempotencyKey,
        ?array $requestedBy = null,
        ?float $expectedTotalInclVat = null,
    ): ZatcaCreditNote {
        return $this->issuePriceAdjustments(
            $original,
            [new PriceAdjustmentInput($sourceKey, $amount, $description)],
            $reason,
            $idempotencyKey,
            $requestedBy,
            $expectedTotalInclVat,
        );
    }

    /**
     * Locks the original, resolves and locks its authoritative head, and refuses one
     * that may not be credited — the steps both kinds of note start with.
     *
     * @throws CreditNoteException
     */
    private function lockEligibleOriginal(ZatcaInvoice $original): ZatcaInvoice
    {
        $locked = ZatcaInvoice::query()->lockForUpdate()->findOrFail($original->getKey());
        $authoritative = $this->lockAuthoritative($locked);

        $verdict = $this->eligibilityPolicy->verdict($authoritative);

        if (! $verdict->eligible) {
            throw CreditNoteException::notEligible($verdict->reason);
        }

        return $authoritative;
    }

    /**
     * Inserts the business record with zeroed totals and gives it its number. Number
     * depends on the row's own id, so it is assigned right after insert — still inside
     * the caller's transaction, so nothing outside it ever observes the row without a
     * number.
     */
    private function createNote(
        ZatcaInvoice $authoritative,
        CreditNoteLedgerSnapshot $ledgerSnapshot,
        ZatcaInvoiceData $originalData,
        ZatcaCreditNoteType $type,
        ZatcaCreditNoteReason $reasonType,
        string $reason,
        string $idempotencyKey,
        ?array $requestedBy,
    ): ZatcaCreditNote {
        $creditNote = ZatcaCreditNote::create([
            'egs_identifier' => $authoritative->egs_identifier,
            'environment' => $authoritative->environment->value,
            'original_zatca_invoice_id' => $authoritative->getKey(),
            'original_source_reference' => Str::before($authoritative->source_reference, '#r'),
            'billing_reference_id' => $authoritative->source_reference,
            'type' => $type->value,
            'reason_type' => $reasonType->value,
            'reason' => $reason,
            'document_snapshot' => [],
            'currency' => $originalData->currency,
            'total_excl_vat' => 0,
            'total_vat' => 0,
            'total_incl_vat' => 0,
            'original_total_incl_vat' => $ledgerSnapshot->originalTotalInclVat,
            'original_line_count' => $ledgerSnapshot->totalLines,
            'idempotency_key' => $idempotencyKey,
            'requested_by_type' => $requestedBy['type'] ?? null,
            'requested_by_id' => $requestedBy['id'] ?? null,
            'issued_at' => now(),
        ]);

        $creditNote->update(['credit_note_number' => sprintf('CN-%06d', $creditNote->getKey())]);

        return $creditNote;
    }

    /**
     * Writes the signed-to-be snapshot and totals, records the request and queues
     * generation for after commit.
     *
     * @param  array<string, mixed>  $eventDetail
     */
    private function finish(ZatcaCreditNote $creditNote, ZatcaInvoiceData $creditNoteData, ?array $requestedBy, array $eventDetail): ZatcaCreditNote
    {
        $creditNote->update([
            'document_snapshot' => InvoiceDataSnapshot::toArray($creditNoteData),
            'total_excl_vat' => $creditNoteData->totalExclVat,
            'total_vat' => $creditNoteData->totalVat,
            'total_incl_vat' => $creditNoteData->totalInclVat,
        ]);

        ZatcaCreditNoteEvent::record($creditNote->getKey(), 'requested', $requestedBy, $eventDetail);

        DB::afterCommit(fn () => GenerateZatcaCreditNoteJob::dispatch($creditNote->getKey()));

        return $creditNote->fresh();
    }

    /** @throws CreditNoteException when the admin confirmed a different figure than this note carries. */
    private function assertExpectedTotal(?float $expectedTotalInclVat, ZatcaInvoiceData $creditNoteData): void
    {
        if ($expectedTotalInclVat !== null
            && round($expectedTotalInclVat, 2) !== round($creditNoteData->totalInclVat, 2)) {
            throw CreditNoteException::amountChanged();
        }
    }

    /**
     * Gross amount to credit per selected original line, for every line that is NOT
     * credited exactly as invoiced: a bundle line (its value less the discount it
     * consumed) and a line whose price was adjusted (less what the adjustments already
     * credited). An index left out is credited verbatim, as it always was.
     *
     * Never negative. Each adjustment was capped below the item's worst-case value
     * when it was issued, so only rounding could take a line below zero — and a note
     * that under-credits a fil is recoverable where a negative line is not.
     *
     * @param  int[]  $selected
     * @return array<int, float>
     */
    private function lineCredits(
        ZatcaInvoiceData $originalData,
        CreditNoteLedgerSnapshot $ledgerSnapshot,
        ?CreditNoteDiscountAllocation $allocation,
        array $selected,
    ): array {
        $credits = $allocation?->creditInclVat ?? [];

        foreach ($selected as $index) {
            $adjusted = $ledgerSnapshot->adjustmentFor(CreditSource::invoiceLineKey($index));

            if ($adjusted <= 0.0) {
                continue;
            }

            $value = $credits[$index] ?? $originalData->lines[$index]->lineTotalInclVat();
            $credits[$index] = round(max(0.0, $value - $adjusted), 2);
        }

        return $credits;
    }

    /**
     * A debit note line as this note credits it: verbatim, unless its item's price was
     * adjusted, or the bundle discount on the item it raised reached into it.
     */
    private function debitLineCredit(CreditNoteLedgerSnapshot $ledgerSnapshot, CreditSource $source, string $creditNoteNumber, float $discount = 0.0): ZatcaInvoiceLine
    {
        $adjusted = round($ledgerSnapshot->adjustmentFor($source->key()) + $discount, 2);

        if ($adjusted <= 0.0) {
            return $source->line;
        }

        return $this->dataFactory->reprice(
            $source->line,
            round(max(0.0, $source->grossInclVat() - $adjusted), 2),
            $creditNoteNumber,
        );
    }

    /**
     * Draws down the document-level discount for the selected lines, or returns null
     * for the ordinary invoice that has no allowance — in which case every line is
     * credited exactly as invoiced and nothing downstream behaves differently.
     *
     * @param  int[]  $selected
     *
     * @throws CreditNoteException when the original carries a discount whose line
     *   membership was never frozen. Refusing is deliberate: the alternative is
     *   guessing which items a discount belonged to, and a wrong guess credits an
     *   amount that was never charged.
     */
    private function allocateDiscount(
        ZatcaInvoiceData $originalData,
        CreditNoteLedgerSnapshot $ledgerSnapshot,
        array $selected,
        array $selectedDebit = [],
    ): ?CreditNoteDiscountAllocation {
        if (! CreditNoteDiscountAllocator::hasDiscountPools($originalData)) {
            return null;
        }

        if (CreditNoteDiscountAllocator::poolByLineIndex($originalData) === []) {
            throw CreditNoteException::discountPoolUnresolved();
        }

        return $this->allocator->allocate(
            $originalData,
            $selected,
            $ledgerSnapshot->availableDiscountByPool(),
            CreditNoteDiscountAllocator::effectiveValues($originalData, $ledgerSnapshot, $selected, $selectedDebit),
        );
    }

    /**
     * A bundle item worth less than its discount credits nothing on its own — and
     * that alone is refused a few lines below, by the note-wide zero-value check,
     * because nothing else in the selection gives the note any value either.
     *
     * The gap that check cannot see: the SAME worthless item selected ALONGSIDE an
     * item from a different pool, or no pool at all. The note's total is then
     * positive — from the unrelated item — so the zero-value check passes, while
     * the bundle item still drained its discount for a 0.00 credit. Checked per
     * pool, not per note, so this can never slip through behind someone else's value.
     *
     * A pool is EXEMPT when every one of its credited lines in this note sums to the
     * note's own total — that is just the worthless-alone case the check downstream
     * already refuses, with its own clearer reason.
     *
     * A pool that drew NO discount in this note drained nothing, so it is never refused
     * here: a bundle item that was free in its own right (priced 0) credits 0.00 and
     * consumes nothing, and may be cancelled alongside any other item.
     *
     * An item's price increases credited beside it count toward its pool: the discount
     * is drawn against the item's whole value, so the increase is where its credit is.
     *
     * @param  CreditSource[]  $debitSources
     * @param  array<int, array{amount: float, pool: string}>  $overflow
     * @param  string[]  $freePools  bundles sold for nothing — see CreditNoteDiscountAllocator::freePools()
     */
    private function assertNoBundlePoolDrainedForFree(
        CreditNoteDiscountAllocation $allocation,
        array $selected,
        CreditNoteLedgerSnapshot $ledgerSnapshot,
        array $debitSources,
        array $overflow,
        array $freePools = [],
    ): void {
        $poolTotals = [];
        $poolDiscounts = [];

        foreach ($selected as $index) {
            $pool = $allocation->poolFor($index);

            if ($pool === null || in_array($pool, $freePools, true)) {
                continue;
            }

            $poolTotals[$pool] = round(($poolTotals[$pool] ?? 0.0) + ($allocation->creditFor($index) ?? 0.0), 2);
            $poolDiscounts[$pool] = round(($poolDiscounts[$pool] ?? 0.0) + ($allocation->discountFor($index) ?? 0.0), 2);
        }

        foreach ($debitSources as $source) {
            $taken = $overflow[$source->debitNoteLineId] ?? null;

            if ($taken !== null && ! in_array($taken['pool'], $freePools, true)) {
                $credit = max(0.0, $source->grossInclVat() - $ledgerSnapshot->adjustmentFor($source->key()) - $taken['amount']);
                $poolTotals[$taken['pool']] = round(($poolTotals[$taken['pool']] ?? 0.0) + $credit, 2);
            }
        }

        $hasWorthlessPool = array_filter(
            $poolTotals,
            fn (float $total, string $pool) => $total <= 0.0 && ($poolDiscounts[$pool] ?? 0.0) > 0.0,
            ARRAY_FILTER_USE_BOTH,
        ) !== [];

        if (! $hasWorthlessPool) {
            return;
        }

        if (round($allocation->totalInclVat(), 2) <= 0.0) {
            return;
        }

        throw CreditNoteException::worthlessBundleLine();
    }

    /**
     * Whether any selected line drew on a bundle discount in this note — what tells a
     * selection a discount made worthless apart from one that was free to begin with.
     * A bundle sold for nothing was free to begin with, whatever its discount says.
     *
     * @param  int[]  $selected
     * @param  string[]  $freePools
     */
    private function drawsOnDiscount(?CreditNoteDiscountAllocation $allocation, array $selected, array $freePools = []): bool
    {
        foreach ($selected as $index) {
            if (in_array($allocation?->poolFor($index), $freePools, true)) {
                continue;
            }

            if (($allocation?->discountFor($index) ?? 0.0) > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The financial invariant, checked before anything is signed: what this note
     * credits, plus everything already credited or reserved against this original,
     * can never exceed what the original charged plus what accepted debit notes added
     * — in total, and for each discount pool separately, so one bundle's discount can
     * never subsidise another's.
     *
     * Belt and braces over the allocator's own arithmetic. The allocator is pure and
     * unit-tested, but it is the only thing standing between a bundle invoice and an
     * over-credit, so its output is verified against the ledger rather than trusted.
     *
     * @param  int[]  $selected
     * @param  array<int, float>  $lineCredits  see lineCredits()
     * @param  ZatcaInvoiceLine[]  $debitLines  the debit note lines as this note credits them
     *
     * @throws CreditNoteException
     */
    private function assertWithinInvoicedAmount(
        ZatcaInvoiceData $originalData,
        CreditNoteLedgerSnapshot $ledgerSnapshot,
        ?CreditNoteDiscountAllocation $allocation,
        array $selected,
        array $lineCredits,
        array $debitLines,
    ): void {
        $fromOriginal = round(array_sum(array_map(
            fn (int $index) => $lineCredits[$index] ?? $originalData->lines[$index]->lineTotalInclVat(),
            $selected
        )), 2);

        $requested = round($fromOriginal + array_sum(array_map(
            fn (ZatcaInvoiceLine $line) => $line->lineTotalInclVat(),
            $debitLines
        )), 2);

        if ($requested > round($ledgerSnapshot->remainingCreditableInclVat, 2)) {
            throw CreditNoteException::exceedsRemaining();
        }

        if ($allocation === null) {
            return;
        }

        foreach ($ledgerSnapshot->discountPools as $poolKey => $pool) {
            $spent = 0.0;

            foreach ($selected as $index) {
                if ($allocation->poolFor($index) === $poolKey) {
                    $spent = round($spent + $allocation->discountFor($index), 2);
                }
            }

            if ($spent > round($pool['available'], 2)) {
                throw CreditNoteException::exceedsRemaining();
            }
        }
    }

    /**
     * Locks its way to the authoritative document, exactly like ZatcaInvoice::authoritative()
     * but taking a row lock at each hop — the eligibility decided inside issue() must be
     * decided against a row nothing else can change out from under it mid-transaction.
     */
    private function lockAuthoritative(ZatcaInvoice $invoice): ZatcaInvoice
    {
        $current = $invoice;
        $seen = [$current->getKey() => true];

        for ($depth = 0; $depth < ZatcaInvoice::MAX_REGENERATION_CHAIN_DEPTH; $depth++) {
            $nextId = $current->superseded_by_id;

            if ($nextId === null || isset($seen[$nextId])) {
                break;
            }

            $next = ZatcaInvoice::query()->lockForUpdate()->find($nextId);

            if (! $next) {
                break;
            }

            $seen[$nextId] = true;
            $current = $next;
        }

        return $current;
    }
}

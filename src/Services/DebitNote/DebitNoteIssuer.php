<?php

namespace Zatca\Services\DebitNote;

use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Exceptions\DebitNoteException;
use Zatca\Jobs\GenerateZatcaDebitNoteJob;
use Zatca\Models\ZatcaDebitNote;
use Zatca\Models\ZatcaDebitNoteEvent;
use Zatca\Models\ZatcaDebitNoteLine;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteLedger;
use Zatca\Services\CreditNote\CreditSource;
use Zatca\Services\Invoice\InvoiceDataValidator;
use Zatca\Support\InvoiceDataSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the zatca_debit_notes business record and its lines, then queues
 * generation. This is the ONE place a Debit Note is ever created — the admin
 * controller, and nothing else, calls into here.
 *
 * Concurrency, and how it differs from CreditNoteIssuer:
 *
 *  1. Idempotency replay is checked first, outside any lock — the common case (a
 *     retried or double-clicked request with the same Idempotency-Key) never needs
 *     to lock anything. Identical to the Credit Note path, and it is the guarantee
 *     that matters most here.
 *  2. The original invoice row is still locked with `SELECT ... FOR UPDATE` and the
 *     authoritative head resolved under that lock — but for a narrower reason than
 *     on the Credit Note path. There, the lock serializes competing claims on the
 *     same lines. Here nothing is claimed: the lock exists only so eligibility is
 *     decided against a row that cannot change (be superseded, be rejected)
 *     mid-transaction.
 *  3. There is consequently NO line-availability check and no active_line_key
 *     backstop. Two admins may issue two debit notes against the same invoice, for
 *     the same item, at the same moment, and both are valid documents. That is
 *     the business rule, not an oversight: multiple debit notes per invoice are
 *     explicitly allowed. Duplicate-charge prevention is a human review concern,
 *     and the Idempotency-Key covers the only case a machine can judge — the same
 *     request arriving twice.
 *
 * The original invoice, its lines and its snapshot are never read for values and
 * never written to. Even Price/VAT Adjustment, which references an original line
 * index, only records that reference. The same holds for an adjustment of an item
 * an earlier Debit Note added: it records that debit note line's id and nothing more.
 */
class DebitNoteIssuer
{
    public function __construct(
        private readonly DebitNoteEligibilityPolicy $eligibilityPolicy = new DebitNoteEligibilityPolicy,
        private readonly DebitNoteDataFactory $dataFactory = new DebitNoteDataFactory,
        private readonly InvoiceDataValidator $validator = new InvoiceDataValidator,
        // Reused, not duplicated: which items accepted debit notes added to this
        // original, and which items have been credited, are exactly what the Credit Note
        // flow already decides, and the two flows must never disagree about either.
        private readonly CreditNoteLedger $creditLedger = new CreditNoteLedger,
    ) {}

    /**
     * @param  ZatcaInvoice  $original  any document in the chain — the authoritative head is
     *   resolved and locked inside the transaction, not trusted from the caller.
     * @param  DebitNoteLineInput[]  $lines  the new charges. At least one.
     * @param  string  $reason  the KSA-10 / BR-KSA-17 text that goes into the signed XML.
     * @param  array{type: string, id: int|string}|null  $requestedBy  null means no human did —
     *   never guessed.
     *
     * @throws DebitNoteException when the original is not eligible, or the lines are empty
     *   or malformed.
     */
    public function issue(
        ZatcaInvoice $original,
        ZatcaDebitNoteReason $reasonType,
        array $lines,
        string $reason,
        string $idempotencyKey,
        ?array $requestedBy = null,
    ): ZatcaDebitNote {
        $existing = ZatcaDebitNote::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        if ($lines === []) {
            throw DebitNoteException::noLines();
        }

        $debitNote = DB::transaction(function () use ($original, $reasonType, $lines, $reason, $idempotencyKey, $requestedBy) {
            // Re-checked inside the lock: two requests with different keys could both have
            // passed the fast-path check above before either committed.
            $existing = ZatcaDebitNote::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing) {
                return $existing;
            }

            $locked = ZatcaInvoice::query()->lockForUpdate()->findOrFail($original->getKey());
            $authoritative = $this->lockAuthoritative($locked);

            $verdict = $this->eligibilityPolicy->verdict($authoritative);

            if (! $verdict->eligible) {
                throw DebitNoteException::notEligible($verdict->reason);
            }

            $this->assertReferencesAreAdjustable($authoritative, $lines);

            $originalData = InvoiceDataSnapshot::fromArray($authoritative->invoice_data_snapshot);

            $debitNote = ZatcaDebitNote::create([
                'egs_identifier' => $authoritative->egs_identifier,
                'environment' => $authoritative->environment->value,
                'original_zatca_invoice_id' => $authoritative->getKey(),
                'original_source_reference' => Str::before($authoritative->source_reference, '#r'),
                'billing_reference_id' => $authoritative->source_reference,
                'reason_type' => $reasonType->value,
                'reason' => $reason,
                'document_snapshot' => [],
                'currency' => $originalData->currency,
                'total_excl_vat' => 0,
                'total_vat' => 0,
                'total_incl_vat' => 0,
                'original_total_incl_vat' => $originalData->totalInclVat,
                'original_line_count' => count($originalData->lines),
                'idempotency_key' => $idempotencyKey,
                'requested_by_type' => $requestedBy['type'] ?? null,
                'requested_by_id' => $requestedBy['id'] ?? null,
                'issued_at' => now(),
            ]);

            // Number depends on the row's own id, so it is assigned right after insert —
            // still inside this transaction, so nothing outside it ever observes the row
            // without a number.
            $debitNote->update(['debit_note_number' => sprintf('DN-%06d', $debitNote->getKey())]);

            $debitNoteData = $this->dataFactory->make(
                $originalData,
                $lines,
                $debitNote->debit_note_number,
                $authoritative->source_reference,
                $reason,
                $debitNote->issued_at->toDateTimeImmutable(),
            );

            // Arithmetic + KSA-10/BR-KSA-56/BR-KSA-F-06-C13 — the same gate a plain invoice
            // and a Credit Note pass through before consuming an ICV. Throwing here rolls
            // back everything above; nothing is left half-created.
            $this->validator->validate($debitNoteData);

            foreach ($lines as $line) {
                ZatcaDebitNoteLine::create([
                    'debit_note_id' => $debitNote->getKey(),
                    'original_zatca_invoice_id' => $authoritative->getKey(),
                    // Informational only — see the lines migration. Nothing ever writes to
                    // the original line this points at.
                    'original_line_index' => $line->originalLineIndex,
                    'source_debit_note_line_id' => $line->sourceDebitNoteLineId,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                    'vat_rate' => $line->vatRate,
                    'line_total_excl_vat' => $line->lineTotalExclVat,
                    'vat_amount' => $line->vatAmount,
                    'line_total_incl_vat' => $line->lineTotalInclVat(),
                    'item_id' => $line->itemId,
                ]);
            }

            $debitNote->update([
                'document_snapshot' => InvoiceDataSnapshot::toArray($debitNoteData),
                'total_excl_vat' => $debitNoteData->totalExclVat,
                'total_vat' => $debitNoteData->totalVat,
                'total_incl_vat' => $debitNoteData->totalInclVat,
            ]);

            ZatcaDebitNoteEvent::record($debitNote->getKey(), 'requested', $requestedBy, [
                'reason_type' => $reasonType->value,
                'line_count' => count($lines),
                'item_ids' => array_values(array_filter(array_map(fn ($l) => $l->itemId, $lines))),
                'total_incl_vat' => $debitNoteData->totalInclVat,
            ]);

            DB::afterCommit(fn () => GenerateZatcaDebitNoteJob::dispatch($debitNote->getKey()));

            return $debitNote->fresh();
        });

        return $debitNote;
    }

    /**
     * What a line that adjusts something may reference:
     *
     *  - An item an earlier Debit Note added must be an Additional Item line of an
     *    ACCEPTED debit note against this same original — the items the Credit Note
     *    flow offers for crediting, so both flows agree on what exists — and the line
     *    must not also name an original line.
     *  - Either kind of item must still be 'available' in the Credit Note ledger. One
     *    credited back by an accepted Credit Note has been refunded, and charging more for
     *    it would bill the buyer for something they no longer have. One held by a Credit
     *    Note still in flight is refused until that note is accepted or cancelled.
     *
     * An original line index the original does not have is left to DebitNoteDataFactory,
     * which refuses it with its own code.
     *
     * Decided under the same lock as eligibility. CreditNoteIssuer takes that same lock,
     * so a Credit Note cannot claim the item between this check and the insert.
     *
     * @param  DebitNoteLineInput[]  $lines
     *
     * @throws DebitNoteException
     */
    private function assertReferencesAreAdjustable(ZatcaInvoice $original, array $lines): void
    {
        $ledger = null;
        $referenced = [];

        foreach ($lines as $line) {
            if ($line->sourceDebitNoteLineId === null && $line->originalLineIndex === null) {
                continue;
            }

            // One note, one line per item. Two lines naming the same item is a request
            // nobody meant to make, and silently charging both would hide it.
            $reference = $line->sourceDebitNoteLineId !== null
                ? CreditSource::debitNoteLineKey($line->sourceDebitNoteLineId)
                : CreditSource::invoiceLineKey($line->originalLineIndex);

            if (isset($referenced[$reference])) {
                throw DebitNoteException::invalidLine("Item [{$reference}] is named by more than one line.");
            }

            $referenced[$reference] = true;

            $ledger ??= $this->creditLedger->forOriginal($original);

            if ($line->sourceDebitNoteLineId !== null) {
                $id = $line->sourceDebitNoteLineId;
                $source = $ledger->debitSources[$id] ?? null;

                if ($line->originalLineIndex !== null || $source === null || ! $source->canBePriceAdjusted()) {
                    throw DebitNoteException::invalidDebitNoteLineReference($id);
                }

                if (($ledger->debitLineStates[$id] ?? null) !== 'available') {
                    throw DebitNoteException::lineNotAdjustable(CreditSource::debitNoteLineKey($id));
                }

                continue;
            }

            $state = $ledger->lineStates[$line->originalLineIndex] ?? null;

            if ($state !== null && $state !== 'available') {
                throw DebitNoteException::lineNotAdjustable(CreditSource::invoiceLineKey($line->originalLineIndex));
            }
        }
    }

    /**
     * Locks its way to the authoritative document, exactly like
     * CreditNoteIssuer::lockAuthoritative() — the eligibility decided inside issue()
     * must be decided against a row nothing else can change out from under it
     * mid-transaction.
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

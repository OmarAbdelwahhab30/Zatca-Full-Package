<?php

namespace Zatca\Services\CreditNote;

use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Exceptions\ZatcaException;
use Zatca\Models\ZatcaDebitNote;
use Zatca\Models\ZatcaDebitNoteLine;
use Zatca\Models\ZatcaInvoice;
use Zatca\Support\InvoiceDataSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * Finds the debit note lines an original invoice may now be credited for.
 *
 * Only ACCEPTED debit notes count — accepted_at is the same single fact that makes
 * a debit note count financially everywhere else. A pending, processing, failed,
 * rejected or cancelled note introduces nothing. Acceptance is written once and a
 * note can never be cancelled after it, so this set only ever grows.
 *
 * Amounts come from the debit note's signed document_snapshot, matched position by
 * position to its line rows (both are written in the same order by DebitNoteIssuer).
 * If the two ever disagree the note's lines are left out rather than guessed at —
 * leaving an amount uncreditable is recoverable, crediting a wrong one is not.
 */
class CreditSourceResolver
{
    private const EPSILON = 0.005;

    /**
     * @return array<int, CreditSource> debit note line id => source, ordered by debit note, then line
     */
    public function debitNoteSources(ZatcaInvoice $original): array
    {
        $notes = ZatcaDebitNote::query()
            ->where('original_zatca_invoice_id', $original->getKey())
            ->whereNotNull('accepted_at')
            ->whereNull('cancelled_at')
            ->with(['lines' => fn ($query) => $query->orderBy('id')])
            ->orderBy('id')
            ->get();

        $sources = [];

        foreach ($notes as $note) {
            foreach ($this->sourcesOf($note) as $source) {
                $sources[$source->debitNoteLineId] = $source;
            }
        }

        return $sources;
    }

    /** @return CreditSource[] */
    private function sourcesOf(ZatcaDebitNote $note): array
    {
        try {
            $signed = InvoiceDataSnapshot::fromArray($note->document_snapshot)->lines;
        } catch (ZatcaException $e) {
            return $this->unresolved($note, 'unusable document snapshot: '.$e->getMessage());
        }

        $rows = $note->lines->values();

        if (count($signed) !== $rows->count()) {
            return $this->unresolved($note, 'line count differs between snapshot and line rows');
        }

        $reason = $note->reason_type instanceof ZatcaDebitNoteReason
            ? $note->reason_type
            : ZatcaDebitNoteReason::tryFrom((string) $note->reason_type);

        if ($reason === null) {
            return $this->unresolved($note, 'unknown reason type');
        }

        $sources = [];

        foreach ($rows as $position => $row) {
            /** @var ZatcaDebitNoteLine $row */
            $line = $signed[$position];

            if (! $this->matches($line->lineTotalExclVat, (float) $row->line_total_excl_vat)
                || ! $this->matches($line->vatAmount, (float) $row->vat_amount)
                || ! $this->matches($line->vatRate, (float) $row->vat_rate)) {
                return $this->unresolved($note, "line row #{$row->id} differs from the signed snapshot");
            }

            $sources[] = new CreditSource(
                line: $line,
                debitNoteId: (int) $note->getKey(),
                debitNoteLineId: (int) $row->getKey(),
                debitNoteNumber: (string) $note->debit_note_number,
                reasonType: $reason,
                referencedOriginalLineIndex: $row->original_line_index !== null ? (int) $row->original_line_index : null,
                courseId: $row->course_id !== null ? (int) $row->course_id : null,
                referencedDebitNoteLineId: $row->source_debit_note_line_id !== null
                    ? (int) $row->source_debit_note_line_id
                    : null,
            );
        }

        return $sources;
    }

    private function matches(float $a, float $b): bool
    {
        return abs($a - $b) < self::EPSILON;
    }

    /** @return CreditSource[] */
    private function unresolved(ZatcaDebitNote $note, string $why): array
    {
        Log::warning('[ZATCA] Accepted Debit Note left out of creditable sources: '.$why, [
            'debit_note_id' => $note->getKey(),
        ]);

        return [];
    }
}

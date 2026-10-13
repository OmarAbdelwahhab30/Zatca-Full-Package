<?php

namespace Zatca\Models;

use Zatca\Services\CreditNote\CreditSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZatcaCreditNoteLine extends Model
{
    protected $table = 'zatca_credit_note_lines';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'line_total_excl_vat' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'line_total_incl_vat' => 'decimal:2',
        'discount_applied_incl_vat' => 'decimal:2',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(ZatcaCreditNote::class, 'credit_note_id');
    }

    public function originalZatcaInvoice(): BelongsTo
    {
        return $this->belongsTo(ZatcaInvoice::class, 'original_zatca_invoice_id');
    }

    /** The accepted Debit Note this line credits back, when it is a debit note source. */
    public function sourceDebitNote(): BelongsTo
    {
        return $this->belongsTo(ZatcaDebitNote::class, 'source_debit_note_id');
    }

    public function isDebitNoteSource(): bool
    {
        return $this->source_type === CreditSource::TYPE_DEBIT_NOTE_LINE;
    }

    /**
     * A partial credit of one course's value (see ZatcaCreditNoteReason::PriceAdjustment).
     * Neither an original-line claim nor a debit-note-line claim: the course it adjusts
     * stays creditable, so callers that read claims by source must leave these out.
     */
    public function isPriceAdjustment(): bool
    {
        return $this->source_type === CreditSource::TYPE_PRICE_ADJUSTMENT;
    }

    /** A claim on a line of the original invoice itself — neither a debit note line nor a price adjustment. */
    public function isInvoiceLineSource(): bool
    {
        return ! $this->isDebitNoteSource() && ! $this->isPriceAdjustment();
    }

    /** "{original_zatca_invoice_id}:{original_line_index}" — the concurrency backstop key. */
    public static function activeLineKey(int $originalZatcaInvoiceId, int $originalLineIndex): string
    {
        return "{$originalZatcaInvoiceId}:{$originalLineIndex}";
    }

    /**
     * "dn_line:{debit_note_line_id}" — the same backstop for a debit note line. Debit
     * note line ids are globally unique, and the prefix can never collide with the
     * numeric "{invoice}:{index}" form, so one unique index guards both kinds.
     */
    public static function debitNoteLineKey(int $debitNoteLineId): string
    {
        return CreditSource::debitNoteLineKey($debitNoteLineId);
    }

    /**
     * "pa:{credit_note_id}:{source_key}" — a price adjustment's key. It claims no source
     * (a course can be adjusted more than once), so it is keyed by its own note; the
     * source key is part of it because one note may adjust several courses, and the
     * column is unique. It still lives in active_line_key so that cancelling a note
     * releases it by the same single mechanism as every other claim.
     */
    public static function priceAdjustmentKey(int $creditNoteId, string $sourceKey): string
    {
        return 'pa:'.$creditNoteId.':'.$sourceKey;
    }
}

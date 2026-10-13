<?php

namespace Zatca\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZatcaDebitNoteLine extends Model
{
    protected $table = 'zatca_debit_note_lines';

    protected $guarded = [];

    protected $casts = [
        'item_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'line_total_excl_vat' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'line_total_incl_vat' => 'decimal:2',
    ];

    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(ZatcaDebitNote::class, 'debit_note_id');
    }

    public function originalZatcaInvoice(): BelongsTo
    {
        return $this->belongsTo(ZatcaInvoice::class, 'original_zatca_invoice_id');
    }

    /** The earlier debit note line (an added item) this line adjusts, if any. */
    public function sourceDebitNoteLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_debit_note_line_id');
    }

    /** @deprecated Use `$line->item_id`. Read-only alias kept for code written before the rename. */
    public function getCourseIdAttribute(): ?int
    {
        $value = $this->attributes['item_id'] ?? null;

        return $value === null ? null : (int) $value;
    }
}

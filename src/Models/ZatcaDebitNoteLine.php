<?php

namespace Zatca\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZatcaDebitNoteLine extends Model
{
    protected $table = 'zatca_debit_note_lines';

    protected $guarded = [];

    protected $casts = [
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

    /** The earlier debit note line (an added course) this line adjusts, if any. */
    public function sourceDebitNoteLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_debit_note_line_id');
    }
}

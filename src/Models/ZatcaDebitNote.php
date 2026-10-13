<?php

namespace Zatca\Models;

use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Enums\ZatcaEnvironment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ZatcaDebitNote extends Model
{
    protected $table = 'zatca_debit_notes';

    protected $guarded = [];

    protected $casts = [
        'environment' => ZatcaEnvironment::class,
        'reason_type' => ZatcaDebitNoteReason::class,
        'document_snapshot' => 'array',
        'total_excl_vat' => 'decimal:2',
        'total_vat' => 'decimal:2',
        'total_incl_vat' => 'decimal:2',
        'original_total_incl_vat' => 'decimal:2',
        'issued_at' => 'datetime',
        'accepted_at' => 'datetime',
        'generation_claimed_at' => 'datetime',
        'generation_failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** The authoritative original document at the moment this note was requested. */
    public function originalZatcaInvoice(): BelongsTo
    {
        return $this->belongsTo(ZatcaInvoice::class, 'original_zatca_invoice_id');
    }

    /**
     * Every technical zatca_invoices row ever built for this note — one per
     * generation/regeneration attempt, exactly as for a Credit Note or an invoice.
     */
    public function zatcaInvoices(): HasMany
    {
        return $this->hasMany(ZatcaInvoice::class, 'debit_note_id');
    }

    /** The current technical document for this note — see ZatcaCreditNote::latestZatcaInvoice(). */
    public function latestZatcaInvoice(): HasOne
    {
        return $this->hasOne(ZatcaInvoice::class, 'debit_note_id')->latestOfMany();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ZatcaDebitNoteLine::class, 'debit_note_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ZatcaDebitNoteEvent::class, 'debit_note_id');
    }

    /**
     * Accepted by ZATCA — the only state that counts financially.
     *
     * Emphatically NOT "the student has paid". ZATCA accepting a debit note means
     * the document is fiscally valid, nothing more; collecting the money is a
     * separate, manual, human process through the ordinary payment flow.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}

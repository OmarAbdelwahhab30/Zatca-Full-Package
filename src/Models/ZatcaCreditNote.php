<?php

namespace Zatca\Models;

use Zatca\Enums\ZatcaCreditNoteReason;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Enums\ZatcaEnvironment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ZatcaCreditNote extends Model
{
    protected $table = 'zatca_credit_notes';

    protected $guarded = [];

    protected $casts = [
        'environment' => ZatcaEnvironment::class,
        'type' => ZatcaCreditNoteType::class,
        'reason_type' => ZatcaCreditNoteReason::class,
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
     * generation/regeneration attempt, mirroring how an invoice's replacements work.
     */
    public function zatcaInvoices(): HasMany
    {
        return $this->hasMany(ZatcaInvoice::class, 'credit_note_id');
    }

    /**
     * The current technical document for this note. Not "the latest by id" — a
     * regenerated note's old document is superseded but not deleted, so this
     * follows the same authoritative()/supersede convention as an invoice.
     */
    public function latestZatcaInvoice(): HasOne
    {
        return $this->hasOne(ZatcaInvoice::class, 'credit_note_id')->latestOfMany();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ZatcaCreditNoteLine::class, 'credit_note_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ZatcaCreditNoteEvent::class, 'credit_note_id');
    }

    /** Accepted by ZATCA — the only state that counts toward Credit Status (BR-09). */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Why this note was issued. NULL is a note issued before reasons existed, and every
     * one of those was a course cancellation — see the reason_type migration.
     */
    public function creditReason(): ZatcaCreditNoteReason
    {
        return $this->reason_type ?? ZatcaCreditNoteReason::CourseCancellation;
    }

    public function isPriceAdjustment(): bool
    {
        return $this->creditReason() === ZatcaCreditNoteReason::PriceAdjustment;
    }
}

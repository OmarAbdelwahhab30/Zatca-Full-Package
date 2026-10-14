<?php

namespace Zatca\Models;

use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Enums\ZatcaSubmissionModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ZatcaInvoice extends Model
{
    protected $table = 'zatca_invoices';

    protected $guarded = [];

    protected $casts = [
        'environment' => ZatcaEnvironment::class,
        'status' => ZatcaInvoiceStatus::class,
        'document_type' => ZatcaComplianceDocumentType::class,
        'last_zatca_response' => 'array',
        'invoice_data_snapshot' => 'array',
        'icv' => 'integer',
        'attempt_count' => 'integer',
        'total_excl_vat' => 'decimal:2',
        'total_vat' => 'decimal:2',
        'total_incl_vat' => 'decimal:2',
        'reported_at' => 'datetime',
        'regeneration_claimed_at' => 'datetime',
        'generation_claimed_at' => 'datetime',
        'abandoned_at' => 'datetime',
        'cleared_at' => 'datetime',
        'submission_claimed_at' => 'datetime',
        'in_doubt_since' => 'datetime',
        'reissued_by_id' => 'integer',
    ];

    /**
     * How ZATCA receives this document. The column defaults to 'reporting'; a model
     * created without it (every B2C path) has no attribute until refreshed, and reads as
     * Reporting too. An unknown stored value throws, like the other enum casts.
     */
    protected function submissionModel(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? ZatcaSubmissionModel::Reporting : ZatcaSubmissionModel::from($value),
            set: fn (ZatcaSubmissionModel|string $value) => $value instanceof ZatcaSubmissionModel ? $value->value : ZatcaSubmissionModel::from($value)->value,
        );
    }

    /**
     * How far a regeneration chain may be followed.
     *
     * Guards against a corrupt superseded_by_id link (a cycle, or a row pointing at
     * itself) turning a page render into an infinite loop. Also the natural ceiling on
     * how many times one invoice may be regenerated — see ZatcaRegenerationEligibility.
     */
    public const MAX_REGENERATION_CHAIN_DEPTH = 5;

    /** The Credit Note business record this technical document belongs to, if it is one. */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(ZatcaCreditNote::class, 'credit_note_id');
    }

    /** The Debit Note business record this technical document belongs to, if it is one. */
    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(ZatcaDebitNote::class, 'debit_note_id');
    }

    public function submissionAttempts(): HasMany
    {
        return $this->hasMany(ZatcaSubmissionAttempt::class);
    }

    /**
     * The most recent attempt, used to tell a ZATCA outage (retryable) apart
     * from a ZATCA rejection (never retryable) once an invoice has landed in
     * manual_review.
     */
    public function latestSubmissionAttempt(): HasOne
    {
        return $this->hasOne(ZatcaSubmissionAttempt::class)->latestOfMany('attempt_number');
    }

    /** The rejected document this one was regenerated from, if it is a replacement. */
    public function regeneratedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'regenerated_from_id');
    }

    /** The replacement that took over from this document, if it has been superseded. */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * Every replacement ever built from this document — including abandoned Drafts,
     * which are kept because their ICV slot is permanently consumed.
     */
    public function regenerations(): HasMany
    {
        return $this->hasMany(self::class, 'regenerated_from_id');
    }

    /**
     * When ZATCA accepted this document, whichever way it was submitted: a Simplified
     * (B2C) document is reported and stamps reported_at, a Standard (B2B) one is
     * cleared and stamps cleared_at only. Read this for "تاريخ الإبلاغ", never
     * reported_at alone, or every cleared document shows as not yet reported.
     */
    public function acceptedAt(): ?\Illuminate\Support\Carbon
    {
        return $this->reported_at ?? $this->cleared_at;
    }

    /**
     * Whether a Credit Note or Debit Note against this original has not reached a final
     * state yet — neither accepted nor cancelled. A rejected or failed note counts: it
     * still holds what it claimed until it is retried or cancelled.
     *
     * While one does, no further note of either kind may be issued against this
     * original, so the same item can never be adjusted twice before the first
     * adjustment settles. See CreditNoteEligibilityPolicy / DebitNoteEligibilityPolicy.
     */
    public function hasAdjustmentInProgress(): bool
    {
        $unsettled = fn ($query) => $query
            ->where('original_zatca_invoice_id', $this->getKey())
            ->whereNull('accepted_at')
            ->whereNull('cancelled_at');

        return $unsettled(ZatcaCreditNote::query())->exists()
            || $unsettled(ZatcaDebitNote::query())->exists();
    }

    /** Whether a newer document has taken over from this one. */
    public function isSuperseded(): bool
    {
        return $this->superseded_by_id !== null;
    }

    /** Whether a regeneration is in flight for this document right now. */
    public function isRegenerationInFlight(): bool
    {
        return $this->regeneration_claimed_at !== null;
    }

    /** Whether an administrator-triggered completion of this unsigned Draft is running. */
    public function isGenerationInFlight(): bool
    {
        return $this->generation_claimed_at !== null;
    }

    /** Whether this technical document is a Credit Note (381), Simplified or Standard, rather than an invoice (388). */
    public function isCreditNote(): bool
    {
        return $this->document_type?->isCreditNoteKind() === true;
    }

    /** Whether this technical document is a Debit Note (383), Simplified or Standard, rather than an invoice (388). */
    public function isDebitNote(): bool
    {
        return $this->document_type?->isDebitNoteKind() === true;
    }

    /** Whether this document adjusts another one — a credit note (381) or a debit note (383). */
    public function isAdjustmentNote(): bool
    {
        return $this->document_type?->requiresBillingReference() === true;
    }

    /**
     * Whether this document was built but never signed.
     *
     * Both halves are checked because they can disagree. A row is created as Draft and
     * only leaves that status once signing commits, so status alone is the intent; but
     * signing writes the status and the signed document together, so the absence of the
     * document is the fact. Treating a row as unsigned when either says so keeps the
     * conservative answer: nothing is ever signed twice on the strength of one column.
     */
    public function isUnsignedDraft(): bool
    {
        return $this->status === ZatcaInvoiceStatus::Draft && blank($this->signed_xml_base64);
    }

    /**
     * Whether this Draft has been permanently retired.
     *
     * Set on a replacement Draft whose XML the current generator no longer reproduces.
     * Its reserved ICV stays consumed — ICVs are never recycled — but the document itself
     * must never be signed, because signing it would emit the very content a regeneration
     * was meant to replace and would move the hash chain to accommodate it.
     */
    public function isAbandoned(): bool
    {
        return $this->abandoned_at !== null;
    }

    /**
     * The document that currently represents this invoice.
     *
     * Follows superseded_by_id to the end of the chain. That link is written only
     * after a replacement has been signed, so this can never land on an unsigned or
     * abandoned Draft — the authoritative document is always a real, submittable one.
     *
     * Bounded by MAX_REGENERATION_CHAIN_DEPTH so a cyclic link cannot hang the caller.
     * A chain longer than that stops where it stops rather than throwing: returning a
     * slightly stale document is far better than failing to render an invoice.
     */
    public function authoritative(): self
    {
        $current = $this;
        $seen = [$current->getKey() => true];

        for ($depth = 0; $depth < self::MAX_REGENERATION_CHAIN_DEPTH; $depth++) {
            $nextId = $current->superseded_by_id;

            if ($nextId === null || isset($seen[$nextId])) {
                break;
            }

            $next = self::query()->find($nextId);

            if (! $next) {
                break; // Dangling link — the current document remains authoritative.
            }

            $seen[$nextId] = true;
            $current = $next;
        }

        return $current;
    }

    /**
     * How many regenerations deep this document sits, walking regenerated_from_id back
     * towards the original. 0 for a document the purchase flow generated directly.
     */
    public function regenerationDepth(): int
    {
        $depth = 0;
        $current = $this;
        $seen = [$current->getKey() => true];

        while ($current->regenerated_from_id !== null && $depth < self::MAX_REGENERATION_CHAIN_DEPTH) {
            $parentId = $current->regenerated_from_id;

            if (isset($seen[$parentId])) {
                break;
            }

            $parent = self::query()->find($parentId);

            if (! $parent) {
                break;
            }

            $seen[$parentId] = true;
            $current = $parent;
            $depth++;
        }

        return $depth;
    }
}

<?php

namespace Zatca\Models;

use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
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
    ];

    /**
     * How far a regeneration chain may be followed.
     *
     * Guards against a corrupt superseded_by_id link (a cycle, or a row pointing at
     * itself) turning a page render into an infinite loop. Also the natural ceiling on
     * how many times one invoice may be regenerated — see ZatcaRegenerationEligibility.
     */
    public const MAX_REGENERATION_CHAIN_DEPTH = 5;

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

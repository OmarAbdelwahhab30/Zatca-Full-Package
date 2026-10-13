<?php

namespace Zatca\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZatcaCreditNoteEvent extends Model
{
    protected $table = 'zatca_credit_note_events';

    protected $guarded = [];

    public $timestamps = false; // created_at only, set explicitly

    protected $casts = [
        'detail' => 'array',
        'created_at' => 'datetime',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(ZatcaCreditNote::class, 'credit_note_id');
    }

    public static function record(int $creditNoteId, string $event, ?array $actor = null, ?array $detail = null): self
    {
        return self::create([
            'credit_note_id' => $creditNoteId,
            'event' => $event,
            'actor_type' => $actor['type'] ?? null,
            'actor_id' => $actor['id'] ?? null,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }
}

<?php

namespace Zatca\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZatcaDebitNoteEvent extends Model
{
    protected $table = 'zatca_debit_note_events';

    protected $guarded = [];

    public $timestamps = false; // created_at only, set explicitly

    protected $casts = [
        'detail' => 'array',
        'created_at' => 'datetime',
    ];

    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(ZatcaDebitNote::class, 'debit_note_id');
    }

    public static function record(int $debitNoteId, string $event, ?array $actor = null, ?array $detail = null): self
    {
        return self::create([
            'debit_note_id' => $debitNoteId,
            'event' => $event,
            'actor_type' => $actor['type'] ?? null,
            'actor_id' => $actor['id'] ?? null,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }
}

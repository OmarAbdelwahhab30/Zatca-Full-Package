<?php

namespace Asas\Zatca\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZatcaSubmissionAttempt extends Model
{
    protected $table = 'zatca_submission_attempts';

    protected $guarded = [];

    protected $casts = [
        'sanitized_response' => 'array',
        'attempted_at' => 'datetime',
        'attempt_number' => 'integer',
        'http_status' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ZatcaInvoice::class, 'zatca_invoice_id');
    }
}

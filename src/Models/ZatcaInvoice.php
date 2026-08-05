<?php

namespace Zatca\Models;

use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZatcaInvoice extends Model
{
    protected $table = 'zatca_invoices';

    protected $guarded = [];

    protected $casts = [
        'environment' => ZatcaEnvironment::class,
        'status' => ZatcaInvoiceStatus::class,
        'last_zatca_response' => 'array',
        'icv' => 'integer',
        'attempt_count' => 'integer',
        'total_excl_vat' => 'decimal:2',
        'total_vat' => 'decimal:2',
        'total_incl_vat' => 'decimal:2',
        'reported_at' => 'datetime',
    ];

    public function submissionAttempts(): HasMany
    {
        return $this->hasMany(ZatcaSubmissionAttempt::class);
    }
}

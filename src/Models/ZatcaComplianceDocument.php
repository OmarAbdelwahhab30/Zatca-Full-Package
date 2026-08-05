<?php

namespace Asas\Zatca\Models;

use Illuminate\Database\Eloquent\Model;

class ZatcaComplianceDocument extends Model
{
    protected $table = 'zatca_compliance_documents';

    protected $guarded = [];

    protected $casts = [
        'zatca_response' => 'array',
        'icv' => 'integer',
    ];
}

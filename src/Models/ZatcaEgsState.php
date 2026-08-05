<?php

namespace Asas\Zatca\Models;

use Asas\Zatca\Enums\ZatcaCredentialType;
use Asas\Zatca\Enums\ZatcaEnvironment;
use Illuminate\Database\Eloquent\Model;

class ZatcaEgsState extends Model
{
    protected $table = 'zatca_egs_states';

    protected $guarded = [];

    protected $casts = [
        'environment' => ZatcaEnvironment::class,
        'state_type' => ZatcaCredentialType::class,
        'icv' => 'integer',
    ];
}

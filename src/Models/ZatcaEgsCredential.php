<?php

namespace Asas\Zatca\Models;

use Asas\Zatca\Enums\ZatcaCertificateState;
use Asas\Zatca\Enums\ZatcaCredentialType;
use Asas\Zatca\Enums\ZatcaEnvironment;
use Illuminate\Database\Eloquent\Model;

class ZatcaEgsCredential extends Model
{
    protected $table = 'zatca_egs_credentials';

    protected $guarded = [];

    protected $casts = [
        'environment' => ZatcaEnvironment::class,
        'credential_type' => ZatcaCredentialType::class,
        'certificate_state' => ZatcaCertificateState::class,
        'certificate_issued_at' => 'datetime',
        'certificate_expires_at' => 'datetime',
    ];

    protected $hidden = ['secret', 'private_key'];

    public function scopeForEgs($query, string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $type)
    {
        return $query
            ->where('egs_identifier', $egsIdentifier)
            ->where('environment', $environment->value)
            ->where('credential_type', $type->value);
    }
}

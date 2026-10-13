<?php

namespace Zatca\Support;

use Illuminate\Support\Facades\Schema;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Services\Diagnostics\ZatcaInspector;
use Zatca\State\EgsRegistry;

/**
 * Whether B2B (Standard, cleared) invoicing can actually run: the switch is on AND the
 * B2B EGS is configured, holds a valid production certificate, and has passed the
 * Standard compliance steps.
 *
 * ZatcaGate::b2bEnabled() is the switch alone — an env flag an admin can leave on long
 * after the EGS it pointed at was torn down (e.g. its onboarding rows cleared on
 * staging). This is the switch PLUS the persisted onboarding state, and it is what the
 * checkout and admin screens use to decide whether the Company option is actually
 * usable, not just configured to be.
 */
class B2bReadiness
{
    public function __construct(
        private readonly ZatcaGate $gate = new ZatcaGate,
        private readonly EgsRegistry $egsRegistry = new EgsRegistry,
        private readonly ZatcaInspector $inspector = new ZatcaInspector(new EgsRegistry),
    ) {}

    public function ready(): bool
    {
        // The switch is checked first so "off" can never be reported as "on but not
        // ready" — a deliberately disabled B2B flow is just off, not an onboarding gap.
        if ($this->gate->b2bDisabled()) {
            return false;
        }

        $egsIdentifier = config('zatca.b2b.egs_identifier');

        if (! is_string($egsIdentifier) || $egsIdentifier === ''
            || ! in_array($egsIdentifier, $this->egsRegistry->identifiers(), true)) {
            return false;
        }

        // The package's migrations may not have run yet on a fresh install, and an
        // environment whose onboarding tables were wiped (e.g. a staging reset) must
        // report "not ready", never fall back to the env switch's say-so.
        if (! Schema::hasTable('zatca_egs_credentials')) {
            return false;
        }

        $credentials = collect($this->inspector->credentials($egsIdentifier));

        $hasPassedCompliance = $credentials
            ->firstWhere('credential_type', ZatcaCredentialType::Compliance->value)['has_certificate'] ?? false;

        $production = $credentials->firstWhere('credential_type', ZatcaCredentialType::Production->value);

        $hasValidProductionCertificate = $production !== null
            && ($production['has_certificate'] ?? false)
            && ($production['certificate_state'] ?? null) === 'valid';

        return $hasPassedCompliance && $hasValidProductionCertificate;
    }
}

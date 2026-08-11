<?php

namespace Zatca\Console\Commands;

use Zatca\DTOs\EgsConfig;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\SDK\ZatcaSdkFingerprint;
use Zatca\Services\SDK\ZatcaSdkRuntimeManager;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\State\EgsRegistry;
use Illuminate\Console\Command;

/**
 * Non-destructive readiness check. Verifies SDK binary identity (fails
 * loudly on drift instead of masquerading as a ZATCA API change),
 * credential presence per EGS, and certificate state — everything an
 * operator needs before trusting production reporting to run unattended.
 */
class ZatcaHealthCheck extends Command
{
    protected $signature = 'zatca:health-check';

    protected $description = 'Verify SDK fingerprint, credentials, and certificate state for all configured EGS.';

    public function handle(
        ZatcaSdkRuntimeManager $runtime,
        ZatcaSdkFingerprint $fingerprint,
        EgsRegistry $registry,
        ZatcaCredentialStore $credentialStore,
    ): int {
        $healthy = true;

        try {
            $runtime->jarPath();
            $this->info('[OK] SDK executable found: '.$runtime->jarPath());
        } catch (ZatcaException $e) {
            $this->error('[FAIL] '.$e->getMessage());
            $healthy = false;
        }

        try {
            $fingerprint->verify();
            $expected = $fingerprint->expected();
            $this->info($expected ? '[OK] SDK fingerprint matches recorded value.' : '[SKIP] No SDK fingerprint recorded yet (run zatca:generate-credentials, then set ZATCA_SDK_FINGERPRINT).');
        } catch (\Throwable $e) {
            $this->error('[FAIL] '.$e->getMessage());
            $healthy = false;
        }

        foreach ($registry->identifiers() as $egs) {
            $egsConfig = $registry->for($egs);

            if (! $this->checkCompanyConfig($egs, $egsConfig)) {
                $healthy = false;
            }

            foreach ([ZatcaCredentialType::Compliance, ZatcaCredentialType::Production] as $type) {
                if (! $credentialStore->has($egs, $egsConfig->environment, $type)) {
                    $this->warn("[PENDING] [{$egs}] {$type->value} credentials not yet onboarded.");

                    continue;
                }

                $credentials = $credentialStore->get($egs, $egsConfig->environment, $type);
                $this->info("[OK] [{$egs}] {$type->value} credentials present — certificate state: {$credentials->certificateState->value}.");

                if (in_array($credentials->certificateState->value, ['expired', 'failed'], true)) {
                    $healthy = false;
                }
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Pre-flight the company configuration that ends up inside the CSR and the
     * invoice XML. Every rule here would otherwise surface as an opaque ZATCA
     * rejection at onboarding or reporting time — long after the deploy that
     * caused it.
     *
     * Missing structured-address fields are a warning, not a failure: the
     * generators fall back to the legacy ZATCA_LOCATION_ADDRESS, so an
     * installation that predates those keys is degraded, not broken.
     */
    private function checkCompanyConfig(string $egs, EgsConfig $egsConfig): bool
    {
        $healthy = true;

        // ZATCA's CSR spec defines csr.organization.identifier as the taxpayer's
        // VAT registration number. A CSR whose identifier and VAT disagree is
        // rejected during production onboarding with an unhelpful error.
        if ($egsConfig->organizationIdentifier && $egsConfig->vatNumber
            && $egsConfig->organizationIdentifier !== $egsConfig->vatNumber) {
            $this->error("[FAIL] [{$egs}] ZATCA_ORG_IDENTIFIER does not match ZATCA_VAT_NUMBER — ZATCA expects both to be the same 15-digit VAT registration number.");
            $healthy = false;
        }

        if ($egsConfig->vatNumber && ! preg_match('/^3\d{13}3$/', $egsConfig->vatNumber)) {
            $this->error("[FAIL] [{$egs}] ZATCA_VAT_NUMBER must be 15 digits starting and ending with 3.");
            $healthy = false;
        }

        if ($egsConfig->commercialRegistrationNumber && ! preg_match('/^\d{10}$/', $egsConfig->commercialRegistrationNumber)) {
            $this->error("[FAIL] [{$egs}] ZATCA_CRN must be exactly 10 digits.");
            $healthy = false;
        }

        // Length is not cosmetic: ZATCA validates BuildingNumber as 4 digits
        // and PostalZone as 5, and rejects the invoice otherwise.
        foreach ([
            ['ZATCA_BUILDING_NUMBER', $egsConfig->buildingNumber, '/^\d{4}$/', '4 digits'],
            ['ZATCA_ADDITIONAL_NUMBER', $egsConfig->additionalNumber, '/^\d{4}$/', '4 digits'],
            ['ZATCA_POSTAL_CODE', $egsConfig->postalCode, '/^\d{5}$/', '5 digits'],
        ] as [$key, $value, $pattern, $expected]) {
            if ($value && ! preg_match($pattern, $value)) {
                $this->error("[FAIL] [{$egs}] {$key} must be exactly {$expected} — ZATCA rejects any other length.");
                $healthy = false;
            }
        }

        $missing = [];

        foreach ([
            'ZATCA_STREET' => $egsConfig->street,
            'ZATCA_DISTRICT' => $egsConfig->district,
            'ZATCA_BUILDING_NUMBER' => $egsConfig->buildingNumber,
            'ZATCA_CITY' => $egsConfig->city,
            'ZATCA_POSTAL_CODE' => $egsConfig->postalCode,
        ] as $key => $value) {
            if (! $value) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            $this->warn("[PENDING] [{$egs}] Structured national address incomplete (".implode(', ', $missing).') — falling back to ZATCA_LOCATION_ADDRESS.');
        } else {
            $this->info("[OK] [{$egs}] Company configuration and national address look well-formed.");
        }

        return $healthy;
    }
}

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

        if (! $this->checkEgsSet($registry)) {
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
     * One taxpayer, one identity: every EGS must carry the primary EGS's seller data,
     * or two documents of the same seller would name two different companies. Also
     * reports a B2B EGS identifier that collides with the primary (it is then ignored)
     * and B2B CSR fields that are not set yet.
     */
    private function checkEgsSet(\Zatca\State\EgsRegistry $registry): bool
    {
        $healthy = true;
        $primary = $registry->primary();
        $b2b = config('zatca.b2b.egs_identifier');

        if (is_string($b2b) && $b2b !== '' && $b2b === $primary->egsIdentifier) {
            $this->error("[FAIL] ZATCA_B2B_EGS_IDENTIFIER [{$b2b}] equals the primary EGS identifier; the B2B EGS is ignored. Give it its own identifier.");
            $healthy = false;
        }

        $identity = fn (EgsConfig $c) => [
            'VAT number' => $c->vatNumber,
            'organization identifier' => $c->organizationIdentifier,
            'English legal name' => $c->organizationName,
            'Arabic legal name' => $c->companyNameAr,
            'CR number' => $c->commercialRegistrationNumber,
            'street' => $c->street,
            'district' => $c->district,
            'building number' => $c->buildingNumber,
            'additional number' => $c->additionalNumber,
            'city' => $c->city,
            'postal code' => $c->postalCode,
            'VAT percentage' => $c->vatPercentage,
        ];

        foreach ($registry->identifiers() as $egs) {
            if ($egs === $primary->egsIdentifier) {
                continue;
            }
            $config = $registry->for($egs);
            $drift = array_keys(array_diff_assoc(array_map('strval', $identity($config)), array_map('strval', $identity($primary))));

            if ($drift !== []) {
                $this->error("[FAIL] [{$egs}] seller data differs from the primary EGS [{$primary->egsIdentifier}]: ".implode(', ', $drift).'.');
                $healthy = false;
            }

            if ($egs === $b2b) {
                $raw = config("zatca.egs.{$egs}");
                $unset = array_keys(array_filter([
                    'ZATCA_B2B_CSR_COMMON_NAME' => $raw['common_name'] ?? null,
                    'ZATCA_B2B_CSR_SERIAL_NUMBER' => $raw['serial_number'] ?? null,
                    'ZATCA_B2B_INVOICE_TYPE' => $raw['invoice_type'] ?? null,
                ], fn ($v) => $v === null || $v === ''));

                if ($unset !== []) {
                    $this->warn("[PENDING] [{$egs}] B2B EGS not ready for onboarding — not set: ".implode(', ', $unset).'.');
                }

                if ($config->environment !== $primary->environment) {
                    $this->warn("[WARN] [{$egs}] environment {$config->environment->value} differs from the primary EGS ({$primary->environment->value}).");
                }
            }
        }

        return $healthy;
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

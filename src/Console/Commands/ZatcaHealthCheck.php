<?php

namespace Asas\Zatca\Console\Commands;

use Asas\Zatca\Enums\ZatcaCredentialType;
use Asas\Zatca\Exceptions\ZatcaException;
use Asas\Zatca\Services\SDK\ZatcaSdkFingerprint;
use Asas\Zatca\Services\SDK\ZatcaSdkRuntimeManager;
use Asas\Zatca\Services\Security\ZatcaCredentialStore;
use Asas\Zatca\State\EgsRegistry;
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
}

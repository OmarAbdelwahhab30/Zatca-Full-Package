<?php

namespace Asas\Zatca\Console\Commands;

use Asas\Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;
use Illuminate\Console\Command;

class GenerateCredentials extends Command
{
    protected $signature = 'zatca:generate-credentials {--egs=default : EGS identifier from config(zatca.egs)}';

    protected $description = 'Generate the private key and CSR for an EGS via the ZATCA SDK (no API call, no manual OpenSSL).';

    public function handle(ComplianceOnboardingOrchestrator $orchestrator): int
    {
        $egs = $this->option('egs');

        $this->info("Generating private key + CSR for EGS [{$egs}] via the ZATCA SDK...");

        $result = $orchestrator->generateCredentials($egs);

        $this->info('Private key and CSR generated and stored (encrypted) successfully.');
        $this->line('SDK fingerprint: '.$result['sdkFingerprint']);
        $this->line('Set this as ZATCA_SDK_FINGERPRINT in .env so `zatca:health-check` can detect unexpected SDK changes.');
        $this->newLine();
        $this->line('CSR (submit via `zatca:onboard compliance --otp=XXXXXX`):');
        $this->line($result['csr']);

        return self::SUCCESS;
    }
}

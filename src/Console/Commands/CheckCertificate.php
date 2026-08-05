<?php

namespace Asas\Zatca\Console\Commands;

use Asas\Zatca\Enums\ZatcaCredentialType;
use Asas\Zatca\Services\Certificate\CertificateLifecycleManager;
use Asas\Zatca\State\EgsRegistry;
use Illuminate\Console\Command;

class CheckCertificate extends Command
{
    protected $signature = 'zatca:certificate:check {--egs= : Specific EGS identifier, defaults to all configured EGS}';

    protected $description = 'Evaluate certificate expiry state (Valid/ExpiringSoon/Expired) for one or all EGS. Intended for the scheduler.';

    public function handle(CertificateLifecycleManager $manager, EgsRegistry $registry): int
    {
        $egsIdentifiers = $this->option('egs') ? [$this->option('egs')] : $registry->identifiers();

        foreach ($egsIdentifiers as $egs) {
            foreach ([ZatcaCredentialType::Compliance, ZatcaCredentialType::Production] as $type) {
                $state = $manager->check($egs, $type);
                $this->line("[{$egs}] {$type->value}: {$state->value}");
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace Zatca\Console\Commands;

use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Services\Certificate\CertificateLifecycleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class RenewCertificate extends Command
{
    protected $signature = 'zatca:certificate:renew {--egs=default : EGS identifier}
        {--type=production : compliance|production}';

    protected $description = 'Renew a certificate that is ExpiringSoon/Expired/Failed and resume any invoices left Pending Reporting.';

    public function handle(CertificateLifecycleManager $manager): int
    {
        $egs = $this->option('egs');
        $type = ZatcaCredentialType::from($this->option('type'));

        if (! Config::get('zatca.certificate.auto_renewal_enabled', false)) {
            $this->warn('Automatic renewal is disabled (ZATCA_AUTO_RENEWAL_ENABLED=false). The renewal contract has not been verified.');
            $this->warn('Invoices will remain paused/pending_reporting. See CertificateLifecycleManager::renew() and the activation guide before enabling it.');
        }

        $this->info("Renewing {$type->value} certificate for EGS [{$egs}]...");
        $state = $manager->renew($egs, $type);

        $this->line('Resulting state: '.$state->value);

        return $state === ZatcaCertificateState::Valid ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace Zatca\Console\Commands;

use Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;
use Illuminate\Console\Command;

/**
 * Single onboarding command covering all three ZATCA onboarding API calls,
 * matching the zatca:inspect {resource} pattern (plan §2.1 — reduces
 * artisan surface vs. one command per step).
 */
class Onboard extends Command
{
    protected $signature = 'zatca:onboard {step : compliance|test-invoice|test-credit-note|test-debit-note|production}
        {--egs=default : EGS identifier from config(zatca.egs)}
        {--otp= : OTP from the Fatoora portal, required for the compliance step}';

    protected $description = 'Run one step of ZATCA onboarding: compliance (API 1), test-invoice/test-credit-note/test-debit-note (API 2, once per required document type), or production (API 3).';

    public function handle(ComplianceOnboardingOrchestrator $orchestrator): int
    {
        $egs = $this->option('egs');
        $step = $this->argument('step');

        try {
            return match ($step) {
                'compliance' => $this->handleCompliance($orchestrator, $egs),
                'test-invoice' => $this->handleTestDocument($egs, 'invoice', fn () => $orchestrator->testInvoice($egs)),
                'test-credit-note' => $this->handleTestDocument($egs, 'credit note', fn () => $orchestrator->testCreditNote($egs)),
                'test-debit-note' => $this->handleTestDocument($egs, 'debit note', fn () => $orchestrator->testDebitNote($egs)),
                'production' => $this->handleProduction($orchestrator, $egs),
                default => throw new \InvalidArgumentException("Unknown step [{$step}]. Use compliance, test-invoice, test-credit-note, test-debit-note, or production."),
            };
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function handleCompliance(ComplianceOnboardingOrchestrator $orchestrator, string $egs): int
    {
        $otp = $this->option('otp');

        if (! $otp) {
            $this->error('--otp is required for the compliance step (get it from the Fatoora portal).');

            return self::FAILURE;
        }

        $this->info("Requesting Compliance CSID for EGS [{$egs}]...");
        $result = $orchestrator->compliance($egs, $otp);

        $this->info('Compliance Token, Secret, Certificate and Request ID saved.');
        $this->line('Request ID: '.$result['requestId']);

        return self::SUCCESS;
    }

    /** @param \Closure(): array{status:string, warnings:array<string>, errors:array<string>} $call */
    private function handleTestDocument(string $egs, string $label, \Closure $call): int
    {
        $this->info("Generating, signing, validating and submitting the compliance test {$label} for EGS [{$egs}]...");
        $result = $call();

        $this->line('Status: '.strtoupper($result['status']));

        foreach ($result['warnings'] as $warning) {
            $this->warn('Warning: '.$warning);
        }

        foreach ($result['errors'] as $error) {
            $this->error('Error: '.$error);
        }

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }

    private function handleProduction(ComplianceOnboardingOrchestrator $orchestrator, string $egs): int
    {
        $this->info("Requesting Production CSID for EGS [{$egs}]...");
        $orchestrator->production($egs);

        $this->info('Production Token, Secret and Certificate saved. Reporting is now live for this EGS.');

        return self::SUCCESS;
    }
}

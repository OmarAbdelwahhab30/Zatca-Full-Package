<?php

namespace Zatca\Dashboard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Throwable;
use Zatca\Models\ZatcaComplianceDocument;
use Zatca\Services\Diagnostics\ZatcaInspector;
use Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;
use Zatca\State\EgsRegistry;

class OnboardingController extends Controller
{
    private const STEPS = ['generate', 'compliance', 'test-invoice', 'test-credit-note', 'test-debit-note', 'production'];

    public function show(ZatcaInspector $inspector, EgsRegistry $registry)
    {
        $devices = [];
        foreach ($registry->identifiers() as $egs) {
            $devices[] = [
                'egs' => $egs,
                'config' => $registry->for($egs),
                'credentials' => $inspector->credentials($egs),
                'compliance_docs' => ZatcaComplianceDocument::where('egs_identifier', $egs)->orderBy('id')
                    ->get(['id', 'document_type', 'reporting_status', 'zatca_response', 'created_at']),
            ];
        }

        return view('zatca-dashboard::onboarding', ['devices' => $devices, 'environment' => $registry->primary()->environment->value]);
    }

    public function run(Request $request, string $egs, string $step, ComplianceOnboardingOrchestrator $onboarding, EgsRegistry $registry)
    {
        abort_unless(in_array($step, self::STEPS, true) && in_array($egs, $registry->identifiers(), true), 404);

        try {
            @set_time_limit(0);
            $result = match ($step) {
                'generate' => $onboarding->generateCredentials($egs),
                'compliance' => $onboarding->compliance($egs, (string) $request->validate(['otp' => 'required|string'])['otp']),
                'test-invoice' => $onboarding->testInvoice($egs),
                'test-credit-note' => $onboarding->testCreditNote($egs),
                'test-debit-note' => $onboarding->testDebitNote($egs),
                'production' => $onboarding->production($egs),
            };

            // Only status-like text is shown; keys, tokens and the CSR body never reach the page.
            $status = isset($result['status']) ? strtoupper((string) $result['status']) : null;
            $problems = $this->flat($result['errors'] ?? []);
            $message = $status ? "Status: {$status}".($problems ? " — {$problems}" : '') : 'Completed.';

            return back()->with($status === 'ERROR' ? 'error' : 'ok', "[$egs] $step: $message");
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', "[$egs] $step failed: ".$e->getMessage());
        }
    }

    private function flat(array $items): string
    {
        return collect($items)->map(fn ($i) => is_scalar($i) ? (string) $i : json_encode($i))->implode('; ');
    }
}

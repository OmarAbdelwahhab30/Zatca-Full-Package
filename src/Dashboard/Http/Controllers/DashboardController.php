<?php

namespace Zatca\Dashboard\Http\Controllers;

use Illuminate\Routing\Controller;
use Zatca\Dashboard\Demo\DemoCustomer;
use Zatca\Dashboard\Demo\DemoOrder;
use Zatca\Dashboard\Demo\DemoProduct;
use Zatca\Dashboard\Demo\ScenarioRunner;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaDebitNote;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Diagnostics\ZatcaInspector;
use Zatca\State\EgsRegistry;
use Zatca\Support\B2bReadiness;

class DashboardController extends Controller
{
    public function index(ZatcaInspector $inspector, EgsRegistry $registry)
    {
        $environment = $registry->primary()->environment->value;

        $success = collect(ZatcaInvoiceStatus::cases())->filter->isTerminalSuccess()->map->value->all();
        $failed = ['manual_review', 'clearance_manual_review', 'clearance_rejected', 'failed'];

        $base = fn () => ZatcaInvoice::where('environment', $environment);

        $egsRows = [];
        foreach ($registry->identifiers() as $egs) {
            $creds = collect($inspector->credentials($egs));
            $production = $creds->firstWhere('credential_type', 'production');
            $egsRows[] = [
                'egs' => $egs,
                'compliance' => $creds->firstWhere('credential_type', 'compliance'),
                'production' => $production,
                'state' => $inspector->state($egs),
                'onboarded' => $production && $production['has_certificate'] && $production['has_token'],
            ];
        }

        $demoReady = \Illuminate\Support\Facades\Schema::hasTable('zatca_demo_orders');

        return view('zatca-dashboard::overview', [
            'environment' => $environment,
            'egsRows' => $egsRows,
            'b2bReady' => app(B2bReadiness::class)->ready(),
            'total' => $base()->count(),
            'successful' => $base()->whereIn('status', $success)->count(),
            'failed' => $base()->whereIn('status', $failed)->count(),
            'pending' => $base()->whereNotIn('status', array_merge($success, $failed))->count(),
            'creditNotes' => ZatcaCreditNote::count(),
            'debitNotes' => ZatcaDebitNote::count(),
            'counts' => $demoReady
                ? ['customers' => DemoCustomer::count(), 'products' => DemoProduct::count(), 'orders' => DemoOrder::count()]
                : ['customers' => 0, 'products' => 0, 'orders' => 0],
            'demoReady' => $demoReady,
            'scenarios' => ScenarioRunner::SCENARIOS,
        ]);
    }
}

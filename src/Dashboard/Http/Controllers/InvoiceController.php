<?php

namespace Zatca\Dashboard\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Zatca\Dashboard\Demo\ScenarioRunner;
use Zatca\Models\ZatcaInvoice;

class InvoiceController extends Controller
{
    public function index()
    {
        return view('zatca-dashboard::invoices', [
            'invoices' => ZatcaInvoice::with(['creditNote', 'debitNote'])->latest('id')->paginate(25),
            'scenarios' => ScenarioRunner::SCENARIOS,
        ]);
    }

    public function show(ZatcaInvoice $invoice)
    {
        $invoice->load(['submissionAttempts', 'creditNote', 'debitNote']);

        return view('zatca-dashboard::invoice', ['invoice' => $invoice]);
    }

    public function run(string $scenario, ScenarioRunner $runner)
    {
        abort_unless(isset(ScenarioRunner::SCENARIOS[$scenario]), 404);

        $label = ScenarioRunner::SCENARIOS[$scenario][0];

        if (! Schema::hasTable('zatca_demo_orders')) {
            return redirect()->route('zatca.dashboard.invoices.index')
                ->with('error', 'The demo tables are missing. Run `php artisan migrate` with ZATCA_DASHBOARD_ENABLED=true.');
        }

        try {
            $doc = $runner->run($scenario);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('zatca.dashboard.invoices.index')->with('error', "$label failed: ".$e->getMessage());
        }

        return redirect()->route('zatca.dashboard.invoices.show', $doc)
            ->with($doc->status->isTerminalSuccess() ? 'ok' : 'error', "$label → status: {$doc->status->value}");
    }

    /** XML download: signed (decoded), cleared (B2B, ZATCA's copy) or unsigned. */
    public function xml(ZatcaInvoice $invoice, string $variant)
    {
        $xml = match ($variant) {
            'signed' => $invoice->signed_xml_base64 ? base64_decode($invoice->signed_xml_base64) : null,
            'cleared' => $invoice->cleared_xml_base64 ? base64_decode($invoice->cleared_xml_base64) : null,
            'unsigned' => $invoice->unsigned_xml,
            default => abort(404),
        };
        abort_if(! $xml, 404, 'This XML is not available for the document.');

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.$invoice->source_reference.'-'.$variant.'.xml"',
        ]);
    }
}

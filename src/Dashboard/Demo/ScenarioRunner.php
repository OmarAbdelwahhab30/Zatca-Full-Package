<?php

namespace Zatca\Dashboard\Demo;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Jobs\GenerateZatcaInvoiceJob;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteEligibilityPolicy;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\CreditNote\CreditNoteLedger;
use Zatca\Services\DebitNote\DebitNoteEligibilityPolicy;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\State\EgsRegistry;
use Zatca\Support\B2bReadiness;

/**
 * Runs the six ZATCA scenarios of the built-in dashboard through the package's public API
 * only: GenerateZatcaInvoiceJob, CreditNoteIssuer and DebitNoteIssuer.
 */
class ScenarioRunner
{
    public const SCENARIOS = [
        'b2c-invoice' => ['B2C Simplified Invoice', 'b2c', 'invoice'],
        'b2c-credit' => ['B2C Credit Note', 'b2c', 'credit'],
        'b2c-debit' => ['B2C Debit Note', 'b2c', 'debit'],
        'b2b-invoice' => ['B2B Standard Invoice', 'b2b', 'invoice'],
        'b2b-credit' => ['B2B Credit Note', 'b2b', 'credit'],
        'b2b-debit' => ['B2B Debit Note', 'b2b', 'debit'],
    ];

    /** Runs a scenario end-to-end and returns the resulting technical ZATCA document. */
    public function run(string $scenario): ZatcaInvoice
    {
        [, $family, $kind] = self::SCENARIOS[$scenario] ?? throw new RuntimeException("Unknown scenario [{$scenario}].");

        DemoSeeder::seed();

        return match ($kind) {
            'invoice' => $this->issueInvoice($family),
            'credit' => $this->issueCredit($family),
            'debit' => $this->issueDebit($family),
        };
    }

    public function egsFor(string $family): string
    {
        return $family === 'b2b' ? config('zatca.b2b.egs_identifier') : config('zatca.primary_egs');
    }

    public function issueInvoice(string $family): ZatcaInvoice
    {
        $egs = $this->egsFor($family);

        if ($family === 'b2b' && ! app(B2bReadiness::class)->ready()) {
            throw new RuntimeException('Company (B2B) invoicing is not ready: onboard the B2B device and set ZATCA_B2B_ENABLED=true.');
        }

        $order = $this->createOrder($family);

        dispatch(GenerateZatcaInvoiceJob::forModel($order, $egs)->afterCommit());
        $this->drainQueue();

        return $this->findForOrder($order, $egs)
            ?? throw new RuntimeException('No ZATCA document was created for demo order '.$order->id.'. See storage/logs/laravel.log and the failed_jobs table.');
    }

    public function issueCredit(string $family): ZatcaInvoice
    {
        $original = $this->eligibleOriginal($family, 'credit') ?? $this->issueAcceptedOriginal($family);

        $ledger = app(CreditNoteLedger::class)->forOriginal($original);
        $lineIndex = $ledger->availableIndexes[0] ?? throw new RuntimeException('The original invoice has no creditable lines left.');

        $note = app(CreditNoteIssuer::class)->issue(
            original: $original,
            type: ZatcaCreditNoteType::Partial,
            lineIndexes: [$lineIndex],
            reason: 'Customer returned the item on line '.($lineIndex + 1),
            idempotencyKey: (string) Str::uuid(),
        );

        $this->drainQueue();

        return $note->refresh()->latestZatcaInvoice
            ?? throw new RuntimeException('Credit note '.$note->credit_note_number.' has no ZATCA document yet. Last error: '.($note->last_error ?? 'n/a'));
    }

    public function issueDebit(string $family): ZatcaInvoice
    {
        $original = $this->eligibleOriginal($family, 'debit') ?? $this->issueAcceptedOriginal($family);

        // B2B demo orders are priced VAT-exclusive, B2C VAT-inclusive (see DemoInvoiceSource).
        $isB2b = $family === 'b2b';

        $note = app(DebitNoteIssuer::class)->issue(
            original: $original,
            reasonType: ZatcaDebitNoteReason::AdditionalItem,
            lines: [new DebitNoteLineInput('Delivery charge', 1, $isB2b ? '20.00' : '23.00')],
            reason: 'Delivery charge was omitted from the original invoice',
            idempotencyKey: (string) Str::uuid(),
            priceMode: $isB2b ? VatPriceMode::Exclusive : VatPriceMode::Inclusive,
        );

        $this->drainQueue();

        return $note->refresh()->latestZatcaInvoice
            ?? throw new RuntimeException('Debit note '.$note->debit_note_number.' has no ZATCA document yet. Last error: '.($note->last_error ?? 'n/a'));
    }

    /** An already-accepted demo invoice of this family that can still take a note, or null. */
    private function eligibleOriginal(string $family, string $kind): ?ZatcaInvoice
    {
        $egs = $this->egsFor($family);
        $env = app(EgsRegistry::class)->for($egs)->environment->value;
        $type = $family === 'b2b' ? ZatcaComplianceDocumentType::StandardInvoice : ZatcaComplianceDocumentType::Invoice;

        $candidates = ZatcaInvoice::query()
            ->where('egs_identifier', $egs)->where('environment', $env)
            ->where('document_type', $type->value)
            ->where('source_reference', 'like', 'DEMO-%')
            ->whereIn('status', ['reported', 'reported_with_warnings', 'cleared', 'cleared_with_warnings'])
            ->latest('id')->limit(15)->get();

        foreach ($candidates as $candidate) {
            $verdict = $kind === 'credit'
                ? app(CreditNoteEligibilityPolicy::class)->verdict($candidate)
                : app(DebitNoteEligibilityPolicy::class)->verdict($candidate);

            if ($verdict->eligible) {
                return $candidate;
            }
        }

        return null;
    }

    private function issueAcceptedOriginal(string $family): ZatcaInvoice
    {
        $invoice = $this->issueInvoice($family);

        if (! $invoice->status->isTerminalSuccess()) {
            throw new RuntimeException("The original {$family} invoice was not accepted by ZATCA (status: {$invoice->status->value}), so a note cannot be issued against it. Error: ".($invoice->last_error ?? 'n/a'));
        }

        return $invoice;
    }

    public function findForOrder(DemoOrder $order, string $egs): ?ZatcaInvoice
    {
        $env = app(EgsRegistry::class)->for($egs)->environment->value;

        return ZatcaInvoice::where('egs_identifier', $egs)
            ->where('environment', $env)
            ->where('source_reference', 'DEMO-'.$order->id)
            ->first()?->authoritative();
    }

    public function createOrder(string $family): DemoOrder
    {
        $isB2b = $family === 'b2b';
        $customer = DemoCustomer::where('type', $isB2b ? 'company' : 'individual')->inRandomOrder()->firstOrFail();

        $order = DemoOrder::create([
            'customer_id' => $customer->id,
            'type' => $family,
            'paid_at' => now(),
            'supplied_at' => $isB2b ? now()->toDateString() : null,
        ]);

        foreach (DemoProduct::inRandomOrder()->limit(3)->get() as $product) {
            $net = (float) $product->price_excl_vat;
            $order->items()->create([
                'name' => $product->name,
                'quantity' => random_int(1, 3),
                // B2C prices are VAT-inclusive, B2B prices VAT-exclusive.
                'price' => $isB2b ? $net : round($net * (1 + $product->vat_rate / 100), 2),
                'vat_rate' => null,
            ]);
        }

        return $order->load('items', 'customer');
    }

    /** Runs the package's queue until empty, in-process, so the demo needs no worker. */
    public function drainQueue(): void
    {
        @set_time_limit(0);

        Artisan::call('queue:work', [
            'connection' => config('zatca.queue.connection'),
            '--queue' => config('zatca.queue.queue').',default',
            '--stop-when-empty' => true,
            '--tries' => 5,
            '--sleep' => 0,
        ]);
    }
}

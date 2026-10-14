<?php

namespace Zatca\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\ZatcaInvoiceDiscount;
use Zatca\DTOs\ZatcaInvoiceInput;
use Zatca\DTOs\ZatcaInvoiceItem;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * VAT calculated from prices through the whole document lifecycle: generation, the
 * frozen snapshot, regeneration, credit notes and debit notes — and what changing
 * ZATCA_VAT_PERCENTAGE / ZATCA_VAT_MODE afterwards does (nothing, to anything signed).
 */
class VatCalculationLifecycleTest extends TestCase
{
    use BuildsProductionInvoices;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([
            \Zatca\Jobs\GenerateZatcaCreditNoteJob::class,
            \Zatca\Jobs\GenerateZatcaDebitNoteJob::class,
            \Zatca\Jobs\ReportZatcaInvoiceJob::class,
        ]);
        $this->seedFakeProductionCredentials();
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());
    }

    public function test_an_invoice_is_calculated_from_prices_and_its_values_are_frozen(): void
    {
        $invoice = $this->generate(9701, [new ZatcaInvoiceItem('Bulk item', 10, '100.00')]);

        $this->assertSame(['869.57', '130.43', '1000.00'], [$invoice->total_excl_vat, $invoice->total_vat, $invoice->total_incl_vat]);
        $this->assertStringContainsString('<cbc:PriceAmount currencyID="SAR">86.957</cbc:PriceAmount>', $invoice->unsigned_xml);

        $line = $invoice->invoice_data_snapshot['lines'][0];
        $this->assertSame(86.957, $line['unit_price']);
        $this->assertSame('100.00', $line['supplied_price']);
        $this->assertSame('inclusive', $line['price_mode']);
        $this->assertSame(869.57, $line['line_total_excl_vat']);
        $this->assertSame(130.43, $line['vat_amount']);
    }

    public function test_a_per_invoice_exclusive_price_mode(): void
    {
        $invoice = $this->generate(9702, [new ZatcaInvoiceItem('Office chair', 1, 400)], priceMode: VatPriceMode::Exclusive);

        $this->assertSame(['400.00', '60.00', '460.00'], [$invoice->total_excl_vat, $invoice->total_vat, $invoice->total_incl_vat]);
        $this->assertSame('exclusive', $invoice->invoice_data_snapshot['lines'][0]['price_mode']);
    }

    public function test_the_configured_rate_and_mode_apply_to_new_invoices(): void
    {
        Config::set('zatca.vat.percentage', '5');
        Config::set('zatca.vat.mode', 'exclusive');

        $invoice = $this->generate(9703, [new ZatcaInvoiceItem('Item', 2, 50)]);

        $this->assertSame(['100.00', '5.00', '105.00'], [$invoice->total_excl_vat, $invoice->total_vat, $invoice->total_incl_vat]);
        $this->assertEquals(5.0, $invoice->invoice_data_snapshot['lines'][0]['vat_rate']);
    }

    public function test_regeneration_after_a_config_change_rebuilds_exactly_the_same_amounts(): void
    {
        $original = $this->generate(9704, [new ZatcaInvoiceItem('Bulk item', 10, '100.00'), new ZatcaInvoiceItem('Reduced', 1, 105, vatRate: 5)]);
        $this->markRejected($original);

        Config::set('zatca.vat.percentage', '10');
        Config::set('zatca.vat.mode', 'exclusive');

        $replacement = app(ProductionInvoiceOrchestrator::class)->regenerate($original->fresh());

        $before = $original->fresh()->invoice_data_snapshot;
        $after = $replacement->fresh()->invoice_data_snapshot;

        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame($before['lines'], $after['lines']);
        $this->assertSame([$before['total_excl_vat'], $before['total_vat'], $before['total_incl_vat']], [$after['total_excl_vat'], $after['total_vat'], $after['total_incl_vat']]);
        // Stored as JSON, so whole numbers come back as integers: compare by value.
        $this->assertEquals([969.57, 135.43, 1105.00], [$after['total_excl_vat'], $after['total_vat'], $after['total_incl_vat']]);

        // And the snapshot restores to the same calculated DTO, whatever the config says.
        $restored = InvoiceDataSnapshot::fromArray($after);
        $this->assertSame(86.957, $restored->lines[0]->netUnitPrice);
        $this->assertSame(VatPriceMode::Inclusive, $restored->lines[0]->priceMode);
        $this->assertEquals($after, InvoiceDataSnapshot::toArray($restored));
    }

    public function test_a_credit_note_keeps_the_original_rates_after_a_config_change(): void
    {
        $original = $this->reported(9705, [new ZatcaInvoiceItem('Standard item', 1, 115), new ZatcaInvoiceItem('Reduced item', 1, 105, vatRate: 5)]);

        Config::set('zatca.vat.percentage', '10');
        Config::set('zatca.vat.mode', 'exclusive');

        $creditNote = app(CreditNoteIssuer::class)->issue($original, ZatcaCreditNoteType::Full, [], 'Cancelled order', (string) Str::uuid());
        $lines = $creditNote->fresh()->document_snapshot['lines'];

        $this->assertEquals([15.0, 5.0], array_column($lines, 'vat_rate'));
        $this->assertEquals([100.0, 100.0], array_column($lines, 'line_total_excl_vat'));
        $this->assertEquals([15.0, 5.0], array_column($lines, 'vat_amount'));
        $this->assertSame(['200.00', '20.00', '220.00'], [$creditNote->total_excl_vat, $creditNote->total_vat, $creditNote->total_incl_vat]);
    }

    /**
     * The amount is split at the item's own frozen rate (15%) and read in the mode the item
     * was invoiced in (inclusive) — not the 10% exclusive configured since.
     */
    public function test_a_price_adjustment_splits_at_the_frozen_rate_and_mode(): void
    {
        $original = $this->reported(9706, [new ZatcaInvoiceItem('Course A', 1, 700)]);

        Config::set('zatca.vat.percentage', '10');
        Config::set('zatca.vat.mode', 'exclusive');

        $creditNote = app(CreditNoteIssuer::class)->issuePriceAdjustment($original, 'line:0', 23, 'Price correction', 'Overcharged', (string) Str::uuid());
        $line = $creditNote->fresh()->document_snapshot['lines'][0];

        $this->assertEquals([15.0, 20.0, 3.0, 20.0], [$line['vat_rate'], $line['line_total_excl_vat'], $line['vat_amount'], $line['unit_price']]);
        $this->assertSame('23.00', $creditNote->total_incl_vat);
    }

    public function test_a_debit_note_line_keeps_the_adjusted_items_rate_and_a_new_item_uses_the_current_one(): void
    {
        $original = $this->reported(9707, [new ZatcaInvoiceItem('Course A', 1, 115)]);

        Config::set('zatca.vat.percentage', '5');
        Config::set('zatca.vat.mode', 'exclusive');

        $debitNote = app(DebitNoteIssuer::class)->issue(
            $original,
            ZatcaDebitNoteReason::Other,
            [
                new DebitNoteLineInput('Course A - price increase', 1, 10, originalLineIndex: 0),
                new DebitNoteLineInput('Extra material', 2, 50),
            ],
            'Price corrected and material added',
            (string) Str::uuid(),
        );

        $rows = $debitNote->lines()->orderBy('id')->get();

        // Adjusts Course A: its own 15%, priced in the current (exclusive) mode.
        $this->assertSame(['15.00', '10.00', '1.50', '11.50'], [$rows[0]->vat_rate, $rows[0]->line_total_excl_vat, $rows[0]->vat_amount, $rows[0]->line_total_incl_vat]);
        // A new item: the configured 5%.
        $this->assertSame(['5.00', '100.00', '5.00', '50.000000'], [$rows[1]->vat_rate, $rows[1]->line_total_excl_vat, $rows[1]->vat_amount, $rows[1]->unit_price]);
        $this->assertSame(['110.00', '6.50', '116.50'], [$debitNote->total_excl_vat, $debitNote->total_vat, $debitNote->total_incl_vat]);
    }

    public function test_a_vat_only_debit_note_line(): void
    {
        $original = $this->reported(9708, [new ZatcaInvoiceItem('Course A', 1, 115)]);

        $debitNote = app(DebitNoteIssuer::class)->issue(
            $original,
            ZatcaDebitNoteReason::VatAdjustment,
            [DebitNoteLineInput::vatAdjustment('VAT under-charged', '2.50', originalLineIndex: 0)],
            'VAT correction',
            (string) Str::uuid(),
        );

        $this->assertSame(['0.00', '2.50', '2.50'], [$debitNote->total_excl_vat, $debitNote->total_vat, $debitNote->total_incl_vat]);
        $this->assertSame('15.00', $debitNote->lines()->first()->vat_rate);
    }

    public function test_an_inclusive_discount_through_generation(): void
    {
        $invoice = $this->generate(9709, [new ZatcaInvoiceItem('Course A', 1, 120), new ZatcaInvoiceItem('Course B', 1, 200)], [
            new ZatcaInvoiceDiscount('Bundle discount', 32, poolKey: 'bundle:1', lineIndexes: [0, 1]),
        ]);

        $this->assertSame(['250.43', '37.57', '288.00'], [$invoice->total_excl_vat, $invoice->total_vat, $invoice->total_incl_vat]);
        $this->assertSame('bundle:1', $invoice->invoice_data_snapshot['allowance_charges'][0]['pool_key']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function generate(int $sourceId, array $items, array $discounts = [], ?VatPriceMode $priceMode = null): ZatcaInvoice
    {
        $source = new class($items, $discounts, $priceMode) implements InvoiceSourceContract
        {
            public function __construct(private readonly array $items, private readonly array $discounts, private readonly ?VatPriceMode $priceMode) {}

            public function toInvoiceInput(mixed $source, string $egsIdentifier): ZatcaInvoiceInput
            {
                return new ZatcaInvoiceInput(
                    egsIdentifier: $egsIdentifier,
                    sourceReference: $this->sourceReference($source),
                    buyerName: 'Buyer',
                    buyerVatNumber: null,
                    issueDateTime: new DateTimeImmutable('2026-10-14T10:00:00+03:00'),
                    currency: 'SAR',
                    items: $this->items,
                    discounts: $this->discounts,
                    priceMode: $this->priceMode,
                );
            }

            public function sourceReference(mixed $source): string
            {
                return 'order:'.$source->id;
            }
        };

        return app(ProductionInvoiceOrchestrator::class)->generate($source, (object) ['id' => $sourceId], 'default')->fresh();
    }

    private function reported(int $sourceId, array $items): ZatcaInvoice
    {
        $invoice = $this->generate($sourceId, $items);
        $invoice->update(['status' => ZatcaInvoiceStatus::Reported, 'reported_at' => now()]);

        return $invoice->fresh();
    }
}

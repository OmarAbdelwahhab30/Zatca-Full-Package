<?php

namespace Zatca\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Exceptions\CrossEgsDuplicateException;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\State\ProductionInvoiceCounterManager;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * Blueprint P19: one sale, one tax document, whichever EGS signs it.
 */
class CrossEgsUniquenessTest extends TestCase
{
    use BuildsProductionInvoices;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('zatca.egs.b2b', Config::get('zatca.egs.default'));
        $this->seedFakeProductionCredentials('default');
        $this->seedFakeProductionCredentials('b2b');
        $this->app->instance(ZatcaSdkInterface::class, $this->scriptedSdk());
    }

    public function test_a_sale_documented_on_one_egs_is_refused_on_another_without_spending_an_icv(): void
    {
        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $first = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 501], 'default');

        try {
            $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 501], 'b2b');
            $this->fail('A second document for the same sale was created on another EGS.');
        } catch (CrossEgsDuplicateException $e) {
            $this->assertStringContainsString('[order:501] in [sandbox] already has a ZATCA document on EGS [default]', $e->getMessage());
        }

        $this->assertSame(1, ZatcaInvoice::query()->where('source_reference', 'order:501')->count());
        $this->assertSame(0, app(ProductionInvoiceCounterManager::class)->current('b2b', ZatcaEnvironment::Sandbox), 'No ICV consumed on the refusing EGS.');
        $this->assertSame($first->id, ZatcaInvoice::query()->sole()->id);
    }

    /** Same-EGS idempotency is unchanged: the existing document is returned. */
    public function test_the_same_egs_still_returns_its_existing_document(): void
    {
        $orchestrator = app(ProductionInvoiceOrchestrator::class);
        $first = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 502], 'default');
        $again = $orchestrator->generate($this->fakeInvoiceSource(), (object) ['id' => 502], 'default');

        $this->assertSame($first->id, $again->id);
    }

    /** The adjustment-note path is guarded too. */
    public function test_a_note_number_documented_on_another_egs_is_refused(): void
    {
        $this->row('CN-000001', 'default');
        $data = app(\Zatca\Services\Tax\InvoiceCalculator::class)->calculate($this->fakeInvoiceSource()->toInvoiceInput((object) ['id' => 0], 'b2b'));
        $note = new \Zatca\DTOs\ZatcaInvoiceData(
            egsIdentifier: 'b2b',
            sourceReference: 'CN-000001',
            buyerName: $data->buyerName,
            buyerVatNumber: null,
            issueDateTime: $data->issueDateTime,
            currency: 'SAR',
            lines: $data->lines,
            totalExclVat: $data->totalExclVat,
            totalVat: $data->totalVat,
            totalInclVat: $data->totalInclVat,
            documentType: \Zatca\Enums\ZatcaComplianceDocumentType::CreditNote,
            billingReferenceId: 'INV-1',
            adjustmentReason: 'test',
        );

        $this->expectException(CrossEgsDuplicateException::class);

        app(ProductionInvoiceOrchestrator::class)->generateCreditNote($note, 'b2b', 1);
    }

    /** The index is the final arbiter when the code check is bypassed (a race). */
    public function test_the_database_rejects_a_second_egs_row_for_the_same_sale(): void
    {
        $this->row('order:503', 'default');

        $this->expectException(QueryException::class);

        $this->row('order:503', 'b2b');
    }

    public function test_other_environments_and_replacement_suffixes_are_independent(): void
    {
        $this->row('order:504', 'default');
        $this->row('order:504', 'b2b', ZatcaEnvironment::Simulation);
        $this->row('order:504#r1', 'b2b');

        $this->assertSame(3, ZatcaInvoice::query()->count());
    }

    public function test_the_check_command_reports_ok_on_a_clean_table(): void
    {
        $this->row('order:505', 'default');

        $this->artisan('zatca:check-source-uniqueness')
            ->expectsOutputToContain('[OK] Every sale has at most one ZATCA document per environment.')
            ->assertSuccessful();
    }

    /** Pre-existing duplicates (impossible with one EGS) make the check fail and the migration refuse. */
    public function test_duplicates_fail_the_check_and_block_the_migration(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_28_000002_add_env_source_unique_to_zatca_invoices_table.php';
        $migration->down();
        $this->row('order:506', 'default');
        $this->row('order:506', 'b2b');

        $this->artisan('zatca:check-source-uniqueness')
            ->expectsOutputToContain('[FAIL] 1 sale(s) have documents on more than one EGS')
            ->assertFailed();

        try {
            $migration->up();
            $this->fail('The migration ran over duplicates.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('zatca:check-source-uniqueness', $e->getMessage());
        }

        ZatcaInvoice::query()->where('egs_identifier', 'b2b')->delete();
        $migration->up();
        $this->assertContains('zatca_inv_env_src_uq', array_column(DB::select("PRAGMA index_list('zatca_invoices')"), 'name'));
    }

    private function row(string $sourceReference, string $egs, ZatcaEnvironment $environment = ZatcaEnvironment::Sandbox): ZatcaInvoice
    {
        static $n = 0;
        $n++;

        return ZatcaInvoice::create([
            'egs_identifier' => $egs,
            'environment' => $environment->value,
            'source_reference' => $sourceReference,
            'uuid' => (string) Str::uuid(),
            'icv' => 1000 + $n,
            'invoice_hash' => 'H'.$n,
            'previous_invoice_hash' => 'P',
            'unsigned_xml' => '<Invoice/>',
            'signed_xml_base64' => base64_encode('<Invoice/>'),
            'qr_code' => 'QR',
            'total_excl_vat' => 100,
            'total_vat' => 15,
            'total_incl_vat' => 115,
            'currency' => 'SAR',
            'status' => ZatcaInvoiceStatus::Reported,
        ]);
    }
}

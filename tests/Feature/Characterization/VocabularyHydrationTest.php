<?php

namespace Zatca\Tests\Feature\Characterization;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Jobs\RetryZatcaInvoiceJob;
use Zatca\Models\ZatcaInvoice;
use Zatca\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Readers first (blueprint P16): a zatca_invoices row carrying any new document type
 * or status loads through the model casts, and the reporting sweeps leave it alone.
 *
 * @group characterization
 */
#[Group('characterization')]
class VocabularyHydrationTest extends TestCase
{
    use CreatesInvoiceRows;

    protected function tearDown(): void
    {
        ZatcaInvoice::query()->delete();

        parent::tearDown();
    }

    public function test_rows_with_every_new_value_hydrate(): void
    {
        $types = [ZatcaComplianceDocumentType::StandardInvoice, ZatcaComplianceDocumentType::StandardCreditNote, ZatcaComplianceDocumentType::StandardDebitNote];

        foreach (ZatcaInvoiceStatus::cases() as $i => $status) {
            if (! $status->isClearanceState()) {
                continue;
            }
            $type = $types[$i % 3];
            $row = $this->invoiceRow(ZatcaInvoiceStatus::Draft);
            // Raw write: nothing in the package may write these values yet.
            DB::table('zatca_invoices')->where('id', $row->id)->update(['status' => $status->value, 'document_type' => $type->value]);

            $loaded = ZatcaInvoice::query()->findOrFail($row->id);
            $this->assertSame($status, $loaded->status);
            $this->assertSame($type, $loaded->document_type);
        }
    }

    /** Clearance documents are never sent to Reporting by the sweep or by requeue. */
    public function test_report_pending_and_requeue_never_report_clearance_rows(): void
    {
        Bus::fake();
        foreach (ZatcaInvoiceStatus::cases() as $status) {
            if ($status->isClearanceState()) {
                $this->invoiceRow($status, ['document_type' => ZatcaComplianceDocumentType::StandardInvoice, 'submission_model' => 'clearance']);
            }
        }

        $this->artisan('zatca:report-pending')->assertSuccessful();
        $this->artisan('zatca:requeue', ['--status' => 'pending_clearance'])->assertSuccessful();
        $this->artisan('zatca:requeue', ['--status' => 'clearance_rejected'])->assertFailed();
        $this->artisan('zatca:requeue', ['--status' => 'manual_review'])->assertSuccessful();

        Bus::assertNotDispatched(RetryZatcaInvoiceJob::class);
        Bus::assertDispatched(\Zatca\Jobs\RetryZatcaClearanceJob::class);
    }
}

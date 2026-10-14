<?php

namespace Zatca\Tests\Feature\Characterization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Models\ZatcaInvoice;
use Zatca\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Blueprint P18 (M-P1): the B2B columns are additive and every existing row keeps its
 * B2C meaning.
 *
 * @group characterization
 */
#[Group('characterization')]
class B2bColumnsMigrationTest extends TestCase
{
    use CreatesInvoiceRows;

    private const COLUMNS = [
        'submission_model', 'clearance_status', 'cleared_xml_base64', 'cleared_invoice_hash', 'cleared_at',
        'submission_claimed_at', 'in_doubt_since', 'reissue_reason', 'reissued_by_type', 'reissued_by_id',
    ];

    protected function tearDown(): void
    {
        ZatcaInvoice::query()->delete();

        parent::tearDown();
    }

    public function test_columns_and_index_exist(): void
    {
        $columns = Schema::getColumnListing('zatca_invoices');
        foreach (self::COLUMNS as $column) {
            $this->assertContains($column, $columns);
        }

        $indexes = array_column(DB::select("PRAGMA index_list('zatca_invoices')"), 'name');
        $this->assertContains('zatca_inv_model_status_idx', $indexes);
    }

    /** A row written the B2C way (no new column mentioned) is a reporting document with nothing else set. */
    public function test_a_b2c_row_keeps_its_meaning(): void
    {
        $row = $this->invoiceRow(ZatcaInvoiceStatus::PendingReporting);

        $this->assertSame(ZatcaSubmissionModel::Reporting, $row->submission_model, 'Before refresh (attribute absent).');

        $raw = (array) DB::table('zatca_invoices')->where('id', $row->id)->first();
        $this->assertSame('reporting', $raw['submission_model']);
        foreach (array_diff(self::COLUMNS, ['submission_model']) as $column) {
            $this->assertNull($raw[$column], $column);
        }
        $this->assertSame(ZatcaSubmissionModel::Reporting, $row->fresh()->submission_model);
    }

    public function test_an_unsaved_model_reads_as_reporting(): void
    {
        $this->assertSame(ZatcaSubmissionModel::Reporting, (new ZatcaInvoice)->submission_model);
    }

    public function test_submission_model_round_trips_and_casts_dates(): void
    {
        $row = $this->invoiceRow(ZatcaInvoiceStatus::Draft, [
            'submission_model' => ZatcaSubmissionModel::Clearance,
            'cleared_at' => '2026-09-28 10:00:00',
            'in_doubt_since' => '2026-09-28 09:00:00',
        ])->fresh();

        $this->assertSame(ZatcaSubmissionModel::Clearance, $row->submission_model);
        $this->assertSame('2026-09-28 10:00:00', $row->cleared_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-28 09:00:00', $row->in_doubt_since->format('Y-m-d H:i:s'));

        $row->update(['submission_model' => 'reporting']);
        $this->assertSame(ZatcaSubmissionModel::Reporting, $row->fresh()->submission_model);
    }

    /** Readers fail closed on a value no release knows, like the other enum casts. */
    public function test_an_unknown_stored_value_throws(): void
    {
        $row = $this->invoiceRow(ZatcaInvoiceStatus::Draft);
        DB::table('zatca_invoices')->where('id', $row->id)->update(['submission_model' => 'teleport']);

        $this->expectException(\ValueError::class);

        $row->fresh()->submission_model;
    }

    public function test_down_refuses_once_a_clearance_row_exists(): void
    {
        $this->invoiceRow(ZatcaInvoiceStatus::Draft, ['submission_model' => 'clearance']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rollback floor');

        $this->migration()->down();
    }

    public function test_down_and_up_round_trip_without_clearance_rows(): void
    {
        $this->invoiceRow(ZatcaInvoiceStatus::Reported);

        $this->migration()->down();
        $this->assertNotContains('submission_model', Schema::getColumnListing('zatca_invoices'));
        $this->assertSame(1, ZatcaInvoice::query()->count(), 'Existing rows survive.');

        $this->migration()->up();
        $this->assertSame('reporting', DB::table('zatca_invoices')->value('submission_model'));
    }

    private function migration(): object
    {
        return require __DIR__.'/../../../database/migrations/2026_09_28_000001_add_b2b_columns_to_zatca_invoices_table.php';
    }
}

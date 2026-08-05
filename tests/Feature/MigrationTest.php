<?php

namespace Zatca\Tests\Feature;

use Zatca\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

/** Proves the package's migrations run cleanly on their own (no host schema assumed). */
class MigrationTest extends TestCase
{
    /** @dataProvider tableProvider */
    public function test_table_exists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table), "Expected migrated table [{$table}] to exist.");
    }

    public static function tableProvider(): array
    {
        return [
            ['zatca_egs_credentials'],
            ['zatca_egs_states'],
            ['zatca_compliance_documents'],
            ['zatca_invoices'],
            ['zatca_submission_attempts'],
        ];
    }

    public function test_zatca_invoices_signing_columns_are_nullable(): void
    {
        // Required for the Draft-row-before-signing flow (invoice_hash/signed_xml_base64/qr_code
        // are only populated once signing succeeds).
        $columns = Schema::getConnection()->getSchemaBuilder()->getColumnListing('zatca_invoices');

        $this->assertContains('invoice_hash', $columns);
        $this->assertContains('signed_xml_base64', $columns);
        $this->assertContains('qr_code', $columns);
        $this->assertContains('csr', Schema::getConnection()->getSchemaBuilder()->getColumnListing('zatca_egs_credentials'));
    }
}

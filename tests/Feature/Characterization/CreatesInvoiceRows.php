<?php

namespace Zatca\Tests\Feature\Characterization;

use Illuminate\Support\Str;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaInvoice;

/**
 * Inserts zatca_invoices rows in a chosen state.
 *
 * The characterization suites select and report rows by status; building each state
 * by driving the orchestrator would test the orchestrator again, not the selection.
 * Rows carry a stored signed document unless a test says otherwise.
 */
trait CreatesInvoiceRows
{
    private static int $rowSequence = 0;

    protected function invoiceRow(ZatcaInvoiceStatus $status, array $overrides = []): ZatcaInvoice
    {
        $n = ++self::$rowSequence;

        return ZatcaInvoice::create(array_merge([
            'egs_identifier' => 'default',
            'environment' => ZatcaEnvironment::Sandbox->value,
            'source_reference' => 'order:char-'.$n,
            'uuid' => (string) Str::uuid(),
            'icv' => $n,
            'invoice_hash' => 'HASH-'.$n,
            'previous_invoice_hash' => base64_encode(hash('sha256', '0')),
            'unsigned_xml' => '<Invoice/>',
            'signed_xml_base64' => base64_encode('<Invoice/>'),
            'qr_code' => 'QR',
            'total_excl_vat' => 100,
            'total_vat' => 15,
            'total_incl_vat' => 115,
            'currency' => 'SAR',
            'status' => $status,
        ], $overrides));
    }
}

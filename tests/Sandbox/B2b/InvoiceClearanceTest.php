<?php

namespace Zatca\Tests\Sandbox\B2b;

use PHPUnit\Framework\Attributes\Group;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Models\ZatcaInvoice;
use Zatca\Tests\Sandbox\SandboxTestCase;

/**
 * B2B invoices: a company order is generated on the B2B EGS by GenerateZatcaInvoiceJob and
 * cleared, and ZATCA's cleared copy is stored.
 *
 * @group zatca-sandbox
 */
#[Group('zatca-sandbox')]
class InvoiceClearanceTest extends SandboxTestCase
{
    public function test_company_invoices_are_cleared_in_their_own_chain_and_the_cleared_copy_is_stored(): void
    {
        $this->onboard(self::B2C_EGS);
        $this->onboard(self::B2B_EGS);

        $invoices = [
            $this->acceptedInvoice($this->order([['Office chair', 2, '460.00']], company: true), self::B2B_EGS),
            $this->acceptedInvoice($this->order([['Meeting table', 1, '2300.00'], ['Whiteboard', 3, '172.50']], company: true), self::B2B_EGS),
        ];

        foreach ($invoices as $invoice) {
            $this->assertSame(ZatcaComplianceDocumentType::StandardInvoice, $invoice->document_type);
            $this->assertSame(ZatcaSubmissionModel::Clearance, $invoice->submission_model);
            $this->assertSame('CLEARED', $invoice->clearance_status);
            $this->assertNotNull($invoice->cleared_at);
            $this->assertStringContainsString('Riyadh Office Supplies Co.', $invoice->unsigned_xml);
            $this->assertStringContainsString('<cbc:ActualDeliveryDate>', $invoice->unsigned_xml);

            // ZATCA's cleared copy: the same document (UUID), now carrying a QR code (G-06).
            $cleared = (string) base64_decode((string) $invoice->cleared_xml_base64, true);
            $this->assertNotSame('', $cleared, 'ZATCA returned no cleared invoice.');
            $this->assertStringContainsString('<cbc:UUID>'.$invoice->uuid.'</cbc:UUID>', $cleared);
            $this->assertStringContainsString('<cbc:ID>QR</cbc:ID>', $cleared);
        }

        $this->assertTotals(['800.00', '120.00', '920.00'], $invoices[0]);
        $this->assertTotals(['2450.00', '367.50', '2817.50'], $invoices[1]);

        // The B2B EGS has its own chain from ICV 1; the B2C EGS issued nothing.
        $this->assertSame(base64_encode(hash('sha256', '0')), $invoices[0]->previous_invoice_hash);
        $this->assertUnbrokenChain($invoices);
        $this->assertSame(0, ZatcaInvoice::query()->where('egs_identifier', self::B2C_EGS)->count());

        $this->assertOnlySentTo('/invoices/clearance/single', 2);
    }
}

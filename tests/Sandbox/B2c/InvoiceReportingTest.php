<?php

namespace Zatca\Tests\Sandbox\B2c;

use PHPUnit\Framework\Attributes\Group;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Tests\Sandbox\SandboxTestCase;

/**
 * B2C invoices: generated from a host order by GenerateZatcaInvoiceJob and reported.
 *
 * @group zatca-sandbox
 */
#[Group('zatca-sandbox')]
class InvoiceReportingTest extends SandboxTestCase
{
    public function test_invoices_are_reported_in_one_unbroken_icv_and_hash_chain(): void
    {
        $this->onboard(self::B2C_EGS);

        $invoices = [
            $this->acceptedInvoice($this->order([['Wireless headphones', 1, '230.00']]), self::B2C_EGS),
            $this->acceptedInvoice($this->order([['USB-C cable', 3, '34.50'], ['Phone case', 1, '57.50']]), self::B2C_EGS),
            $this->acceptedInvoice($this->order([['Screen protector', 2, '19.99']]), self::B2C_EGS),
        ];

        foreach ($invoices as $invoice) {
            $this->assertSame(ZatcaComplianceDocumentType::Invoice, $invoice->document_type);
            $this->assertSame(ZatcaSubmissionModel::Reporting, $invoice->submission_model);
            $this->assertNotEmpty($invoice->qr_code);
        }

        // Production numbering is its own chain, starting again at ICV 1 and the genesis PIH.
        $this->assertSame(base64_encode(hash('sha256', '0')), $invoices[0]->previous_invoice_hash);
        $this->assertUnbrokenChain($invoices);

        // VAT calculated by the package from VAT-inclusive prices.
        $this->assertTotals(['200.00', '30.00', '230.00'], $invoices[0]);
        $this->assertTotals(['140.00', '21.00', '161.00'], $invoices[1]);
        $this->assertTotals(['34.77', '5.21', '39.98'], $invoices[2]);

        $this->assertOnlySentTo('/invoices/reporting/single', 3);

        foreach ($this->callsTo('/invoices/reporting/single') as $call) {
            $this->assertSame('REPORTED', $call['body']['reportingStatus'] ?? null);
        }
    }
}

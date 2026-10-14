<?php

namespace Zatca\Tests\Sandbox\B2b;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Tests\Sandbox\SandboxTestCase;

/**
 * B2B credit notes against a cleared Standard invoice: themselves Standard, carrying the
 * original's buyer, and cleared.
 *
 * @group zatca-sandbox
 */
#[Group('zatca-sandbox')]
class CreditNoteTest extends SandboxTestCase
{
    public function test_a_full_credit_note_is_cleared_against_the_invoice(): void
    {
        $this->onboard(self::B2C_EGS);
        $this->onboard(self::B2B_EGS);
        $original = $this->acceptedInvoice($this->order([['Office chair', 1, '460.00'], ['Desk', 1, '1150.00']], company: true), self::B2B_EGS);

        $creditNote = app(CreditNoteIssuer::class)->issue($original, ZatcaCreditNoteType::Full, [], 'Order cancelled by the company', (string) Str::uuid());
        $note = $this->noteDocument('credit_note_id', $creditNote->id);

        $this->assertAccepted($note);
        $this->assertSame(ZatcaComplianceDocumentType::StandardCreditNote, $note->document_type);
        $this->assertSame(ZatcaSubmissionModel::Clearance, $note->submission_model);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">381</cbc:InvoiceTypeCode>', $note->unsigned_xml);
        $this->assertStringContainsString('Riyadh Office Supplies Co.', $note->unsigned_xml);
        $this->assertNotEmpty($note->cleared_xml_base64);
        $this->assertBillsAgainst($note, $original);
        $this->assertTotals(['1400.00', '210.00', '1610.00'], $note);
        $this->assertUnbrokenChain([$original, $note]);
        $this->assertOnlySentTo('/invoices/clearance/single', 2);
    }

    public function test_a_partial_credit_note_is_cleared_for_the_returned_line_only(): void
    {
        $this->onboard(self::B2C_EGS);
        $this->onboard(self::B2B_EGS);
        $original = $this->acceptedInvoice($this->order([['Office chair', 1, '460.00'], ['Desk', 1, '1150.00']], company: true), self::B2B_EGS);

        $creditNote = app(CreditNoteIssuer::class)->issue($original, ZatcaCreditNoteType::Partial, [0], 'Chair returned', (string) Str::uuid());
        $note = $this->noteDocument('credit_note_id', $creditNote->id);

        $this->assertAccepted($note);
        $this->assertSame(ZatcaComplianceDocumentType::StandardCreditNote, $note->document_type);
        $this->assertStringContainsString('Office chair', $note->unsigned_xml);
        $this->assertStringNotContainsString('>Desk<', $note->unsigned_xml);
        $this->assertBillsAgainst($note, $original);
        $this->assertTotals(['400.00', '60.00', '460.00'], $note);
        $this->assertUnbrokenChain([$original, $note]);
        $this->assertOnlySentTo('/invoices/clearance/single', 2);
    }
}

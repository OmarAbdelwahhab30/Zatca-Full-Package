<?php

namespace Zatca\Tests\Sandbox\B2c;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaCreditNoteType;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Services\CreditNote\CreditNoteIssuer;
use Zatca\Tests\Sandbox\SandboxTestCase;

/**
 * B2C credit notes, issued with CreditNoteIssuer against a reported invoice; the sync queue
 * generates and reports them.
 *
 * @group zatca-sandbox
 */
#[Group('zatca-sandbox')]
class CreditNoteTest extends SandboxTestCase
{
    public function test_a_full_credit_note_is_reported_against_the_invoice(): void
    {
        $this->onboard(self::B2C_EGS);
        $original = $this->acceptedInvoice($this->order([['Office chair', 1, '460.00'], ['Desk lamp', 2, '57.50']]), self::B2C_EGS);

        $creditNote = app(CreditNoteIssuer::class)->issue($original, ZatcaCreditNoteType::Full, [], 'Order cancelled', (string) Str::uuid());
        $note = $this->noteDocument('credit_note_id', $creditNote->id);

        $this->assertAccepted($note);
        $this->assertSame(ZatcaComplianceDocumentType::CreditNote, $note->document_type);
        $this->assertSame(ZatcaSubmissionModel::Reporting, $note->submission_model);
        $this->assertStringContainsString('>381</cbc:InvoiceTypeCode>', $note->unsigned_xml);
        $this->assertBillsAgainst($note, $original);
        $this->assertTotals(['500.00', '75.00', '575.00'], $note);
        $this->assertUnbrokenChain([$original, $note]);
        $this->assertOnlySentTo('/invoices/reporting/single', 2);
    }

    public function test_a_partial_credit_note_is_reported_for_the_returned_line_only(): void
    {
        $this->onboard(self::B2C_EGS);
        $original = $this->acceptedInvoice($this->order([['Office chair', 1, '460.00'], ['Desk lamp', 2, '57.50']]), self::B2C_EGS);

        // Line 2 (index 1): the two lamps are returned.
        $creditNote = app(CreditNoteIssuer::class)->issue($original, ZatcaCreditNoteType::Partial, [1], 'Customer returned the lamps', (string) Str::uuid());
        $note = $this->noteDocument('credit_note_id', $creditNote->id);

        $this->assertAccepted($note);
        $this->assertSame(ZatcaComplianceDocumentType::CreditNote, $note->document_type);
        $this->assertBillsAgainst($note, $original);
        $this->assertStringContainsString('Desk lamp', $note->unsigned_xml);
        $this->assertStringNotContainsString('Office chair', $note->unsigned_xml);
        $this->assertTotals(['100.00', '15.00', '115.00'], $note);
        $this->assertUnbrokenChain([$original, $note]);
        $this->assertOnlySentTo('/invoices/reporting/single', 2);
    }
}

<?php

namespace Zatca\Tests\Sandbox\B2b;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\Tests\Sandbox\SandboxTestCase;

/**
 * B2B debit notes against a cleared Standard invoice: themselves Standard, carrying the
 * original's buyer, and cleared.
 *
 * @group zatca-sandbox
 */
#[Group('zatca-sandbox')]
class DebitNoteTest extends SandboxTestCase
{
    public function test_a_debit_note_for_an_added_item_is_cleared_against_the_invoice(): void
    {
        $this->onboard(self::B2C_EGS);
        $this->onboard(self::B2B_EGS);
        $original = $this->acceptedInvoice($this->order([['Office chair', 1, '460.00']], company: true), self::B2B_EGS);

        $debitNote = app(DebitNoteIssuer::class)->issue(
            $original,
            ZatcaDebitNoteReason::AdditionalItem,
            [new DebitNoteLineInput('Chair armrests', 2, '57.50')],
            'Armrests added to the order',
            (string) Str::uuid(),
        );
        $note = $this->noteDocument('debit_note_id', $debitNote->id);

        $this->assertAccepted($note);
        $this->assertSame(ZatcaComplianceDocumentType::StandardDebitNote, $note->document_type);
        $this->assertSame(ZatcaSubmissionModel::Clearance, $note->submission_model);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">383</cbc:InvoiceTypeCode>', $note->unsigned_xml);
        $this->assertStringContainsString('Riyadh Office Supplies Co.', $note->unsigned_xml);
        $this->assertNotEmpty($note->cleared_xml_base64);
        $this->assertBillsAgainst($note, $original);
        $this->assertTotals(['100.00', '15.00', '115.00'], $note);
        $this->assertUnbrokenChain([$original, $note]);
        $this->assertOnlySentTo('/invoices/clearance/single', 2);
    }
}

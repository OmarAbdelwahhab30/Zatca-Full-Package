<?php

namespace Zatca\Tests\Sandbox\B2c;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaDebitNoteReason;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Services\DebitNote\DebitNoteIssuer;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use Zatca\Tests\Sandbox\SandboxTestCase;

/**
 * B2C debit notes, issued with DebitNoteIssuer against a reported invoice; the sync queue
 * generates and reports them.
 *
 * @group zatca-sandbox
 */
#[Group('zatca-sandbox')]
class DebitNoteTest extends SandboxTestCase
{
    public function test_a_debit_note_for_an_added_item_is_reported_against_the_invoice(): void
    {
        $this->onboard(self::B2C_EGS);
        $original = $this->acceptedInvoice($this->order([['Office chair', 1, '460.00']]), self::B2C_EGS);

        $debitNote = app(DebitNoteIssuer::class)->issue(
            $original,
            ZatcaDebitNoteReason::AdditionalItem,
            [new DebitNoteLineInput('Monitor stand', 1, '115.00')],
            'Monitor stand added to the order',
            (string) Str::uuid(),
        );
        $note = $this->noteDocument('debit_note_id', $debitNote->id);

        $this->assertAccepted($note);
        $this->assertSame(ZatcaComplianceDocumentType::DebitNote, $note->document_type);
        $this->assertSame(ZatcaSubmissionModel::Reporting, $note->submission_model);
        $this->assertStringContainsString('>383</cbc:InvoiceTypeCode>', $note->unsigned_xml);
        $this->assertBillsAgainst($note, $original);
        $this->assertTotals(['100.00', '15.00', '115.00'], $note);
        $this->assertUnbrokenChain([$original, $note]);
        $this->assertOnlySentTo('/invoices/reporting/single', 2);
    }
}

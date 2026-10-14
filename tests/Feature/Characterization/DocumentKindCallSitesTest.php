<?php

namespace Zatca\Tests\Feature\Characterization;

use Zatca\Enums\ZatcaComplianceDocumentType as Type;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\CreditNote\CreditNoteEligibilityPolicy;
use Zatca\Services\CreditNote\CreditNoteEligibilityVerdict;
use Zatca\Services\DebitNote\DebitNoteEligibilityPolicy;
use Zatca\Services\DebitNote\DebitNoteEligibilityVerdict;
use Zatca\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The package call sites that compare document types (blueprint P17), one test each.
 * The inventory and its kind/subtype classification are in docs/zatca-b2b/implementation-log.md.
 *
 * @group characterization
 */
#[Group('characterization')]
class DocumentKindCallSitesTest extends TestCase
{
    use CreatesInvoiceRows;

    protected function tearDown(): void
    {
        ZatcaInvoice::query()->delete();

        parent::tearDown();
    }

    /**
     * ZatcaInvoice::isCreditNote()/isDebitNote() mean the kind (381/383) of either
     * subtype; isAdjustmentNote() already did.
     *
     * @dataProvider kinds
     */
    #[DataProvider('kinds')]
    public function test_model_kind_helpers(Type $type, bool $credit, bool $debit): void
    {
        $row = (new ZatcaInvoice)->forceFill(['document_type' => $type]);

        $this->assertSame($credit, $row->isCreditNote());
        $this->assertSame($debit, $row->isDebitNote());
        $this->assertSame($credit || $debit, $row->isAdjustmentNote());
    }

    public static function kinds(): array
    {
        return [
            'simplified invoice' => [Type::Invoice, false, false],
            'simplified credit note' => [Type::CreditNote, true, false],
            'simplified debit note' => [Type::DebitNote, false, true],
            'standard invoice' => [Type::StandardInvoice, false, false],
            'standard credit note' => [Type::StandardCreditNote, true, false],
            'standard debit note' => [Type::StandardDebitNote, false, true],
        ];
    }

    public function test_a_row_without_a_document_type_is_no_note(): void
    {
        $row = new ZatcaInvoice;

        $this->assertFalse($row->isCreditNote());
        $this->assertFalse($row->isDebitNote());
    }

    /**
     * P41: both policies now switch to isInvoiceKind(), together with the note
     * factories (CreditNoteDataFactory/DebitNoteDataFactory), which choose
     * StandardCreditNote/StandardDebitNote for a Standard original. A Standard invoice
     * is no longer refused BY KIND — it now falls through to the same next check a
     * Simplified invoice would (here: no usable snapshot, since this fixture never sets
     * one), exactly like test_note_eligibility_type_check_still_passes_a_simplified_invoice.
     */
    public function test_note_eligibility_no_longer_refuses_a_standard_invoice_by_kind(): void
    {
        $standard = $this->invoiceRow(ZatcaInvoiceStatus::Cleared, ['document_type' => Type::StandardInvoice])->fresh();

        $this->assertNotSame(CreditNoteEligibilityVerdict::NOT_AN_INVOICE, app(CreditNoteEligibilityPolicy::class)->verdict($standard)->reason);
        $this->assertNotSame(DebitNoteEligibilityVerdict::NOT_AN_INVOICE, app(DebitNoteEligibilityPolicy::class)->verdict($standard)->reason);
        $this->assertSame(CreditNoteEligibilityVerdict::NO_SNAPSHOT, app(CreditNoteEligibilityPolicy::class)->verdict($standard)->reason);
        $this->assertSame(DebitNoteEligibilityVerdict::NO_SNAPSHOT, app(DebitNoteEligibilityPolicy::class)->verdict($standard)->reason);
    }

    /** A Standard credit/debit note itself must still be refused — it is not an invoice, of either subtype. */
    public function test_note_eligibility_still_refuses_a_standard_note(): void
    {
        $standardCreditNote = $this->invoiceRow(ZatcaInvoiceStatus::Cleared, ['document_type' => Type::StandardCreditNote])->fresh();
        $standardDebitNote = $this->invoiceRow(ZatcaInvoiceStatus::Cleared, ['document_type' => Type::StandardDebitNote])->fresh();

        $this->assertSame(CreditNoteEligibilityVerdict::NOT_AN_INVOICE, app(CreditNoteEligibilityPolicy::class)->verdict($standardCreditNote)->reason);
        $this->assertSame(DebitNoteEligibilityVerdict::NOT_AN_INVOICE, app(DebitNoteEligibilityPolicy::class)->verdict($standardDebitNote)->reason);
    }

    /** A reported Simplified invoice passes the type check (it then stops at the missing snapshot). */
    public function test_note_eligibility_type_check_still_passes_a_simplified_invoice(): void
    {
        // fresh(): the row relies on the column default for document_type.
        $simplified = $this->invoiceRow(ZatcaInvoiceStatus::Reported)->fresh();
        $this->assertSame(Type::Invoice, $simplified->document_type);

        $this->assertNotSame(CreditNoteEligibilityVerdict::NOT_AN_INVOICE, app(CreditNoteEligibilityPolicy::class)->verdict($simplified)->reason);
        $this->assertNotSame(DebitNoteEligibilityVerdict::NOT_AN_INVOICE, app(DebitNoteEligibilityPolicy::class)->verdict($simplified)->reason);
    }
}

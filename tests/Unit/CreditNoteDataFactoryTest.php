<?php

namespace Zatca\Tests\Unit;

use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\CreditNote\CreditNoteDataFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class CreditNoteDataFactoryTest extends TestCase
{
    public function test_it_copies_a_single_selected_line_and_sums_its_totals(): void
    {
        $result = $this->factory()->make(
            $this->fourLineOriginal(),
            lineIndexes: [1],
            creditNoteNumber: 'CN-000001',
            billingReferenceId: 'payment:412',
            reason: 'Course B refunded',
            issueDateTime: new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertCount(1, $result->lines);
        $this->assertSame('Course B', $result->lines[0]->description);
        $this->assertSame(300.00, $result->totalExclVat);
        $this->assertSame(45.00, $result->totalVat);
        $this->assertSame(345.00, $result->totalInclVat);
    }

    public function test_it_copies_multiple_selected_lines(): void
    {
        $result = $this->factory()->make(
            $this->fourLineOriginal(),
            lineIndexes: [0, 2],
            creditNoteNumber: 'CN-000002',
            billingReferenceId: 'payment:412',
            reason: 'A and C refunded',
            issueDateTime: new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertCount(2, $result->lines);
        $this->assertSame(730.00, $result->totalExclVat);
        $this->assertSame(109.50, $result->totalVat);
        $this->assertSame(839.50, $result->totalInclVat);
    }

    /** Full credit selects every line — the caller passes all indexes. */
    public function test_full_credit_of_every_line_reproduces_the_original_totals(): void
    {
        $original = $this->fourLineOriginal();

        $result = $this->factory()->make(
            $original,
            lineIndexes: [0, 1, 2, 3],
            creditNoteNumber: 'CN-000003',
            billingReferenceId: 'payment:412',
            reason: 'Full refund',
            issueDateTime: new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertSame($original->totalExclVat, $result->totalExclVat);
        $this->assertSame($original->totalVat, $result->totalVat);
        $this->assertSame($original->totalInclVat, $result->totalInclVat);
    }

    public function test_it_sets_document_type_billing_reference_and_reason(): void
    {
        $result = $this->factory()->make(
            $this->fourLineOriginal(),
            lineIndexes: [0],
            creditNoteNumber: 'CN-000004',
            billingReferenceId: 'payment:412',
            reason: 'Reason text',
            issueDateTime: new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertSame(ZatcaComplianceDocumentType::CreditNote, $result->documentType);
        $this->assertSame('payment:412', $result->billingReferenceId);
        $this->assertSame('Reason text', $result->adjustmentReason);
        $this->assertSame('CN-000004', $result->sourceReference);
    }

    /** Buyer and currency are carried from the original untouched — never re-derived. */
    public function test_it_carries_buyer_and_currency_from_the_original(): void
    {
        $result = $this->factory()->make(
            $this->fourLineOriginal(),
            lineIndexes: [0],
            creditNoteNumber: 'CN-000005',
            billingReferenceId: 'payment:412',
            reason: 'x',
            issueDateTime: new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertSame('Original Buyer', $result->buyerName);
        $this->assertSame('SAR', $result->currency);
    }

    /**
     * B2C regression (P41): a Simplified original never carries a structured buyer or
     * supply date, so the note built from it never does either — this class does not
     * invent either field for a Simplified sale.
     */
    public function test_a_simplified_original_never_gets_a_structured_buyer_or_supply_date(): void
    {
        $result = $this->factory()->make(
            $this->fourLineOriginal(),
            lineIndexes: [0],
            creditNoteNumber: 'CN-000009',
            billingReferenceId: 'payment:412',
            reason: 'x',
            issueDateTime: new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertSame(ZatcaComplianceDocumentType::CreditNote, $result->documentType);
        $this->assertNull($result->buyer);
        $this->assertNull($result->supplyDate);
    }

    /**
     * A Credit Note against a Standard (B2B) original is itself Standard, and it
     * inherits the ORIGINAL invoice's own frozen buyer/supply date verbatim — never
     * re-resolved from the host's current company/student state.
     */
    public function test_a_standard_original_produces_a_standard_credit_note_with_its_inherited_buyer(): void
    {
        $buyer = new BuyerParty(
            registrationName: 'شركة الاختبار المحدودة',
            vatNumber: '310123456700003',
            otherId: null,
            address: new BuyerAddress(
                street: 'شارع الاختبار',
                buildingNumber: '1234',
                district: 'حي الاختبار',
                city: 'الرياض',
                postalCode: '12345',
            ),
        );
        $supplyDate = new DateTimeImmutable('2026-09-01');

        $standardOriginal = new ZatcaInvoiceData(
            egsIdentifier: 'b2b',
            sourceReference: 'payment:900',
            buyerName: 'شركة الاختبار المحدودة',
            buyerVatNumber: '310123456700003',
            issueDateTime: new DateTimeImmutable('2026-09-01T10:00:00+03:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('Course A', 1, 500.00, 15.0, 500.00, 75.00)],
            totalExclVat: 500.00,
            totalVat: 75.00,
            totalInclVat: 575.00,
            documentType: ZatcaComplianceDocumentType::StandardInvoice,
            buyer: $buyer,
            supplyDate: $supplyDate,
        );

        $result = $this->factory()->make(
            $standardOriginal,
            lineIndexes: [0],
            creditNoteNumber: 'CN-000010',
            billingReferenceId: 'payment:900',
            reason: 'Company cancelled the course',
            issueDateTime: new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertSame(ZatcaComplianceDocumentType::StandardCreditNote, $result->documentType);
        $this->assertSame($buyer, $result->buyer);
        $this->assertSame('شركة الاختبار المحدودة', $result->buyer->registrationName);
        $this->assertSame($supplyDate, $result->supplyDate);
        $this->assertSame('b2b', $result->egsIdentifier);
    }

    /** The same inheritance holds for a price adjustment note, not only a full/partial credit. */
    public function test_a_standard_original_produces_a_standard_credit_note_for_a_price_adjustment_too(): void
    {
        $buyer = new BuyerParty(
            registrationName: 'شركة أخرى',
            vatNumber: null,
            otherId: \Zatca\DTOs\BuyerOtherId::commercialRegistration('7000000007'),
            address: new BuyerAddress(street: 's', buildingNumber: '1', district: 'd', city: 'c', postalCode: '11111'),
        );

        $standardOriginal = new ZatcaInvoiceData(
            egsIdentifier: 'b2b',
            sourceReference: 'payment:901',
            buyerName: 'شركة أخرى',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-01T10:00:00+03:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('Course A', 1, 500.00, 15.0, 500.00, 75.00)],
            totalExclVat: 500.00,
            totalVat: 75.00,
            totalInclVat: 575.00,
            documentType: ZatcaComplianceDocumentType::StandardInvoice,
            buyer: $buyer,
        );

        $adjustmentLine = new ZatcaInvoiceLine('Course A price adjustment', 1, 50.00, 15.0, 50.00, 7.50);

        $result = $this->factory()->makeAdjustment(
            $standardOriginal,
            $adjustmentLine,
            'CN-000011',
            'payment:901',
            'Price correction',
            new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertSame(ZatcaComplianceDocumentType::StandardCreditNote, $result->documentType);
        $this->assertSame($buyer, $result->buyer);
    }

    /** Discounted line values are copied exactly, never recomputed (BR-07, scenario 9). */
    public function test_it_never_recomputes_a_discounted_line(): void
    {
        $original = new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:412',
            buyerName: 'Original Buyer',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-01T10:00:00+03:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('Discounted Course', 1, 400.00, 15.0, 400.00, 60.00)],
            totalExclVat: 400.00,
            totalVat: 60.00,
            totalInclVat: 460.00,
        );

        $result = $this->factory()->make(
            $original,
            lineIndexes: [0],
            creditNoteNumber: 'CN-000006',
            billingReferenceId: 'payment:412',
            reason: 'x',
            issueDateTime: new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertSame(460.00, $result->totalInclVat);
        $this->assertSame(400.00, $result->lines[0]->lineTotalExclVat);
        $this->assertSame(60.00, $result->lines[0]->vatAmount);
    }

    public function test_it_refuses_no_lines_selected(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/no lines selected/');

        $this->factory()->make(
            $this->fourLineOriginal(),
            lineIndexes: [],
            creditNoteNumber: 'CN-000007',
            billingReferenceId: 'payment:412',
            reason: 'x',
            issueDateTime: new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );
    }

    public function test_it_refuses_an_index_that_does_not_exist_on_the_original(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $this->factory()->make(
            $this->fourLineOriginal(),
            lineIndexes: [99],
            creditNoteNumber: 'CN-000008',
            billingReferenceId: 'payment:412',
            reason: 'x',
            issueDateTime: new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );
    }

    private function factory(): CreditNoteDataFactory
    {
        return new CreditNoteDataFactory;
    }

    private function fourLineOriginal(): ZatcaInvoiceData
    {
        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:412',
            buyerName: 'Original Buyer',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-01T10:00:00+03:00'),
            currency: 'SAR',
            lines: [
                new ZatcaInvoiceLine('Course A', 1, 500.00, 15.0, 500.00, 75.00),
                new ZatcaInvoiceLine('Course B', 1, 300.00, 15.0, 300.00, 45.00),
                new ZatcaInvoiceLine('Course C', 1, 230.00, 15.0, 230.00, 34.50),  // rounds to 34.50
                new ZatcaInvoiceLine('Course D', 1, 120.00, 15.0, 120.00, 18.00),
            ],
            totalExclVat: 1150.00,
            totalVat: 172.50,
            totalInclVat: 1322.50,
        );
    }
}

<?php

namespace Zatca\Tests\Unit;

use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Exceptions\DebitNoteException;
use Zatca\Services\DebitNote\DebitNoteDataFactory;
use Zatca\Services\DebitNote\DebitNoteLineInput;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Counterpart of CreditNoteDataFactoryTest, for DebitNoteDataFactory (P41): a Debit
 * Note's subtype, structured buyer and supply date are all inherited from the
 * ORIGINAL invoice, never decided or re-resolved by this class.
 */
class DebitNoteDataFactoryTest extends TestCase
{
    public function test_it_sets_document_type_billing_reference_and_reason_for_a_simplified_original(): void
    {
        $result = $this->factory()->make(
            $this->simplifiedOriginal(),
            [new DebitNoteLineInput('Extra course', 1, 200.00, 15.0, 200.00, 30.00)],
            'DN-000001',
            'payment:412',
            'Additional course',
            new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertSame(ZatcaComplianceDocumentType::DebitNote, $result->documentType);
        $this->assertSame('payment:412', $result->billingReferenceId);
        $this->assertSame('Additional course', $result->adjustmentReason);
        $this->assertSame('DN-000001', $result->sourceReference);
        $this->assertSame(230.00, $result->totalInclVat);
    }

    /** B2C regression (P41): a Simplified original never carries a structured buyer or supply date. */
    public function test_a_simplified_original_never_gets_a_structured_buyer_or_supply_date(): void
    {
        $result = $this->factory()->make(
            $this->simplifiedOriginal(),
            [new DebitNoteLineInput('Extra course', 1, 200.00, 15.0, 200.00, 30.00)],
            'DN-000002',
            'payment:412',
            'x',
            new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertNull($result->buyer);
        $this->assertNull($result->supplyDate);
        $this->assertSame('Original Buyer', $result->buyerName);
    }

    /**
     * A Debit Note against a Standard (B2B) original is itself Standard, and it
     * inherits the ORIGINAL invoice's own frozen buyer/supply date verbatim — never
     * re-resolved from the host's current company/student state.
     */
    public function test_a_standard_original_produces_a_standard_debit_note_with_its_inherited_buyer(): void
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
            [new DebitNoteLineInput('Extra course', 1, 200.00, 15.0, 200.00, 30.00)],
            'DN-000010',
            'payment:900',
            'Additional course added after purchase',
            new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );

        $this->assertSame(ZatcaComplianceDocumentType::StandardDebitNote, $result->documentType);
        $this->assertSame($buyer, $result->buyer);
        $this->assertSame('شركة الاختبار المحدودة', $result->buyer->registrationName);
        $this->assertSame($supplyDate, $result->supplyDate);
        $this->assertSame('b2b', $result->egsIdentifier);
    }

    public function test_it_refuses_no_lines(): void
    {
        $this->expectException(DebitNoteException::class);

        $this->factory()->make(
            $this->simplifiedOriginal(),
            [],
            'DN-000003',
            'payment:412',
            'x',
            new DateTimeImmutable('2026-09-14T12:00:00+03:00'),
        );
    }

    private function factory(): DebitNoteDataFactory
    {
        return new DebitNoteDataFactory;
    }

    private function simplifiedOriginal(): ZatcaInvoiceData
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
            ],
            totalExclVat: 500.00,
            totalVat: 75.00,
            totalInclVat: 575.00,
        );
    }
}

<?php

namespace Zatca\Tests\Unit;

use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerOtherId;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\Invoice\InvoiceDataValidator;
use Zatca\Tests\TestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

class InvoiceDataValidatorTest extends TestCase
{
    public function test_it_accepts_a_well_formed_invoice(): void
    {
        $this->validator()->validate($this->invoice());

        $this->addToAssertionCount(1);
    }

    /**
     * The case this class was added for. A coupon that validated at checkout and
     * failed re-validation at callback time leaves the price distributor holding
     * full prices against a discounted charge; before the fix, the difference was
     * subtracted from one course and could take it below zero. Nothing downstream
     * would have refused it — the totals still reconcile, so the arithmetic checks
     * pass and only the sign is wrong.
     */
    public function test_it_refuses_a_negative_invoice_line(): void
    {
        $data = $this->invoice(lines: [
            new ZatcaInvoiceLine('Course A', 1, 869.57, 15.0, 869.57, 130.43),
            new ZatcaInvoiceLine('Course B', 1, -230.43, 15.0, -230.43, -34.57),
        ], net: 639.14, vat: 95.86, gross: 735.00);

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/line 2 is negative/');

        $this->validator()->validate($data);
    }

    public function test_it_refuses_an_invoice_with_no_lines(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/has no lines/');

        $this->validator()->validate($this->invoice(lines: [], net: 0.0, vat: 0.0, gross: 0.0));
    }

    public function test_it_refuses_a_line_with_no_quantity(): void
    {
        $data = $this->invoice(lines: [
            new ZatcaInvoiceLine('Course A', 0, 100.00, 15.0, 100.00, 15.00),
        ], net: 100.00, vat: 15.00, gross: 115.00);

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/quantity 0/');

        $this->validator()->validate($data);
    }

    /** BR-CO-10: the lines are the invoice; the total is only their sum. */
    public function test_it_refuses_lines_that_do_not_sum_to_the_tax_exclusive_amount(): void
    {
        $data = $this->invoice(net: 100.01);

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/BR-CO-10/');

        $this->validator()->validate($data);
    }

    /** BR-CO-14 */
    public function test_it_refuses_line_vat_that_does_not_sum_to_the_vat_total(): void
    {
        $data = $this->invoice(vat: 15.01, gross: 115.01);

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/BR-CO-14/');

        $this->validator()->validate($data);
    }

    /** BR-CO-15 */
    public function test_it_refuses_totals_that_do_not_add_up(): void
    {
        $data = $this->invoice(gross: 115.01);

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/BR-CO-15/');

        $this->validator()->validate($data);
    }

    /**
     * Float representation is not a discrepancy. Summing 0.01 a hundred times does
     * not land exactly on 1.00 in binary, and rejecting that would fail invoices
     * that are perfectly correct.
     */
    public function test_it_tolerates_float_representation_error(): void
    {
        $lines = [];
        $net = 0.0;

        for ($i = 0; $i < 100; $i++) {
            $lines[] = new ZatcaInvoiceLine('Course', 1, 0.01, 15.0, 0.01, 0.00);
            $net += 0.01;
        }

        $this->validator()->validate($this->invoice(lines: $lines, net: $net, vat: 0.0, gross: $net));

        $this->addToAssertionCount(1);
    }

    public function test_passes_reports_without_raising(): void
    {
        $this->assertTrue($this->validator()->passes($this->invoice()));
        $this->assertFalse($this->validator()->passes($this->invoice(gross: 115.01)));
    }

    public function test_it_accepts_a_well_formed_credit_note(): void
    {
        $this->validator()->validate($this->creditNote());

        $this->addToAssertionCount(1);
    }

    /** BR-KSA-56: a note must reference the document it adjusts. */
    public function test_it_refuses_a_credit_note_with_no_billing_reference(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/billing reference/');

        $this->validator()->validate($this->creditNote(billingReferenceId: null));
    }

    /** KSA-10 / BR-KSA-17: a note must state why it was issued. */
    public function test_it_refuses_a_credit_note_with_no_reason(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/no reason/');

        $this->validator()->validate($this->creditNote(reason: null));
    }

    /** BR-KSA-F-06-C13: the InstructionNote is capped at 1000 characters. */
    public function test_it_refuses_a_credit_note_reason_over_1000_characters(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/exceeds 1000 characters/');

        $this->validator()->validate($this->creditNote(reason: str_repeat('x', 1001)));
    }

    public function test_it_accepts_a_credit_note_reason_at_exactly_1000_characters(): void
    {
        $this->validator()->validate($this->creditNote(reason: str_repeat('x', 1000)));

        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Standard (B2B) buyer — P22. Every rule here is from the KSA schematron rules
    // shipped with the ZATCA SDK (Facts Register F-SDK-04…F-SDK-10).
    // -------------------------------------------------------------------------

    public function test_it_accepts_a_well_formed_standard_invoice(): void
    {
        $this->validator()->validate($this->standardInvoice());

        $this->addToAssertionCount(1);
    }

    public function test_it_refuses_a_standard_document_with_no_buyer(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/has no buyer/');

        $this->validator()->validate($this->standardInvoice(buyer: null));
    }

    public function test_it_refuses_a_standard_document_with_no_supply_date(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/no supply date/');

        $this->validator()->validate($this->standardInvoice(supplyDate: null));
    }

    public function test_it_refuses_an_empty_buyer_legal_name(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/legal name/');

        $this->validator()->validate($this->standardInvoice(buyer: $this->buyer(registrationName: '  ')));
    }

    public function test_it_refuses_a_buyer_with_both_vat_and_other_id(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/both a VAT number and another ID/');

        $this->validator()->validate($this->standardInvoice(buyer: $this->buyer(
            vatNumber: '310123456700003',
            otherId: BuyerOtherId::commercialRegistration('7000000007'),
        )));
    }

    public function test_it_refuses_a_buyer_with_neither_vat_nor_other_id(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/neither a VAT number nor another ID/');

        $this->validator()->validate($this->standardInvoice(buyer: $this->buyer(vatNumber: null, otherId: null)));
    }

    /** @dataProvider malformedVatNumbers */
    #[DataProvider('malformedVatNumbers')]
    public function test_it_refuses_a_malformed_buyer_vat_number(string $vatNumber): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/VAT number must be 15 digits/');

        $this->validator()->validate($this->standardInvoice(buyer: $this->buyer(vatNumber: $vatNumber, otherId: null)));
    }

    public static function malformedVatNumbers(): array
    {
        return [
            'too short' => ['31012345670003'],
            'does not start with 3' => ['410123456700003'],
            'does not end with 3' => ['310123456700004'],
        ];
    }

    public function test_it_accepts_a_buyer_with_a_valid_other_id_instead_of_vat(): void
    {
        $this->validator()->validate($this->standardInvoice(buyer: $this->buyer(
            vatNumber: null,
            otherId: BuyerOtherId::commercialRegistration('7000000007'),
        )));

        $this->addToAssertionCount(1);
    }

    public function test_it_refuses_a_non_alphanumeric_other_id_value(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/must be alphanumeric/');

        $this->validator()->validate($this->standardInvoice(buyer: $this->buyer(
            vatNumber: null,
            otherId: new BuyerOtherId('CRN', '7000 000007'),
        )));
    }

    /** @dataProvider missingSaudiAddressFields */
    #[DataProvider('missingSaudiAddressFields')]
    public function test_it_refuses_a_saudi_buyer_address_missing_a_mandatory_field(string $field, string $label): void
    {
        $address = $this->address();
        $address = new BuyerAddress(
            street: $field === 'street' ? null : $address->street,
            buildingNumber: $field === 'buildingNumber' ? null : $address->buildingNumber,
            district: $field === 'district' ? null : $address->district,
            city: $field === 'city' ? null : $address->city,
            postalCode: $field === 'postalCode' ? null : $address->postalCode,
        );

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($label, '/').'/');

        $this->validator()->validate($this->standardInvoice(buyer: $this->buyer(address: $address)));
    }

    public static function missingSaudiAddressFields(): array
    {
        return [
            'street' => ['street', 'BT-50'],
            'building number' => ['buildingNumber', 'KSA-18'],
            'district' => ['district', 'KSA-4'],
            'city' => ['city', 'BT-52'],
            'postal code' => ['postalCode', 'BT-53'],
        ];
    }

    /** @dataProvider malformedPostalCodes */
    #[DataProvider('malformedPostalCodes')]
    public function test_it_refuses_a_malformed_saudi_postal_code(string $postalCode): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/postal code must be 5 digits/');

        $this->validator()->validate($this->standardInvoice(buyer: $this->buyer(
            address: new BuyerAddress(
                street: 'شارع الاختبار',
                buildingNumber: '1234',
                district: 'حي الاختبار',
                city: 'الرياض',
                postalCode: $postalCode,
            ),
        )));
    }

    public static function malformedPostalCodes(): array
    {
        return [
            'four digits' => ['1234'],
            'six digits' => ['123456'],
            'non-numeric' => ['ABCDE'],
        ];
    }

    /** BR-KSA-63's additional number is deliberately not mandatory (F-SDK-07). */
    public function test_it_accepts_a_saudi_buyer_with_no_additional_number(): void
    {
        $this->validator()->validate($this->standardInvoice(buyer: $this->buyer(
            address: new BuyerAddress(
                street: 'شارع الاختبار',
                buildingNumber: '1234',
                district: 'حي الاختبار',
                city: 'الرياض',
                postalCode: '12345',
                additionalNumber: null,
            ),
        )));

        $this->addToAssertionCount(1);
    }

    /** BR-KSA-10: a non-SA buyer only needs street, city and country. */
    public function test_it_accepts_a_non_saudi_buyer_with_only_street_and_city(): void
    {
        $this->validator()->validate($this->standardInvoice(buyer: $this->buyer(
            address: new BuyerAddress(
                street: 'Some Street',
                buildingNumber: null,
                district: null,
                city: 'Dubai',
                postalCode: null,
                countryCode: 'AE',
            ),
        )));

        $this->addToAssertionCount(1);
    }

    public function test_it_refuses_a_standard_credit_note_with_no_buyer(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessageMatches('/has no buyer/');

        $this->validator()->validate(new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'CN-000002',
            buyerName: 'Company',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-14 10:00:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('Course A', 1, 100.00, 15.0, 100.00, 15.00)],
            totalExclVat: 100.00,
            totalVat: 15.00,
            totalInclVat: 115.00,
            documentType: ZatcaComplianceDocumentType::StandardCreditNote,
            billingReferenceId: 'payment:412',
            adjustmentReason: 'Course refunded',
        ));
    }

    private function buyer(
        string $registrationName = 'شركة الاختبار المحدودة',
        ?string $vatNumber = '310123456700003',
        ?BuyerOtherId $otherId = null,
        ?BuyerAddress $address = null,
    ): BuyerParty {
        return new BuyerParty(
            registrationName: $registrationName,
            vatNumber: $vatNumber,
            otherId: $otherId,
            address: $address ?? $this->address(),
        );
    }

    private function address(): BuyerAddress
    {
        return new BuyerAddress(
            street: 'شارع الاختبار',
            buildingNumber: '1234',
            district: 'حي الاختبار',
            city: 'الرياض',
            postalCode: '12345',
            additionalNumber: '6789',
        );
    }

    private function standardInvoice(
        BuyerParty|bool|null $buyer = false, // false = "use the default", so null can mean "no buyer"
        DateTimeImmutable|bool|null $supplyDate = false,
    ): ZatcaInvoiceData {
        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:2',
            buyerName: 'Standard Buyer LLC',
            buyerVatNumber: '310123456700003',
            issueDateTime: new DateTimeImmutable('2026-08-20 10:00:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('Course A', 1, 100.00, 15.0, 100.00, 15.00)],
            totalExclVat: 100.00,
            totalVat: 15.00,
            totalInclVat: 115.00,
            documentType: ZatcaComplianceDocumentType::StandardInvoice,
            buyer: $buyer === false ? $this->buyer() : $buyer,
            supplyDate: $supplyDate === false ? new DateTimeImmutable('2026-08-20 10:00:00') : $supplyDate,
        );
    }

    private function validator(): InvoiceDataValidator
    {
        return new InvoiceDataValidator;
    }

    private function creditNote(?string $billingReferenceId = 'payment:412', ?string $reason = 'Course refunded'): ZatcaInvoiceData
    {
        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'CN-000001',
            buyerName: 'Student',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-14 10:00:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('Course A', 1, 100.00, 15.0, 100.00, 15.00)],
            totalExclVat: 100.00,
            totalVat: 15.00,
            totalInclVat: 115.00,
            documentType: ZatcaComplianceDocumentType::CreditNote,
            billingReferenceId: $billingReferenceId,
            adjustmentReason: $reason,
        );
    }

    /** @param  array<int, ZatcaInvoiceLine>|null  $lines */
    private function invoice(
        ?array $lines = null,
        float $net = 100.00,
        float $vat = 15.00,
        float $gross = 115.00,
    ): ZatcaInvoiceData {
        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:1',
            buyerName: 'Student',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-08-20 10:00:00'),
            currency: 'SAR',
            lines: $lines ?? [new ZatcaInvoiceLine('Course A', 1, 100.00, 15.0, 100.00, 15.00)],
            totalExclVat: $net,
            totalVat: $vat,
            totalInclVat: $gross,
        );
    }
}

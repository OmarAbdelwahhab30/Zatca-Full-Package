<?php

namespace Zatca\Tests\Unit\Golden;

use DateTimeImmutable;
use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerOtherId;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaComplianceDocumentType as Type;
use Zatca\Exceptions\ZatcaException;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Blueprint P21: snapshot version 3 for Standard documents.
 *
 * @group golden
 */
#[Group('golden')]
class StandardSnapshotTest extends TestCase
{
    /** @dataProvider standardTypes */
    #[DataProvider('standardTypes')]
    public function test_standard_documents_round_trip_through_v3(Type $type): void
    {
        $data = self::standard($type);

        $snapshot = InvoiceDataSnapshot::toArray($data);
        $stored = json_decode(json_encode($snapshot), true); // as the Eloquent array cast stores it

        $this->assertSame(3, $snapshot['version']);
        $this->assertSame($type->value, $snapshot['document_type']);
        $this->assertSame($snapshot, InvoiceDataSnapshot::toArray(InvoiceDataSnapshot::fromArray($stored)));
        $restored = InvoiceDataSnapshot::fromArray($stored);
        $this->assertEquals($data->buyer, $restored->buyer);
        $this->assertSame('2026-09-01T00:00:00+03:00', $restored->supplyDate->format(DATE_ATOM));
        $this->assertSame($type, $restored->documentType);
    }

    public static function standardTypes(): array
    {
        return [
            'invoice' => [Type::StandardInvoice],
            'credit note' => [Type::StandardCreditNote],
            'debit note' => [Type::StandardDebitNote],
        ];
    }

    public function test_the_v3_shape_is_pinned(): void
    {
        $snapshot = InvoiceDataSnapshot::toArray(self::standard(Type::StandardInvoice));

        $this->assertSame([
            'version', 'egs_identifier', 'source_reference', 'buyer_name', 'buyer_vat_number', 'issue_date_time', 'currency',
            'lines', 'total_excl_vat', 'total_vat', 'total_incl_vat', 'document_type', 'buyer', 'supply_date',
        ], array_keys($snapshot));
        $this->assertSame([
            'registration_name' => 'شركة تجريبية',
            'vat_number' => null,
            'other_id' => ['scheme' => 'CRN', 'value' => '7000000007'],
            'address' => ['street' => 'شارع', 'building_number' => '9090', 'district' => 'حي', 'city' => 'مدينة', 'postal_code' => '90909', 'country_code' => 'SA', 'additional_number' => null],
        ], $snapshot['buyer']);
    }

    public function test_a_standard_document_without_a_buyer_is_not_snapshotted(): void
    {
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('needs a buyer');

        InvoiceDataSnapshot::toArray(self::standard(Type::StandardInvoice)->withBuyer(null));
    }

    /** @dataProvider corruptions */
    #[DataProvider('corruptions')]
    public function test_an_unusable_v3_snapshot_is_refused(\Closure $corrupt, string $message): void
    {
        $snapshot = $corrupt(InvoiceDataSnapshot::toArray(self::standard(Type::StandardCreditNote)));

        $this->assertFalse(InvoiceDataSnapshot::isUsable($snapshot));
        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage($message);

        InvoiceDataSnapshot::fromArray($snapshot);
    }

    public static function corruptions(): array
    {
        return [
            'no buyer' => [fn (array $s) => array_diff_key($s, ['buyer' => 1]), 'missing the buyer'],
            'buyer without address' => [fn (array $s) => array_replace($s, ['buyer' => ['registration_name' => 'X']]), 'missing the buyer'],
            'unknown scheme' => [fn (array $s) => array_replace_recursive($s, ['buyer' => ['other_id' => ['scheme' => 'XYZ']]]), 'invalid buyer'],
            'Simplified type in v3' => [fn (array $s) => array_replace($s, ['document_type' => 'simplified_credit_note']), 'cannot hold'],
            'no billing reference on a note' => [fn (array $s) => array_replace($s, ['billing_reference_id' => null]), 'billing_reference_id'],
            'unknown type' => [fn (array $s) => array_replace($s, ['document_type' => 'nope']), 'unknown document_type'],
        ];
    }

    public function test_a_v2_snapshot_cannot_hold_a_standard_type(): void
    {
        $snapshot = InvoiceDataSnapshot::toArray(self::standard(Type::StandardCreditNote));
        $snapshot['version'] = 2;

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('cannot hold');

        InvoiceDataSnapshot::fromArray($snapshot);
    }

    private static function standard(Type $type): ZatcaInvoiceData
    {
        $isNote = $type->requiresBillingReference();

        return new ZatcaInvoiceData(
            egsIdentifier: 'b2b',
            sourceReference: $isNote ? 'CN-000009' : 'payment:9',
            buyerName: 'شركة تجريبية',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-02T10:00:00+03:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('دورة', 1, 500.0, 15.0, 500.0, 75.0)],
            totalExclVat: 500.0,
            totalVat: 75.0,
            totalInclVat: 575.0,
            documentType: $type,
            billingReferenceId: $isNote ? 'INV-1' : null,
            adjustmentReason: $isNote ? 'price change' : null,
            buyer: new BuyerParty('شركة تجريبية', null, BuyerOtherId::commercialRegistration('7000000007'), new BuyerAddress('شارع', '9090', 'حي', 'مدينة', '90909')),
            supplyDate: new DateTimeImmutable('2026-09-01T00:00:00+03:00'),
        );
    }
}

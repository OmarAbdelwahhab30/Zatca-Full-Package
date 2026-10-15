<?php

namespace Zatca\Tests\Unit;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Exceptions\ZatcaException;
use Zatca\Support\InvoiceDataSnapshot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Round-trip fidelity of the frozen business snapshot.
 *
 * A snapshot that loses a cent is worse than no snapshot at all: ZATCA rejects an invoice
 * whose lines do not reconcile with its document totals, so a lossy round trip would turn
 * one rejected invoice into a permanently unfixable one. Precision is therefore asserted
 * field by field rather than by comparing objects loosely.
 */
class InvoiceDataSnapshotTest extends TestCase
{
    public function test_it_round_trips_every_field(): void
    {
        $original = $this->invoiceData();

        $restored = InvoiceDataSnapshot::fromArray(InvoiceDataSnapshot::toArray($original));

        $this->assertSame($original->egsIdentifier, $restored->egsIdentifier);
        $this->assertSame($original->sourceReference, $restored->sourceReference);
        $this->assertSame($original->buyerName, $restored->buyerName);
        $this->assertSame($original->buyerVatNumber, $restored->buyerVatNumber);
        $this->assertSame($original->currency, $restored->currency);
        $this->assertSame($original->totalExclVat, $restored->totalExclVat);
        $this->assertSame($original->totalVat, $restored->totalVat);
        $this->assertSame($original->totalInclVat, $restored->totalInclVat);
    }

    /**
     * The issue instant is a fiscal value that appears in the XML, so it must survive
     * exactly — including its offset, which a naive round trip through a local timezone
     * would shift.
     */
    public function test_it_preserves_the_issue_instant_including_offset(): void
    {
        $original = $this->invoiceData();

        $restored = InvoiceDataSnapshot::fromArray(InvoiceDataSnapshot::toArray($original));

        $this->assertSame(
            $original->issueDateTime->format(DateTimeImmutable::ATOM),
            $restored->issueDateTime->format(DateTimeImmutable::ATOM)
        );

        // The two values the XML actually carries.
        $this->assertSame('2026-08-17', $restored->issueDateTime->format('Y-m-d'));
        $this->assertSame('10:30:00', $restored->issueDateTime->format('H:i:s'));
    }

    public function test_it_round_trips_multiple_lines_without_losing_precision(): void
    {
        $original = new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:77',
            buyerName: 'مشترٍ',
            buyerVatNumber: '399999999900003',
            issueDateTime: new DateTimeImmutable('2026-08-17T10:30:00+03:00'),
            currency: 'SAR',
            lines: [
                new ZatcaInvoiceLine('دورة أولى', 1, 33.33, 15.0, 33.33, 5.00),
                new ZatcaInvoiceLine('Second Item', 3, 10.01, 15.0, 30.03, 4.50),
            ],
            totalExclVat: 63.36,
            totalVat: 9.50,
            totalInclVat: 72.86,
        );

        $restored = InvoiceDataSnapshot::fromArray(InvoiceDataSnapshot::toArray($original));

        $this->assertCount(2, $restored->lines);

        foreach ($original->lines as $index => $line) {
            $this->assertSame($line->description, $restored->lines[$index]->description);
            $this->assertSame($line->quantity, $restored->lines[$index]->quantity);
            $this->assertSame($line->netUnitPrice, $restored->lines[$index]->netUnitPrice);
            $this->assertSame($line->vatRate, $restored->lines[$index]->vatRate);
            $this->assertSame($line->lineTotalExclVat, $restored->lines[$index]->lineTotalExclVat);
            $this->assertSame($line->vatAmount, $restored->lines[$index]->vatAmount);
        }

        // Lines must still reconcile with the document totals after the round trip.
        $lineSum = array_sum(array_map(fn (ZatcaInvoiceLine $l) => $l->lineTotalExclVat, $restored->lines));
        $this->assertSame(63.36, round($lineSum, 2));
    }

    /**
     * @dataProvider unusableSnapshots
     *
     * Every one of these must throw rather than hydrate something partial. Falling back to
     * the host record is the one genuinely dangerous option — it would re-price the invoice —
     * so an unusable snapshot has to be a hard stop.
     */
    #[DataProvider('unusableSnapshots')]
    public function test_it_refuses_an_unusable_snapshot(?array $snapshot): void
    {
        $this->expectException(ZatcaException::class);

        InvoiceDataSnapshot::fromArray($snapshot);
    }

    public static function unusableSnapshots(): array
    {
        $valid = [
            'version' => InvoiceDataSnapshot::VERSION,
            'egs_identifier' => 'default',
            'source_reference' => 'payment:1',
            'issue_date_time' => '2026-08-17T10:30:00+03:00',
            'currency' => 'SAR',
            'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1.0, 'vat_rate' => 15.0, 'line_total_excl_vat' => 1.0, 'vat_amount' => 0.15]],
            'total_excl_vat' => 1.0,
            'total_vat' => 0.15,
            'total_incl_vat' => 1.15,
        ];

        return [
            'null' => [null],
            'empty' => [[]],
            'unknown version' => [array_merge($valid, ['version' => 999])],
            'missing version' => [array_diff_key($valid, ['version' => null])],
            'missing currency' => [array_diff_key($valid, ['currency' => null])],
            'missing issue date' => [array_diff_key($valid, ['issue_date_time' => null])],
            'no lines' => [array_merge($valid, ['lines' => []])],
            'malformed line' => [array_merge($valid, ['lines' => ['not-an-array']])],
        ];
    }

    public function test_is_usable_reports_without_throwing(): void
    {
        $this->assertTrue(InvoiceDataSnapshot::isUsable(InvoiceDataSnapshot::toArray($this->invoiceData())));
        $this->assertFalse(InvoiceDataSnapshot::isUsable(null));
        $this->assertFalse(InvoiceDataSnapshot::isUsable([]));
    }

    /** An absent buyer stays absent rather than becoming an empty string in the XML. */
    public function test_absent_buyer_fields_stay_null(): void
    {
        $data = new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:1',
            buyerName: null,
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-08-17T10:30:00+03:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('x', 1, 1.0, 15.0, 1.0, 0.15)],
            totalExclVat: 1.0,
            totalVat: 0.15,
            totalInclVat: 1.15,
        );

        $restored = InvoiceDataSnapshot::fromArray(InvoiceDataSnapshot::toArray($data));

        $this->assertNull($restored->buyerName);
        $this->assertNull($restored->buyerVatNumber);
    }

    /**
     * The snapshot is business data only. Nothing in ZatcaInvoiceData carries credential
     * material, so this holds by construction — asserted so a future field addition that
     * broke it would fail here rather than in production.
     */
    public function test_a_snapshot_contains_no_credential_material(): void
    {
        $encoded = json_encode(InvoiceDataSnapshot::toArray($this->invoiceData()));

        foreach (['BEGIN', 'PRIVATE KEY', 'CERTIFICATE', 'secret', 'token', 'binarySecurityToken'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $encoded);
        }
    }

    /** A plain invoice keeps writing v1 unchanged — a rollback must still be able to read it. */
    public function test_a_plain_invoice_always_writes_version_1(): void
    {
        $snapshot = InvoiceDataSnapshot::toArray($this->invoiceData());

        $this->assertSame(1, $snapshot['version']);
        $this->assertArrayNotHasKey('document_type', $snapshot);
        $this->assertArrayNotHasKey('billing_reference_id', $snapshot);
        $this->assertArrayNotHasKey('adjustment_reason', $snapshot);
    }

    /** A Credit Note writes v2 and round-trips its extra fields. */
    public function test_a_credit_note_round_trips_document_type_billing_reference_and_reason(): void
    {
        $original = $this->creditNoteData();

        $snapshot = InvoiceDataSnapshot::toArray($original);
        $this->assertSame(InvoiceDataSnapshot::VERSION_CREDIT_NOTE, $snapshot['version']);

        $restored = InvoiceDataSnapshot::fromArray($snapshot);

        $this->assertSame(ZatcaComplianceDocumentType::CreditNote, $restored->documentType);
        $this->assertSame('payment:412', $restored->billingReferenceId);
        $this->assertSame('Item refunded per policy', $restored->adjustmentReason);
    }

    /** @dataProvider unusableCreditNoteSnapshots */
    #[DataProvider('unusableCreditNoteSnapshots')]
    public function test_it_refuses_a_credit_note_snapshot_missing_required_fields(array $snapshot): void
    {
        $this->expectException(ZatcaException::class);

        InvoiceDataSnapshot::fromArray($snapshot);
    }

    public static function unusableCreditNoteSnapshots(): array
    {
        $valid = [
            'version' => InvoiceDataSnapshot::VERSION_CREDIT_NOTE,
            'egs_identifier' => 'default',
            'source_reference' => 'CN-000001',
            'issue_date_time' => '2026-09-14T10:30:00+03:00',
            'currency' => 'SAR',
            'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1.0, 'vat_rate' => 15.0, 'line_total_excl_vat' => 1.0, 'vat_amount' => 0.15]],
            'total_excl_vat' => 1.0,
            'total_vat' => 0.15,
            'total_incl_vat' => 1.15,
            'document_type' => 'simplified_credit_note',
            'billing_reference_id' => 'payment:412',
            'adjustment_reason' => 'Item refunded',
        ];

        return [
            'missing billing reference' => [array_diff_key($valid, ['billing_reference_id' => null])],
            'blank billing reference' => [array_merge($valid, ['billing_reference_id' => ''])],
            'missing reason' => [array_diff_key($valid, ['adjustment_reason' => null])],
            'blank reason' => [array_merge($valid, ['adjustment_reason' => ''])],
            'missing document type' => [array_diff_key($valid, ['document_type' => null])],
        ];
    }

    private function creditNoteData(): ZatcaInvoiceData
    {
        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'CN-000001',
            buyerName: 'Original Buyer',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-09-14T10:30:00+03:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('Item Enrollment', 1, 100.00, 15.0, 100.00, 15.00)],
            totalExclVat: 100.00,
            totalVat: 15.00,
            totalInclVat: 115.00,
            documentType: ZatcaComplianceDocumentType::CreditNote,
            billingReferenceId: 'payment:412',
            adjustmentReason: 'Item refunded per policy',
        );
    }

    private function invoiceData(): ZatcaInvoiceData
    {
        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:412',
            buyerName: 'Original Buyer',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-08-17T10:30:00+03:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('Item Enrollment', 1, 100.00, 15.0, 100.00, 15.00)],
            totalExclVat: 100.00,
            totalVat: 15.00,
            totalInclVat: 115.00,
        );
    }
}

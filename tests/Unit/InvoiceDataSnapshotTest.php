<?php

namespace Zatca\Tests\Unit;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Exceptions\ZatcaException;
use Zatca\Support\InvoiceDataSnapshot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

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
                new ZatcaInvoiceLine('Second Course', 3, 10.01, 15.0, 30.03, 4.50),
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
            $this->assertSame($line->unitPrice, $restored->lines[$index]->unitPrice);
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

    private function invoiceData(): ZatcaInvoiceData
    {
        return new ZatcaInvoiceData(
            egsIdentifier: 'default',
            sourceReference: 'payment:412',
            buyerName: 'Original Buyer',
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable('2026-08-17T10:30:00+03:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('Course Enrollment', 1, 100.00, 15.0, 100.00, 15.00)],
            totalExclVat: 100.00,
            totalVat: 15.00,
            totalInclVat: 115.00,
        );
    }
}

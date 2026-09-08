<?php

namespace Zatca\Tests\Unit;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Exceptions\ZatcaException;
use Zatca\Services\Invoice\InvoiceDataValidator;
use Zatca\Tests\TestCase;
use DateTimeImmutable;

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

    private function validator(): InvoiceDataValidator
    {
        return new InvoiceDataValidator;
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

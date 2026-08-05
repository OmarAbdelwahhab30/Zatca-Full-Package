<?php

namespace Zatca\Services\Invoice;

use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use DateTimeImmutable;

/**
 * Builds the canned invoice used only by `zatca:onboard test-invoice`
 * (API 2 — Compliance Invoice check). Never used for real customer
 * invoices; production invoices always come from the host's
 * InvoiceSourceContract implementation.
 */
class ComplianceTestInvoiceDataFactory
{
    public function make(string $egsIdentifier): ZatcaInvoiceData
    {
        $line = new ZatcaInvoiceLine(
            description: 'Compliance Test Item',
            quantity: 1,
            unitPrice: 100.00,
            vatRate: 15.0,
            lineTotalExclVat: 100.00,
            vatAmount: 15.00,
        );

        return new ZatcaInvoiceData(
            egsIdentifier: $egsIdentifier,
            sourceReference: 'compliance-test-'.now()->format('YmdHis'),
            buyerName: null,
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable,
            currency: 'SAR',
            lines: [$line],
            totalExclVat: 100.00,
            totalVat: 15.00,
            totalInclVat: 115.00,
        );
    }
}

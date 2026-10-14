<?php

namespace Zatca\Services\Invoice;

use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerOtherId;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaComplianceDocumentType;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * Builds the canned invoice used only by `zatca:onboard test-invoice`
 * (API 2 — Compliance Invoice check). Never used for real customer
 * invoices; production invoices always come from the host's
 * InvoiceSourceContract implementation.
 */
class ComplianceTestInvoiceDataFactory
{
    /**
     * @param  ZatcaComplianceDocumentType  $documentType  a Standard type attaches the
     *   canned buyer and supply date a Standard document requires (BR-KSA-63/67,
     *   BR-KSA-15) — ignored for Simplified, exactly like production data.
     */
    public function make(
        string $egsIdentifier,
        string $description = 'Compliance Test Item',
        ZatcaComplianceDocumentType $documentType = ZatcaComplianceDocumentType::Invoice,
    ): ZatcaInvoiceData {
        $line = new ZatcaInvoiceLine(
            description: $description,
            quantity: 1,
            netUnitPrice: 100.00,
            vatRate: 15.0,
            lineTotalExclVat: 100.00,
            vatAmount: 15.00,
        );

        return new ZatcaInvoiceData(
            egsIdentifier: $egsIdentifier,
            // Unique per document: two compliance documents built in the same second must not
            // share an invoice number (a note's own ID would otherwise equal the invoice it bills).
            sourceReference: 'compliance-test-'.now()->format('YmdHis').'-'.Str::lower(Str::random(6)),
            buyerName: null,
            buyerVatNumber: null,
            issueDateTime: new DateTimeImmutable,
            currency: 'SAR',
            lines: [$line],
            totalExclVat: 100.00,
            totalVat: 15.00,
            totalInclVat: 115.00,
            // A Standard note's onboarding placeholder reason — the Standard note
            // generators refuse to emit one silently (unlike the Simplified ones,
            // which default this for onboarding); Simplified stays untouched (null).
            adjustmentReason: ($documentType->isStandard() && $documentType->requiresBillingReference())
                ? 'Compliance test '.($documentType->isCreditNoteKind() ? 'credit note' : 'debit note')
                : null,
            buyer: $documentType->isStandard() ? $this->cannedBuyer() : null,
            supplyDate: $documentType->isStandard() ? new DateTimeImmutable : null,
        );
    }

    private function cannedBuyer(): BuyerParty
    {
        return new BuyerParty(
            registrationName: 'Compliance Test Buyer',
            vatNumber: null,
            // No eight equal or sequential digits: ZATCA flags those as fake IDs
            // (BR-KSA-F-13), and the old 7000000007 drew that warning on every B2B
            // compliance check.
            otherId: BuyerOtherId::commercialRegistration('1010567892'),
            address: new BuyerAddress('Test Street', '1234', 'Test District', 'Riyadh', '12345'),
        );
    }
}

<?php

namespace Zatca\DTOs;

use DateTimeImmutable;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaComplianceDocumentType;

/**
 * What your InvoiceSourceContract returns: the sale as your app knows it — items,
 * quantities and prices — with no VAT arithmetic. The package turns it into the
 * calculated ZatcaInvoiceData (InvoiceCalculator), checks it, and only then spends an ICV.
 */
class ZatcaInvoiceInput
{
    /**
     * @param  array<ZatcaInvoiceItem>  $items
     * @param  array<ZatcaInvoiceDiscount>  $discounts
     * @param  VatPriceMode|null  $priceMode  what the prices on THIS invoice mean; null = ZATCA_VAT_MODE.
     *   For example exclusive B2B prices next to inclusive B2C prices.
     */
    public function __construct(
        public readonly string $egsIdentifier,
        /** Your unique reference for the sale, e.g. "INV-1001". Printed as the invoice number. */
        public readonly string $sourceReference,
        public readonly ?string $buyerName,
        public readonly ?string $buyerVatNumber,
        public readonly DateTimeImmutable $issueDateTime,
        public readonly string $currency,
        public readonly array $items,
        public readonly array $discounts = [],
        public readonly ?VatPriceMode $priceMode = null,
        public readonly ZatcaComplianceDocumentType $documentType = ZatcaComplianceDocumentType::Invoice,
        /** cbc:ID of the document this one bills against, for a document type that needs one. */
        public readonly ?string $billingReferenceId = null,
        /** KSA-10 reason, for a document type that needs one. */
        public readonly ?string $adjustmentReason = null,
        /** Standard (B2B) documents only: the company buyer. */
        public readonly ?BuyerParty $buyer = null,
        /** Standard (B2B) documents only: the supply date KSA-5 (BR-KSA-15). */
        public readonly ?DateTimeImmutable $supplyDate = null,
    ) {}
}

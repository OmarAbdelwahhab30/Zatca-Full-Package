<?php

namespace Zatca\Tests\Sandbox\Fixtures;

use DateTimeImmutable;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\ZatcaInvoiceInput;
use Zatca\DTOs\ZatcaInvoiceItem;
use Zatca\Enums\ZatcaComplianceDocumentType;

/**
 * The host app's mapper, written the way the README tells a developer to write it: items,
 * quantities and prices only, no VAT arithmetic. A company order becomes a Standard (B2B)
 * invoice with a buyer; any other order a Simplified (B2C) one.
 */
class SandboxOrderInvoiceSource implements InvoiceSourceContract
{
    public function toInvoiceInput(mixed $source, string $egsIdentifier): ZatcaInvoiceInput
    {
        $items = array_map(
            fn (array $item) => new ZatcaInvoiceItem($item['name'], $item['quantity'], $item['price']),
            $source->items
        );

        if (! $source->is_company) {
            return new ZatcaInvoiceInput(
                egsIdentifier: $egsIdentifier,
                sourceReference: $this->sourceReference($source),
                buyerName: null,
                buyerVatNumber: null,
                issueDateTime: new DateTimeImmutable('now'),
                currency: 'SAR',
                items: $items,
            );
        }

        $buyer = new BuyerParty(
            registrationName: 'Riyadh Office Supplies Co.',
            vatNumber: '399999999800003',
            otherId: null,
            address: new BuyerAddress(
                street: 'Prince Sultan Street',
                buildingNumber: '4321',
                district: 'Al Rawdah',
                city: 'Jeddah',
                postalCode: '23434',
                countryCode: 'SA',
            ),
        );

        return new ZatcaInvoiceInput(
            egsIdentifier: $egsIdentifier,
            sourceReference: $this->sourceReference($source),
            buyerName: $buyer->registrationName,
            buyerVatNumber: $buyer->vatNumber,
            issueDateTime: new DateTimeImmutable('now'),
            currency: 'SAR',
            items: $items,
            documentType: ZatcaComplianceDocumentType::StandardInvoice,
            buyer: $buyer,
            supplyDate: new DateTimeImmutable('today'),
        );
    }

    public function sourceReference(mixed $source): string
    {
        return $source->reference;
    }
}

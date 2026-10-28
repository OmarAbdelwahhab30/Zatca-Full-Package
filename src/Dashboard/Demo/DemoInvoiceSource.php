<?php

namespace Zatca\Dashboard\Demo;

use DateTimeImmutable;
use DateTimeZone;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerOtherId;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\ZatcaInvoiceInput;
use Zatca\DTOs\ZatcaInvoiceItem;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaComplianceDocumentType;

/**
 * Maps a DemoOrder to invoice input. When the host application already has its own
 * InvoiceSourceContract binding, that mapper is kept as the fallback for every
 * non-demo source, so enabling the dashboard never changes how your real sales are mapped.
 */
class DemoInvoiceSource implements InvoiceSourceContract
{
    public function __construct(private readonly ?InvoiceSourceContract $fallback = null) {}

    public function sourceReference(mixed $source): string
    {
        if (! $source instanceof DemoOrder) {
            return $this->fallbackOrFail()->sourceReference($source);
        }

        return 'DEMO-'.$source->id;
    }

    public function toInvoiceInput(mixed $source, string $egsIdentifier): ZatcaInvoiceInput
    {
        if (! $source instanceof DemoOrder) {
            return $this->fallbackOrFail()->toInvoiceInput($source, $egsIdentifier);
        }

        $items = [];
        foreach ($source->items as $item) {
            $items[] = new ZatcaInvoiceItem(
                description: $item->name,
                quantity: $item->quantity,
                price: $item->price,
                vatRate: $item->vat_rate,
            );
        }

        $issuedAt = DateTimeImmutable::createFromInterface($source->paid_at)
            ->setTimezone(new DateTimeZone('Asia/Riyadh'));

        $isCompany = $source->type === 'b2b';
        $c = $source->customer;

        return new ZatcaInvoiceInput(
            egsIdentifier: $egsIdentifier,
            sourceReference: $this->sourceReference($source),
            buyerName: $c->name,
            buyerVatNumber: null,
            issueDateTime: $issuedAt,
            currency: 'SAR',
            items: $items,
            documentType: $isCompany
                ? ZatcaComplianceDocumentType::StandardInvoice
                : ZatcaComplianceDocumentType::Invoice,
            buyer: $isCompany ? new BuyerParty(
                registrationName: $c->name,
                vatNumber: $c->vat_number,
                otherId: $c->vat_number ? null : BuyerOtherId::commercialRegistration($c->cr_number),
                address: new BuyerAddress(
                    street: $c->street,
                    buildingNumber: $c->building_number,
                    district: $c->district,
                    city: $c->city,
                    postalCode: $c->postal_code,
                    countryCode: $c->country_code ?? 'SA',
                    additionalNumber: $c->additional_number,
                ),
            ) : null,
            supplyDate: $isCompany
                ? DateTimeImmutable::createFromInterface($source->supplied_at)
                : null,
            priceMode: $isCompany ? VatPriceMode::Exclusive : VatPriceMode::Inclusive,
        );
    }

    private function fallbackOrFail(): InvoiceSourceContract
    {
        return $this->fallback
            ?? throw new \LogicException('Only DemoOrder sources can be mapped by the built-in dashboard; bind your own InvoiceSourceContract for other sources.');
    }
}

<?php

namespace Asas\Zatca\DTOs;

use Asas\Zatca\Enums\ZatcaEnvironment;

/** Typed view over one config('zatca.egs.{identifier}') entry. */
class EgsConfig
{
    public function __construct(
        public readonly string $egsIdentifier,
        public readonly ZatcaEnvironment $environment,
        public readonly ?string $vatNumber,
        public readonly ?string $organizationName,
        public readonly ?string $organizationUnitName,
        public readonly ?string $commonName,
        public readonly ?string $serialNumber,
        public readonly ?string $organizationIdentifier,
        public readonly string $countryCode,
        public readonly string $invoiceType,
        public readonly ?string $locationAddress,
        public readonly ?string $businessCategory,
        public readonly ?string $commercialRegistrationNumber,
    ) {}

    public static function fromArray(string $egsIdentifier, array $data): self
    {
        return new self(
            egsIdentifier: $egsIdentifier,
            environment: ZatcaEnvironment::from($data['environment'] ?? 'sandbox'),
            vatNumber: $data['vat_number'] ?? null,
            organizationName: $data['organization_name'] ?? null,
            organizationUnitName: $data['organization_unit_name'] ?? null,
            commonName: $data['common_name'] ?? null,
            serialNumber: $data['serial_number'] ?? null,
            organizationIdentifier: $data['organization_identifier'] ?? null,
            countryCode: $data['country_code'] ?? 'SA',
            invoiceType: $data['invoice_type'] ?? '0100',
            locationAddress: $data['location_address'] ?? null,
            businessCategory: $data['business_category'] ?? null,
            commercialRegistrationNumber: $data['commercial_registration_number'] ?? null,
        );
    }
}

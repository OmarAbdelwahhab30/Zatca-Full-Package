<?php

namespace Zatca\DTOs;

use Zatca\Enums\ZatcaEnvironment;

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
        public readonly ?string $companyNameAr = null,
        public readonly ?string $street = null,
        public readonly ?string $district = null,
        public readonly ?string $buildingNumber = null,
        public readonly ?string $additionalNumber = null,
        public readonly ?string $city = null,
        public readonly ?string $postalCode = null,
    ) {}

    public static function fromArray(string $egsIdentifier, array $data): self
    {
        return new self(
            egsIdentifier: $egsIdentifier,
            environment: ZatcaEnvironment::from($data['environment'] ?? 'sandbox'),
            vatNumber: self::clean($data['vat_number'] ?? null),
            organizationName: self::clean($data['organization_name'] ?? null),
            organizationUnitName: self::clean($data['organization_unit_name'] ?? null),
            commonName: self::clean($data['common_name'] ?? null),
            serialNumber: self::clean($data['serial_number'] ?? null),
            organizationIdentifier: self::clean($data['organization_identifier'] ?? null),
            countryCode: self::clean($data['country_code'] ?? null) ?? 'SA',
            invoiceType: self::clean($data['invoice_type'] ?? null) ?? '0100',
            locationAddress: self::clean($data['location_address'] ?? null),
            businessCategory: self::clean($data['business_category'] ?? null),
            commercialRegistrationNumber: self::clean($data['commercial_registration_number'] ?? null),
            companyNameAr: self::clean($data['company_name_ar'] ?? null),
            street: self::clean($data['street'] ?? null),
            district: self::clean($data['district'] ?? null),
            buildingNumber: self::clean($data['building_number'] ?? null),
            additionalNumber: self::clean($data['additional_number'] ?? null),
            city: self::clean($data['city'] ?? null),
            postalCode: self::clean($data['postal_code'] ?? null),
        );
    }

    /**
     * Normalises one config/.env value before it can reach a CSR or an invoice.
     *
     * A quoted .env value keeps its surrounding spaces (ZATCA_COMPANY_NAME_AR=" أساس"),
     * and that is not survivable downstream: the ZATCA SDK trims the seller name when
     * it builds the QR code's TLV but the XML keeps it verbatim, so ZATCA rejects the
     * invoice with "seller name does not match with qr code seller name". Non-breaking
     * spaces and zero-width marks are stripped for the same reason — they are easy to
     * paste in from a document and impossible to see in a terminal.
     *
     * An empty result becomes null so the ?: fallbacks elsewhere still fire.
     */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // U+00A0 no-break space, U+200B..U+200D zero-width, U+FEFF BOM.
        $value = preg_replace('/^[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+|[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+$/u', '', $value) ?? $value;

        return $value === '' ? null : $value;
    }

    /**
     * Seller legal name for the invoice XML (cbc:RegistrationName).
     * KSA tax invoices are Arabic-first; falls back to the English name so a
     * deployment that has not set ZATCA_COMPANY_NAME_AR is unaffected.
     */
    public function invoiceLegalName(): ?string
    {
        return $this->companyNameAr ?: $this->organizationName;
    }

    /**
     * One-line address for the CSR's free-text csr.location.address field.
     * Falls back to the legacy ZATCA_LOCATION_ADDRESS when no structured
     * field is set, so existing deployments generate an identical CSR.
     */
    public function oneLineAddress(): ?string
    {
        $parts = array_filter([
            $this->buildingNumber,
            $this->street,
            $this->district,
            $this->city,
        ], static fn (?string $part) => $part !== null && $part !== '');

        return $parts === [] ? $this->locationAddress : implode(', ', $parts);
    }
}

<?php

namespace Zatca\DTOs;

/**
 * The buyer's postal address (BG-8). For a Saudi buyer the street, building number,
 * postal code, city, district and country are mandatory on a Standard document
 * (BR-KSA-63, F-SDK-07); the additional number is not. Carried as given — no validation
 * here (see the Standard data validator); an empty optional field is omitted from the XML,
 * never emitted as an empty tag (F-CL-11).
 */
final class BuyerAddress
{
    public function __construct(
        public readonly ?string $street,
        public readonly ?string $buildingNumber,
        public readonly ?string $district,
        public readonly ?string $city,
        public readonly ?string $postalCode,
        public readonly string $countryCode = 'SA',
        public readonly ?string $additionalNumber = null,
    ) {}
}

<?php

namespace Zatca\DTOs;

/**
 * The buyer of a Standard (B2B) document: a company, with its legal name, either a VAT
 * number (BT-48) or another ID (BT-46, BR-KSA-81), and its address.
 *
 * Simplified documents ignore it entirely: it is neither emitted nor snapshotted for
 * them, so a B2C document is identical whether or not a buyer object is attached.
 */
final class BuyerParty
{
    public function __construct(
        public readonly string $registrationName,
        public readonly ?string $vatNumber,
        public readonly ?BuyerOtherId $otherId,
        public readonly BuyerAddress $address,
    ) {}
}

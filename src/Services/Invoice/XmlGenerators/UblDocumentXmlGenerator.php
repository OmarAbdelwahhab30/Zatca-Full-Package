<?php

namespace Zatca\Services\Invoice\XmlGenerators;

use Zatca\DTOs\EgsConfig;
use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\DTOs\ZatcaInvoiceData;

/**
 * Common contract for both document profiles (Simplified/Reporting and
 * Standard/Clearance) so XmlGeneratorFactory can return either without the
 * caller needing to know which one it got — both are handed to the same
 * signer/submission pipeline downstream.
 */
interface UblDocumentXmlGenerator
{
    public function generate(
        ZatcaInvoiceData $invoiceData,
        EgsConfig $egsConfig,
        string $uuid,
        InvoiceSequenceReservation $reservation,
        ?string $billingReferenceId = null
    ): string;
}

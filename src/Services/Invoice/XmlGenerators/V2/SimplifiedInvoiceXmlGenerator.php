<?php

namespace Zatca\Services\Invoice\XmlGenerators\V2;

/**
 * Builds the unsigned UBL 2.1 Simplified Tax Invoice (Reporting model, B2C)
 * XML that the ZATCA SDK expects as its `-sign` input — `cbc:InvoiceTypeCode`
 * 388, no billing reference. See AbstractSimplifiedUblDocumentXmlGenerator
 * for the shared template this and the credit/debit note generators build on.
 *
 * Versioned independently of the ZATCA API/connector version — this folder
 * moves only when ZATCA changes the UBL profile itself (config('zatca.
 * xml_profile_version')), never in lockstep with an API version bump.
 */
class SimplifiedInvoiceXmlGenerator extends AbstractSimplifiedUblDocumentXmlGenerator
{
    protected function invoiceTypeCode(): string
    {
        return '388';
    }
}

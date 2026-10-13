<?php

namespace Zatca\Services\Invoice\XmlGenerators\V2;

/**
 * Builds the unsigned UBL 2.1 Standard Tax Invoice (Clearance model, B2B)
 * XML — `cbc:InvoiceTypeCode` 388, `name="0100000"`, no billing reference.
 * See AbstractStandardUblDocumentXmlGenerator for the shared template.
 */
class StandardInvoiceXmlGenerator extends AbstractStandardUblDocumentXmlGenerator
{
    protected function invoiceTypeCode(): string
    {
        return '388';
    }
}

<?php

namespace Zatca\Services\Invoice\XmlGenerators\V2;

use Zatca\Exceptions\ZatcaException;

/**
 * Builds the unsigned UBL 2.1 Standard Debit Note (Clearance model, B2B)
 * XML — `cbc:InvoiceTypeCode` 383, `name="0100000"`. Mirrors
 * StandardCreditNoteXmlGenerator; the two differ only in the type code.
 */
class StandardDebitNoteXmlGenerator extends AbstractStandardUblDocumentXmlGenerator
{
    protected function invoiceTypeCode(): string
    {
        return '383';
    }

    protected function billingReferenceXml(?string $billingReferenceId): string
    {
        if (! $billingReferenceId) {
            throw new ZatcaException('A Standard debit note requires a billing reference to the invoice it adjusts.');
        }

        return sprintf(
            '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>%s</cbc:ID></cac:InvoiceDocumentReference></cac:BillingReference>',
            htmlspecialchars($billingReferenceId, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }

    protected function paymentMeansInstructionNoteXml(?string $reason): string
    {
        if (! $reason) {
            throw new ZatcaException('A Standard debit note requires a reason (KSA-10 / BR-KSA-17).');
        }

        return sprintf(
            '<cbc:InstructionNote>%s</cbc:InstructionNote>',
            htmlspecialchars($reason, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }
}

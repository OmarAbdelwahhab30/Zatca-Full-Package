<?php

namespace Zatca\Services\Invoice\XmlGenerators\V2;

use Zatca\Exceptions\ZatcaException;

/**
 * Builds the unsigned UBL 2.1 Standard Credit Note (Clearance model, B2B)
 * XML — `cbc:InvoiceTypeCode` 381, `name="0100000"`, plus a
 * `cac:BillingReference` back to the invoice it adjusts. Mirrors
 * SimplifiedCreditNoteXmlGenerator; see AbstractStandardUblDocumentXmlGenerator
 * for the buyer/delivery differences from the Simplified profile.
 */
class StandardCreditNoteXmlGenerator extends AbstractStandardUblDocumentXmlGenerator
{
    protected function invoiceTypeCode(): string
    {
        return '381';
    }

    protected function billingReferenceXml(?string $billingReferenceId): string
    {
        if (! $billingReferenceId) {
            throw new ZatcaException('A Standard credit note requires a billing reference to the invoice it adjusts.');
        }

        return sprintf(
            '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>%s</cbc:ID></cac:InvoiceDocumentReference></cac:BillingReference>',
            htmlspecialchars($billingReferenceId, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }

    /** ZATCA business rule KSA-10 (BR-KSA-17) — same placement as the Simplified profile. */
    protected function paymentMeansInstructionNoteXml(?string $reason): string
    {
        if (! $reason) {
            throw new ZatcaException('A Standard credit note requires a reason (KSA-10 / BR-KSA-17).');
        }

        return sprintf(
            '<cbc:InstructionNote>%s</cbc:InstructionNote>',
            htmlspecialchars($reason, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }
}

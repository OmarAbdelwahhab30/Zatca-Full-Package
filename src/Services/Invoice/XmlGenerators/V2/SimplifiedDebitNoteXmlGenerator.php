<?php

namespace Zatca\Services\Invoice\XmlGenerators\V2;

use Zatca\Exceptions\ZatcaException;

/**
 * Builds the unsigned UBL 2.1 Simplified Debit Note (Reporting model, B2C)
 * XML — `cbc:InvoiceTypeCode` 383, plus a `cac:BillingReference` back to the
 * invoice it adjusts. See SimplifiedCreditNoteXmlGenerator's docblock; the
 * two differ only in the type code ZATCA expects.
 */
class SimplifiedDebitNoteXmlGenerator extends AbstractSimplifiedUblDocumentXmlGenerator
{
    protected function invoiceTypeCode(): string
    {
        return '383';
    }

    protected function billingReferenceXml(?string $billingReferenceId): string
    {
        if (! $billingReferenceId) {
            throw new ZatcaException('A simplified debit note requires a billing reference to the invoice it adjusts.');
        }

        return sprintf(
            '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>%s</cbc:ID></cac:InvoiceDocumentReference></cac:BillingReference>',
            htmlspecialchars($billingReferenceId, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }

    /** See SimplifiedCreditNoteXmlGenerator::paymentMeansInstructionNoteXml() — same ZATCA rule (KSA-10 / BR-KSA-17), same reasoning. */
    protected function paymentMeansInstructionNoteXml(): string
    {
        return '<cbc:InstructionNote>Compliance test debit note</cbc:InstructionNote>';
    }
}

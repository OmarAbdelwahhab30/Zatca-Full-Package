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

    /**
     * ZATCA business rule KSA-10 (BR-KSA-17): a credit/debit note (InvoiceTypeCode
     * 381/383) must state the reason it was issued, specifically as
     * `cac:PaymentMeans/cbc:InstructionNote`.
     *
     * Mirrors SimplifiedCreditNoteXmlGenerator exactly, including the placeholder
     * fallback and why it is kept: a production Debit Note always carries its own
     * admin-supplied reason via ZatcaInvoiceData::$adjustmentReason (DebitNoteIssuer
     * refuses to create one without it, and InvoiceDataValidator refuses to sign one
     * without it). Compliance onboarding's synthetic ZatcaInvoiceData never sets it,
     * so onboarding keeps emitting this fixed placeholder and its output stays
     * byte-identical to what ZATCA already accepted during onboarding.
     *
     * Before this feature the $reason argument was ignored outright — production
     * debit notes did not exist, so every 383 was an onboarding document. It is now
     * honoured, which is the ONLY change to 383 output, and it cannot affect
     * onboarding because onboarding never supplies a reason.
     *
     * TODO(debit-note): verify against ZATCA sandbox — confirm that ZATCA's 383
     * validation accepts an arbitrary Arabic free-text InstructionNote for a DEBIT
     * note the same way it does for a 381 credit note (onboarding only ever proved
     * the fixed English placeholder for 383). If ZATCA turns out to require a coded
     * reason for 383 specifically, this is the single method to change.
     */
    protected function paymentMeansInstructionNoteXml(?string $reason): string
    {
        $reason ??= 'Compliance test debit note';

        return sprintf(
            '<cbc:InstructionNote>%s</cbc:InstructionNote>',
            htmlspecialchars($reason, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }
}

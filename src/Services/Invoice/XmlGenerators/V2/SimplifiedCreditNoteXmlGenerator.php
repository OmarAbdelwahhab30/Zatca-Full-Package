<?php

namespace Zatca\Services\Invoice\XmlGenerators\V2;

use Zatca\Exceptions\ZatcaException;

/**
 * Builds the unsigned UBL 2.1 Simplified Credit Note (Reporting model, B2C)
 * XML — `cbc:InvoiceTypeCode` 381, plus a `cac:BillingReference` back to the
 * invoice it adjusts. ZATCA's Compliance Invoice Check API tracks this as a
 * document type separate from the plain invoice; onboarding a new EGS for
 * production is blocked until a compliant example of each type it may issue
 * has been submitted — see ComplianceOnboardingOrchestrator::testCreditNote().
 *
 * Only used during compliance onboarding today — see
 * ComplianceTestInvoiceDataFactory's docblock for why this never touches real
 * customer documents.
 */
class SimplifiedCreditNoteXmlGenerator extends AbstractSimplifiedUblDocumentXmlGenerator
{
    protected function invoiceTypeCode(): string
    {
        return '381';
    }

    protected function billingReferenceXml(?string $billingReferenceId): string
    {
        if (! $billingReferenceId) {
            throw new ZatcaException('A simplified credit note requires a billing reference to the invoice it adjusts.');
        }

        return sprintf(
            '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>%s</cbc:ID></cac:InvoiceDocumentReference></cac:BillingReference>',
            htmlspecialchars($billingReferenceId, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * ZATCA business rule KSA-10 (BR-KSA-17): a credit/debit note (InvoiceTypeCode
     * 381/383) must state the reason it was issued, specifically as
     * `cac:PaymentMeans/cbc:InstructionNote` — a top-level `cbc:Note` was tried
     * first and rejected by ZATCA's sandbox for landing at the wrong path.
     *
     * A production Credit Note always carries its own admin-supplied reason via
     * ZatcaInvoiceData::$adjustmentReason. Compliance onboarding's synthetic
     * ZatcaInvoiceData never sets it, so onboarding keeps emitting this fixed
     * placeholder — there is no real reversed sale behind that document to
     * describe, and this keeps onboarding output byte-identical.
     */
    protected function paymentMeansInstructionNoteXml(?string $reason): string
    {
        $reason ??= 'Compliance test credit note';

        return sprintf(
            '<cbc:InstructionNote>%s</cbc:InstructionNote>',
            htmlspecialchars($reason, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }
}

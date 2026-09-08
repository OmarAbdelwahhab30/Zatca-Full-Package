<?php

namespace Zatca\Enums;

/**
 * The document types ZATCA's Compliance Invoice Check API (`POST /compliance/invoices`)
 * tracks separately against one compliance certificate. ZATCA will not issue a
 * Production CSID until every type the EGS is expected to produce has at least one
 * PASS/WARNING submission recorded — see ComplianceOnboardingOrchestrator.
 */
enum ZatcaComplianceDocumentType: string
{
    case Invoice = 'simplified_invoice';
    case CreditNote = 'simplified_credit_note';
    case DebitNote = 'simplified_debit_note';

    /** UBL `cbc:InvoiceTypeCode` value — 388/381/383 are the standard UN/CEFACT codes ZATCA expects. */
    public function invoiceTypeCode(): string
    {
        return match ($this) {
            self::Invoice => '388',
            self::CreditNote => '381',
            self::DebitNote => '383',
        };
    }

    /** Credit and debit notes must bill against the invoice they adjust; a plain invoice does not. */
    public function requiresBillingReference(): bool
    {
        return $this !== self::Invoice;
    }

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'simplified invoice',
            self::CreditNote => 'simplified credit note',
            self::DebitNote => 'simplified debit note',
        };
    }
}

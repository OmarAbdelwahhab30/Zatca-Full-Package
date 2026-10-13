<?php

namespace Zatca\Enums;

/**
 * The document types ZATCA's Compliance Invoice Check API (`POST /compliance/invoices`)
 * tracks separately against one compliance certificate. ZATCA will not issue a
 * Production CSID until every type the EGS is expected to produce has at least one
 * PASS/WARNING submission recorded — see ComplianceOnboardingOrchestrator.
 *
 * The Standard cases (B2B tax invoices, cleared before issue) exist so that every
 * reader understands them before anything writes them. Nothing produces a Standard
 * document yet: XmlGeneratorFactory refuses them and onboarding never submits them.
 */
enum ZatcaComplianceDocumentType: string
{
    case Invoice = 'simplified_invoice';
    case CreditNote = 'simplified_credit_note';
    case DebitNote = 'simplified_debit_note';
    case StandardInvoice = 'standard_invoice';
    case StandardCreditNote = 'standard_credit_note';
    case StandardDebitNote = 'standard_debit_note';

    /**
     * UBL `cbc:InvoiceTypeCode` value — 388/381/383 are the standard UN/CEFACT codes ZATCA
     * expects. Standard and Simplified share them; the transaction code name tells them
     * apart (ZATCA SDK samples Standard_Invoice.xml, Standard_Credit_Note.xml,
     * Standard_Debit_Note.xml).
     */
    public function invoiceTypeCode(): string
    {
        return match ($this) {
            self::Invoice, self::StandardInvoice => '388',
            self::CreditNote, self::StandardCreditNote => '381',
            self::DebitNote, self::StandardDebitNote => '383',
        };
    }

    /**
     * The `name` attribute of `cbc:InvoiceTypeCode`: the first two digits say whether the
     * document is a Standard (01) or Simplified (02) tax invoice; the remaining flags
     * (third party, nominal, exports, summary, self-billed) are never set here.
     */
    public function transactionCodeName(): string
    {
        return $this->isStandard() ? '0100000' : '0200000';
    }

    /** Credit and debit notes must bill against the invoice they adjust; a plain invoice does not. */
    public function requiresBillingReference(): bool
    {
        return ! $this->isInvoiceKind();
    }

    /** A 388 of either subtype. Use this, not `=== Invoice`, where "an invoice" is meant. */
    public function isInvoiceKind(): bool
    {
        return $this === self::Invoice || $this === self::StandardInvoice;
    }

    /** A 381 of either subtype. */
    public function isCreditNoteKind(): bool
    {
        return $this === self::CreditNote || $this === self::StandardCreditNote;
    }

    /** A 383 of either subtype. */
    public function isDebitNoteKind(): bool
    {
        return $this === self::DebitNote || $this === self::StandardDebitNote;
    }

    /** A Standard (B2B) document — cleared by ZATCA before it is issued, never reported. */
    public function isStandard(): bool
    {
        return match ($this) {
            self::Invoice, self::CreditNote, self::DebitNote => false,
            self::StandardInvoice, self::StandardCreditNote, self::StandardDebitNote => true,
        };
    }

    public function submissionModel(): ZatcaSubmissionModel
    {
        return $this->isStandard() ? ZatcaSubmissionModel::Clearance : ZatcaSubmissionModel::Reporting;
    }

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'simplified invoice',
            self::CreditNote => 'simplified credit note',
            self::DebitNote => 'simplified debit note',
            self::StandardInvoice => 'standard invoice',
            self::StandardCreditNote => 'standard credit note',
            self::StandardDebitNote => 'standard debit note',
        };
    }
}

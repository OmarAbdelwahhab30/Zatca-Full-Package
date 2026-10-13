<?php

namespace Zatca\Enums;

/**
 * How ZATCA receives a document.
 *
 * Reporting: Simplified (B2C) documents are issued first and reported within 24 hours
 * (`/invoices/reporting/single`, `Clearance-Status: 0`).
 * Clearance: Standard (B2B) documents are sent before issue; ZATCA validates, signs and
 * returns the cleared document (`/invoices/clearance/single`, `Clearance-Status: 1`).
 *
 * A document's model follows from its type (ZatcaComplianceDocumentType::submissionModel())
 * and never changes: a Standard document is never routed to reporting.
 */
enum ZatcaSubmissionModel: string
{
    case Reporting = 'reporting';
    case Clearance = 'clearance';
}

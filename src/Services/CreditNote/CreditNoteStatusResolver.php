<?php

namespace Zatca\Services\CreditNote;

use Zatca\Enums\ZatcaCreditNoteDisplayStatus;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaInvoice;

/**
 * Derives the status shown to an admin for one Credit Note. No new ZatcaInvoiceStatus
 * values were added for this feature (that enum belongs to the reporting pipeline and
 * is shared with plain invoices) — this class is where "Rejected" and "Generation
 * Failed" actually come from, by reading the same facts RegenerationPolicy already
 * reads to tell a transient failure from a rejection.
 */
class CreditNoteStatusResolver
{
    public function resolve(ZatcaCreditNote $creditNote): ZatcaCreditNoteDisplayStatus
    {
        if ($creditNote->isCancelled()) {
            return ZatcaCreditNoteDisplayStatus::Cancelled;
        }

        if ($creditNote->isAccepted()) {
            return ZatcaCreditNoteDisplayStatus::Accepted;
        }

        // Checked before looking at any technical document: a stranded, never-signed
        // Draft can exist alongside a recorded generation failure (the Draft survives
        // exactly so a retry can resume it — see ProductionInvoiceOrchestrator), and
        // that Draft's own status (still "draft") must not read as merely "Pending".
        if ($creditNote->generation_failed_at !== null) {
            return ZatcaCreditNoteDisplayStatus::GenerationFailed;
        }

        /** @var ZatcaInvoice|null $document */
        $document = $creditNote->latestZatcaInvoice;

        if (! $document) {
            return ZatcaCreditNoteDisplayStatus::Pending;
        }

        // Reporting (B2C) and Clearance (B2B) in-flight states side by side: both mean
        // "signed and queued or being retried, nothing definitive from ZATCA yet".
        // ClearanceInDoubt is included here too — a network failure means whether ZATCA
        // received the document is unknown, which is the same "keep waiting" story as a
        // retry, never a rejection.
        if ($document->isGenerationInFlight() || in_array($document->status, [
            ZatcaInvoiceStatus::Draft,
            ZatcaInvoiceStatus::PendingReporting,
            ZatcaInvoiceStatus::Retrying,
            ZatcaInvoiceStatus::PendingClearance,
            ZatcaInvoiceStatus::ClearanceRetrying,
            ZatcaInvoiceStatus::ClearanceInDoubt,
        ], true)) {
            return ZatcaCreditNoteDisplayStatus::Processing;
        }

        // ClearanceRejected IS the definitive ZATCA verdict (NOT_CLEARED) — unlike a
        // Reporting ManualReview, there is no ambiguous HTTP status to weigh: ZATCA read
        // the document and refused it.
        if ($document->status === ZatcaInvoiceStatus::ClearanceRejected) {
            return ZatcaCreditNoteDisplayStatus::Rejected;
        }

        if (! in_array($document->status, [ZatcaInvoiceStatus::Failed, ZatcaInvoiceStatus::ManualReview, ZatcaInvoiceStatus::ClearanceManualReview], true)) {
            return ZatcaCreditNoteDisplayStatus::Pending;
        }

        $httpStatus = $document->latestSubmissionAttempt?->http_status;

        // Same test RegenerationPolicy and ReportsZatcaInvoice use: no attempt, HTTP 0,
        // 5xx, or 401/403 means ZATCA never judged the document — the trip failed, not
        // the document. Anything else in 4xx is a genuine rejection.
        $isTransientOrCredentials = $httpStatus === null
            || (int) $httpStatus === 0
            || (int) $httpStatus >= 500
            || (int) $httpStatus === 401
            || (int) $httpStatus === 403;

        return $isTransientOrCredentials
            ? ZatcaCreditNoteDisplayStatus::Failed
            : ZatcaCreditNoteDisplayStatus::Rejected;
    }
}

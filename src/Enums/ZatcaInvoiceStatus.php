<?php

namespace Zatca\Enums;

enum ZatcaInvoiceStatus: string
{
    case Draft = 'draft';
    case Generated = 'generated';
    case PendingReporting = 'pending_reporting';
    case Reported = 'reported';
    case ReportedWithWarnings = 'reported_with_warnings';
    case Failed = 'failed';
    case Retrying = 'retrying';
    case ManualReview = 'manual_review';

    /*
     * Clearance (Standard documents only). A signed Standard document starts in
     * PendingClearance. isRetryable() is reporting-only; every re-send path routes by the
     * document's submission_model (Zatca\Support\SubmissionRetry), never by status.
     */
    case PendingClearance = 'pending_clearance';
    case ClearanceRetrying = 'clearance_retrying';
    /** Sent, but whether ZATCA received it is unknown (timeout). Never reissued or cancelled while here. */
    case ClearanceInDoubt = 'clearance_in_doubt';
    case Cleared = 'cleared';
    case ClearedWithWarnings = 'cleared_with_warnings';
    /** ZATCA refused the document (NOT_CLEARED). Terminal: the same bytes would fail again. */
    case ClearanceRejected = 'clearance_rejected';
    case ClearanceManualReview = 'clearance_manual_review';

    /** ZATCA accepted the document — reported (B2C) or cleared (B2B). It is never sent again. */
    public function isTerminalSuccess(): bool
    {
        return in_array($this, [self::Reported, self::ReportedWithWarnings, self::Cleared, self::ClearedWithWarnings], true);
    }

    /** Reporting only. Clearance states have their own, see isClearanceRetryable(). */
    public function isRetryable(): bool
    {
        return in_array($this, [self::PendingReporting, self::Failed, self::Retrying], true);
    }

    public function isClearanceState(): bool
    {
        return in_array($this, [
            self::PendingClearance,
            self::ClearanceRetrying,
            self::ClearanceInDoubt,
            self::Cleared,
            self::ClearedWithWarnings,
            self::ClearanceRejected,
            self::ClearanceManualReview,
        ], true);
    }

    /** What the scheduled clearance sweep may send again without a human decision. */
    public function isClearanceRetryable(): bool
    {
        return in_array($this, [self::PendingClearance, self::ClearanceRetrying, self::ClearanceInDoubt], true);
    }
}

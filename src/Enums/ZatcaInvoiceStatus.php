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

    public function isTerminalSuccess(): bool
    {
        return in_array($this, [self::Reported, self::ReportedWithWarnings], true);
    }

    public function isRetryable(): bool
    {
        return in_array($this, [self::PendingReporting, self::Failed, self::Retrying], true);
    }
}

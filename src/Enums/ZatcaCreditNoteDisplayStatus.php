<?php

namespace Zatca\Enums;

/**
 * The Credit Note status shown to an admin — deliberately NOT a value stored on
 * zatca_credit_notes. It is derived every time from accepted_at, cancelled_at,
 * generation_failed_at and the current technical document's status/latest attempt,
 * by CreditNoteStatusResolver. A stored status could drift from those facts; a
 * derived one cannot.
 */
enum ZatcaCreditNoteDisplayStatus: string
{
    case Cancelled = 'cancelled';
    case Accepted = 'accepted';
    case GenerationFailed = 'generation_failed';
    case Processing = 'processing';
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Failed = 'failed';
}

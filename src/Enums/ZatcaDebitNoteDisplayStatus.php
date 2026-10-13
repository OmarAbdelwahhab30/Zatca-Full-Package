<?php

namespace Zatca\Enums;

/**
 * The Debit Note status shown to an admin — deliberately NOT a value stored on
 * zatca_debit_notes, for the same reason ZatcaCreditNoteDisplayStatus is not
 * stored on zatca_credit_notes: it is derived every time from accepted_at,
 * cancelled_at, generation_failed_at and the current technical document's
 * status/latest attempt, by DebitNoteStatusResolver. A stored status could drift
 * from those facts; a derived one cannot.
 */
enum ZatcaDebitNoteDisplayStatus: string
{
    case Cancelled = 'cancelled';
    case Accepted = 'accepted';
    case GenerationFailed = 'generation_failed';
    case Processing = 'processing';
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Failed = 'failed';
}

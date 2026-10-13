<?php

namespace Zatca\Events;

use Zatca\Models\ZatcaDebitNote;

/**
 * Fired the moment a Debit Note's `accepted_at` is written — i.e. ZATCA has
 * actually accepted it. A pending, failed or rejected note never fires this,
 * exactly as for ZatcaCreditNoteAccepted.
 *
 * Acceptance is a FISCAL fact, not a payment. A listener may grant access,
 * record entitlement or notify someone; it must never mark anything paid.
 *
 * The package has no opinion on what an accepted debit note should do to the
 * host's own records — that is entirely host business. Listen to this event in
 * the host app.
 *
 * Dispatched after the DB transaction that writes accepted_at commits, so a
 * listener reading the note sees the persisted state.
 */
class ZatcaDebitNoteAccepted
{
    public function __construct(
        public readonly ZatcaDebitNote $debitNote,
    ) {}
}

<?php

namespace Zatca\Events;

use Zatca\Models\ZatcaCreditNote;

/**
 * Fired the moment a Credit Note's `accepted_at` is written — i.e. ZATCA has
 * actually accepted it (BR-09: pending/failed/rejected notes never fire this).
 *
 * The package has no opinion on what "credited" should do to the host's own
 * records (unlock/relock access, cancel a subscription, etc.) — that is
 * entirely host business. Listen to this event in the host app for that.
 *
 * Dispatched from inside the same DB transaction that writes accepted_at, so a
 * listener that queues work should act on the credit note id, not any state
 * captured on this event, and should expect to run after that transaction
 * commits (queued listeners already do, via ShouldQueue + afterCommit).
 */
class ZatcaCreditNoteAccepted
{
    public function __construct(
        public readonly ZatcaCreditNote $creditNote,
    ) {}
}

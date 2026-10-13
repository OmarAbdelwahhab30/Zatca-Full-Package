<?php

namespace Zatca\Services\CreditNote;

use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Exceptions\CreditNoteException;
use Zatca\Models\ZatcaCreditNote;
use Zatca\Models\ZatcaCreditNoteEvent;
use Zatca\Models\ZatcaCreditNoteLine;
use Zatca\Models\ZatcaInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Releases the lines a Credit Note reserved, so they become available to a future
 * note — the ONLY way lines are ever released (decision D1). Deliberately narrow:
 * allowed only when nothing about this note could still turn out to have been
 * accepted by ZATCA.
 *
 *  - Never generated/signed: nothing was ever sent, trivially safe.
 *  - Signed and reported, but ZATCA's last word was a definite 4xx rejection (not
 *    401/403, which means ZATCA rejected OUR CREDENTIALS, not the document — the
 *    document's fate is still unknown): ZATCA read the document and refused it, so
 *    it is provably not accepted.
 *
 * Never allowed after a transient failure (HTTP 0 or 5xx) or a 401/403: in both,
 * ZATCA may have received and accepted the document despite the trip appearing to
 * fail on our end — the exact ambiguity BR-09 exists to protect against. Never
 * allowed while a submission or generation is in flight, and never once accepted
 * (that path doesn't need cancelling — it is exactly what "credited" means).
 */
class CreditNoteCanceller
{
    /**
     * @param  array{type: string, id: int|string}|null  $cancelledBy
     *
     * @throws CreditNoteException when the note is not in a cancellable state.
     */
    public function cancel(ZatcaCreditNote $creditNote, ?array $cancelledBy = null): ZatcaCreditNote
    {
        return DB::transaction(function () use ($creditNote, $cancelledBy) {
            $locked = ZatcaCreditNote::query()->lockForUpdate()->findOrFail($creditNote->getKey());

            if (! $this->isCancellable($locked)) {
                throw CreditNoteException::notCancellable();
            }

            ZatcaCreditNoteLine::query()
                ->where('credit_note_id', $locked->getKey())
                ->update(['active_line_key' => null]);

            $locked->update([
                'cancelled_at' => now(),
                'cancelled_by_type' => $cancelledBy['type'] ?? null,
                'cancelled_by_id' => $cancelledBy['id'] ?? null,
            ]);

            ZatcaCreditNoteEvent::record($locked->getKey(), 'cancelled', $cancelledBy);

            return $locked->fresh();
        });
    }

    public function isCancellable(ZatcaCreditNote $creditNote): bool
    {
        if ($creditNote->isCancelled() || $creditNote->isAccepted()) {
            return false;
        }

        /** @var ZatcaInvoice|null $document */
        $document = $creditNote->latestZatcaInvoice ?? $creditNote->zatcaInvoices()->latest('id')->first();

        // Never generated, or generation failed before a document even exists — trivially safe.
        if (! $document) {
            return true;
        }

        if ($document->isGenerationInFlight() || $document->isRegenerationInFlight()) {
            return false;
        }

        if (in_array($document->status, [
            ZatcaInvoiceStatus::Draft,
            ZatcaInvoiceStatus::PendingReporting,
            ZatcaInvoiceStatus::Retrying,
            // Clearance counterparts of the same "in flight or about to be" story. Every
            // one of these already implies a signed document, so isUnsignedDraft() is
            // false for them in practice — they are listed for clarity, not because a
            // Standard note is ever actually cancellable from here.
            ZatcaInvoiceStatus::PendingClearance,
            ZatcaInvoiceStatus::ClearanceRetrying,
            ZatcaInvoiceStatus::ClearanceInDoubt,
        ], true)) {
            // An unsigned Draft is safe (never sent); the rest mean a submission may be in
            // flight or about to be — not provably un-accepted.
            return $document->isUnsignedDraft();
        }

        // ClearanceRejected IS ZATCA's definitive verdict (NOT_CLEARED) — the document is
        // provably not accepted, with no HTTP-status ambiguity to weigh (unlike Reporting's
        // ManualReview below).
        if ($document->status === ZatcaInvoiceStatus::ClearanceRejected) {
            return true;
        }

        if (! in_array($document->status, [ZatcaInvoiceStatus::Failed, ZatcaInvoiceStatus::ManualReview, ZatcaInvoiceStatus::ClearanceManualReview], true)) {
            return false;
        }

        $httpStatus = $document->latestSubmissionAttempt?->http_status;

        if ($httpStatus === null) {
            return false;
        }

        $httpStatus = (int) $httpStatus;

        // A definite rejection (4xx, excluding 401/403 which reject credentials, not the
        // document) — and nothing else — proves ZATCA did not accept it.
        return $httpStatus >= 400 && $httpStatus !== 401 && $httpStatus !== 403;
    }
}

<?php

namespace Zatca\Services\DebitNote;

use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Exceptions\DebitNoteException;
use Zatca\Models\ZatcaDebitNote;
use Zatca\Models\ZatcaDebitNoteEvent;
use Zatca\Models\ZatcaInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Marks a Debit Note cancelled, so it stops appearing as an outstanding charge and
 * stops being retried.
 *
 * The cancellability RULE is identical to CreditNoteCanceller's, and for the same
 * reason — it is a statement about what ZATCA can still be holding, not about what
 * the note means:
 *
 *  - Never generated/signed: nothing was ever sent, trivially safe.
 *  - Signed and reported, but ZATCA's last word was a definite 4xx rejection (not
 *    401/403, which rejects OUR CREDENTIALS, not the document): ZATCA read the
 *    document and refused it, so it is provably not accepted.
 *
 * Never allowed after a transient failure (HTTP 0 or 5xx) or a 401/403: in both,
 * ZATCA may have received and accepted the document despite the trip appearing to
 * fail on our end. Never allowed while a submission or generation is in flight, and
 * never once accepted.
 *
 * The one thing this does NOT do, and CreditNoteCanceller does, is release
 * anything: a Debit Note reserves no lines, so cancelling it frees nothing. It is
 * purely a state change plus an audit entry.
 */
class DebitNoteCanceller
{
    /**
     * @param  array{type: string, id: int|string}|null  $cancelledBy
     *
     * @throws DebitNoteException when the note is not in a cancellable state.
     */
    public function cancel(ZatcaDebitNote $debitNote, ?array $cancelledBy = null): ZatcaDebitNote
    {
        return DB::transaction(function () use ($debitNote, $cancelledBy) {
            $locked = ZatcaDebitNote::query()->lockForUpdate()->findOrFail($debitNote->getKey());

            if (! $this->isCancellable($locked)) {
                throw DebitNoteException::notCancellable();
            }

            $locked->update([
                'cancelled_at' => now(),
                'cancelled_by_type' => $cancelledBy['type'] ?? null,
                'cancelled_by_id' => $cancelledBy['id'] ?? null,
            ]);

            ZatcaDebitNoteEvent::record($locked->getKey(), 'cancelled', $cancelledBy);

            return $locked->fresh();
        });
    }

    public function isCancellable(ZatcaDebitNote $debitNote): bool
    {
        if ($debitNote->isCancelled() || $debitNote->isAccepted()) {
            return false;
        }

        /** @var ZatcaInvoice|null $document */
        $document = $debitNote->latestZatcaInvoice ?? $debitNote->zatcaInvoices()->latest('id')->first();

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

<?php

namespace Zatca\Services\Execute;

use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Contracts\OwnerResolverInterface;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\EgsConfig;
use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Events\ZatcaInvoiceGenerated;
use Zatca\Exceptions\CrossEgsDuplicateException;
use Zatca\Exceptions\ZatcaCredentialsMissingException;
use Zatca\Exceptions\ZatcaDisabledException;
use Zatca\Exceptions\ZatcaException;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Invoice\InvoiceDataValidator;
use Zatca\Services\Invoice\XmlGeneratorFactory;
use Zatca\Services\SDK\SignedInvoiceXmlReader;
use Zatca\Services\Security\ZatcaSigningKeyProvider;
use Zatca\State\EgsRegistry;
use Zatca\State\ProductionInvoiceCounterManager;
use Zatca\State\ProductionPreviousInvoiceHashManager;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Support\ZatcaGate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns one paid host-app order into one signed ZATCA production invoice
 * record — in two durable steps so a retry after a signing failure never
 * re-reserves the ICV or creates a second invoice for the same source:
 *
 *  1. reserve() — ICV/PIH reservation + UUID + unsigned XML are generated
 *     and persisted as a Draft row in ONE database transaction. If this
 *     transaction fails (e.g. a concurrent duplicate insert), the ICV
 *     reservation rolls back with it — no gap, no orphaned invoice.
 *  2. sign() — runs the SDK against the already-persisted draft. If this
 *     fails, the Draft row (with its ICV/UUID/XML already fixed) is
 *     left in place; the NEXT attempt finds it via sourceReference() and
 *     reuses it instead of reserving a new ICV or generating new XML.
 *
 * Once a row is signed (status moves past Draft), it is never re-signed,
 * never re-generated, and never has its UUID/ICV changed again.
 *
 * regenerate() is the one exception to "never regenerate", and it is not an
 * exception to the rule above: it never touches the original row. It builds a
 * SEPARATE document from the original's frozen business snapshot and links the two.
 * See that method for why it cannot be expressed as a call to generate().
 */
class ProductionInvoiceOrchestrator
{
    /**
     * Separator appended to a source_reference to distinguish a replacement from the
     * document it replaces, e.g. "payment:412" -> "payment:412#r1".
     *
     * A replacement needs its own source_reference because
     * zatca_invoices_source_unique spans (egs_identifier, environment,
     * source_reference) and the original already occupies that slot. Suffixing rather
     * than relaxing the index keeps the idempotency guarantee that stops the purchase
     * flow issuing two invoices for one payment, and has a useful side effect: the
     * replacement no longer matches the "payment:{id}" / "transaction:{id}" lookups
     * elsewhere in the application, so those keep resolving to the original and the
     * supersede link decides which document is current.
     */
    private const REPLACEMENT_SUFFIX = '#r';

    public function __construct(
        private readonly EgsRegistry $egsRegistry,
        private readonly ZatcaSigningKeyProvider $signingKeyProvider,
        private readonly ProductionInvoiceCounterManager $counterManager,
        private readonly ProductionPreviousInvoiceHashManager $pihManager,
        private readonly XmlGeneratorFactory $xmlGeneratorFactory,
        private readonly ZatcaSdkInterface $sdk,
        private readonly OwnerResolverInterface $ownerResolver,
        private readonly SignedInvoiceXmlReader $xmlReader,
        private readonly InvoiceDataValidator $invoiceDataValidator,
        private readonly ZatcaGate $gate = new ZatcaGate,
    ) {}

    /**
     * Last-resort guard for the three public entry points below.
     *
     * Throws rather than returns, because all three return a non-nullable ZatcaInvoice and
     * the only alternative would be inventing a document that was never created. Every job
     * in this package checks the gate before calling in, so this is reached only by code
     * calling the orchestrator directly — where failing loudly is the correct answer.
     */
    private function assertEnabled(string $operation): void
    {
        if ($this->gate->disabled()) {
            throw ZatcaDisabledException::make($operation);
        }
    }

    public function generate(InvoiceSourceContract $source, mixed $sourceRecord, string $egsIdentifier): ZatcaInvoice
    {
        $this->assertEnabled('generate a ZATCA invoice');

        $invoice = $this->reserveDraft($source, $sourceRecord, $egsIdentifier);

        if ($invoice->status !== ZatcaInvoiceStatus::Draft) {
            // Already signed (or further along) on a previous attempt — never re-sign.
            return $invoice;
        }

        return $this->sign($invoice);
    }

    /**
     * Generates the technical ZATCA document for one Credit Note business record.
     *
     * Structurally the same two-step shape as generate() — reserve, then sign — but the
     * input is already a finished ZatcaInvoiceData (built by CreditNoteDataFactory from
     * the ORIGINAL invoice's frozen snapshot, not from any host record), so there is no
     * InvoiceSourceContract here and nothing is re-read. $sourceReference IS the Credit
     * Note number: idempotency and the (egs, environment, source_reference) unique index
     * work exactly as they do for a plain invoice, so calling this twice for the same
     * note number returns the same document rather than reserving a second ICV.
     *
     * Never called with a documentType other than CreditNote — see
     * XmlGeneratorFactory::forData(), which is what actually decides the generator, and
     * would emit a 388 for any other document_type value here.
     */
    public function generateCreditNote(ZatcaInvoiceData $invoiceData, string $egsIdentifier, int $creditNoteId): ZatcaInvoice
    {
        $this->assertEnabled('generate a ZATCA credit note');

        $invoice = $this->reserveAdjustmentNoteDraft($invoiceData, $egsIdentifier, ['credit_note_id' => $creditNoteId]);

        if ($invoice->status !== ZatcaInvoiceStatus::Draft) {
            return $invoice;
        }

        return $this->sign($invoice);
    }

    /**
     * Generates the technical ZATCA document for one Debit Note business record.
     *
     * Exactly symmetrical to generateCreditNote(), down to sharing its reservation
     * path: the only difference between the two documents at this level is which
     * business record the technical row links back to, and which UBL type code
     * XmlGeneratorFactory::forData() picks from the already-frozen
     * ZatcaInvoiceData::$documentType.
     *
     * In particular a Debit Note takes its ICV and PIH from the SAME EGS chain as
     * invoices and credit notes — there is no separate debit-note counter — which is
     * what keeps the EGS's hash chain a single, verifiable sequence.
     *
     * $invoiceData->sourceReference IS the Debit Note number, so idempotency and the
     * (egs, environment, source_reference) unique index work exactly as they do for a
     * plain invoice: calling this twice for the same note returns the same document
     * rather than reserving a second ICV.
     *
     * Never called with a documentType other than DebitNote — see
     * XmlGeneratorFactory::forData(), which is what actually decides the generator.
     */
    public function generateDebitNote(ZatcaInvoiceData $invoiceData, string $egsIdentifier, int $debitNoteId): ZatcaInvoice
    {
        $this->assertEnabled('generate a ZATCA debit note');

        $invoice = $this->reserveAdjustmentNoteDraft($invoiceData, $egsIdentifier, ['debit_note_id' => $debitNoteId]);

        if ($invoice->status !== ZatcaInvoiceStatus::Draft) {
            return $invoice;
        }

        return $this->sign($invoice);
    }

    /**
     * Builds a NEW ZATCA document to replace one ZATCA rejected on validation grounds,
     * and links it to the original.
     *
     * Deliberately not a mode of generate(), for three independent reasons:
     *
     *  1. generate() resolves an existing row by sourceReference() and returns it. For
     *     a known source that is always the original, so a replacement would never be
     *     created at all.
     *  2. generate() calls $source->toInvoiceData(), re-reading the host record. The
     *     host's prices, items or buyer details may have moved since the sale; a
     *     document built from them would not be a regeneration of this invoice but a
     *     different invoice wearing its number.
     *  3. Nothing in generate() expresses the relationship between a rejected document
     *     and its replacement, and that relationship — not a status — is what decides
     *     which document represents the invoice from here on.
     *
     * What is new: UUID, ICV, PIH, XML (from the CURRENT generator), hash, signature
     * and QR. What is frozen: every business value, taken from the original's snapshot.
     * What is untouched: the original row, in full, including its attempt history.
     *
     * @param  array{type: string, id: int|string}|null  $regeneratedBy  the admin who asked
     *   for this. Null means no human did — never guessed, never backfilled.
     */
    public function regenerate(ZatcaInvoice $original, ?array $regeneratedBy = null): ZatcaInvoice
    {
        $this->assertEnabled("regenerate ZATCA document #{$original->id}");

        if ($original->superseded_by_id !== null) {
            throw new ZatcaException(
                "ZATCA document #{$original->id} has already been superseded by #{$original->superseded_by_id}. "
                .'Regenerating it again would create a second replacement for the same rejected document.'
            );
        }

        if ($original->status?->isTerminalSuccess()) {
            throw new ZatcaException(
                "ZATCA document #{$original->id} has already been accepted by ZATCA. A reported invoice is "
                .'never regenerated.'
            );
        }

        if ($original->regenerationDepth() >= ZatcaInvoice::MAX_REGENERATION_CHAIN_DEPTH) {
            throw new ZatcaException(
                "ZATCA document #{$original->id} is already ".ZatcaInvoice::MAX_REGENERATION_CHAIN_DEPTH
                .' regenerations deep. Each attempt consumes an ICV slot permanently, so the chain stops here '
                .'rather than burning the sequence on a document the current generator cannot fix.'
            );
        }

        $egsConfig = $this->egsRegistry->for($original->egs_identifier);

        if (! $this->signingKeyProvider->hasProductionCredentials($original->egs_identifier, $egsConfig->environment)) {
            throw ZatcaCredentialsMissingException::forEgs($original->egs_identifier, ZatcaCredentialType::Production);
        }

        // The frozen business values. Throws when there is no snapshot rather than
        // falling back to the host record — see InvoiceDataSnapshot for why that
        // fallback would be the one genuinely dangerous thing this method could do.
        $invoiceData = InvoiceDataSnapshot::fromArray($original->invoice_data_snapshot);

        // Checked, never re-reconciled. Snapshots written since reserveDraft() started
        // reconciling are already settled, and an older one is frozen history: adjusting
        // it here would change the amounts of the document being replaced, which is the
        // one thing regeneration must never do. A snapshot that cannot pass is a document
        // that must not be rebuilt, so this stops rather than repairs.
        $this->invoiceDataValidator->validate($invoiceData);

        $replacement = $this->resumableReplacement($original, $invoiceData, $egsConfig)
            ?? $this->reserveReplacement($original, $invoiceData, $egsConfig, $regeneratedBy);

        if ($replacement->status === ZatcaInvoiceStatus::Draft) {
            $replacement = $this->sign($replacement);
        }

        return $this->linkSupersededBy($original, $replacement);
    }

    /**
     * Finishes signing a Draft whose generation stopped part-way.
     *
     * A Draft already holds everything that is scarce or irreversible: its ICV is
     * reserved, its UUID is assigned, its unsigned XML is built and its business values
     * are frozen. Only the SDK's output is missing — invoice_hash, signed_xml_base64 and
     * qr_code. This completes exactly that and nothing else.
     *
     * The one thing it must never do is create a document, and it cannot: it neither
     * reserves an ICV nor inserts a row nor reads the host record. It reaches the same
     * sign() the purchase flow reaches, on the row it was handed. So the invariant the
     * caller cares about — same UUID, same ICV, no second invoice — holds by construction
     * rather than by assertion.
     *
     * Also deliberately NOT a re-dispatch of GenerateZatcaInvoiceJob, which is the obvious
     * way to do this and the wrong one. That job is addressed by (source_type, source_id),
     * and zatca_invoices stores only source_reference — the opaque string the host mapper
     * chose, with no reverse route back to a class. Reconstructing one would mean teaching
     * this package the host's naming conventions, and it would still fail on a Draft whose
     * host record has since been deleted, even though such a Draft is perfectly signable
     * from what it already stores. Going straight to sign() has neither problem.
     *
     * Idempotent under concurrency without needing a lock of its own: sign() locks the row
     * and returns it untouched unless it is still Draft, so two callers racing produce one
     * signature, one chain-head commit and one event.
     *
     * Takes no actor. Signing has no actor column, and the thing an administrator's name
     * belongs on — the submission attempt that follows — is written by the reporting job,
     * which carries the actor itself.
     */
    public function completeGeneration(ZatcaInvoice $draft): ZatcaInvoice
    {
        $this->assertEnabled("complete signing of ZATCA document #{$draft->id}");

        if (! $draft->isUnsignedDraft()) {
            throw new ZatcaException(
                "ZATCA document #{$draft->id} is not an unsigned draft (status: "
                .($draft->status?->value ?? 'unknown').'). A document is signed once and never again: its '
                .'hash is committed to the EGS chain and everything after it links to that hash.'
            );
        }

        if ($draft->isAbandoned()) {
            throw new ZatcaException(
                "ZATCA document #{$draft->id} was abandoned because the current generator no longer produces "
                .'its XML. Signing it would emit the content a regeneration already replaced. Its ICV slot '
                .'stays consumed; regenerate the original instead.'
            );
        }

        if (blank($draft->unsigned_xml)) {
            throw new ZatcaException(
                "ZATCA document #{$draft->id} has no unsigned XML, so there is nothing to sign."
            );
        }

        $egsConfig = $this->egsRegistry->for($draft->egs_identifier);

        if (! $this->signingKeyProvider->hasProductionCredentials($draft->egs_identifier, $egsConfig->environment)) {
            throw ZatcaCredentialsMissingException::forEgs($draft->egs_identifier, ZatcaCredentialType::Production);
        }

        $signed = $this->sign($draft);

        // A Draft can also be an unsigned replacement left behind by a regeneration whose
        // signing failed. Completing it here has to close the same loop regenerate() would
        // have closed on its next run, or the rejected original would stay unsuperseded and
        // both documents would read as current for one sale.
        if ($signed->regenerated_from_id !== null) {
            $original = ZatcaInvoice::query()->find($signed->regenerated_from_id);

            if ($original) {
                $this->linkSupersededBy($original, $signed);
            }
        }

        return $signed;
    }

    /**
     * Step 1: idempotently reserves the ICV/UUID/PIH and persists a Draft row.
     * Safe against: retries after a prior Draft exists, and two concurrent
     * callers racing for the same sourceReference (unique constraint + retry).
     */
    private function reserveDraft(InvoiceSourceContract $source, mixed $sourceRecord, string $egsIdentifier): ZatcaInvoice
    {
        $egsConfig = $this->egsRegistry->for($egsIdentifier);
        $sourceReference = $source->sourceReference($sourceRecord);

        $existing = $this->findExisting($egsIdentifier, $egsConfig->environment->value, $sourceReference);

        if ($existing) {
            return $existing;
        }

        $this->assertNoDocumentOnAnotherEgs($egsIdentifier, $egsConfig->environment->value, $sourceReference);

        if (! $this->signingKeyProvider->hasProductionCredentials($egsIdentifier, $egsConfig->environment)) {
            throw ZatcaCredentialsMissingException::forEgs($egsIdentifier, ZatcaCredentialType::Production);
        }

        // Checked before an ICV slot is spent on it. The host's figures are taken
        // exactly as given — nothing here re-prices or re-rounds them — but an invoice
        // whose own lines do not add up to its own totals, or that carries a negative
        // line, stops here rather than becoming a document that cannot be withdrawn.
        $invoiceData = $source->toInvoiceData($sourceRecord, $egsIdentifier);
        $this->invoiceDataValidator->validate($invoiceData);

        $uuid = (string) Str::uuid();

        try {
            return DB::transaction(function () use ($egsIdentifier, $egsConfig, $sourceReference, $uuid, $invoiceData) {
                // Reserving ICV and inserting the Draft row happen in the SAME transaction:
                // if the insert fails below (e.g. a concurrent duplicate), the ICV increment
                // is rolled back with it via the same connection's savepoint.
                $reservation = $this->counterManager->reserve($egsIdentifier, $egsConfig->environment);

                $unsignedXml = $this->xmlGeneratorFactory->forData($invoiceData)->generate($invoiceData, $egsConfig, $uuid, $reservation);

                return ZatcaInvoice::create([
                    'egs_identifier' => $egsIdentifier,
                    'environment' => $egsConfig->environment->value,
                    'source_reference' => $sourceReference,
                    'owner_id' => $this->ownerResolver->resolve($egsIdentifier),
                    'document_type' => $invoiceData->documentType->value,
                    'submission_model' => $invoiceData->documentType->submissionModel()->value,
                    'uuid' => $uuid,
                    'icv' => $reservation->icv,
                    'previous_invoice_hash' => $reservation->previousInvoiceHash,
                    'unsigned_xml' => $unsignedXml,
                    // The business values behind this document, frozen at the moment it was
                    // built. Nothing reads them during normal reporting; they exist so the
                    // document can be re-chained or replaced later without re-reading (and
                    // therefore silently re-pricing) the host record.
                    'invoice_data_snapshot' => InvoiceDataSnapshot::toArray($invoiceData),
                    'total_excl_vat' => $invoiceData->totalExclVat,
                    'total_vat' => $invoiceData->totalVat,
                    'total_incl_vat' => $invoiceData->totalInclVat,
                    'currency' => $invoiceData->currency,
                    'status' => ZatcaInvoiceStatus::Draft,
                ]);
            });
        } catch (QueryException $e) {
            // Unique constraint race: another concurrent call already created the draft
            // for this exact source between our existence check and our insert.
            if (! $this->isDuplicateKeyViolation($e)) {
                throw $e;
            }

            $existing = $this->findExisting($egsIdentifier, $egsConfig->environment->value, $sourceReference);

            if ($existing) {
                return $existing;
            }

            // Lost the race to a document on another EGS (zatca_inv_env_src_uq).
            $this->assertNoDocumentOnAnotherEgs($egsIdentifier, $egsConfig->environment->value, $sourceReference);

            throw $e;
        }
    }

    /**
     * Adjustment-note counterpart of reserveDraft(), shared by Credit Notes (381) and
     * Debit Notes (383): idempotently reserves the ICV/UUID/PIH and persists a Draft
     * row, keyed by the NOTE's OWN source_reference (its number) rather than the
     * original invoice's.
     *
     * Deliberately not merged into reserveDraft() — that method calls
     * $source->toInvoiceData() and re-reads the host record; here the data is already
     * built by the note's own DataFactory from a frozen snapshot, and there is no
     * InvoiceSourceContract at all.
     *
     * Equally deliberately, ONE method rather than one per note kind. The two differ
     * by a single foreign key column, which is what $linkAttributes carries; writing
     * it twice would have meant two places to fix every future change to how a note's
     * draft is reserved.
     *
     * @param  array<string, int>  $linkAttributes  exactly one of ['credit_note_id' => id]
     *   or ['debit_note_id' => id] — the business record this technical row belongs to.
     */
    private function reserveAdjustmentNoteDraft(ZatcaInvoiceData $invoiceData, string $egsIdentifier, array $linkAttributes): ZatcaInvoice
    {
        $egsConfig = $this->egsRegistry->for($egsIdentifier);
        $sourceReference = $invoiceData->sourceReference;

        $existing = $this->findExisting($egsIdentifier, $egsConfig->environment->value, $sourceReference);

        if ($existing) {
            return $existing;
        }

        $this->assertNoDocumentOnAnotherEgs($egsIdentifier, $egsConfig->environment->value, $sourceReference);

        if (! $this->signingKeyProvider->hasProductionCredentials($egsIdentifier, $egsConfig->environment)) {
            throw ZatcaCredentialsMissingException::forEgs($egsIdentifier, ZatcaCredentialType::Production);
        }

        $this->invoiceDataValidator->validate($invoiceData);

        $uuid = (string) Str::uuid();

        try {
            return DB::transaction(function () use ($egsIdentifier, $egsConfig, $sourceReference, $uuid, $invoiceData, $linkAttributes) {
                $reservation = $this->counterManager->reserve($egsIdentifier, $egsConfig->environment);

                $unsignedXml = $this->xmlGeneratorFactory->forData($invoiceData)->generate($invoiceData, $egsConfig, $uuid, $reservation);

                return ZatcaInvoice::create([
                    'egs_identifier' => $egsIdentifier,
                    'environment' => $egsConfig->environment->value,
                    'source_reference' => $sourceReference,
                    'owner_id' => $this->ownerResolver->resolve($egsIdentifier),
                    'document_type' => $invoiceData->documentType->value,
                    'submission_model' => $invoiceData->documentType->submissionModel()->value,
                    ...$linkAttributes,
                    'uuid' => $uuid,
                    'icv' => $reservation->icv,
                    'previous_invoice_hash' => $reservation->previousInvoiceHash,
                    'unsigned_xml' => $unsignedXml,
                    'invoice_data_snapshot' => InvoiceDataSnapshot::toArray($invoiceData),
                    'total_excl_vat' => $invoiceData->totalExclVat,
                    'total_vat' => $invoiceData->totalVat,
                    'total_incl_vat' => $invoiceData->totalInclVat,
                    'currency' => $invoiceData->currency,
                    'status' => ZatcaInvoiceStatus::Draft,
                ]);
            });
        } catch (QueryException $e) {
            if (! $this->isDuplicateKeyViolation($e)) {
                throw $e;
            }

            $existing = $this->findExisting($egsIdentifier, $egsConfig->environment->value, $sourceReference);

            if ($existing) {
                return $existing;
            }

            $this->assertNoDocumentOnAnotherEgs($egsIdentifier, $egsConfig->environment->value, $sourceReference);

            throw $e;
        }
    }

    /**
     * Step 2: signs an already-persisted Draft row. Never re-reserves ICV/UUID.
     *
     * Resolving the hash-chain head, building the document, signing it and committing
     * the new head are ONE serialized unit per EGS/environment. They have to be.
     * Reserving a slot captures the head as it stands, but signing can happen much
     * later — a Draft survives a signing failure and is retried with a backoff that
     * reaches ten minutes. If another invoice is signed in that window, the captured
     * head is stale: two invoices end up carrying the same previous_invoice_hash and
     * the next one chains to the wrong predecessor. ZATCA validates that chain, so the
     * consequence is not one bad invoice but every invoice after it.
     *
     * The fix is to read the head under lock and hold it until the new head is
     * committed. When the head has moved since reservation the document is re-chained
     * (see rechain()) rather than signed as-is.
     *
     * The cost is that signing is serialized per EGS: the SDK is a local subprocess
     * bounded by zatca.sdk.process_timeout, so the lock is typically held for about a
     * second. A competing generation job that exceeds the database's lock wait simply
     * fails and is retried by its existing tries/backoff policy. Serializing signing
     * is the price of a correct chain, and it is the right trade at any invoice volume
     * this application will see.
     */
    private function sign(ZatcaInvoice $invoice): ZatcaInvoice
    {
        $egsConfig = $this->egsRegistry->for($invoice->egs_identifier);
        $didSign = false;

        $result = DB::transaction(function () use ($invoice, $egsConfig, &$didSign) {
            // Lock order is always chain state first, then the invoice — the same order
            // reserveDraft() takes, so the two paths cannot deadlock against each other.
            $chainHead = $this->pihManager->lockCurrent($invoice->egs_identifier, $egsConfig->environment);

            $locked = ZatcaInvoice::query()->lockForUpdate()->find($invoice->id);

            if (! $locked) {
                return $invoice;
            }

            if ($locked->status !== ZatcaInvoiceStatus::Draft) {
                return $locked; // Another worker already signed it — never overwrite.
            }

            [$unsignedXml, $previousInvoiceHash] = $this->rechain($locked, $chainHead);

            [$certPath, $keyPath, $xmlPath, $cleanup] = $this->signingKeyProvider->prepareSigningWorkspace(
                $locked->egs_identifier,
                $egsConfig->environment,
                ZatcaCredentialType::Production,
                $unsignedXml
            );

            try {
                $signed = $this->sdk->signInvoice($xmlPath, $certPath, $keyPath);
            } finally {
                $cleanup();
            }

            // The new head and the signed document are committed together, under the lock
            // taken above, so no other invoice can be built from a head this one is about
            // to replace.
            $this->pihManager->commit($locked->egs_identifier, $egsConfig->environment, $signed['invoiceHash']);

            $locked->update([
                'unsigned_xml' => $unsignedXml,
                'previous_invoice_hash' => $previousInvoiceHash,
                'invoice_hash' => $signed['invoiceHash'],
                'signed_xml_base64' => $signed['signedXmlBase64'],
                'qr_code' => $signed['qrCode'],
                // Each submission model has its own "signed, waiting to be sent" status, so
                // nothing that selects by status can hand a Standard document to Reporting.
                'status' => $locked->submission_model === ZatcaSubmissionModel::Clearance
                    ? ZatcaInvoiceStatus::PendingClearance
                    : ZatcaInvoiceStatus::PendingReporting,
            ]);

            $didSign = true;

            return $locked->fresh();
        });

        // Only when this call is the one that signed. On the race path above another
        // worker already signed and already announced it; announcing again would have
        // listeners act twice on one invoice.
        if ($didSign) {
            Event::dispatch(new ZatcaInvoiceGenerated($result));
        }

        return $result;
    }

    /**
     * Re-points a Draft at the current end of the hash chain when the chain moved on
     * between reserving its slot and signing it.
     *
     * Only the PIH node is rewritten. Everything else about the document — its UUID,
     * ICV, amounts, lines, and the signature and QR placeholders the SDK locates by
     * position — is left byte-identical, because none of it is wrong. Rebuilding the
     * whole document from the business snapshot would work too, but it would also
     * quietly adopt any generator change since the Draft was built, and it cannot help
     * a Draft that predates snapshot recording. A targeted swap has neither problem.
     *
     * @return array{0: string, 1: string} the XML to sign, and the PIH it now carries
     */
    private function rechain(ZatcaInvoice $draft, ?string $chainHead): array
    {
        $stored = (string) $draft->previous_invoice_hash;

        // No state row yet (first invoice for this EGS), or nothing has been signed
        // since this Draft reserved its slot.
        if ($chainHead === null || $chainHead === $stored) {
            return [$draft->unsigned_xml, $stored];
        }

        Log::warning(
            "[ZATCA] Re-chaining draft invoice #{$draft->id} before signing: the EGS hash chain advanced while "
            .'this draft was unsigned. Signing it with its original previous-invoice-hash would have forked the chain.',
            ['egs_identifier' => $draft->egs_identifier, 'icv' => $draft->icv]
        );

        return [$this->xmlReader->withPreviousInvoiceHash($draft->unsigned_xml, $chainHead), $chainHead];
    }

    /**
     * An unsigned replacement left behind by an earlier regeneration attempt, if
     * resuming it is still correct.
     *
     * A Draft already owns a reserved ICV. Reserving a second one for the same rejected
     * document would consume two slots to produce one invoice, so a previous attempt
     * that failed while signing is resumed rather than replaced — same UUID, same ICV,
     * same document.
     *
     * "Still correct" is decided by content, not by which exception was thrown last
     * time. The Draft's XML is rebuilt from the same business snapshot, using the
     * Draft's OWN uuid, icv and PIH so that generator output is the only thing that can
     * differ, and compared:
     *
     *  - identical  -> the current generator still produces this exact document, so
     *                  whatever failed before was environmental (SDK crash, timeout,
     *                  missing jar). Resume it.
     *  - different  -> the generator has changed since. Re-signing the old XML would
     *                  re-emit the very document this regeneration exists to replace,
     *                  which is worse than spending another ICV. Abandon it.
     *
     * This works because the XML generator is a pure function of its inputs — no clock,
     * no randomness — so a difference can only mean the generator itself changed.
     *
     * An abandoned Draft keeps its consumed ICV and is never reconsidered.
     */
    private function resumableReplacement(ZatcaInvoice $original, ZatcaInvoiceData $invoiceData, EgsConfig $egsConfig): ?ZatcaInvoice
    {
        $draft = ZatcaInvoice::query()
            ->where('regenerated_from_id', $original->getKey())
            ->where('status', ZatcaInvoiceStatus::Draft->value)
            ->whereNull('abandoned_at')
            ->orderByDesc('id')
            ->first();

        if (! $draft) {
            return null;
        }

        $current = $this->xmlGeneratorFactory->forData($invoiceData)->generate(
            $invoiceData,
            $egsConfig,
            $draft->uuid,
            new InvoiceSequenceReservation($draft->icv, (string) $draft->previous_invoice_hash),
        );

        if ($current === $draft->unsigned_xml) {
            Log::info(
                "[ZATCA] Resuming unsigned replacement #{$draft->id} for rejected document #{$original->id} "
                .'instead of reserving another ICV: the current generator reproduces its XML exactly.',
                ['icv' => $draft->icv]
            );

            return $draft;
        }

        $draft->update(['abandoned_at' => now()]);

        Log::warning(
            "[ZATCA] Abandoned unsigned replacement #{$draft->id} for rejected document #{$original->id}: the XML "
            .'generator no longer produces its document, so signing it would re-emit the rejected content. Its ICV '
            .'slot stays consumed and a fresh replacement will be built.',
            ['icv' => $draft->icv]
        );

        return null;
    }

    /** Reserves ICV/UUID/PIH for a brand-new replacement and persists it as a Draft. */
    private function reserveReplacement(
        ZatcaInvoice $original,
        ZatcaInvoiceData $invoiceData,
        EgsConfig $egsConfig,
        ?array $regeneratedBy
    ): ZatcaInvoice {
        $uuid = (string) Str::uuid();

        return DB::transaction(function () use ($original, $invoiceData, $egsConfig, $uuid, $regeneratedBy) {
            // Same atomicity as reserveDraft(): if the insert fails, the ICV increment
            // rolls back with it rather than leaving a gap in the sequence.
            $reservation = $this->counterManager->reserve($original->egs_identifier, $egsConfig->environment);

            $unsignedXml = $this->xmlGeneratorFactory->forData($invoiceData)->generate($invoiceData, $egsConfig, $uuid, $reservation);

            return ZatcaInvoice::create([
                'egs_identifier' => $original->egs_identifier,
                'environment' => $egsConfig->environment->value,
                'source_reference' => $this->nextReplacementReference($original),
                'owner_id' => $original->owner_id,
                'document_type' => $original->document_type,
                'submission_model' => $original->submission_model,
                // Carried across so a regenerated note's replacement still belongs to the
                // same business record — for both note kinds. Exactly one of these is ever
                // non-null on any row.
                'credit_note_id' => $original->credit_note_id,
                'debit_note_id' => $original->debit_note_id,
                'uuid' => $uuid,
                'icv' => $reservation->icv,
                'previous_invoice_hash' => $reservation->previousInvoiceHash,
                'unsigned_xml' => $unsignedXml,
                // Carried across unchanged, so the replacement can itself be re-chained
                // or replaced later from the same frozen values.
                'invoice_data_snapshot' => InvoiceDataSnapshot::toArray($invoiceData),
                'total_excl_vat' => $invoiceData->totalExclVat,
                'total_vat' => $invoiceData->totalVat,
                'total_incl_vat' => $invoiceData->totalInclVat,
                'currency' => $invoiceData->currency,
                'status' => ZatcaInvoiceStatus::Draft,
                'regenerated_from_id' => $original->getKey(),
                'regenerated_by_type' => $regeneratedBy['type'] ?? null,
                'regenerated_by_id' => $regeneratedBy['id'] ?? null,
            ]);
        });
    }

    /**
     * Records that the replacement has taken over from the original.
     *
     * Written only once the replacement is actually signed. Until then the original is
     * still the only submittable document for this invoice, and marking it superseded
     * would leave the invoice pointing at something that cannot be reported. The
     * conditional keeps the first writer's link if two callers ever get this far.
     */
    private function linkSupersededBy(ZatcaInvoice $original, ZatcaInvoice $replacement): ZatcaInvoice
    {
        if (blank($replacement->signed_xml_base64)) {
            return $replacement;
        }

        ZatcaInvoice::query()
            ->whereKey($original->getKey())
            ->whereNull('superseded_by_id')
            ->update(['superseded_by_id' => $replacement->getKey()]);

        return $replacement;
    }

    /**
     * The next free source_reference for a replacement of this document.
     *
     * Derived from the ROOT reference, so a replacement of a replacement reads
     * "payment:412#r2" rather than accumulating suffixes. Takes the highest existing
     * suffix rather than counting rows, so an abandoned Draft's reference is never
     * handed out twice.
     */
    private function nextReplacementReference(ZatcaInvoice $original): string
    {
        $root = Str::before($original->source_reference, self::REPLACEMENT_SUFFIX);

        $highest = 0;

        $existing = ZatcaInvoice::query()
            ->where('egs_identifier', $original->egs_identifier)
            ->where('environment', $original->environment->value)
            ->where('source_reference', 'like', $root.self::REPLACEMENT_SUFFIX.'%')
            ->pluck('source_reference');

        foreach ($existing as $reference) {
            $suffix = (int) Str::after($reference, self::REPLACEMENT_SUFFIX);

            if ($suffix > $highest) {
                $highest = $suffix;
            }
        }

        return $root.self::REPLACEMENT_SUFFIX.($highest + 1);
    }

    private function findExisting(string $egsIdentifier, string $environment, string $sourceReference): ?ZatcaInvoice
    {
        return ZatcaInvoice::query()
            ->where('egs_identifier', $egsIdentifier)
            ->where('environment', $environment)
            ->where('source_reference', $sourceReference)
            ->first();
    }

    /**
     * One sale, one tax document, whichever EGS signs it. Checked before credentials or
     * an ICV slot are touched; the unique index zatca_inv_env_src_uq is the final arbiter
     * for a race. The other EGS's document is never returned in place of this one.
     */
    private function assertNoDocumentOnAnotherEgs(string $egsIdentifier, string $environment, string $sourceReference): void
    {
        $other = ZatcaInvoice::query()
            ->where('environment', $environment)
            ->where('source_reference', $sourceReference)
            ->where('egs_identifier', '!=', $egsIdentifier)
            ->value('egs_identifier');

        if ($other !== null) {
            throw CrossEgsDuplicateException::for($sourceReference, $environment, $egsIdentifier, (string) $other);
        }
    }

    private function isDuplicateKeyViolation(QueryException $e): bool
    {
        // MySQL: SQLSTATE 23000, driver error code 1062 ("Duplicate entry").
        return $e->getCode() === '23000' || str_contains($e->getMessage(), '1062');
    }
}

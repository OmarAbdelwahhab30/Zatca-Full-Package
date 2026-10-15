<?php

namespace Zatca\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Contracts\InvoiceSourceResolverContract;
use Zatca\DTOs\InvoiceSourceReference;
use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Jobs\ClearZatcaInvoiceJob;
use Zatca\Jobs\ReportZatcaInvoiceJob;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\Models\ZatcaInvoice;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Services\Generation\DraftCompletionPolicy;
use Zatca\State\EgsRegistry;
use Zatca\Support\B2bReadiness;
use Zatca\Support\SubmissionRetry;
use Zatca\Support\ZatcaGate;

/**
 * Safely finishes the ZATCA side of ONE sale after a technical failure stopped it part-way:
 * the SDK jar was missing, a temp directory was not writable, ZATCA was down, a worker died
 * between signing and sending.
 *
 * It looks up the sale's ZATCA document and moves it exactly one step forward along the
 * normal pipeline, then waits for the answer so the operator sees the outcome:
 *
 *  - no document yet       -> ProductionInvoiceOrchestrator::generate() (reserve, sign)
 *  - unsigned draft        -> ProductionInvoiceOrchestrator::completeGeneration(), same ICV/UUID
 *  - signed, not accepted  -> ReportZatcaInvoiceJob (Simplified/B2C) or ClearZatcaInvoiceJob
 *                             (Standard/B2B), chosen from the document's own submission_model
 *  - accepted              -> nothing
 *
 * Every step is a building block the live pipeline already uses, and each is idempotent:
 * an existing document is resumed, never duplicated; a signed document is never signed
 * again; an accepted document is never sent again.
 *
 * It deliberately never regenerates. A document ZATCA rejected for its content needs a fix
 * and a replacement document (RegenerateZatcaInvoiceJob), and a replacement uses a new ICV
 * that can never be given back — that is a human decision, not a recovery step. For the
 * same reason a rejected document is never resent: the same bytes would fail the same way.
 * Only a failure ZATCA never answered (HTTP 0 or 5xx) is resent, matching
 * `zatca:requeue --only-transient`.
 *
 * Source-agnostic: the sale is found through the host's InvoiceSourceResolverContract and
 * InvoiceSourceContract, the same contracts GenerateZatcaInvoiceJob uses, so it works for an
 * order, a payment, a booking or anything else the host maps. Host-side follow-ups (the
 * host's own invoice record, receipts, emails) are not this package's business and are not
 * touched.
 */
class RecoverZatcaInvoice extends Command
{
    protected $signature = 'zatca:recover
        {--source-type= : Source type your InvoiceSourceResolverContract understands (by default an Eloquent model class)}
        {--source-id= : The source record\'s id}
        {--id= : A zatca_invoices id. Recovers an existing ZATCA document without loading the source record}
        {--egs= : EGS to sign a NEW document on. Required when a B2B EGS is configured. Ignored for an existing document, which keeps its own EGS}
        {--resend-in-doubt : Allow resending a Standard (B2B) document whose last clearance attempt got no answer (clearance_in_doubt)}
        {--dry-run : Show the state and the planned action only. No writes, no signing, no ZATCA calls}';

    protected $description = "Safely resume one sale's ZATCA document (sign and report/clear) after a technical failure, reusing the normal pipeline.";

    private const STAGE_GENERATE = 'generation';

    private const STAGE_COMPLETE = 'draft_completion';

    private const STAGE_SUBMIT = 'submission';

    private const ACTION_GENERATE = 'generate';

    private const ACTION_COMPLETE = 'complete_draft';

    private const ACTION_SUBMIT = 'submit';

    private const ACTION_NONE = 'none';

    private const ACTION_BLOCKED = 'blocked';

    /** Seconds the per-sale lock is held at most, and how long to wait for it. */
    private const LOCK_SECONDS = 300;

    private const LOCK_WAIT_SECONDS = 5;

    public function handle(
        ZatcaGate $gate,
        EgsRegistry $egsRegistry,
        ProductionInvoiceOrchestrator $orchestrator,
        DraftCompletionPolicy $draftCompletionPolicy,
    ): int {
        $sourceType = $this->option('source-type');
        $sourceId = $this->option('source-id');
        $documentId = $this->option('id');
        $dryRun = (bool) $this->option('dry-run');

        $bySource = $sourceType !== null || $sourceId !== null;

        if ($bySource === ($documentId !== null)) {
            $this->error('Specify either --source-type and --source-id, or --id. Not both, not neither.');
            $this->printUsage();

            return self::FAILURE;
        }

        if ($bySource && (blank($sourceType) || blank($sourceId))) {
            $this->error('--source-type and --source-id must be given together.');

            return self::FAILURE;
        }

        Log::info('[ZATCA][recover] Command invoked.', [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'zatca_invoice_id' => $documentId,
            'dry_run' => $dryRun,
        ]);

        $source = null;
        $sourceRecord = null;

        if ($bySource) {
            if (! $this->laravel->bound(InvoiceSourceContract::class)) {
                $this->error('No InvoiceSourceContract is bound. Bind your implementation in a service provider (see the README) before recovering by source.');

                return self::FAILURE;
            }

            $this->line("Resolving source [{$sourceType}#{$sourceId}]...");
            $sourceRecord = $this->laravel->make(InvoiceSourceResolverContract::class)
                ->resolve(new InvoiceSourceReference((string) $sourceType, (string) $sourceId));

            if ($sourceRecord === null) {
                $this->error("Source [{$sourceType}#{$sourceId}] was not found by your InvoiceSourceResolverContract. Nothing was modified.");

                return self::FAILURE;
            }

            $source = $this->laravel->make(InvoiceSourceContract::class);
            $sourceReference = $source->sourceReference($sourceRecord);
        } else {
            $this->line("Loading ZATCA document #{$documentId}...");
            $document = ZatcaInvoice::query()->find((int) $documentId);

            if (! $document) {
                $this->error("ZATCA document #{$documentId} not found. Nothing was modified.");

                return self::FAILURE;
            }

            // A replacement carries "<reference>#rN"; recovery is always about the sale, so
            // start from the original the chain grew from.
            $sourceReference = $this->rootOf($document)->source_reference;
        }

        $this->line("Source reference: {$sourceReference}");

        $invoice = $this->resolveInvoiceOrAnomaly($sourceReference);

        if ($invoice === false) {
            return self::FAILURE;
        }

        $this->printInvoiceState($invoice, $sourceReference);

        if ($gate->disabled()) {
            $this->line('');
            $this->warn('ZATCA invoicing is disabled (zatca.enabled=false). Nothing was done. Turn ZATCA back on, then run this again.');

            return self::FAILURE;
        }

        $plan = $this->plan($invoice, $source !== null, $gate, $egsRegistry, $draftCompletionPolicy);

        if ($dryRun) {
            $this->printDryRunPlan($plan);

            return $plan['action'] === self::ACTION_BLOCKED ? self::FAILURE : self::SUCCESS;
        }

        if ($plan['action'] === self::ACTION_BLOCKED) {
            $this->line('');
            $this->error('Recovery stopped: '.$plan['message']);

            Log::warning('[ZATCA][recover] Recovery blocked.', [
                'source_reference' => $sourceReference,
                'zatca_invoice_id' => $invoice?->id,
                'reason' => $plan['message'],
            ]);

            return self::FAILURE;
        }

        if ($plan['action'] === self::ACTION_NONE) {
            $this->line('');
            $this->info($plan['message']);

            return self::SUCCESS;
        }

        $lock = $this->acquireLock($sourceReference);

        if (! $lock) {
            return self::FAILURE;
        }

        try {
            // Re-read under the lock: the document may have moved on (a worker, the
            // scheduler, another operator) between the first read and taking the lock.
            $invoice = $this->resolveInvoiceOrAnomaly($sourceReference, quiet: true);

            if ($invoice === false) {
                return self::FAILURE;
            }

            $plan = $this->plan($invoice, $source !== null, $gate, $egsRegistry, $draftCompletionPolicy);

            return $this->executePlan($plan, $invoice, $source, $sourceRecord, $sourceReference, $orchestrator);
        } finally {
            $lock->release();
        }
    }

    // -------------------------------------------------------------------------
    // Deciding what to do
    // -------------------------------------------------------------------------

    /**
     * The single decision of what recovery may do for this document, shared by the dry run
     * and the real run so the two can never disagree.
     *
     * @return array{action: string, message: string, egs?: string}
     */
    private function plan(
        ?ZatcaInvoice $invoice,
        bool $hasSourceRecord,
        ZatcaGate $gate,
        EgsRegistry $egsRegistry,
        DraftCompletionPolicy $draftCompletionPolicy,
    ): array {
        if (! $invoice) {
            if (! $hasSourceRecord) {
                return $this->blocked('there is no ZATCA document for this sale, and creating one needs the source record. Run again with --source-type and --source-id.');
            }

            return $this->planNewDocument($gate, $egsRegistry);
        }

        $requestedEgs = $this->option('egs');

        if (filled($requestedEgs) && $requestedEgs !== $invoice->egs_identifier) {
            return $this->blocked("document #{$invoice->id} belongs to EGS [{$invoice->egs_identifier}], not [{$requestedEgs}]. An existing document always stays on its own EGS; drop --egs.");
        }

        if ($invoice->status->isTerminalSuccess()) {
            return [
                'action' => self::ACTION_NONE,
                'message' => "Document #{$invoice->id} is already ".(SubmissionRetry::isClearance($invoice) ? 'cleared' : 'reported').' by ZATCA. Nothing to recover.',
            ];
        }

        if ($invoice->isRegenerationInFlight()) {
            return $this->blocked("a regeneration of document #{$invoice->id} is in progress. Wait for it to finish, then inspect the result.");
        }

        if ($invoice->isUnsignedDraft()) {
            $verdict = $draftCompletionPolicy->verdict($invoice);

            if (! $verdict->eligible) {
                return $this->blocked("draft #{$invoice->id} cannot be signed right now ({$verdict->reason}).");
            }

            return [
                'action' => self::ACTION_COMPLETE,
                'message' => "Sign the existing draft #{$invoice->id} (same UUID and ICV {$invoice->icv}), then ".$this->submissionVerb($invoice).' it.',
            ];
        }

        if (blank($invoice->signed_xml_base64)) {
            return $this->blocked("document #{$invoice->id} is in status [{$invoice->status->value}] but has no signed XML. This is not a state the pipeline produces; inspect it with zatca:inspect.");
        }

        if ($this->certificateExpired($invoice)) {
            return $this->blocked("the production certificate for EGS [{$invoice->egs_identifier}] has expired. Renew it first; nothing can be sent until then.");
        }

        return $this->planResubmission($invoice);
    }

    /** @return array{action: string, message: string, egs?: string} */
    private function planNewDocument(ZatcaGate $gate, EgsRegistry $egsRegistry): array
    {
        $b2bEgs = (new B2bReadiness(gate: $gate, egsRegistry: $egsRegistry))->configuredEgsIdentifier();
        $b2bConfigured = $b2bEgs !== null;
        $requestedEgs = $this->option('egs');

        if (blank($requestedEgs)) {
            if ($b2bConfigured) {
                // Which device a sale belongs to (B2C or B2B) is host knowledge. Guessing
                // wrong signs the sale as the wrong kind of document and spends an ICV on it.
                return $this->blocked('a B2B EGS is configured, so this command cannot tell whether this sale is B2C or B2B. Run again with --egs=<identifier> for the EGS your app signs this sale on.');
            }

            $egs = $egsRegistry->primary()->egsIdentifier;
        } else {
            if (! in_array($requestedEgs, $egsRegistry->identifiers(), true)) {
                return $this->blocked("EGS [{$requestedEgs}] is not configured. Configured: ".implode(', ', $egsRegistry->identifiers()).'.');
            }

            $egs = $requestedEgs;
        }

        if ($b2bConfigured && $egs === $b2bEgs && $gate->b2bDisabled()) {
            return $this->blocked('B2B invoicing is switched off (ZATCA_B2B_ENABLED), so no new B2B document may be created.');
        }

        return [
            'action' => self::ACTION_GENERATE,
            'message' => "Create the ZATCA document on EGS [{$egs}]: reserve a new ICV and UUID, build the XML, sign it, then send it to ZATCA.",
            'egs' => $egs,
        ];
    }

    /** @return array{action: string, message: string} */
    private function planResubmission(ZatcaInvoice $invoice): array
    {
        $status = $invoice->status;
        $verb = $this->submissionVerb($invoice);
        $resend = [
            'action' => self::ACTION_SUBMIT,
            'message' => "Send the stored signed document #{$invoice->id} to ZATCA again ({$verb}). No re-signing: same UUID, ICV and hash.",
        ];

        if (SubmissionRetry::isClearance($invoice)) {
            if (in_array($status, [ZatcaInvoiceStatus::PendingClearance, ZatcaInvoiceStatus::ClearanceRetrying], true)) {
                return $resend;
            }

            if ($status === ZatcaInvoiceStatus::ClearanceInDoubt) {
                // Clearance is not naturally idempotent, and what ZATCA does with a resent
                // document after a timeout is not documented (gate G-07). Only a person who
                // has checked may resend it.
                return $this->option('resend-in-doubt')
                    ? $resend
                    : $this->blocked("document #{$invoice->id} is clearance_in_doubt: it was sent but ZATCA never answered, so it may already be cleared. Check it on the ZATCA side, then run again with --resend-in-doubt if it was not received.");
            }

            if ($status === ZatcaInvoiceStatus::ClearanceManualReview) {
                return $this->lastFailureWasTransient($invoice) ? $resend : $this->rejected($invoice);
            }

            return $this->rejected($invoice);
        }

        if ($status->isRetryable()) {
            return $resend;
        }

        if ($status === ZatcaInvoiceStatus::ManualReview) {
            return $this->lastFailureWasTransient($invoice) ? $resend : $this->rejected($invoice);
        }

        return $this->blocked("document #{$invoice->id} is in status [{$status->value}], which recovery does not resend.");
    }

    /** @return array{action: string, message: string} */
    private function rejected(ZatcaInvoice $invoice): array
    {
        return $this->blocked(
            "ZATCA rejected document #{$invoice->id} ({$invoice->status->value}); sending the same document again would fail the same way. "
            .'Read the reason with `zatca:inspect invoice --id='.$invoice->id.'`, fix the cause, then regenerate it '
            .'(RegenerateZatcaInvoiceJob). Recovery never regenerates: a replacement uses a new ICV that can never be given back.'
        );
    }

    /** @return array{action: string, message: string} */
    private function blocked(string $message): array
    {
        return ['action' => self::ACTION_BLOCKED, 'message' => $message];
    }

    /**
     * Same rule as `zatca:requeue --only-transient`: only a failure ZATCA never answered
     * (no response, or a 5xx) is a reason to send the same bytes again. No attempt on
     * record proves nothing, so it does not count.
     */
    private function lastFailureWasTransient(ZatcaInvoice $invoice): bool
    {
        $httpStatus = $invoice->submissionAttempts()->orderByDesc('attempt_number')->value('http_status');

        if ($httpStatus === null) {
            return false;
        }

        return (int) $httpStatus === 0 || (int) $httpStatus >= 500;
    }

    /** Same check the reporting and clearance jobs make before sending anything. */
    private function certificateExpired(ZatcaInvoice $invoice): bool
    {
        $state = ZatcaEgsCredential::query()
            ->where('egs_identifier', $invoice->egs_identifier)
            ->where('environment', $invoice->environment->value)
            ->where('credential_type', ZatcaCredentialType::Production->value)
            ->value('certificate_state');

        $value = $state instanceof ZatcaCertificateState ? $state->value : $state;

        return $value === ZatcaCertificateState::Expired->value;
    }

    // -------------------------------------------------------------------------
    // Doing it
    // -------------------------------------------------------------------------

    /** @param  array{action: string, message: string, egs?: string}  $plan */
    private function executePlan(
        array $plan,
        ?ZatcaInvoice $invoice,
        ?InvoiceSourceContract $source,
        mixed $sourceRecord,
        string $sourceReference,
        ProductionInvoiceOrchestrator $orchestrator,
    ): int {
        $this->line('');

        if ($plan['action'] === self::ACTION_BLOCKED) {
            $this->error('Recovery stopped: '.$plan['message']);

            return self::FAILURE;
        }

        if ($plan['action'] === self::ACTION_NONE) {
            $this->info($plan['message']);

            return self::SUCCESS;
        }

        $this->info('Recovering...');
        $stage = $plan['action'] === self::ACTION_GENERATE ? self::STAGE_GENERATE : self::STAGE_COMPLETE;

        try {
            if ($plan['action'] === self::ACTION_GENERATE) {
                $this->line("  Creating and signing the document on EGS [{$plan['egs']}]...");
                Log::info('[ZATCA][recover] Stage entered.', ['stage' => $stage, 'source_reference' => $sourceReference, 'egs_identifier' => $plan['egs']]);

                $invoice = $orchestrator->generate($source, $sourceRecord, $plan['egs']);

                $this->line("  Signing: OK. Document #{$invoice->id}, ICV {$invoice->icv}, status {$invoice->status->value}.");
                Log::info('[ZATCA][recover] Stage completed.', ['stage' => $stage, 'zatca_invoice_id' => $invoice->id, 'icv' => $invoice->icv, 'status' => $invoice->status->value]);
            } elseif ($plan['action'] === self::ACTION_COMPLETE) {
                $invoice = $this->completeDraft($invoice, $orchestrator);
            }

            if ($invoice->status->isTerminalSuccess()) {
                return $this->finish($invoice);
            }

            if ($invoice->isUnsignedDraft()) {
                $this->warn("  Document #{$invoice->id} is still unsigned; another process holds it. Nothing was sent. Run again shortly.");

                return $this->finish($invoice);
            }

            $stage = self::STAGE_SUBMIT;
            $invoice = $this->submit($invoice);
        } catch (Throwable $e) {
            // A Draft may have been reserved moments before the failure; re-read so the
            // report shows what actually exists now rather than a stale value.
            $invoice = ZatcaInvoice::query()->where('source_reference', $sourceReference)->first()?->authoritative() ?? $invoice;
            $this->reportStageFailure($stage, $invoice?->id, $e);
            $this->line('');
            $this->error('Recovery failed. Whatever already succeeded is kept. It is safe to run this command again; it continues from where it stopped.');
            $this->printFinalState($invoice);

            return self::FAILURE;
        }

        return $this->finish($invoice);
    }

    /**
     * Signs an unsigned draft (an original or a regeneration's replacement) through the same
     * path as CompleteZatcaInvoiceGenerationJob, but synchronously so the operator sees the
     * outcome. The generation claim is taken exactly as the dashboard's completion action
     * takes it, so the two can never sign the same draft at once.
     */
    private function completeDraft(ZatcaInvoice $draft, ProductionInvoiceOrchestrator $orchestrator): ZatcaInvoice
    {
        $this->line("  Signing the existing draft #{$draft->id} (same UUID and ICV {$draft->icv})...");
        Log::info('[ZATCA][recover] Stage entered.', ['stage' => self::STAGE_COMPLETE, 'zatca_invoice_id' => $draft->id]);

        $claimed = ZatcaInvoice::query()
            ->whereKey($draft->getKey())
            ->where('status', ZatcaInvoiceStatus::Draft->value)
            ->whereNull('signed_xml_base64')
            ->whereNull('generation_claimed_at')
            ->whereNull('regeneration_claimed_at')
            ->update(['generation_claimed_at' => now()]);

        if ($claimed === 0) {
            $this->warn("  Draft #{$draft->id} was claimed by another process just now. Nothing was signed.");

            return $draft->fresh();
        }

        try {
            $signed = $orchestrator->completeGeneration($draft->fresh());
        } catch (Throwable $e) {
            // Same column and same guard as CompleteZatcaInvoiceGenerationJob, so the reason
            // is visible wherever the host shows last_error, and can never land on a
            // document that was signed in the meantime.
            ZatcaInvoice::query()
                ->whereKey($draft->getKey())
                ->where('status', ZatcaInvoiceStatus::Draft->value)
                ->whereNull('signed_xml_base64')
                ->update(['last_error' => mb_substr('Draft completion failed: '.$e->getMessage(), 0, 1000)]);

            throw $e;
        } finally {
            ZatcaInvoice::query()->whereKey($draft->getKey())->update(['generation_claimed_at' => null]);
        }

        $this->line("  Signing: OK. Status {$signed->status->value}.");
        Log::info('[ZATCA][recover] Stage completed.', ['stage' => self::STAGE_COMPLETE, 'zatca_invoice_id' => $signed->id, 'status' => $signed->status->value]);

        return $signed->fresh();
    }

    /**
     * Sends a signed document once, synchronously, by its own submission model: a Standard
     * document is cleared and a Simplified one reported. The job itself refuses a document
     * ZATCA already accepted.
     */
    private function submit(ZatcaInvoice $invoice): ZatcaInvoice
    {
        $isClearance = SubmissionRetry::isClearance($invoice);
        $this->line('  '.($isClearance ? 'Sending for clearance (Standard / B2B)...' : 'Reporting (Simplified / B2C)...'));
        Log::info('[ZATCA][recover] Stage entered.', ['stage' => self::STAGE_SUBMIT, 'zatca_invoice_id' => $invoice->id, 'submission_model' => $invoice->submission_model->value]);

        if ($isClearance) {
            ClearZatcaInvoiceJob::dispatchSync($invoice->id);
        } else {
            ReportZatcaInvoiceJob::dispatchSync($invoice->id);
        }

        $invoice = ZatcaInvoice::query()->findOrFail($invoice->id);

        $this->line('  '.($isClearance ? 'Clearance' : 'Reporting').": status is now {$invoice->status->value}.");
        Log::info('[ZATCA][recover] Stage completed.', [
            'stage' => self::STAGE_SUBMIT,
            'zatca_invoice_id' => $invoice->id,
            'status' => $invoice->status->value,
            'attempt_count' => $invoice->attempt_count,
        ]);

        return $invoice;
    }

    /** Success means ZATCA accepted the document; anything short of that is reported as not done. */
    private function finish(ZatcaInvoice $invoice): int
    {
        $this->line('');

        if ($invoice->status->isTerminalSuccess()) {
            $this->info('Recovery completed: ZATCA accepted the document.');
            $this->printFinalState($invoice);

            Log::info('[ZATCA][recover] Recovery completed.', ['zatca_invoice_id' => $invoice->id, 'status' => $invoice->status->value]);

            return self::SUCCESS;
        }

        $this->warn('Recovery ran, but ZATCA has not accepted the document yet.');
        $this->line('  '.$this->nextStepFor($invoice));
        $this->printFinalState($invoice);

        Log::warning('[ZATCA][recover] Recovery ended without acceptance.', ['zatca_invoice_id' => $invoice->id, 'status' => $invoice->status->value]);

        return self::FAILURE;
    }

    private function nextStepFor(ZatcaInvoice $invoice): string
    {
        return match ($invoice->status) {
            ZatcaInvoiceStatus::Retrying, ZatcaInvoiceStatus::ClearanceRetrying => 'ZATCA did not answer. The scheduled zatca:report-pending sweep will retry it; nothing else to do now.',
            ZatcaInvoiceStatus::ClearanceInDoubt => 'ZATCA did not answer, so it is unknown whether the document was cleared. Check before resending.',
            ZatcaInvoiceStatus::ManualReview, ZatcaInvoiceStatus::ClearanceManualReview, ZatcaInvoiceStatus::ClearanceRejected => "ZATCA refused it or retries ran out. Read the reason with `zatca:inspect invoice --id={$invoice->id}`.",
            default => "Inspect it with `zatca:inspect invoice --id={$invoice->id}`.",
        };
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The single document for a source reference, following any regeneration chain to the
     * one that currently represents the sale — or false after an anomaly report if more
     * than one root document exists (never something to guess through).
     */
    private function resolveInvoiceOrAnomaly(string $sourceReference, bool $quiet = false): ZatcaInvoice|false|null
    {
        $candidates = ZatcaInvoice::query()->where('source_reference', $sourceReference)->orderBy('id')->get();

        if ($candidates->count() > 1) {
            $this->error("More than one ZATCA document exists for [{$sourceReference}]. Stopping: this needs investigation, not a guess.");
            $this->table(
                ['ID', 'Environment', 'EGS', 'Status'],
                $candidates->map(fn (ZatcaInvoice $i) => [$i->id, $i->environment->value, $i->egs_identifier, $i->status->value])->all()
            );

            Log::error('[ZATCA][recover] Multiple documents found for one source reference.', [
                'source_reference' => $sourceReference,
                'zatca_invoice_ids' => $candidates->pluck('id')->all(),
            ]);

            return false;
        }

        $current = $candidates->first()?->authoritative();

        if (! $quiet && $current && $current->id !== $candidates->first()->id) {
            $this->line("Document #{$candidates->first()->id} was replaced; working on the current document #{$current->id}.");
        }

        return $current;
    }

    /** Walks regenerated_from_id back to the original document, bounded like authoritative(). */
    private function rootOf(ZatcaInvoice $document): ZatcaInvoice
    {
        $current = $document;
        $seen = [$current->getKey() => true];

        for ($depth = 0; $depth < ZatcaInvoice::MAX_REGENERATION_CHAIN_DEPTH; $depth++) {
            $parentId = $current->regenerated_from_id;

            if ($parentId === null || isset($seen[$parentId])) {
                break;
            }

            $parent = ZatcaInvoice::query()->find($parentId);

            if (! $parent) {
                break;
            }

            $seen[$parentId] = true;
            $current = $parent;
        }

        return $current;
    }

    private function submissionVerb(ZatcaInvoice $invoice): string
    {
        return SubmissionRetry::isClearance($invoice) ? 'clear (Standard / B2B)' : 'report (Simplified / B2C)';
    }

    private function printUsage(): void
    {
        $this->line('');
        $this->line('Usage:');
        $this->line('  php artisan zatca:recover --source-type=<type> --source-id=<id> [--egs=<identifier>] [--dry-run]');
        $this->line('  php artisan zatca:recover --id=<zatca_invoices id> [--dry-run]');
    }

    private function printInvoiceState(?ZatcaInvoice $invoice, string $sourceReference): void
    {
        $this->line('');

        if (! $invoice) {
            $this->line("No ZATCA document exists yet for [{$sourceReference}].");

            return;
        }

        $this->line('Current ZATCA document:');
        $this->line("  Document ID        : {$invoice->id}");
        $this->line("  UUID               : {$invoice->uuid}");
        $this->line("  ICV                : {$invoice->icv}");
        $this->line("  EGS                : {$invoice->egs_identifier}");
        $this->line("  Environment        : {$invoice->environment->value}");
        $this->line('  Type               : '.($invoice->document_type?->value ?? 'unknown').' ('.$this->submissionVerb($invoice).')');
        $this->line('  Unsigned XML       : '.$this->yn(filled($invoice->unsigned_xml)));
        $this->line('  Signed XML         : '.$this->yn(filled($invoice->signed_xml_base64)));
        $this->line('  Invoice hash       : '.$this->yn(filled($invoice->invoice_hash)));
        $this->line('  QR code            : '.$this->yn(filled($invoice->qr_code)));
        $this->line('  Submission attempts: '.$invoice->submissionAttempts()->count());
        $this->line('  Accepted by ZATCA  : '.$this->yn($invoice->status->isTerminalSuccess()));
        $this->line("  Status             : {$invoice->status->value}");

        if ($invoice->last_error) {
            $this->line("  Last error         : {$invoice->last_error}");
        }
    }

    /** @param  array{action: string, message: string}  $plan */
    private function printDryRunPlan(array $plan): void
    {
        $this->line('');
        $this->info('DRY RUN: nothing will be written, signed or sent.');
        $this->line('');

        if ($plan['action'] === self::ACTION_BLOCKED) {
            $this->warn('Recovery would stop: '.$plan['message']);

            return;
        }

        $this->line('Planned action:');
        $this->line('  - '.$plan['message']);
    }

    private function printFinalState(?ZatcaInvoice $invoice): void
    {
        $this->line('');

        if (! $invoice) {
            $this->line('No ZATCA document exists for this sale; creating it did not get far enough to reserve one. See the failure above.');

            return;
        }

        $this->line("Document ID: {$invoice->id}");
        $this->line("UUID: {$invoice->uuid}");
        $this->line("ICV: {$invoice->icv}");
        $this->line("Status: {$invoice->status->value}");

        if ($invoice->last_error) {
            $this->warn("Last error: {$invoice->last_error}");
        }
    }

    private function reportStageFailure(string $stage, ?int $invoiceId, Throwable $e): void
    {
        $this->line('');
        $this->error("Recovery FAILED at: {$stage}");
        $this->error('Document ID: '.($invoiceId ?? 'n/a'));
        $this->error('Error: '.$e->getMessage());

        Log::error('[ZATCA][recover] Recovery failed.', [
            'stage' => $stage,
            'zatca_invoice_id' => $invoiceId,
            'exception_class' => get_class($e),
            'exception' => $e->getMessage(),
        ]);
    }

    /**
     * One recovery per sale at a time, so two operators (or an operator and a retry) cannot
     * both run the writing stages. block() throws on timeout rather than returning false.
     */
    private function acquireLock(string $sourceReference): ?Lock
    {
        $lock = Cache::lock('zatca-recover:'.$sourceReference, self::LOCK_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            $this->error("Another recovery is already running for [{$sourceReference}]. Try again shortly.");

            return null;
        }

        return $lock;
    }

    private function yn(bool $value): string
    {
        return $value ? 'YES' : 'NO';
    }
}

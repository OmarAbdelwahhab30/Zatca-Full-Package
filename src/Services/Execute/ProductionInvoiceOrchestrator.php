<?php

namespace Asas\Zatca\Services\Execute;

use Asas\Zatca\Contracts\InvoiceSourceContract;
use Asas\Zatca\Contracts\OwnerResolverInterface;
use Asas\Zatca\Contracts\ZatcaSdkInterface;
use Asas\Zatca\Enums\ZatcaCredentialType;
use Asas\Zatca\Enums\ZatcaInvoiceStatus;
use Asas\Zatca\Events\ZatcaInvoiceGenerated;
use Asas\Zatca\Exceptions\ZatcaCredentialsMissingException;
use Asas\Zatca\Models\ZatcaInvoice;
use Asas\Zatca\Services\Invoice\XmlGeneratorFactory;
use Asas\Zatca\Services\Security\ZatcaSigningKeyProvider;
use Asas\Zatca\State\EgsRegistry;
use Asas\Zatca\State\ProductionInvoiceCounterManager;
use Asas\Zatca\State\ProductionPreviousInvoiceHashManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
 *     fails, the Draft row (with its ICV/UUID/PIH/XML already fixed) is
 *     left in place; the NEXT attempt finds it via sourceReference() and
 *     reuses it instead of reserving a new ICV or generating new XML.
 *
 * Once a row is signed (status moves past Draft), it is never re-signed,
 * never re-generated, and never has its UUID/ICV/PIH changed again.
 */
class ProductionInvoiceOrchestrator
{
    public function __construct(
        private readonly EgsRegistry $egsRegistry,
        private readonly ZatcaSigningKeyProvider $signingKeyProvider,
        private readonly ProductionInvoiceCounterManager $counterManager,
        private readonly ProductionPreviousInvoiceHashManager $pihManager,
        private readonly XmlGeneratorFactory $xmlGeneratorFactory,
        private readonly ZatcaSdkInterface $sdk,
        private readonly OwnerResolverInterface $ownerResolver,
    ) {}

    public function generate(InvoiceSourceContract $source, mixed $sourceRecord, string $egsIdentifier): ZatcaInvoice
    {
        $invoice = $this->reserveDraft($source, $sourceRecord, $egsIdentifier);

        if ($invoice->status !== ZatcaInvoiceStatus::Draft) {
            // Already signed (or further along) on a previous attempt — never re-sign.
            return $invoice;
        }

        return $this->sign($invoice);
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

        if (! $this->signingKeyProvider->hasProductionCredentials($egsIdentifier, $egsConfig->environment)) {
            throw ZatcaCredentialsMissingException::forEgs($egsIdentifier, ZatcaCredentialType::Production);
        }

        $invoiceData = $source->toInvoiceData($sourceRecord, $egsIdentifier);
        $uuid = (string) Str::uuid();

        try {
            return DB::transaction(function () use ($egsIdentifier, $egsConfig, $sourceReference, $uuid, $invoiceData) {
                // Reserving ICV and inserting the Draft row happen in the SAME transaction:
                // if the insert fails below (e.g. a concurrent duplicate), the ICV increment
                // is rolled back with it via the same connection's savepoint.
                $reservation = $this->counterManager->reserve($egsIdentifier, $egsConfig->environment);

                $unsignedXml = $this->xmlGeneratorFactory->make()->generate($invoiceData, $egsConfig, $uuid, $reservation);

                return ZatcaInvoice::create([
                    'egs_identifier' => $egsIdentifier,
                    'environment' => $egsConfig->environment->value,
                    'source_reference' => $sourceReference,
                    'owner_id' => $this->ownerResolver->resolve($egsIdentifier),
                    'uuid' => $uuid,
                    'icv' => $reservation->icv,
                    'previous_invoice_hash' => $reservation->previousInvoiceHash,
                    'unsigned_xml' => $unsignedXml,
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

            throw $e;
        }
    }

    /** Step 2: signs an already-persisted Draft row. Never re-reserves ICV/UUID/PIH. */
    private function sign(ZatcaInvoice $invoice): ZatcaInvoice
    {
        $egsConfig = $this->egsRegistry->for($invoice->egs_identifier);

        [$certPath, $keyPath, $xmlPath, $cleanup] = $this->signingKeyProvider->prepareSigningWorkspace(
            $invoice->egs_identifier,
            $egsConfig->environment,
            ZatcaCredentialType::Production,
            $invoice->unsigned_xml
        );

        try {
            $signed = $this->sdk->signInvoice($xmlPath, $certPath, $keyPath);
        } finally {
            $cleanup();
        }

        $this->pihManager->commit($invoice->egs_identifier, $egsConfig->environment, $signed['invoiceHash']);

        $invoice = DB::transaction(function () use ($invoice, $signed) {
            $locked = ZatcaInvoice::query()->lockForUpdate()->find($invoice->id);

            if ($locked->status !== ZatcaInvoiceStatus::Draft) {
                return $locked; // Another worker already signed it — never overwrite.
            }

            $locked->update([
                'invoice_hash' => $signed['invoiceHash'],
                'signed_xml_base64' => $signed['signedXmlBase64'],
                'qr_code' => $signed['qrCode'],
                'status' => ZatcaInvoiceStatus::PendingReporting,
            ]);

            return $locked->fresh();
        });

        Event::dispatch(new ZatcaInvoiceGenerated($invoice));

        return $invoice;
    }

    private function findExisting(string $egsIdentifier, string $environment, string $sourceReference): ?ZatcaInvoice
    {
        return ZatcaInvoice::query()
            ->where('egs_identifier', $egsIdentifier)
            ->where('environment', $environment)
            ->where('source_reference', $sourceReference)
            ->first();
    }

    private function isDuplicateKeyViolation(QueryException $e): bool
    {
        // MySQL: SQLSTATE 23000, driver error code 1062 ("Duplicate entry").
        return $e->getCode() === '23000' || str_contains($e->getMessage(), '1062');
    }
}

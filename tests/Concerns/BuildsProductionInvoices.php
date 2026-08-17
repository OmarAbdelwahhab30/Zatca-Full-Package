<?php

namespace Zatca\Tests\Concerns;

use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Models\ZatcaInvoice;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\Security\ZatcaCredentialStore;
use DateTimeImmutable;

/**
 * Shared scaffolding for tests that drive ProductionInvoiceOrchestrator.
 *
 * Kept separate from the helpers inside ProductionInvoiceOrchestratorIcvSafetyTest so
 * that test stays exactly as it was — it is the regression baseline for ICV safety, and a
 * refactor of its fixtures would weaken what it proves.
 */
trait BuildsProductionInvoices
{
    /**
     * Clears invoice rows before Testbench rolls the package migrations back.
     *
     * Tests here deliberately leave unsigned drafts behind, and an unsigned draft has a
     * NULL invoice_hash. The pre-existing
     * 2026_07_29_000001_make_zatca_invoices_signing_columns_nullable down-migration puts
     * that column back to NOT NULL, which SQLite implements by copying the table — and the
     * copy fails on those NULLs. The in-memory database is thrown away immediately after
     * either way, so clearing rows first lets teardown complete without changing anything a
     * test proved.
     *
     * Note the same down-migration would fail on a real database for the same reason
     * whenever an unsigned draft exists. That is a pre-existing property of that migration,
     * not something these tests introduce.
     */
    protected function tearDown(): void
    {
        try {
            ZatcaSubmissionAttempt::query()->delete();
            ZatcaInvoice::query()->delete();
        } catch (\Throwable) {
            // Schema was never built for this test; nothing to clear.
        }

        parent::tearDown();
    }

    protected function seedFakeProductionCredentials(string $egs = 'default'): void
    {
        app(ZatcaCredentialStore::class)->store($egs, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production, [
            'binary_security_token' => 'FAKE_TOKEN',
            'secret' => 'FAKE_SECRET',
            'certificate' => 'FAKE_CERT',
            'private_key' => 'FAKE_KEY',
        ]);
    }

    /**
     * A scripted SDK that records the XML it was actually handed.
     *
     * Recording matters as much as signing: the previous-invoice-hash a document is signed
     * with lives inside that XML, so asserting on the stored row alone could pass while the
     * bytes sent to the signer said something else.
     */
    protected function scriptedSdk(array $failOnAttempts = []): ZatcaSdkInterface
    {
        return new ScriptedZatcaSdk($failOnAttempts);
    }

    protected function fakeInvoiceSource(?string $buyerName = null): InvoiceSourceContract
    {
        return new FakeInvoiceSource($buyerName);
    }

    /**
     * One signed invoice that ZATCA then refused on validation grounds — the exact state
     * regeneration exists for.
     *
     * Built by driving the real orchestrator rather than by inserting a row, so the
     * document under test has a genuine snapshot, XML, ICV and PIH.
     */
    protected function rejectedInvoice(int $sourceId = 9001, int $httpStatus = 400, string $egs = 'default'): ZatcaInvoice
    {
        $invoice = app(\Zatca\Services\Execute\ProductionInvoiceOrchestrator::class)
            ->generate($this->fakeInvoiceSource(), (object) ['id' => $sourceId], $egs);

        return $this->markRejected($invoice, $httpStatus);
    }

    /**
     * One Draft whose signing failed — the state GenerateZatcaInvoiceJob leaves behind once
     * its retry budget is spent, and the state draft completion exists for.
     *
     * Produced by driving the real orchestrator against an SDK scripted to crash, so the
     * Draft has a genuine reserved ICV, UUID, PIH, unsigned XML and snapshot. Building it by
     * inserting a row would prove nothing about the path that actually strands documents.
     */
    protected function strandedDraft(int $sourceId = 9201, string $egs = 'default'): ZatcaInvoice
    {
        try {
            app(\Zatca\Services\Execute\ProductionInvoiceOrchestrator::class)
                ->generate($this->fakeInvoiceSource(), (object) ['id' => $sourceId], $egs);
        } catch (\Zatca\Exceptions\ZatcaSdkProcessException) {
            // The point of the fixture.
        }

        return ZatcaInvoice::query()->where('source_reference', 'order:'.$sourceId)->firstOrFail();
    }

    /** Puts an already-signed invoice into the state a ZATCA refusal leaves it in. */
    protected function markRejected(ZatcaInvoice $invoice, int $httpStatus = 400): ZatcaInvoice
    {
        $invoice->update([
            'status' => ZatcaInvoiceStatus::ManualReview,
            'attempt_count' => 1,
            'last_error' => 'simulated rejection',
        ]);

        ZatcaSubmissionAttempt::create([
            'zatca_invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'outcome' => 'failed',
            'http_status' => $httpStatus,
            'error_code' => 'BR-KSA-XX',
            'error_message' => 'simulated rejection',
            'attempted_at' => now(),
        ]);

        return $invoice->fresh();
    }
}

/**
 * @internal fixture for BuildsProductionInvoices
 */
class ScriptedZatcaSdk implements ZatcaSdkInterface
{
    public int $signAttempts = 0;

    /** @var array<int, string> the XML handed to each signing call, in order */
    public array $signedXmlSeen = [];

    public function __construct(private readonly array $failOnAttempts = []) {}

    public function generateCsr(string $csrConfigPath, ZatcaEnvironment $environment): array
    {
        return ['csr' => '', 'privateKey' => ''];
    }

    public function signInvoice(string $unsignedXmlPath, string $certificatePath, string $privateKeyPath): array
    {
        $this->signAttempts++;
        $this->signedXmlSeen[] = (string) file_get_contents($unsignedXmlPath);

        if (in_array($this->signAttempts, $this->failOnAttempts, true)) {
            throw \Zatca\Exceptions\ZatcaSdkProcessException::nonZeroExit(['-sign'], 1, 'simulated SDK crash');
        }

        return [
            'signedXmlPath' => $unsignedXmlPath,
            'signedXmlBase64' => base64_encode('signed-'.$this->signAttempts),
            'invoiceHash' => 'HASH-'.$this->signAttempts,
            'qrCode' => 'QR-'.$this->signAttempts,
        ];
    }

    public function generateHash(string $xmlPath): string
    {
        return 'HASH';
    }

    public function validateInvoice(string $signedXmlPath, string $certificatePath): array
    {
        return ['valid' => true, 'errors' => [], 'warnings' => []];
    }

    public function version(): string
    {
        return '3.4.8';
    }

    public function executablePath(): string
    {
        return '/fake/path.jar';
    }
}

/**
 * @internal fixture for BuildsProductionInvoices
 */
class FakeInvoiceSource implements InvoiceSourceContract
{
    /** Incremented whenever the host record is read, so a test can prove it was not. */
    public int $toInvoiceDataCalls = 0;

    public function __construct(private readonly ?string $buyerName = null) {}

    public function toInvoiceData(mixed $source, string $egsIdentifier): ZatcaInvoiceData
    {
        $this->toInvoiceDataCalls++;

        return new ZatcaInvoiceData(
            egsIdentifier: $egsIdentifier,
            sourceReference: $this->sourceReference($source),
            buyerName: $this->buyerName ?? 'Original Buyer',
            buyerVatNumber: null,
            // Fixed to the second: the XML carries date and time only, and a fixed instant
            // keeps generated documents byte-comparable across calls.
            issueDateTime: new DateTimeImmutable('2026-08-17T10:30:00+03:00'),
            currency: 'SAR',
            lines: [new ZatcaInvoiceLine('Course Enrollment', 1, 100.00, 15.0, 100.00, 15.00)],
            totalExclVat: 100.00,
            totalVat: 15.00,
            totalInclVat: 115.00,
        );
    }

    public function sourceReference(mixed $source): string
    {
        return 'order:'.$source->id;
    }
}

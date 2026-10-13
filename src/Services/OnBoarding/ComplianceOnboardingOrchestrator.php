<?php

namespace Zatca\Services\OnBoarding;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Exceptions\ZatcaException;
use Zatca\Models\ZatcaComplianceDocument;
use Zatca\Services\Certificate\CertificateParser;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Services\Invoice\ComplianceTestInvoiceDataFactory;
use Zatca\Services\Invoice\XmlGeneratorFactory;
use Zatca\Services\SDK\ZatcaSdkFingerprint;
use Zatca\Services\Security\CsrGenerator;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Services\Security\ZatcaSigningKeyProvider;
use Zatca\State\ComplianceInvoiceCounterManager;
use Zatca\State\CompliancePreviousInvoiceHashManager;
use Zatca\State\EgsRegistry;
use Illuminate\Support\Str;

/**
 * Drives the three ZATCA onboarding API calls end to end
 * (Compliance CSID → Compliance Invoice check → Production CSID), plus the
 * preceding local key/CSR generation step. No PowerShell, Postman, or manual
 * hash/UUID/Base64 work — every step here is one Laravel call.
 *
 * The Compliance Invoice check is called once per document type ZATCA
 * requires proof for before it will issue a Production CSID: testInvoice()
 * for the plain simplified invoice, testCreditNote()/testDebitNote() for the
 * two note types (each billed against the invoice via testInvoice()'s
 * result). Skipping a required type is exactly what the Production CSID
 * request reports back as "compliance certificate is not done with the
 * following compliance steps yet [...]".
 */
class ComplianceOnboardingOrchestrator
{
    public function __construct(
        private readonly EgsRegistry $egsRegistry,
        private readonly CsrGenerator $csrGenerator,
        private readonly ZatcaCredentialStore $credentialStore,
        private readonly ZatcaSigningKeyProvider $signingKeyProvider,
        private readonly ConnectorFactory $connectorFactory,
        private readonly ZatcaSdkInterface $sdk,
        private readonly ZatcaSdkFingerprint $fingerprint,
        private readonly XmlGeneratorFactory $xmlGeneratorFactory,
        private readonly ComplianceTestInvoiceDataFactory $testInvoiceFactory,
        private readonly ComplianceInvoiceCounterManager $counterManager,
        private readonly CompliancePreviousInvoiceHashManager $pihManager,
        private readonly CertificateParser $certificateParser,
    ) {}

    /** @return array{csr:string, privateKeyGenerated:bool, sdkFingerprint:string} */
    public function generateCredentials(string $egsIdentifier): array
    {
        $egsConfig = $this->egsRegistry->for($egsIdentifier);

        $generated = $this->csrGenerator->generate($egsConfig);

        $this->credentialStore->store($egsIdentifier, $egsConfig->environment, ZatcaCredentialType::Compliance, [
            'csr' => $generated['csr'],
            'private_key' => $generated['privateKey'],
        ]);

        return [
            'csr' => $generated['csr'],
            'privateKeyGenerated' => true,
            'sdkFingerprint' => $this->fingerprint->current(),
        ];
    }

    /** @return array{requestId:string, dispositionMessage:?string} */
    public function compliance(string $egsIdentifier, string $otp): array
    {
        $egsConfig = $this->egsRegistry->for($egsIdentifier);
        $csr = $this->credentialStore->getCsr($egsIdentifier, $egsConfig->environment, ZatcaCredentialType::Compliance);

        if (! $csr) {
            throw new ZatcaException("No CSR on record for EGS [{$egsIdentifier}]. Run `zatca:generate-credentials` first.");
        }

        $result = $this->connectorFactory->make()->requestComplianceCsid($egsIdentifier, $csr, $otp);

        if (! $result->successful) {
            throw new ZatcaException('Compliance CSID request failed: '.implode('; ', $result->errors));
        }

        $payload = $result->payload;
        $certificate = $payload->binarySecurityToken;
        [$issuedAt, $expiresAt] = $this->parseCertificateValidity($certificate);

        $this->credentialStore->store($egsIdentifier, $egsConfig->environment, ZatcaCredentialType::Compliance, [
            'binary_security_token' => $payload->binarySecurityToken,
            'secret' => $payload->secret,
            'request_id' => $payload->requestId,
            'certificate' => $certificate,
            'certificate_issued_at' => $issuedAt,
            'certificate_expires_at' => $expiresAt,
            'certificate_state' => ZatcaCertificateState::Valid,
        ]);

        return [
            'requestId' => $payload->requestId,
            'dispositionMessage' => $result->sanitizedResponse['dispositionMessage'] ?? null,
        ];
    }

    /** @return array{status:string, warnings:array<string>, errors:array<string>} */
    public function testInvoice(string $egsIdentifier): array
    {
        return $this->submitComplianceDocument($egsIdentifier, $this->invoiceTypeFor($egsIdentifier));
    }

    /**
     * Submits a compliant credit note, billed against the most recently
     * submitted testInvoice() document for this EGS. ZATCA requires this —
     * separately from the plain invoice check — before it will issue a
     * Production CSID.
     *
     * @return array{status:string, warnings:array<string>, errors:array<string>}
     */
    public function testCreditNote(string $egsIdentifier): array
    {
        $type = $this->noteTypeFor($egsIdentifier, ZatcaComplianceDocumentType::CreditNote, ZatcaComplianceDocumentType::StandardCreditNote);

        return $this->submitComplianceDocument(
            $egsIdentifier,
            $type,
            $this->requireInvoiceReference($egsIdentifier, $type)
        );
    }

    /** @return array{status:string, warnings:array<string>, errors:array<string>} */
    public function testDebitNote(string $egsIdentifier): array
    {
        $type = $this->noteTypeFor($egsIdentifier, ZatcaComplianceDocumentType::DebitNote, ZatcaComplianceDocumentType::StandardDebitNote);

        return $this->submitComplianceDocument(
            $egsIdentifier,
            $type,
            $this->requireInvoiceReference($egsIdentifier, $type)
        );
    }

    /**
     * A document belongs to the Standard profile when its EGS is not the primary
     * one — the same rule EgsRegistry::primary() uses to tell the B2C unit from
     * any other configured unit (today, the B2B one).
     */
    private function isStandardEgs(string $egsIdentifier): bool
    {
        return $egsIdentifier !== $this->egsRegistry->primary()->egsIdentifier;
    }

    private function invoiceTypeFor(string $egsIdentifier): ZatcaComplianceDocumentType
    {
        return $this->isStandardEgs($egsIdentifier) ? ZatcaComplianceDocumentType::StandardInvoice : ZatcaComplianceDocumentType::Invoice;
    }

    private function noteTypeFor(string $egsIdentifier, ZatcaComplianceDocumentType $simplified, ZatcaComplianceDocumentType $standard): ZatcaComplianceDocumentType
    {
        return $this->isStandardEgs($egsIdentifier) ? $standard : $simplified;
    }

    /** @return array{status:string, warnings:array<string>, errors:array<string>} */
    private function submitComplianceDocument(
        string $egsIdentifier,
        ZatcaComplianceDocumentType $documentType,
        ?string $billingReferenceId = null,
    ): array {
        $egsConfig = $this->egsRegistry->for($egsIdentifier);

        $invoiceData = $this->testInvoiceFactory->make($egsIdentifier, 'Compliance Test Item ('.$documentType->label().')', $documentType);
        $uuid = (string) Str::uuid();
        $reservation = $this->counterManager->reserve($egsIdentifier, $egsConfig->environment);

        $unsignedXml = $this->xmlGeneratorFactory->make($documentType)
            ->generate($invoiceData, $egsConfig, $uuid, $reservation, $billingReferenceId);

        [$certPath, $keyPath, $xmlPath, $cleanup] = $this->signingKeyProvider->prepareSigningWorkspace(
            $egsIdentifier,
            $egsConfig->environment,
            ZatcaCredentialType::Compliance,
            $unsignedXml
        );

        try {
            $signed = $this->sdk->signInvoice($xmlPath, $certPath, $keyPath);
        } finally {
            $cleanup();
        }

        $connector = $this->connectorFactory->make();
        $result = $connector->submitComplianceInvoice($egsIdentifier, $signed['invoiceHash'], $uuid, $signed['signedXmlBase64']);

        if ($result->successful) {
            $this->pihManager->commit($egsIdentifier, $egsConfig->environment, $signed['invoiceHash']);
        }

        ZatcaComplianceDocument::create([
            'egs_identifier' => $egsIdentifier,
            'document_type' => $documentType->value,
            'document_reference' => $invoiceData->sourceReference,
            'uuid' => $uuid,
            'icv' => $reservation->icv,
            'invoice_hash' => $signed['invoiceHash'],
            'previous_invoice_hash' => $reservation->previousInvoiceHash,
            'unsigned_xml' => $unsignedXml,
            'signed_xml_base64' => $signed['signedXmlBase64'],
            'reporting_status' => $result->payload->reportingStatus ?? $result->status->value,
            'zatca_response' => $result->sanitizedResponse,
        ]);

        return [
            'status' => $result->status->value,
            'warnings' => $result->warnings,
            'errors' => $result->errors,
        ];
    }

    /**
     * The invoice a credit/debit note compliance check must bill against — the most recent
     * compliance invoice of the note's own subtype (a Simplified note bills against a
     * Simplified invoice, a Standard note against a Standard one).
     */
    private function requireInvoiceReference(string $egsIdentifier, ZatcaComplianceDocumentType $noteType): string
    {
        $invoiceType = $noteType->isStandard()
            ? ZatcaComplianceDocumentType::StandardInvoice
            : ZatcaComplianceDocumentType::Invoice;

        $invoice = ZatcaComplianceDocument::query()
            ->where('egs_identifier', $egsIdentifier)
            ->where('document_type', $invoiceType->value)
            ->latest('id')
            ->first();

        if (! $invoice || ! $invoice->document_reference) {
            throw new ZatcaException(
                "No compliant {$invoiceType->label()} on record for EGS [{$egsIdentifier}]. Run `zatca:onboard test-invoice` "
                .'first — ZATCA requires the credit/debit note compliance check to bill against it.'
            );
        }

        return $invoice->document_reference;
    }

    /** @return array{binarySecurityToken:bool} */
    public function production(string $egsIdentifier): array
    {
        $egsConfig = $this->egsRegistry->for($egsIdentifier);
        $complianceCredentials = $this->credentialStore->get($egsIdentifier, $egsConfig->environment, ZatcaCredentialType::Compliance);

        if (! $complianceCredentials->requestId) {
            throw new ZatcaException("No compliance request ID on record for EGS [{$egsIdentifier}]. Run `zatca:onboard compliance` first.");
        }

        $result = $this->connectorFactory->make()->requestProductionCsid($egsIdentifier, $complianceCredentials->requestId);

        if (! $result->successful) {
            throw new ZatcaException('Production CSID request failed: '.implode('; ', $result->errors));
        }

        $payload = $result->payload;
        [$issuedAt, $expiresAt] = $this->parseCertificateValidity($payload->binarySecurityToken);

        // Production reuses the same key pair as compliance — ZATCA re-issues a certificate
        // for the same CSR/private key, it does not generate a new key pair. The raw key
        // never passes through this class — ZatcaSigningKeyProvider handles the copy.
        $this->signingKeyProvider->reuseComplianceKeyForProduction($egsIdentifier, $egsConfig->environment, [
            'binary_security_token' => $payload->binarySecurityToken,
            'secret' => $payload->secret,
            'certificate' => $payload->binarySecurityToken,
            'certificate_issued_at' => $issuedAt,
            'certificate_expires_at' => $expiresAt,
            'certificate_state' => ZatcaCertificateState::Valid,
        ]);

        return ['binarySecurityToken' => true];
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} */
    private function parseCertificateValidity(string $rawBase64Certificate): array
    {
        return $this->certificateParser->validity($rawBase64Certificate);
    }
}

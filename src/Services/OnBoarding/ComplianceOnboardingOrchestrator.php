<?php

namespace Asas\Zatca\Services\OnBoarding;

use Asas\Zatca\Contracts\ZatcaSdkInterface;
use Asas\Zatca\Enums\ZatcaCertificateState;
use Asas\Zatca\Enums\ZatcaCredentialType;
use Asas\Zatca\Exceptions\ZatcaException;
use Asas\Zatca\Models\ZatcaComplianceDocument;
use Asas\Zatca\Services\Connectors\ConnectorFactory;
use Asas\Zatca\Services\Invoice\ComplianceTestInvoiceDataFactory;
use Asas\Zatca\Services\Invoice\XmlGeneratorFactory;
use Asas\Zatca\Services\SDK\ZatcaSdkFingerprint;
use Asas\Zatca\Services\Security\CsrGenerator;
use Asas\Zatca\Services\Security\ZatcaCredentialStore;
use Asas\Zatca\Services\Security\ZatcaSigningKeyProvider;
use Asas\Zatca\State\ComplianceInvoiceCounterManager;
use Asas\Zatca\State\CompliancePreviousInvoiceHashManager;
use Asas\Zatca\State\EgsRegistry;
use Illuminate\Support\Str;

/**
 * Drives the three ZATCA onboarding API calls end to end
 * (Compliance CSID → Compliance Invoice check → Production CSID), plus the
 * preceding local key/CSR generation step. No PowerShell, Postman, or manual
 * hash/UUID/Base64 work — every step here is one Laravel call.
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
        $egsConfig = $this->egsRegistry->for($egsIdentifier);

        $invoiceData = $this->testInvoiceFactory->make($egsIdentifier);
        $uuid = (string) Str::uuid();
        $reservation = $this->counterManager->reserve($egsIdentifier, $egsConfig->environment);

        $unsignedXml = $this->xmlGeneratorFactory->make()->generate($invoiceData, $egsConfig, $uuid, $reservation);

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
            'document_type' => 'simplified_invoice',
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
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($rawBase64Certificate, 64, "\n")."-----END CERTIFICATE-----\n";

        $parsed = @openssl_x509_parse($pem);

        if (! $parsed) {
            return [null, null];
        }

        return [
            isset($parsed['validFrom_time_t']) ? (new \DateTimeImmutable)->setTimestamp($parsed['validFrom_time_t']) : null,
            isset($parsed['validTo_time_t']) ? (new \DateTimeImmutable)->setTimestamp($parsed['validTo_time_t']) : null,
        ];
    }
}

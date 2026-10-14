<?php

namespace Zatca\Tests\Sandbox\Concerns;

use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaComplianceDocumentType;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Models\ZatcaComplianceDocument;
use Zatca\Models\ZatcaEgsCredential;
use Zatca\State\EgsRegistry;

/**
 * The six onboarding steps, run one by one with the package's artisan commands, each
 * followed by the checks for exactly what that step must have done — so a failure names
 * the step that broke, not just "onboarding failed".
 *
 *   1. zatca:generate-credentials   CSR + private key
 *   2. zatca:onboard compliance     Compliance CSID (OTP)
 *   3. zatca:onboard test-invoice   compliance check: invoice
 *   4. zatca:onboard test-credit-note  compliance check: credit note
 *   5. zatca:onboard test-debit-note   compliance check: debit note
 *   6. zatca:onboard production     Production CSID
 *
 * Used by the B2C and B2B onboarding tests; $standard selects the B2B expectations.
 */
trait AssertsOnboardingSteps
{
    protected function stepGenerateCredentials(string $egs, bool $standard): void
    {
        $this->runCommand('zatca:generate-credentials', ['--egs' => $egs]);

        $config = app(EgsRegistry::class)->for($egs);
        $csr = $this->store()->getCsr($egs, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance);

        // Stored as ZATCA's API expects it: base64 of the PEM CSR.
        $pem = base64_decode((string) $csr, true);
        $this->assertIsString($pem, 'The stored CSR is not base64.');
        $this->assertStringContainsString('-----BEGIN CERTIFICATE REQUEST-----', $pem);

        $this->assertSame([
            'countryName' => 'SA',
            'organizationalUnitName' => $config->organizationUnitName,
            'organizationName' => $config->organizationName,
            'commonName' => $config->commonName,
        ], openssl_csr_get_subject($pem, false), 'CSR subject.');

        // The ZATCA extensions, read from the CSR's DER: the non-production template, the
        // EGS serial number, the VAT number, the invoice type, the address and the category.
        $der = (string) base64_decode((string) preg_replace('#-----[^-]+-----|\s#', '', $pem));
        $this->assertStringContainsString('TSTZATCA-Code-Signing', $der, 'Sandbox CSRs must use the TSTZATCA template.');
        $this->assertStringContainsString($config->serialNumber, $der, 'CSR serial number (SN).');
        $this->assertStringContainsString(self::SANDBOX_VAT_NUMBER, $der, 'CSR VAT number (UID).');
        $this->assertStringContainsString($standard ? '1000' : '0100', $der, 'CSR invoice type (title).');
        $this->assertSame($standard ? '1000' : '0100', $config->invoiceType);
        $this->assertStringContainsString((string) $config->oneLineAddress(), $der, 'CSR registered address.');
        $this->assertStringContainsString((string) $config->businessCategory, $der, 'CSR business category.');

        // The private key: stored encrypted, and it is the key the CSR was made for.
        $privateKey = (string) $this->credentials($egs, ZatcaCredentialType::Compliance)->privateKey;
        $this->assertNotSame('', $privateKey);
        $this->assertSame(
            $this->ecPointOf(openssl_pkey_get_private($this->privateKeyPem($privateKey))),
            $this->ecPointOf(openssl_csr_get_public_key($pem)),
            'The stored private key does not match the CSR.'
        );
        $this->assertEncryptedAtRest($egs, ZatcaCredentialType::Compliance, 'private_key', $privateKey);

        // Local only: nothing sent to ZATCA, no certificate yet.
        $this->assertSame([], $this->zatcaCalls);
        $this->assertNull($this->credentials($egs, ZatcaCredentialType::Compliance)->binarySecurityToken);
    }

    protected function stepComplianceCsid(string $egs): void
    {
        $this->runCommand('zatca:onboard', ['step' => 'compliance', '--egs' => $egs, '--otp' => self::SANDBOX_OTP]);

        $calls = $this->callsTo('/compliance');
        $this->assertCount(1, $calls);
        $call = $calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame(200, $call['status']);
        $this->assertSame('V2', $call['headers']['Accept-Version'] ?? null);
        $this->assertSame(self::SANDBOX_OTP, $call['headers']['OTP'] ?? null);
        $this->assertFalse($call['authorized'], 'The Compliance CSID request must not carry credentials.');
        $this->assertSame(['csr'], array_keys($call['request']));
        $this->assertSame('ISSUED', $call['body']['dispositionMessage'] ?? null);

        $compliance = $this->credentials($egs, ZatcaCredentialType::Compliance);
        $this->assertSame((string) $call['body']['requestID'], $compliance->requestId, 'requestID not stored.');
        $this->assertNotEmpty($compliance->binarySecurityToken);
        $this->assertNotEmpty($compliance->secret);
        $this->assertSame($compliance->binarySecurityToken, $compliance->certificate);
        $this->assertSame(ZatcaCertificateState::Valid, $compliance->certificateState);
        $this->assertNotNull($compliance->certificateExpiresAt);
        $this->assertTrue($compliance->certificateExpiresAt->isFuture(), 'The compliance certificate is already expired.');
        $this->assertEncryptedAtRest($egs, ZatcaCredentialType::Compliance, 'secret', (string) $compliance->secret);

        // Issued for OUR CSR: same subject, same public key.
        $certificate = $this->certificatePem((string) $compliance->binarySecurityToken);
        $subject = openssl_x509_parse($certificate)['subject'] ?? [];
        $config = app(EgsRegistry::class)->for($egs);
        $this->assertSame($config->commonName, $subject['CN'] ?? null);
        $this->assertSame($config->organizationUnitName, $subject['OU'] ?? null);
        $this->assertSame($config->organizationName, $subject['O'] ?? null);
        $this->assertComplianceCertificateMatchesGeneratedKey($egs);

        // The key pair from step 1 is kept as it was.
        $this->assertNotEmpty($this->store()->getCsr($egs, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance));
        $this->assertFalse($this->store()->has($egs, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production));
    }

    /**
     * Steps 3–5. Checks the document the step stored, the request the package sent for it,
     * and ZATCA's answer.
     */
    protected function stepComplianceDocument(string $egs, string $step, ZatcaComplianceDocumentType $type, string $typeCode, int $expectedIcv, ?ZatcaComplianceDocument $previous, ?ZatcaComplianceDocument $billedInvoice): ZatcaComplianceDocument
    {
        $callsBefore = count($this->callsTo('/compliance/invoices'));

        $this->runCommand('zatca:onboard', ['step' => $step, '--egs' => $egs]);

        $calls = $this->callsTo('/compliance/invoices');
        $this->assertCount($callsBefore + 1, $calls, "[{$step}] must send exactly one compliance document.");
        $call = end($calls);

        $document = ZatcaComplianceDocument::query()->where('egs_identifier', $egs)->latest('id')->firstOrFail();
        $standard = $type->isStandard();

        // The stored row.
        $this->assertSame($type->value, $document->document_type);
        $this->assertSame($expectedIcv, $document->icv);
        $this->assertSame(
            $previous?->invoice_hash ?? base64_encode(hash('sha256', '0')),
            $document->previous_invoice_hash,
            "[{$step}] PIH does not link to the previous compliance document."
        );
        $this->assertSame($standard ? 'CLEARED' : 'REPORTED', $document->reporting_status, "[{$step}] ".$this->lastCallSummary());
        $this->assertSame('PASS', $document->zatca_response['status'] ?? null, "[{$step}] ".json_encode($document->zatca_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->assertSame([], $document->zatca_response['warningMessages'] ?? null, "[{$step}] ZATCA returned warnings.");
        $this->assertSame([], $document->zatca_response['validationErrors'] ?? null);
        $this->assertNotEmpty($document->document_reference);

        // The request: authenticated with the compliance CSID, and carrying this document.
        $this->assertSame(200, $call['status']);
        $this->assertTrue($call['authorized'], 'Compliance documents must be sent with the compliance CSID.');
        $this->assertSame('V2', $call['headers']['Accept-Version'] ?? null);
        $this->assertSame($document->uuid, $call['request']['uuid'] ?? null);
        $this->assertSame($document->invoice_hash, $call['request']['invoiceHash'] ?? null);
        $this->assertSame('PASS', $call['body']['validationResults']['status'] ?? null);
        $this->assertSame($standard ? 'CLEARED' : 'REPORTED', $call['body'][$standard ? 'clearanceStatus' : 'reportingStatus'] ?? null);

        // The XML.
        $unsigned = $document->unsigned_xml;
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="'.($standard ? '0100000' : '0200000').'">'.$typeCode.'</cbc:InvoiceTypeCode>', $unsigned);
        $this->assertStringContainsString('<cbc:UUID>'.$document->uuid.'</cbc:UUID>', $unsigned);
        $this->assertStringContainsString($document->previous_invoice_hash, $unsigned);
        $this->assertStringContainsString(self::SANDBOX_VAT_NUMBER, $unsigned);

        if ($standard) {
            $this->assertStringContainsString('<cac:AccountingCustomerParty>', $unsigned, 'A Standard document needs a buyer.');
        }

        if ($billedInvoice !== null) {
            $this->assertStringContainsString(
                '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>'.$billedInvoice->document_reference.'</cbc:ID>',
                $unsigned,
                "[{$step}] must bill against the latest compliance invoice."
            );
            $this->assertStringContainsString('<cbc:InstructionNote>', $unsigned, 'A note must state its reason (KSA-10).');
        } else {
            $this->assertStringNotContainsString('<cac:BillingReference>', $unsigned);
        }

        // Signed by the SDK with the compliance certificate.
        $signed = (string) base64_decode((string) $document->signed_xml_base64, true);
        $this->assertStringContainsString('<ds:SignatureValue>', $signed);
        preg_match('#<ds:X509Certificate>([^<]+)</ds:X509Certificate>#', $signed, $embedded);
        $this->assertSame(
            base64_decode((string) $this->credentials($egs, ZatcaCredentialType::Compliance)->binarySecurityToken),
            trim($embedded[1] ?? ''),
            'The document was not signed with the compliance certificate.'
        );

        if (! $standard) {
            $this->assertStringContainsString('<cbc:ID>QR</cbc:ID>', $signed, 'A Simplified document carries its QR code.');
        }

        return $document;
    }

    protected function stepProductionCsid(string $egs): void
    {
        $compliance = $this->credentials($egs, ZatcaCredentialType::Compliance);

        $this->runCommand('zatca:onboard', ['step' => 'production', '--egs' => $egs]);

        $calls = $this->callsTo('/production/csids');
        $this->assertCount(1, $calls);
        $call = $calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame(200, $call['status']);
        $this->assertTrue($call['authorized'], 'The Production CSID request must use the compliance CSID.');
        $this->assertSame(['compliance_request_id' => $compliance->requestId], $call['request']);
        $this->assertSame('ISSUED', $call['body']['dispositionMessage'] ?? null);

        $production = $this->credentials($egs, ZatcaCredentialType::Production);
        $this->assertNotEmpty($production->binarySecurityToken);
        $this->assertNotEmpty($production->secret);
        $this->assertNotSame($compliance->binarySecurityToken, $production->binarySecurityToken, 'The production CSID must replace the compliance one.');
        $this->assertSame($production->binarySecurityToken, $production->certificate);
        $this->assertSame(ZatcaCertificateState::Valid, $production->certificateState);
        $this->assertNotNull($production->certificateExpiresAt);
        $this->assertTrue($production->certificateExpiresAt->isFuture());
        $this->assertNotFalse(openssl_x509_parse($this->certificatePem((string) $production->binarySecurityToken)), 'The production certificate does not parse.');

        // ZATCA re-certifies the same key pair; it does not issue a new one.
        $this->assertSame($compliance->privateKey, $production->privateKey, 'Production must reuse the compliance private key.');
        $this->assertEncryptedAtRest($egs, ZatcaCredentialType::Production, 'private_key', (string) $production->privateKey);
        $this->assertEncryptedAtRest($egs, ZatcaCredentialType::Production, 'secret', (string) $production->secret);

        // The compliance credentials stay as they were.
        $this->assertSame($compliance->binarySecurityToken, $this->credentials($egs, ZatcaCredentialType::Compliance)->binarySecurityToken);

        // NOTE: the sandbox's production certificate is a fixed, shared one. Its subject and
        // public key are NOT ours, so they are deliberately not compared here (see SandboxTestCase).
    }

    /** Only the onboarding endpoints were called — nothing was reported or cleared. */
    protected function assertOnlyOnboardingCalls(int $complianceDocuments): void
    {
        $this->assertCount(1, $this->callsTo('/compliance'));
        $this->assertCount($complianceDocuments, $this->callsTo('/compliance/invoices'));
        $this->assertCount(1, $this->callsTo('/production/csids'));
        $this->assertCount(2 + $complianceDocuments, $this->zatcaCalls);
    }

    /** The raw database column holds ciphertext, never the value itself. */
    private function assertEncryptedAtRest(string $egs, ZatcaCredentialType $type, string $column, string $plain): void
    {
        $raw = (string) ZatcaEgsCredential::query()
            ->where('egs_identifier', $egs)
            ->where('environment', ZatcaEnvironment::Sandbox->value)
            ->where('credential_type', $type->value)
            ->value($column);

        $this->assertNotSame('', $raw, "No {$column} stored.");
        $this->assertNotSame($plain, $raw, "{$column} is stored in plain text.");
        $this->assertStringNotContainsString($plain, $raw, "{$column} is stored in plain text.");
    }

    private function store(): \Zatca\Services\Security\ZatcaCredentialStore
    {
        return app(\Zatca\Services\Security\ZatcaCredentialStore::class);
    }
}

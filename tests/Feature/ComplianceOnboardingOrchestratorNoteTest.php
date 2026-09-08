<?php

namespace Zatca\Tests\Feature;

use Zatca\Contracts\ZatcaConnectorInterface;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\ZatcaApiResult;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaResponseStatus;
use Zatca\Exceptions\ZatcaException;
use Zatca\Models\ZatcaComplianceDocument;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Tests\TestCase;

/**
 * Proves the fix for the exact failure this feature exists to prevent: ZATCA
 * refusing a Production CSID with "compliance certificate is not done with
 * the following compliance steps yet [simplified-credit-note-compliant,
 * simplified-debit-note-compliant]" because onboarding only ever submitted a
 * plain invoice. testCreditNote()/testDebitNote() must submit the two
 * missing document types, each billed against the invoice testInvoice()
 * already put on record.
 */
class ComplianceOnboardingOrchestratorNoteTest extends TestCase
{
    private const EGS = 'default';

    public function test_credit_note_is_refused_without_a_prior_test_invoice(): void
    {
        $this->seedComplianceCredentials();

        $this->expectException(ZatcaException::class);
        $this->expectExceptionMessage('Run `zatca:onboard test-invoice` first');

        app(ComplianceOnboardingOrchestrator::class)->testCreditNote(self::EGS);
    }

    public function test_debit_note_is_refused_without_a_prior_test_invoice(): void
    {
        $this->seedComplianceCredentials();

        $this->expectException(ZatcaException::class);

        app(ComplianceOnboardingOrchestrator::class)->testDebitNote(self::EGS);
    }

    public function test_credit_note_bills_against_the_invoice_and_is_recorded_with_its_own_document_type(): void
    {
        $this->seedComplianceCredentials();

        $sdk = $this->fakeSigningSdk();
        $this->app->instance(ZatcaSdkInterface::class, $sdk);
        $this->fakeCompliancePass();

        $orchestrator = app(ComplianceOnboardingOrchestrator::class);

        $invoiceResult = $orchestrator->testInvoice(self::EGS);
        $this->assertSame('pass', $invoiceResult['status']);

        $invoiceDocument = ZatcaComplianceDocument::where('document_type', 'simplified_invoice')->sole();

        $noteResult = $orchestrator->testCreditNote(self::EGS);
        $this->assertSame('pass', $noteResult['status']);

        $noteDocument = ZatcaComplianceDocument::where('document_type', 'simplified_credit_note')->sole();
        $this->assertSame($invoiceDocument->document_reference, $noteDocument->document_reference);

        $signedXml = $sdk->xmlSeen[1];
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>', $signedXml);
        $this->assertStringContainsString(
            '<cac:InvoiceDocumentReference><cbc:ID>'.$invoiceDocument->document_reference.'</cbc:ID></cac:InvoiceDocumentReference>',
            $signedXml
        );
    }

    public function test_debit_note_bills_against_the_invoice_and_is_recorded_with_its_own_document_type(): void
    {
        $this->seedComplianceCredentials();

        $sdk = $this->fakeSigningSdk();
        $this->app->instance(ZatcaSdkInterface::class, $sdk);
        $this->fakeCompliancePass();

        $orchestrator = app(ComplianceOnboardingOrchestrator::class);

        $orchestrator->testInvoice(self::EGS);
        $invoiceDocument = ZatcaComplianceDocument::where('document_type', 'simplified_invoice')->sole();

        $noteResult = $orchestrator->testDebitNote(self::EGS);
        $this->assertSame('pass', $noteResult['status']);

        $noteDocument = ZatcaComplianceDocument::where('document_type', 'simplified_debit_note')->sole();
        $this->assertSame($invoiceDocument->document_reference, $noteDocument->document_reference);

        $signedXml = $sdk->xmlSeen[1];
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">383</cbc:InvoiceTypeCode>', $signedXml);
    }

    private function seedComplianceCredentials(): void
    {
        app(ZatcaCredentialStore::class)->store(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance, [
            'binary_security_token' => 'FAKE_TOKEN',
            'secret' => 'FAKE_SECRET',
            'certificate' => 'FAKE_CERT',
            'private_key' => 'FAKE_KEY',
            'request_id' => 'req-123',
        ]);
    }

    private function fakeCompliancePass(): void
    {
        $this->mock(ConnectorFactory::class, function ($mock) {
            $connector = \Mockery::mock(ZatcaConnectorInterface::class);

            $connector->shouldReceive('submitComplianceInvoice')->andReturn(new ZatcaApiResult(
                successful: true,
                status: ZatcaResponseStatus::Pass,
                httpStatus: 200,
                requestId: null,
                sanitizedResponse: [],
                payload: (object) ['reportingStatus' => 'PASS'],
            ));

            $mock->shouldReceive('make')->andReturn($connector);
        });
    }

    /** A minimal fake ZatcaSdkInterface that records every XML file it was asked to sign, in call order. */
    private function fakeSigningSdk(): ZatcaSdkInterface
    {
        return new class implements ZatcaSdkInterface
        {
            /** @var array<int, string> */
            public array $xmlSeen = [];

            public function generateCsr(string $csrConfigPath, ZatcaEnvironment $environment): array
            {
                return ['csr' => '', 'privateKey' => ''];
            }

            public function signInvoice(string $unsignedXmlPath, string $certificatePath, string $privateKeyPath): array
            {
                $this->xmlSeen[] = (string) file_get_contents($unsignedXmlPath);

                $n = count($this->xmlSeen);

                return [
                    'signedXmlPath' => $unsignedXmlPath,
                    'signedXmlBase64' => base64_encode('signed-'.$n),
                    'invoiceHash' => 'HASH-'.$n,
                    'qrCode' => 'QR-'.$n,
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
        };
    }
}

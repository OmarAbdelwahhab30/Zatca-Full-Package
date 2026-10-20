<?php

namespace Zatca\Console\Commands;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\EgsConfig;
use Zatca\DTOs\InvoiceSequenceReservation;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Services\Invoice\XmlGeneratorFactory;
use Zatca\Services\SDK\ZatcaSdkFingerprint;
use Zatca\Services\SDK\ZatcaSdkRuntimeManager;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Explicitly opt-in end-to-end check that the real ZATCA SDK actually works
 * on this machine — Java, the jar, CSR generation, hashing, signing,
 * validation, and QR extraction. Never run as part of the normal unit-test
 * suite (it shells out to Java and takes real seconds); the Production
 * deployment checklist requires this command to pass before go-live.
 *
 * Deliberately does NOT call the ZATCA sandbox/production API — this only
 * proves the local SDK mechanics work, using the SDK's own bundled sample
 * certificate/key. It skips safely (exit 0, clearly labeled SKIPPED) when
 * the SDK isn't configured, so it can be wired into CI without failing
 * environments that don't have Java/the SDK installed.
 */
class SdkSmokeTest extends Command
{
    protected $signature = 'zatca:sdk-smoke-test';

    protected $description = 'Opt-in real-SDK check: Java, CSR generation, hashing, signing, validation, QR extraction. Never called by the normal test suite.';

    public function handle(ZatcaSdkRuntimeManager $runtime, ZatcaSdkFingerprint $fingerprint, ZatcaSdkInterface $sdk, XmlGeneratorFactory $xmlGeneratorFactory): int
    {
        if (! Config::get('zatca.sdk.executable') || ! Config::get('zatca.sdk.root')) {
            $this->warn('SKIPPED: ZATCA_SDK_EXECUTABLE / ZATCA_SDK_ROOT are not configured in this environment.');

            return self::SUCCESS;
        }

        $failed = false;

        $failed = ! $this->check('Java availability', fn () => $this->checkJava()) || $failed;
        $failed = ! $this->check('SDK executable exists', fn () => is_file($runtime->jarPath())) || $failed;
        $failed = ! $this->check('SDK root directory exists', fn () => is_dir($runtime->sdkRoot())) || $failed;
        $failed = ! $this->check('SDK fingerprint computable', fn () => strlen($fingerprint->current()) === 64) || $failed;

        $sdkRoot = $runtime->sdkRoot();
        $sampleCert = $sdkRoot.'/Data/Certificates/cert.pem';
        $sampleKey = $sdkRoot.'/Data/Certificates/ec-secp256k1-priv-key.pem';

        if (! is_file($sampleCert) || ! is_file($sampleKey)) {
            $this->warn('SKIPPED: SDK sample Data/Certificates/{cert.pem,ec-secp256k1-priv-key.pem} not found — cannot smoke-test signing/validation.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $unsignedXmlPath = null;
        $signedXmlPath = null;
        $csrConfigPath = null;

        try {
            $unsignedXml = $this->buildSampleXml($xmlGeneratorFactory);
            $unsignedXmlPath = $this->tempFile($unsignedXml, 'xml');
            $csrConfigPath = $this->tempFile($this->buildCsrConfig(), 'properties');

            $failed = ! $this->check('CSR generation', function () use ($sdk, $csrConfigPath) {
                $result = $sdk->generateCsr($csrConfigPath, ZatcaEnvironment::Sandbox);
                $ok = is_file($result['csr']) && is_file($result['privateKey']);
                @unlink($result['csr']);
                @unlink($result['privateKey']);

                return $ok;
            }) || $failed;

            $failed = ! $this->check('Hash generation', fn () => (bool) $sdk->generateHash($unsignedXmlPath)) || $failed;

            $signResult = null;
            $failed = ! $this->check('Invoice signing', function () use ($sdk, $unsignedXmlPath, $sampleCert, $sampleKey, &$signResult) {
                $signResult = $sdk->signInvoice($unsignedXmlPath, $sampleCert, $sampleKey);

                return ! empty($signResult['signedXmlBase64']) && ! empty($signResult['invoiceHash']);
            }) || $failed;

            $failed = ! $this->check('QR extraction', fn () => $signResult && ! empty($signResult['qrCode'])) || $failed;

            if ($signResult) {
                $signedXmlPath = $this->tempFile((string) base64_decode($signResult['signedXmlBase64']), 'xml');

                $failed = ! $this->check('Invoice validation runs (result may legitimately be PASS or FAIL)', function () use ($sdk, $signedXmlPath, $sampleCert) {
                    $result = $sdk->validateInvoice($signedXmlPath, $sampleCert);

                    return array_key_exists('valid', $result);
                }) || $failed;
            }
        } finally {
            if ($unsignedXmlPath) {
                @unlink($unsignedXmlPath);
            }
            if ($csrConfigPath) {
                @unlink($csrConfigPath);
            }
            if ($signedXmlPath && is_file($signedXmlPath)) {
                @unlink($signedXmlPath);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkJava(): bool
    {
        $process = new Process(['java', '-version']);
        $process->run();

        return $process->isSuccessful();
    }

    private function check(string $label, callable $check): bool
    {
        try {
            $result = $check();
        } catch (\Throwable $e) {
            $this->error("  [FAIL] {$label}: {$e->getMessage()}");

            return false;
        }

        if ($result === null) {
            $this->line("  [SKIP] {$label}");

            return true;
        }

        $result ? $this->info("  [OK] {$label}") : $this->error("  [FAIL] {$label}");

        return (bool) $result;
    }

    private function buildSampleXml(XmlGeneratorFactory $xmlGeneratorFactory): string
    {
        $egsConfig = new EgsConfig(
            egsIdentifier: 'smoke-test',
            environment: ZatcaEnvironment::Sandbox,
            vatNumber: '399999999900003',
            organizationName: 'Maximum Speed Tech Supply LTD',
            organizationUnitName: 'Smoke Test',
            commonName: 'SMOKE-TEST',
            serialNumber: '1-SMOKE|2-TEST|3-'.Str::uuid(),
            organizationIdentifier: '399999999900003',
            countryCode: 'SA',
            invoiceType: '0100',
            locationAddress: 'Prince Sultan',
            businessCategory: 'Education',
            commercialRegistrationNumber: '1010010000',
        );

        $line = new ZatcaInvoiceLine('SDK Smoke Test Item', 1, 100.00, 15.0, 100.00, 15.00);

        $invoiceData = new ZatcaInvoiceData(
            'smoke-test',
            'smoke-test-'.now()->format('YmdHis'),
            null,
            null,
            new DateTimeImmutable,
            'SAR',
            [$line],
            100.00,
            15.00,
            115.00,
        );

        $reservation = new InvoiceSequenceReservation(1, base64_encode(hash('sha256', '0')));

        return $xmlGeneratorFactory->make()->generate($invoiceData, $egsConfig, (string) Str::uuid(), $reservation);
    }

    private function buildCsrConfig(): string
    {
        // A self-contained, valid CSR config — deliberately not the SDK's bundled
        // csr-config-template.properties, which ships with blank/placeholder fields
        // and fails CSR generation with "common name is mandatory field".
        $lines = [
            'csr.common.name=SMOKE-TEST-EGS-01',
            'csr.serial.number=1-SMOKE|2-TEST|3-'.Str::uuid(),
            'csr.organization.identifier=399999999900003',
            'csr.organization.unit.name=Smoke Test Unit',
            'csr.organization.name=Smoke Test Organization',
            'csr.country.name=SA',
            'csr.invoice.type=0100',
            'csr.location.address=Test Address',
            'csr.industry.business.category=Education',
        ];

        return implode("\n", $lines)."\n";
    }

    private function tempFile(string $contents, string $extension): string
    {
        $dir = storage_path('app/private/zatca/smoke-test');

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $path = $dir.'/'.Str::uuid().'.'.$extension;
        file_put_contents($path, $contents);

        return $path;
    }
}

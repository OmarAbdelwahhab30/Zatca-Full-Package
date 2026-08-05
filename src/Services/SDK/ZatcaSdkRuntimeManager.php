<?php

namespace Asas\Zatca\Services\SDK;

use Asas\Zatca\DTOs\SdkWorkspace;
use Asas\Zatca\Exceptions\ZatcaException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds and tears down one isolated filesystem workspace per SDK invocation.
 *
 * The fatoora jar resolves its Configuration/config.json relative to the
 * process's working directory (sibling of the "Apps" folder it's run from),
 * and reads the signing certificate/private key from whatever paths that
 * config.json points at. Because those paths are mutable per invocation
 * (different EGS, different environment, concurrent invoices), every call
 * gets its own workspace directory with its own config.json and its own
 * Data/Certificates — never shared, never left over between invoices. Only
 * the static, read-only rule files (XSD/Schematron/usage.txt) are referenced
 * directly from the real SDK root, since those never change per invocation.
 */
class ZatcaSdkRuntimeManager
{
    public function create(): SdkWorkspace
    {
        $sdkRoot = $this->sdkRoot();

        $root = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'zatca'.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.(string) Str::uuid());

        $appsDir = $root.DIRECTORY_SEPARATOR.'Apps';
        $configurationDir = $root.DIRECTORY_SEPARATOR.'Configuration';
        $dataDir = $root.DIRECTORY_SEPARATOR.'Data';
        $certificatesDir = $dataDir.DIRECTORY_SEPARATOR.'Certificates';
        $pihDir = $dataDir.DIRECTORY_SEPARATOR.'PIH';
        $inputDir = $dataDir.DIRECTORY_SEPARATOR.'Input';
        $outputDir = $root.DIRECTORY_SEPARATOR.'output';

        foreach ([$appsDir, $configurationDir, $certificatesDir, $pihDir, $inputDir, $outputDir] as $dir) {
            if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new RuntimeException("Unable to create ZATCA SDK workspace directory [{$dir}]");
            }
        }

        $pihPath = $pihDir.DIRECTORY_SEPARATOR.'pih.txt';
        file_put_contents($pihPath, base64_encode('0'));
        chmod($pihPath, 0600);

        $config = [
            'xsdPath' => $sdkRoot.'/Data/Schemas/xsds/UBL2.1/xsd/maindoc/UBL-Invoice-2.1.xsd',
            'enSchematron' => $sdkRoot.'/Data/Rules/Schematrons/CEN-EN16931-UBL.xsl',
            'zatcaSchematron' => $sdkRoot.'/Data/Rules/Schematrons/20210819_ZATCA_E-invoice_Validation_Rules.xsl',
            'certPath' => $certificatesDir.DIRECTORY_SEPARATOR.'cert.pem',
            'privateKeyPath' => $certificatesDir.DIRECTORY_SEPARATOR.'ec-secp256k1-priv-key.pem',
            'pihPath' => $pihPath,
            'inputPath' => $inputDir,
            'usagePathFile' => $sdkRoot.'/Configuration/usage.txt',
        ];

        file_put_contents(
            $configurationDir.DIRECTORY_SEPARATOR.'config.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return new SdkWorkspace($root, $appsDir, $configurationDir, $certificatesDir, $inputDir, $outputDir, $pihPath);
    }

    /** Writes the raw (no PEM header/footer/newlines) cert + private key this invocation must sign/validate with. */
    public function writeCredentials(SdkWorkspace $workspace, string $certificateRawBase64, string $privateKeyRawBase64): void
    {
        file_put_contents($workspace->certificatePath(), $this->stripPemArmor($certificateRawBase64));
        chmod($workspace->certificatePath(), 0600);

        file_put_contents($workspace->privateKeyPath(), $this->stripPemArmor($privateKeyRawBase64));
        chmod($workspace->privateKeyPath(), 0600);
    }

    /**
     * Seeds the SDK's own local pih.txt with the same Previous Invoice Hash
     * value already embedded in the invoice XML being signed/validated, so
     * the SDK's local [PIH] consistency check passes. This is the SDK CLI's
     * own bookkeeping file, unrelated to ZATCA's authoritative PIH chain,
     * which our EgsState table (State\*PreviousInvoiceHashManager) owns.
     */
    public function seedPreviousInvoiceHash(SdkWorkspace $workspace, string $previousInvoiceHashBase64): void
    {
        file_put_contents($workspace->pihPath, $previousInvoiceHashBase64);
    }

    public function destroy(SdkWorkspace $workspace): void
    {
        $this->deleteDirectory($workspace->root);
    }

    public function jarPath(): string
    {
        $executable = Config::get('zatca.sdk.executable');

        if (! $executable || ! is_file($executable)) {
            throw new ZatcaException("Configured ZATCA_SDK_EXECUTABLE [{$executable}] does not point to an existing SDK jar file.");
        }

        return $executable;
    }

    public function sdkRoot(): string
    {
        $root = Config::get('zatca.sdk.root');

        if (! $root || ! is_dir($root)) {
            throw new ZatcaException("Configured ZATCA_SDK_ROOT [{$root}] does not point to an existing directory.");
        }

        return rtrim(str_replace('\\', '/', $root), '/');
    }

    private function stripPemArmor(string $value): string
    {
        $stripped = preg_replace('/-----(BEGIN|END)[^-]+-----/', '', $value);

        return trim(preg_replace('/\s+/', '', $stripped));
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;

            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

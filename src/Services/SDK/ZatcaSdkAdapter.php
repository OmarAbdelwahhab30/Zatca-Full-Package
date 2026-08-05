<?php

namespace Zatca\Services\SDK;

use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\SdkWorkspace;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Exceptions\ZatcaSdkProcessException;
use Zatca\Services\SDK\OutputParsers\V3_4_8\CsrOutputParser;
use Zatca\Services\SDK\OutputParsers\V3_4_8\HashOutputParser;
use Zatca\Services\SDK\OutputParsers\V3_4_8\ValidationOutputParser;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;

/**
 * Wraps the ZATCA SDK jar so no part of the package ever shells out
 * directly. Every operation gets a brand-new isolated workspace from
 * ZatcaSdkRuntimeManager and is torn down afterwards — no shared state
 * between invocations, no manual PowerShell/CLI steps for a human to run.
 */
class ZatcaSdkAdapter implements ZatcaSdkInterface
{
    public function __construct(
        private readonly ZatcaSdkRuntimeManager $runtime,
        private readonly CsrOutputParser $csrParser,
        private readonly HashOutputParser $hashParser,
        private readonly ValidationOutputParser $validationParser,
        private readonly SignedInvoiceXmlReader $xmlReader,
    ) {}

    public function generateCsr(string $csrConfigPath, ZatcaEnvironment $environment): array
    {
        $workspace = $this->runtime->create();

        try {
            $csrOutputPath = $workspace->outputDir.DIRECTORY_SEPARATOR.'generated.csr';
            $privateKeyOutputPath = $workspace->outputDir.DIRECTORY_SEPARATOR.'generated-private-key.key';

            $args = ['-csr', '-csrConfig', $csrConfigPath, '-generatedCsr', $csrOutputPath, '-privateKey', $privateKeyOutputPath];

            $output = $this->run($workspace, $environment, $args);

            if (! $this->csrParser->wasSuccessful($output)) {
                throw ZatcaSdkProcessException::nonZeroExit($args, 0, $output);
            }

            if (! is_file($csrOutputPath) || ! is_file($privateKeyOutputPath)) {
                throw ZatcaSdkProcessException::missingOutputFile($csrOutputPath);
            }

            // Copy out of the workspace before it's destroyed.
            $finalCsrPath = $this->persistOutsideWorkspace($csrOutputPath, 'csr');
            $finalKeyPath = $this->persistOutsideWorkspace($privateKeyOutputPath, 'key');

            return ['csr' => $finalCsrPath, 'privateKey' => $finalKeyPath];
        } finally {
            $this->runtime->destroy($workspace);
        }
    }

    public function signInvoice(string $unsignedXmlPath, string $certificatePath, string $privateKeyPath): array
    {
        $workspace = $this->runtime->create();

        try {
            $this->runtime->writeCredentials(
                $workspace,
                file_get_contents($certificatePath),
                file_get_contents($privateKeyPath)
            );
            $this->runtime->seedPreviousInvoiceHash($workspace, $this->xmlReader->extractPih(file_get_contents($unsignedXmlPath)));

            $signedOutputPath = $workspace->outputDir.DIRECTORY_SEPARATOR.'signed.xml';

            $args = ['-sign', '-invoice', $unsignedXmlPath, '-signedInvoice', $signedOutputPath];

            $output = $this->run($workspace, ZatcaEnvironment::Sandbox, $args, includeEnvironmentFlag: false);

            if (! is_file($signedOutputPath)) {
                throw ZatcaSdkProcessException::missingOutputFile($signedOutputPath);
            }

            $invoiceHash = $this->hashParser->parse($output);
            $signedXml = file_get_contents($signedOutputPath);
            $qrCode = $this->xmlReader->extractQrCode($signedXml);
            $finalSignedPath = $this->persistOutsideWorkspace($signedOutputPath, 'xml');

            return [
                'signedXmlPath' => $finalSignedPath,
                'signedXmlBase64' => base64_encode($signedXml),
                'invoiceHash' => $invoiceHash,
                'qrCode' => $qrCode,
            ];
        } finally {
            $this->runtime->destroy($workspace);
        }
    }

    public function generateHash(string $xmlPath): string
    {
        $workspace = $this->runtime->create();

        try {
            $output = $this->run($workspace, ZatcaEnvironment::Sandbox, ['-generateHash', '-invoice', $xmlPath], includeEnvironmentFlag: false);

            return $this->hashParser->parse($output);
        } finally {
            $this->runtime->destroy($workspace);
        }
    }

    public function validateInvoice(string $signedXmlPath, string $certificatePath): array
    {
        $workspace = $this->runtime->create();

        try {
            // Validation only checks the signature against the certificate; no private key needed,
            // but the SDK's config.json still requires a privateKeyPath file to exist.
            $this->runtime->writeCredentials($workspace, file_get_contents($certificatePath), base64_encode('unused'));
            $this->runtime->seedPreviousInvoiceHash($workspace, $this->xmlReader->extractPih(file_get_contents($signedXmlPath)));

            $output = $this->run($workspace, ZatcaEnvironment::Sandbox, ['-validate', '-invoice', $signedXmlPath], includeEnvironmentFlag: false);

            $result = $this->validationParser->parse($output);

            return [
                'valid' => $result->valid,
                'errors' => $result->errors,
                'warnings' => $result->warnings,
            ];
        } finally {
            $this->runtime->destroy($workspace);
        }
    }

    public function version(): string
    {
        return (string) Config::get('zatca.sdk.version');
    }

    public function executablePath(): string
    {
        return $this->runtime->jarPath();
    }

    private function run(
        SdkWorkspace $workspace,
        ZatcaEnvironment $environment,
        array $args,
        bool $includeEnvironmentFlag = true
    ): string {
        $command = ['java', '-jar', $this->runtime->jarPath(), '--globalVersion', $this->version(), ...$args];

        if ($includeEnvironmentFlag && $environment->sdkFlag()) {
            $command[] = $environment->sdkFlag();
        }

        $process = new Process($command, $workspace->appsDir, null, null, (float) Config::get('zatca.sdk.process_timeout', 60));
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        if (! $process->isSuccessful()) {
            throw ZatcaSdkProcessException::nonZeroExit($args, $process->getExitCode() ?? -1, $output);
        }

        return $output;
    }

    private function persistOutsideWorkspace(string $path, string $extension): string
    {
        $destination = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'zatca'.DIRECTORY_SEPARATOR.'sdk-output'.DIRECTORY_SEPARATOR.uniqid('', true).'.'.$extension);

        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0700, true);
        }

        copy($path, $destination);
        chmod($destination, 0600);

        return $destination;
    }
}

<?php

namespace Asas\Zatca\Services\Security;

use Asas\Zatca\Contracts\ZatcaSdkInterface;
use Asas\Zatca\DTOs\EgsConfig;
use Illuminate\Support\Str;

/**
 * Builds the SDK's CSR configuration properties file from EgsConfig and
 * drives ZatcaSdkInterface::generateCsr(). The SDK generates the private
 * key and CSR together as one atomic artifact (there is no separate manual
 * key-generation step to wrap), so this single class covers what the
 * original plan split into PrivateKeyGenerator + CsrGenerator.
 */
class CsrGenerator
{
    public function __construct(private readonly ZatcaSdkInterface $sdk) {}

    /** @return array{csr: string, privateKey: string} raw (no PEM armor) CSR and private key contents */
    public function generate(EgsConfig $egsConfig): array
    {
        $configPath = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'zatca'.DIRECTORY_SEPARATOR.'csr-config-'.Str::uuid().'.properties');

        if (! is_dir(dirname($configPath))) {
            mkdir(dirname($configPath), 0700, true);
        }

        file_put_contents($configPath, $this->buildProperties($egsConfig));
        chmod($configPath, 0600);

        $result = null;

        try {
            $result = $this->sdk->generateCsr($configPath, $egsConfig->environment);

            return [
                'csr' => trim(file_get_contents($result['csr'])),
                'privateKey' => trim(file_get_contents($result['privateKey'])),
            ];
        } finally {
            @unlink($configPath);

            // The SDK adapter persists its CSR/private-key output files outside the destroyed
            // workspace so this method can read them — but once read into memory, the on-disk
            // copies must not linger with plaintext key material in them.
            if ($result !== null) {
                @unlink($result['csr']);
                @unlink($result['privateKey']);
            }
        }
    }

    private function buildProperties(EgsConfig $egsConfig): string
    {
        $lines = [
            'csr.common.name='.$egsConfig->commonName,
            'csr.serial.number='.$egsConfig->serialNumber,
            'csr.organization.identifier='.$egsConfig->organizationIdentifier,
            'csr.organization.unit.name='.$egsConfig->organizationUnitName,
            'csr.organization.name='.$egsConfig->organizationName,
            'csr.country.name='.$egsConfig->countryCode,
            'csr.invoice.type='.$egsConfig->invoiceType,
            'csr.location.address='.$egsConfig->locationAddress,
            'csr.industry.business.category='.$egsConfig->businessCategory,
        ];

        return implode("\n", $lines)."\n";
    }
}

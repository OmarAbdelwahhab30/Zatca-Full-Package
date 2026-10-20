<?php

namespace Zatca\Services\Security;

use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Services\Certificate\CertificateParser;
use Illuminate\Support\Str;

/**
 * The ONLY seam through which decrypted private-key material is allowed to
 * touch a filesystem or move between credential rows. Orchestrators and
 * jobs never call ZatcaCredentialStore::get()/getPrivateKeyRaw() directly
 * for signing purposes — they call this class, which:
 *
 *  - writes cert/key material to a private, restrictively-permissioned,
 *    per-call temporary workspace, and hands back a cleanup closure that
 *    the caller MUST invoke in a finally block;
 *  - never returns the raw key string to application code;
 *  - never logs key material (see ZatcaSanitizer for anything that does
 *    need to describe a failure).
 *
 * If a future caller needs "the private key" for something else, that need
 * should be added as a narrow method here, not by reaching back into
 * ZatcaCredentialStore from outside this class.
 */
class ZatcaSigningKeyProvider
{
    public function __construct(
        private readonly ZatcaCredentialStore $credentialStore,
        private readonly CertificateParser $certificateParser,
    ) {}

    public function hasProductionCredentials(string $egsIdentifier, ZatcaEnvironment $environment): bool
    {
        return $this->credentialStore->has($egsIdentifier, $environment, ZatcaCredentialType::Production);
    }

    public function hasComplianceCredentials(string $egsIdentifier, ZatcaEnvironment $environment): bool
    {
        return $this->credentialStore->has($egsIdentifier, $environment, ZatcaCredentialType::Compliance);
    }

    /**
     * Writes the cert/key/unsigned-XML for one signing call into a private,
     * per-call workspace and returns the three file paths plus a cleanup
     * closure. The caller MUST call the cleanup closure in a `finally` block
     * — the files contain plaintext key material until it runs.
     *
     * @return array{0:string,1:string,2:string,3:callable():void} cert path, key path, xml path, cleanup
     */
    public function prepareSigningWorkspace(
        string $egsIdentifier,
        ZatcaEnvironment $environment,
        ZatcaCredentialType $credentialType,
        string $unsignedXml
    ): array {
        $credentials = $this->credentialStore->get($egsIdentifier, $environment, $credentialType);

        $dir = $this->privateStorageDir('tmp');
        $id = (string) Str::uuid();

        $certPath = $dir.DIRECTORY_SEPARATOR.$id.'-cert.pem';
        $keyPath = $dir.DIRECTORY_SEPARATOR.$id.'-key.pem';
        $xmlPath = $dir.DIRECTORY_SEPARATOR.$id.'-invoice.xml';

        file_put_contents($certPath, $this->certificateParser->normalizeForSdk((string) $credentials->certificate));
        chmod($certPath, 0600);

        file_put_contents($keyPath, (string) $credentials->privateKey);
        chmod($keyPath, 0600);

        file_put_contents($xmlPath, $unsignedXml);
        chmod($xmlPath, 0600);

        $cleanup = static function () use ($certPath, $keyPath, $xmlPath): void {
            @unlink($certPath);
            @unlink($keyPath);
            @unlink($xmlPath);
        };

        return [$certPath, $keyPath, $xmlPath, $cleanup];
    }

    /**
     * Copies the Compliance private key into the Production credential row
     * during production onboarding — the raw key never leaves this class;
     * the caller only learns that the copy succeeded.
     */
    public function reuseComplianceKeyForProduction(string $egsIdentifier, ZatcaEnvironment $environment, #[\SensitiveParameter] array $productionCredentialData): void
    {
        $complianceCredentials = $this->credentialStore->get($egsIdentifier, $environment, ZatcaCredentialType::Compliance);

        $this->credentialStore->store($egsIdentifier, $environment, ZatcaCredentialType::Production, [
            ...$productionCredentialData,
            'private_key' => $complianceCredentials->privateKey,
        ]);
    }

    private function privateStorageDir(string $subPath): string
    {
        $dir = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'zatca'.DIRECTORY_SEPARATOR.$subPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }
}

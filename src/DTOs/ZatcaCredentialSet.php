<?php

namespace Zatca\DTOs;

use Zatca\Enums\ZatcaCertificateState;
use DateTimeImmutable;

/** Decrypted credential bundle for one egs_identifier/environment/credential_type. */
class ZatcaCredentialSet
{
    public function __construct(
        public readonly ?string $binarySecurityToken,
        #[\SensitiveParameter] public readonly ?string $secret,
        public readonly ?string $requestId,
        #[\SensitiveParameter] public readonly ?string $privateKey,
        public readonly ?string $certificate,
        public readonly ?string $sdkFingerprint,
        public readonly ?DateTimeImmutable $certificateExpiresAt,
        public readonly ZatcaCertificateState $certificateState,
    ) {}

    /**
     * var_dump()/print_r()/debug output shows that the secret and key exist, never their
     * values. (Symfony's dump()/dd() read properties directly and do not use this, so
     * never dump a credential set on a machine whose output is shared.)
     */
    public function __debugInfo(): array
    {
        return [
            'binarySecurityToken' => $this->binarySecurityToken !== null ? '[REDACTED]' : null,
            'secret' => $this->secret !== null ? '[REDACTED]' : null,
            'requestId' => $this->requestId,
            'privateKey' => $this->privateKey !== null ? '[REDACTED]' : null,
            'certificate' => $this->certificate !== null ? '[certificate]' : null,
            'sdkFingerprint' => $this->sdkFingerprint,
            'certificateExpiresAt' => $this->certificateExpiresAt,
            'certificateState' => $this->certificateState,
        ];
    }

    /** Basic-auth username/password ZATCA expects for authenticated endpoints. */
    public function authUsername(): string
    {
        return (string) $this->binarySecurityToken;
    }

    public function authPassword(): string
    {
        return (string) $this->secret;
    }
}

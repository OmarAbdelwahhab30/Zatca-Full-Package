<?php

namespace Zatca\DTOs;

use Zatca\Enums\ZatcaCertificateState;
use DateTimeImmutable;

/** Decrypted credential bundle for one egs_identifier/environment/credential_type. */
class ZatcaCredentialSet
{
    public function __construct(
        public readonly ?string $binarySecurityToken,
        public readonly ?string $secret,
        public readonly ?string $requestId,
        public readonly ?string $privateKey,
        public readonly ?string $certificate,
        public readonly ?string $sdkFingerprint,
        public readonly ?DateTimeImmutable $certificateExpiresAt,
        public readonly ZatcaCertificateState $certificateState,
    ) {}

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

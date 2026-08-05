<?php

namespace Zatca\Services\Security;

use Zatca\Contracts\CredentialEncrypterInterface;
use Zatca\DTOs\ZatcaCredentialSet;
use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Exceptions\ZatcaCredentialsMissingException;
use Zatca\Models\ZatcaEgsCredential;
use Carbon\CarbonImmutable;

/**
 * The only place secrets/private keys are encrypted or decrypted. Never log
 * or return raw model attributes for `secret`/`private_key` outside this class.
 */
class ZatcaCredentialStore
{
    public function __construct(private readonly CredentialEncrypterInterface $encrypter) {}

    public function has(string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $type): bool
    {
        return $this->findRow($egsIdentifier, $environment, $type) !== null;
    }

    /** CSR is not secret — stored/read in the clear (needed between generate-credentials and onboard compliance). */
    public function getCsr(string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $type): ?string
    {
        return $this->findRow($egsIdentifier, $environment, $type)?->csr;
    }

    public function get(string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $type): ZatcaCredentialSet
    {
        $row = $this->findRow($egsIdentifier, $environment, $type);

        if (! $row) {
            throw ZatcaCredentialsMissingException::forEgs($egsIdentifier, $type);
        }

        return new ZatcaCredentialSet(
            binarySecurityToken: $row->binary_security_token,
            secret: $row->secret ? $this->encrypter->decrypt($row->secret) : null,
            requestId: $row->request_id,
            privateKey: $row->private_key ? $this->encrypter->decrypt($row->private_key) : null,
            certificate: $row->certificate,
            sdkFingerprint: $row->sdk_fingerprint,
            certificateExpiresAt: $row->certificate_expires_at ? CarbonImmutable::parse($row->certificate_expires_at) : null,
            certificateState: $row->certificate_state instanceof ZatcaCertificateState
                ? $row->certificate_state
                : ZatcaCertificateState::from((string) $row->certificate_state),
        );
    }

    /**
     * @param array{binary_security_token?:string,secret?:string,request_id?:string,private_key?:string,
     *              certificate?:string,sdk_fingerprint?:string,certificate_issued_at?:\DateTimeInterface,
     *              certificate_expires_at?:\DateTimeInterface,certificate_state?:ZatcaCertificateState} $data
     */
    public function store(string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $type, array $data): void
    {
        $attributes = [];

        if (array_key_exists('binary_security_token', $data)) {
            $attributes['binary_security_token'] = $data['binary_security_token'];
        }
        if (array_key_exists('secret', $data) && $data['secret'] !== null) {
            $attributes['secret'] = $this->encrypter->encrypt($data['secret']);
        }
        if (array_key_exists('request_id', $data)) {
            $attributes['request_id'] = $data['request_id'];
        }
        if (array_key_exists('private_key', $data) && $data['private_key'] !== null) {
            $attributes['private_key'] = $this->encrypter->encrypt($data['private_key']);
        }
        if (array_key_exists('certificate', $data)) {
            $attributes['certificate'] = $data['certificate'];
        }
        if (array_key_exists('csr', $data)) {
            $attributes['csr'] = $data['csr'];
        }
        if (array_key_exists('sdk_fingerprint', $data)) {
            $attributes['sdk_fingerprint'] = $data['sdk_fingerprint'];
        }
        if (array_key_exists('certificate_issued_at', $data)) {
            $attributes['certificate_issued_at'] = $data['certificate_issued_at'];
        }
        if (array_key_exists('certificate_expires_at', $data)) {
            $attributes['certificate_expires_at'] = $data['certificate_expires_at'];
        }
        if (array_key_exists('certificate_state', $data)) {
            $attributes['certificate_state'] = $data['certificate_state'] instanceof ZatcaCertificateState
                ? $data['certificate_state']->value
                : $data['certificate_state'];
        }

        ZatcaEgsCredential::query()->updateOrCreate(
            [
                'egs_identifier' => $egsIdentifier,
                'environment' => $environment->value,
                'credential_type' => $type->value,
            ],
            $attributes
        );
    }

    private function findRow(string $egsIdentifier, ZatcaEnvironment $environment, ZatcaCredentialType $type): ?ZatcaEgsCredential
    {
        return ZatcaEgsCredential::query()
            ->where('egs_identifier', $egsIdentifier)
            ->where('environment', $environment->value)
            ->where('credential_type', $type->value)
            ->first();
    }
}

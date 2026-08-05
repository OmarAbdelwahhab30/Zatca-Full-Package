<?php

namespace Zatca\Services\Security;

use Zatca\Contracts\CredentialEncrypterInterface;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Default CredentialEncrypterInterface binding — uses Laravel's APP_KEY-based
 * encrypter. Host apps needing Vault/KMS swap this binding in their own
 * service provider; ZatcaCredentialStore never knows the difference.
 */
class LaravelCredentialEncrypter implements CredentialEncrypterInterface
{
    public function __construct(private readonly Encrypter $encrypter) {}

    public function encrypt(string $plaintext): string
    {
        return $this->encrypter->encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        return $this->encrypter->decryptString($ciphertext);
    }
}

<?php

namespace Zatca\Contracts;

/**
 * Secret backend seam — host apps may swap Laravel's encrypter for Vault/KMS
 * without touching ZatcaCredentialStore or any calling code.
 */
interface CredentialEncrypterInterface
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): string;
}

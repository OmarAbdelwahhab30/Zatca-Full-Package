<?php

namespace Asas\Zatca\Tests\Feature;

use Asas\Zatca\Enums\ZatcaCredentialType;
use Asas\Zatca\Enums\ZatcaEnvironment;
use Asas\Zatca\Exceptions\ZatcaCredentialsMissingException;
use Asas\Zatca\Models\ZatcaEgsCredential;
use Asas\Zatca\Services\Security\ZatcaCredentialStore;
use Asas\Zatca\Tests\TestCase;

class CredentialStoreTest extends TestCase
{
    private const EGS = 'default';

    public function test_secret_and_private_key_round_trip_through_encryption(): void
    {
        $store = app(ZatcaCredentialStore::class);

        $store->store(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance, [
            'binary_security_token' => 'token-123',
            'secret' => 'super-secret-value',
            'private_key' => 'raw-private-key-bytes',
        ]);

        $credentials = $store->get(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance);

        $this->assertSame('super-secret-value', $credentials->secret);
        $this->assertSame('raw-private-key-bytes', $credentials->privateKey);

        $row = ZatcaEgsCredential::where('egs_identifier', self::EGS)->first();
        $this->assertNotSame('super-secret-value', $row->getRawOriginal('secret'));
        $this->assertNotSame('raw-private-key-bytes', $row->getRawOriginal('private_key'));
    }

    public function test_storing_null_secret_and_private_key_does_not_throw(): void
    {
        // Regression test: ZatcaSigningKeyProvider::reuseComplianceKeyForProduction() can pass a
        // null privateKey if compliance credentials somehow lack one — store() must not crash.
        $store = app(ZatcaCredentialStore::class);

        $store->store(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production, [
            'binary_security_token' => 'token',
            'secret' => null,
            'private_key' => null,
        ]);

        $this->assertTrue($store->has(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production));
    }

    public function test_missing_credentials_throw_a_typed_exception(): void
    {
        $this->expectException(ZatcaCredentialsMissingException::class);

        app(ZatcaCredentialStore::class)->get(self::EGS, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production);
    }
}

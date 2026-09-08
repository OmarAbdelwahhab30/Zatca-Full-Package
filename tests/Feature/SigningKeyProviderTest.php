<?php

namespace Zatca\Tests\Feature;

use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Services\Security\ZatcaSigningKeyProvider;
use Zatca\Tests\TestCase;

class SigningKeyProviderTest extends TestCase
{
    public function test_it_writes_a_sdk_ready_certificate_without_changing_the_private_key(): void
    {
        $token = trim(file_get_contents(__DIR__.'/../Fixtures/Certificates/zatca_sample_bst.txt'));
        $pem = "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_decode($token, true), 64, "\n")
            ."-----END CERTIFICATE-----\n";
        $privateKey = 'PRIVATE-KEY-BODY';
        $unsignedXml = '<Invoice>unsigned</Invoice>';

        app(ZatcaCredentialStore::class)->store('default', ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance, [
            'binary_security_token' => $token,
            'secret' => 'secret',
            'request_id' => 'request-id',
            'certificate' => $token,
            'private_key' => $privateKey,
            'certificate_state' => ZatcaCertificateState::Valid,
        ]);

        [$certPath, $keyPath, $xmlPath, $cleanup] = app(ZatcaSigningKeyProvider::class)
            ->prepareSigningWorkspace('default', ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance, $unsignedXml);

        try {
            $this->assertSame($pem, file_get_contents($certPath));
            $this->assertSame($privateKey, file_get_contents($keyPath));
            $this->assertSame($unsignedXml, file_get_contents($xmlPath));
        } finally {
            $cleanup();
        }
    }
}

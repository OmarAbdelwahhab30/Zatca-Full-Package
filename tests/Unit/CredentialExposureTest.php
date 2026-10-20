<?php

namespace Zatca\Tests\Unit;

use Illuminate\Contracts\Encryption\Encrypter;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Zatca\DTOs\ZatcaCredentialSet;
use Zatca\Enums\ZatcaCertificateState;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Services\Connectors\V2\HttpConnector;
use Zatca\Services\OnBoarding\ComplianceOnboardingOrchestrator;
use Zatca\Services\Security\LaravelCredentialEncrypter;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Services\Security\ZatcaSigningKeyProvider;
use Zatca\Services\SDK\ZatcaSdkRuntimeManager;
use Zatca\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Secrets must not travel in plain text through places a host's tooling captures:
 * exception stack traces (Ignition, Sentry, Flare) and var_dump()/print_r() output.
 *
 * #[SensitiveParameter] is honoured from PHP 8.2; PHP 8.1 ignores the attribute, so the
 * behavioural test below skips there and the reflection test is what guards the code.
 */
class CredentialExposureTest extends TestCase
{
    /** @dataProvider sensitiveParameters */
    #[DataProvider('sensitiveParameters')]
    public function test_secret_bearing_parameters_are_marked_sensitive(string $class, string $method, string $parameter): void
    {
        $found = null;

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $candidate) {
            if ($candidate->getName() === $parameter) {
                $found = $candidate;
            }
        }

        $this->assertNotNull($found, "{$class}::{$method}() has no \${$parameter} parameter.");
        $this->assertNotEmpty(
            $found->getAttributes('SensitiveParameter'),
            "{$class}::{$method}(\${$parameter}) carries a secret and must be #[\\SensitiveParameter]."
        );
    }

    public static function sensitiveParameters(): array
    {
        return [
            'encrypter plaintext' => [LaravelCredentialEncrypter::class, 'encrypt', 'plaintext'],
            'credential store data' => [ZatcaCredentialStore::class, 'store', 'data'],
            'SDK workspace private key' => [ZatcaSdkRuntimeManager::class, 'writeCredentials', 'privateKeyRawBase64'],
            'key reuse for production' => [ZatcaSigningKeyProvider::class, 'reuseComplianceKeyForProduction', 'productionCredentialData'],
            'credential set secret' => [ZatcaCredentialSet::class, '__construct', 'secret'],
            'credential set private key' => [ZatcaCredentialSet::class, '__construct', 'privateKey'],
            'compliance OTP (connector)' => [HttpConnector::class, 'requestComplianceCsid', 'otp'],
            'compliance OTP (onboarding)' => [ComplianceOnboardingOrchestrator::class, 'compliance', 'otp'],
        ];
    }

    public function test_an_exception_thrown_while_storing_a_secret_does_not_carry_it_in_the_trace(): void
    {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('#[SensitiveParameter] is only honoured from PHP 8.2.');
        }

        ini_set('zend.exception_ignore_args', '0');

        $secret = 'S3CRET-VALUE-9f2c';

        $encrypter = Mockery::mock(Encrypter::class);
        $encrypter->shouldReceive('encryptString')->andReturnUsing(function ($value) {
            throw new RuntimeException('encryption backend unavailable');
        });

        $store = new ZatcaCredentialStore(new LaravelCredentialEncrypter($encrypter));

        try {
            $store->store('default', ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production, ['secret' => $secret]);
            $this->fail('The broken encrypter should have thrown.');
        } catch (RuntimeException $e) {
            $trace = $e->getTrace();
        }

        // Guard against a vacuous pass: the unprotected test closure's frame does hold the value.
        $this->assertStringContainsString($secret, print_r($trace, true));

        $packageFrames = array_filter($trace, fn (array $frame) => str_starts_with($frame['class'] ?? '', 'Zatca\\Services\\'));

        $this->assertNotEmpty($packageFrames);

        foreach ($packageFrames as $frame) {
            $this->assertStringNotContainsString($secret, print_r($frame['args'] ?? [], true));
        }
    }

    public function test_dumping_a_credential_set_never_shows_the_secret_or_key(): void
    {
        $set = new ZatcaCredentialSet(
            binarySecurityToken: 'TOKEN-abc',
            secret: 'SECRET-xyz',
            requestId: '1234',
            privateKey: 'PRIVATE-KEY-material',
            certificate: 'CERT',
            sdkFingerprint: null,
            certificateExpiresAt: null,
            certificateState: ZatcaCertificateState::Valid,
        );

        ob_start();
        var_dump($set);
        $dumped = ob_get_clean().print_r($set, true);

        $this->assertStringNotContainsString('SECRET-xyz', $dumped);
        $this->assertStringNotContainsString('PRIVATE-KEY-material', $dumped);
        $this->assertStringNotContainsString('TOKEN-abc', $dumped);
        $this->assertStringContainsString('1234', $dumped);
    }
}

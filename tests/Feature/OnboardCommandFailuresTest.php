<?php

namespace Zatca\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Models\ZatcaComplianceDocument;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\State\ComplianceInvoiceCounterManager;
use Zatca\Tests\TestCase;

/**
 * Onboarding steps run in the wrong order, or refused by ZATCA, through the artisan
 * commands a developer types. The package's real HttpConnector is used; only the server
 * is fake, and every request it would have sent is recorded.
 *
 * These cases cannot be tested against the developer-portal sandbox: it accepts any OTP
 * and issues a Production CSID even when no compliance check was run (measured
 * 2026-10-15). The wrong-order cases never reach ZATCA at all.
 */
class OnboardCommandFailuresTest extends TestCase
{
    private const B2C = 'default';

    private const B2B = 'b2b';

    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $sent = [];

    private MockHandler $zatca;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('zatca.egs.'.self::B2B, array_merge($app['config']->get('zatca.egs.'.self::B2C), [
            'common_name' => 'TST-EGS-B2B',
            'serial_number' => '1-TST|2-TST|3-b2b',
            'invoice_type' => '1000',
        ]));
        $app['config']->set('zatca.b2b', ['enabled' => true, 'egs_identifier' => self::B2B]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Real console output, so the text a developer sees can be asserted.
        $this->withoutMockingConsoleOutput();

        $this->zatca = new MockHandler;
        $stack = HandlerStack::create($this->zatca);
        $stack->push(Middleware::mapRequest(function ($request) {
            $this->sent[] = $request;

            return $request;
        }));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    // ---- Step 2: compliance -------------------------------------------------

    public function test_compliance_without_an_otp_fails_before_contacting_zatca(): void
    {
        $this->afterGenerateCredentials(self::B2C);

        [$exit, $output] = $this->onboard('compliance', self::B2C);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--otp is required', $output);
        $this->assertSame([], $this->sent);
        $this->assertNull($this->credentials(self::B2C, ZatcaCredentialType::Compliance)->binarySecurityToken);
    }

    public function test_compliance_before_generate_credentials_fails_before_contacting_zatca(): void
    {
        foreach ([self::B2C, self::B2B] as $egs) {
            [$exit, $output] = $this->onboard('compliance', $egs, ['--otp' => '123456']);

            $this->assertSame(1, $exit);
            $this->assertStringContainsString("No CSR on record for EGS [{$egs}]", $output);
            $this->assertStringContainsString('zatca:generate-credentials', $output);
        }

        $this->assertSame([], $this->sent);
    }

    /** ZATCA's documented error for a wrong or expired OTP. */
    public function test_a_rejected_otp_stores_nothing_and_the_step_can_be_retried_with_a_new_otp(): void
    {
        $this->afterGenerateCredentials(self::B2C);
        $csrBefore = $this->store()->getCsr(self::B2C, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance);

        $this->zatca->append(new Response(400, [], '{"errors":[{"code":"Invalid-OTP","message":"The provided OTP is invalid"}]}'));
        [$exit, $output] = $this->onboard('compliance', self::B2C, ['--otp' => '111111']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('The provided OTP is invalid', $output);
        $this->assertCount(1, $this->sent);
        $this->assertSame('111111', $this->sent[0]->getHeaderLine('OTP'));

        $compliance = $this->credentials(self::B2C, ZatcaCredentialType::Compliance);
        $this->assertNull($compliance->binarySecurityToken);
        $this->assertNull($compliance->secret);
        $this->assertNull($compliance->requestId);
        $this->assertSame('FAKE_PRIVATE_KEY', $compliance->privateKey, 'The key pair must survive a refused OTP.');
        $this->assertSame($csrBefore, $this->store()->getCsr(self::B2C, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance));

        // Retried with a fresh OTP, the same CSR goes through.
        $this->zatca->append(new Response(200, [], json_encode([
            'requestID' => 1234567890123,
            'dispositionMessage' => 'ISSUED',
            'binarySecurityToken' => $this->sampleBinarySecurityToken(),
            'secret' => 'sample-secret',
            'errors' => null,
        ])));
        [$exit] = $this->onboard('compliance', self::B2C, ['--otp' => '222222']);

        $this->assertSame(0, $exit);
        $this->assertSame('222222', $this->sent[1]->getHeaderLine('OTP'));
        $this->assertSame(json_decode((string) $this->sent[0]->getBody(), true), json_decode((string) $this->sent[1]->getBody(), true), 'The retry must send the same CSR.');

        $compliance = $this->credentials(self::B2C, ZatcaCredentialType::Compliance);
        $this->assertSame('1234567890123', $compliance->requestId);
        $this->assertSame('sample-secret', $compliance->secret);
        $this->assertNotNull($compliance->certificateExpiresAt);
    }

    // ---- Steps 3–5: compliance checks -----------------------------------------

    public function test_a_compliance_check_before_the_compliance_csid_fails_without_spending_an_icv(): void
    {
        foreach ([self::B2C, self::B2B] as $egs) {
            $this->afterGenerateCredentials($egs);

            [$exit, $output] = $this->onboard('test-invoice', $egs);
            $this->assertSame(1, $exit, "[test-invoice] on [{$egs}]");
            $this->assertStringContainsString("No Compliance CSID on record for EGS [{$egs}]", $output);
            $this->assertStringContainsString('zatca:onboard compliance', $output);

            // The notes point at the step right before them; test-invoice then points at compliance.
            foreach (['test-credit-note', 'test-debit-note'] as $step) {
                [$exit, $output] = $this->onboard($step, $egs);

                $this->assertSame(1, $exit, "[{$step}] on [{$egs}]");
                $this->assertStringContainsString('Run `zatca:onboard test-invoice` first', $output);
            }

            $this->assertSame(0, app(ComplianceInvoiceCounterManager::class)->current($egs, ZatcaEnvironment::Sandbox), "No ICV may be spent on [{$egs}].");
        }

        $this->assertSame([], $this->sent);
        $this->assertSame(0, ZatcaComplianceDocument::query()->count());
    }

    public function test_note_checks_before_the_invoice_check_fail_without_spending_an_icv(): void
    {
        foreach ([self::B2C, self::B2B] as $egs) {
            $this->afterComplianceCsid($egs);

            foreach (['test-credit-note', 'test-debit-note'] as $step) {
                [$exit, $output] = $this->onboard($step, $egs);

                $this->assertSame(1, $exit, "[{$step}] on [{$egs}]");
                $this->assertStringContainsString('Run `zatca:onboard test-invoice` first', $output);
            }

            $this->assertSame(0, app(ComplianceInvoiceCounterManager::class)->current($egs, ZatcaEnvironment::Sandbox), "No ICV may be spent on [{$egs}].");
        }

        $this->assertSame([], $this->sent);
        $this->assertSame(0, ZatcaComplianceDocument::query()->count());
    }

    /** A B2B note must bill against a Standard compliance invoice, never a Simplified one. */
    public function test_a_b2b_note_check_does_not_bill_against_a_b2c_compliance_invoice(): void
    {
        $this->afterComplianceCsid(self::B2B);
        ZatcaComplianceDocument::create([
            'egs_identifier' => self::B2B,
            'document_type' => 'simplified_invoice',
            'document_reference' => 'compliance-simplified-1',
            'uuid' => 'simplified-on-b2b',
            'icv' => 1,
            'invoice_hash' => 'h',
            'previous_invoice_hash' => 'p',
            'unsigned_xml' => '<x/>',
            'signed_xml_base64' => 'eA==',
            'reporting_status' => 'REPORTED',
        ]);

        [$exit, $output] = $this->onboard('test-credit-note', self::B2B);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No compliant standard invoice on record', $output);
        $this->assertSame([], $this->sent);
    }

    // ---- Step 6: production -------------------------------------------------

    public function test_production_before_the_compliance_csid_fails_before_contacting_zatca(): void
    {
        foreach ([self::B2C, self::B2B] as $egs) {
            [$exit] = $this->onboard('production', $egs);
            $this->assertSame(1, $exit);

            $this->afterGenerateCredentials($egs);
            [$exit, $output] = $this->onboard('production', $egs);
            $this->assertSame(1, $exit);
            $this->assertStringContainsString("No compliance request ID on record for EGS [{$egs}]", $output);

            $this->assertFalse($this->store()->has($egs, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production));
        }

        $this->assertSame([], $this->sent);
    }

    /**
     * Real ZATCA refuses a Production CSID until every compliance check passed. The
     * refusal text is the one quoted in ComplianceOnboardingOrchestrator; the exact body
     * shape is not documented, so the documented `errors` list is used.
     */
    public function test_a_production_csid_refused_by_zatca_stores_nothing(): void
    {
        $this->afterComplianceCsid(self::B2C);
        $this->zatca->append(new Response(400, [], json_encode(['errors' => [[
            'code' => 'Invalid-Request',
            'message' => 'compliance certificate is not done with the following compliance steps yet [simplified-credit-note-compliant, simplified-debit-note-compliant]',
        ]]])));

        [$exit, $output] = $this->onboard('production', self::B2C);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Production CSID request failed', $output);
        $this->assertStringContainsString('simplified-credit-note-compliant', $output);
        $this->assertCount(1, $this->sent);
        $this->assertSame(['compliance_request_id' => 'req-'.self::B2C], json_decode((string) $this->sent[0]->getBody(), true));
        $this->assertFalse($this->store()->has(self::B2C, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production));
        $this->assertSame('TOKEN-'.self::B2C, $this->credentials(self::B2C, ZatcaCredentialType::Compliance)->binarySecurityToken, 'The compliance CSID must be kept.');
    }

    public function test_an_unknown_step_is_refused(): void
    {
        [$exit, $output] = $this->onboard('produtcion', self::B2C);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown step [produtcion]', $output);
        $this->assertSame([], $this->sent);
    }

    // ---- Helpers --------------------------------------------------------------

    /** @return array{0: int, 1: string} exit code and output */
    private function onboard(string $step, string $egs, array $options = []): array
    {
        $exit = Artisan::call('zatca:onboard', ['step' => $step, '--egs' => $egs] + $options);

        return [$exit, Artisan::output()];
    }

    /** The state zatca:generate-credentials leaves: a CSR and a private key, no certificate. */
    private function afterGenerateCredentials(string $egs): void
    {
        $this->store()->store($egs, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance, [
            'csr' => base64_encode("-----BEGIN CERTIFICATE REQUEST-----\nFAKE-{$egs}\n-----END CERTIFICATE REQUEST-----"),
            'private_key' => 'FAKE_PRIVATE_KEY',
        ]);
    }

    /** The state a successful compliance step leaves. */
    private function afterComplianceCsid(string $egs): void
    {
        $this->afterGenerateCredentials($egs);
        $this->store()->store($egs, ZatcaEnvironment::Sandbox, ZatcaCredentialType::Compliance, [
            'binary_security_token' => 'TOKEN-'.$egs,
            'secret' => 'SECRET-'.$egs,
            'request_id' => 'req-'.$egs,
            'certificate' => 'TOKEN-'.$egs,
        ]);
    }

    /** ZATCA's public SDK sample certificate, in CSID form: base64 of the base64 DER. */
    private function sampleBinarySecurityToken(): string
    {
        $pem = (string) file_get_contents(__DIR__.'/../../SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8/Data/Certificates/cert.pem');

        return base64_encode((string) preg_replace('#-----[^-]+-----|\s#', '', $pem));
    }

    private function credentials(string $egs, ZatcaCredentialType $type): \Zatca\DTOs\ZatcaCredentialSet
    {
        return $this->store()->get($egs, ZatcaEnvironment::Sandbox, $type);
    }

    private function store(): ZatcaCredentialStore
    {
        return app(ZatcaCredentialStore::class);
    }
}

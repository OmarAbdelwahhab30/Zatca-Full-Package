<?php

namespace Zatca\Tests\Sandbox;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Enums\ZatcaInvoiceStatus;
use Zatca\Enums\ZatcaSubmissionModel;
use Zatca\Jobs\GenerateZatcaInvoiceJob;
use Zatca\Models\ZatcaInvoice;
use Zatca\Models\ZatcaSubmissionAttempt;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Tests\Sandbox\Fixtures\SandboxOrder;
use Zatca\Tests\Sandbox\Fixtures\SandboxOrderInvoiceSource;
use Zatca\Tests\TestCase;

/**
 * Base for the tests that talk to ZATCA's real developer-portal sandbox.
 *
 * Opt-in. Skipped unless all three are set:
 *
 *   ZATCA_RUN_SANDBOX_TESTS=1
 *   ZATCA_SDK_ROOT=<path>/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8
 *   ZATCA_SDK_EXECUTABLE=$ZATCA_SDK_ROOT/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar
 *
 * plus Java 11 to 14 on the PATH (the SDK's own requirement).
 *
 * The package is used exactly as a host app uses it: artisan commands for onboarding, a
 * real Eloquent order, the host's InvoiceSourceContract, and GenerateZatcaInvoiceJob /
 * CreditNoteIssuer / DebitNoteIssuer on a sync queue. Nothing is mocked. The only thing
 * added is a guard on the HTTP client: every request must go to the developer-portal
 * sandbox, or the test stops before anything is sent.
 *
 * Isolation: a fresh in-memory database and fresh EGSs (unique CSR serial numbers) per
 * test, the dummy sandbox VAT number 399999999900003, and simulation/production base URLs
 * that point nowhere.
 *
 * Known sandbox limitation (measured 2026-10-15): the compliance certificate is issued for
 * our CSR, but the production certificate is a fixed, shared one that does not match the
 * EGS's private key, and the sandbox still accepts invoices signed with that pair. So these
 * tests prove the API contract, the XML and ZATCA's business rules, and the package's whole
 * flow — NOT the production signature. That needs the Simulation environment.
 */
abstract class SandboxTestCase extends TestCase
{
    protected const HOST = 'gw-fatoora.zatca.gov.sa';

    protected const PATH_PREFIX = '/e-invoicing/developer-portal/';

    /** ZATCA's documented dummy VAT number for the sandbox. */
    protected const SANDBOX_VAT_NUMBER = '399999999900003';

    /** The sandbox's fixed OTP. */
    protected const SANDBOX_OTP = '123345';

    protected const B2C_EGS = 'default';

    protected const B2B_EGS = 'b2b';

    /** @var list<array{method: string, path: string, headers: array<string, string>, authorized: bool, request: mixed, status: ?int, body: mixed}> */
    protected array $zatcaCalls = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];

        $config->set('zatca.sdk.root', getenv('ZATCA_SDK_ROOT') ?: null);
        $config->set('zatca.sdk.executable', getenv('ZATCA_SDK_EXECUTABLE') ?: null);

        $config->set('zatca.http.base_urls', [
            'sandbox' => 'https://'.self::HOST.rtrim(self::PATH_PREFIX, '/'),
            'simulation' => 'https://blocked.invalid',
            'production' => 'https://blocked.invalid',
        ]);
        $config->set('zatca.http.accept_language', 'en');

        $config->set('queue.default', 'sync');
        $config->set('zatca.queue.connection', 'sync');

        $seller = [
            'environment' => 'sandbox',
            'vat_number' => self::SANDBOX_VAT_NUMBER,
            'organization_identifier' => self::SANDBOX_VAT_NUMBER,
            'organization_name' => 'Sandbox Trading Co',
            'organization_unit_name' => 'Riyadh Branch',
            'company_name_ar' => 'شركة التجربة للتجارة',
            'country_code' => 'SA',
            'business_category' => 'Retail',
            'commercial_registration_number' => '1010010000',
            'location_address' => null,
            'street' => 'King Fahd Road',
            'building_number' => '2322',
            'additional_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12214',
        ];

        $config->set('zatca.primary_egs', self::B2C_EGS);
        $config->set('zatca.egs', [
            self::B2C_EGS => $seller + [
                'common_name' => 'TST-886431145-'.self::SANDBOX_VAT_NUMBER,
                'serial_number' => '1-TST|2-TST|3-'.Str::uuid(),
                'invoice_type' => '0100',
            ],
            self::B2B_EGS => $seller + [
                'common_name' => 'TST-886431146-'.self::SANDBOX_VAT_NUMBER,
                'serial_number' => '1-TST|2-TST|3-'.Str::uuid(),
                'invoice_type' => '1000',
            ],
        ]);
        $config->set('zatca.b2b', ['enabled' => true, 'egs_identifier' => self::B2B_EGS]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('ZATCA_RUN_SANDBOX_TESTS') !== '1') {
            $this->markTestSkipped('ZATCA sandbox tests are opt-in: set ZATCA_RUN_SANDBOX_TESTS=1 (they call ZATCA\'s developer-portal sandbox).');
        }

        $root = getenv('ZATCA_SDK_ROOT') ?: '';
        $executable = getenv('ZATCA_SDK_EXECUTABLE') ?: '';

        if (! is_dir($root) || ! is_file($executable)) {
            $this->markTestSkipped('ZATCA sandbox tests need the real SDK: set ZATCA_SDK_ROOT and ZATCA_SDK_EXECUTABLE.');
        }

        foreach (config('zatca.egs') as $identifier => $egs) {
            if ($egs['environment'] !== ZatcaEnvironment::Sandbox->value) {
                $this->fail("Refusing to run: EGS [{$identifier}] is not a sandbox EGS.");
            }
        }

        // Real console output, so a failing step shows what the command printed.
        $this->withoutMockingConsoleOutput();
        $this->bindGuardedHttpClient();

        Schema::create('sandbox_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->boolean('is_company')->default(false);
            $table->json('items');
            $table->timestamps();
        });

        // What a host app does in its AppServiceProvider.
        $this->app->bind(InvoiceSourceContract::class, SandboxOrderInvoiceSource::class);
    }

    protected function tearDown(): void
    {
        // An unsigned draft left by a failed run would break the package's own
        // down-migrations on SQLite; the database is discarded either way.
        try {
            ZatcaSubmissionAttempt::query()->delete();
            ZatcaInvoice::query()->delete();
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    /** Runs the full onboarding for one EGS with the package's own artisan commands. */
    protected function onboard(string $egs): void
    {
        $this->runCommand('zatca:generate-credentials', ['--egs' => $egs]);
        $this->runCommand('zatca:onboard', ['step' => 'compliance', '--egs' => $egs, '--otp' => self::SANDBOX_OTP]);
        $this->runCommand('zatca:onboard', ['step' => 'test-invoice', '--egs' => $egs]);
        $this->runCommand('zatca:onboard', ['step' => 'test-credit-note', '--egs' => $egs]);
        $this->runCommand('zatca:onboard', ['step' => 'test-debit-note', '--egs' => $egs]);
        $this->runCommand('zatca:onboard', ['step' => 'production', '--egs' => $egs]);
    }

    /** Runs one artisan command, asserts it succeeded, and returns what it printed. */
    protected function runCommand(string $command, array $arguments): string
    {
        $exitCode = Artisan::call($command, $arguments);
        $printed = Artisan::output();

        $this->assertSame(0, $exitCode, "[{$command} ".($arguments['step'] ?? '')."] failed:\n{$printed}\n".$this->lastCallSummary());

        return $printed;
    }

    /** @param  list<array{0: string, 1: int, 2: int|float|string}>  $items  [name, quantity, price incl. VAT] */
    protected function order(array $items, bool $company = false): SandboxOrder
    {
        return SandboxOrder::create([
            'reference' => ($company ? 'B2B-' : 'B2C-').now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'is_company' => $company,
            'items' => array_map(fn (array $i) => ['name' => $i[0], 'quantity' => $i[1], 'price' => $i[2]], $items),
        ]);
    }

    /** Invoices an order as a host app would; the sync queue then reports or clears it. */
    protected function invoice(SandboxOrder $order, string $egs): ZatcaInvoice
    {
        dispatch(GenerateZatcaInvoiceJob::forModel($order, $egs));

        return ZatcaInvoice::query()
            ->where('egs_identifier', $egs)
            ->where('source_reference', $order->reference)
            ->sole();
    }

    /** Invoices an order and asserts ZATCA accepted it (reported, or cleared). */
    protected function acceptedInvoice(SandboxOrder $order, string $egs): ZatcaInvoice
    {
        $invoice = $this->invoice($order, $egs)->refresh();
        $this->assertAccepted($invoice);

        return $invoice;
    }

    /**
     * ZATCA accepted the document cleanly — Reported or Cleared, with NO warnings — through
     * the endpoint its kind requires. A warning is a failure here: ZATCA accepts it today,
     * but it marks data the package got wrong (the BR-KSA-F-13 compliance buyer ID was
     * found exactly this way).
     */
    protected function assertAccepted(ZatcaInvoice $document): void
    {
        $document->refresh();
        $clearance = $document->submission_model === ZatcaSubmissionModel::Clearance;

        $this->assertSame(
            $clearance ? ZatcaInvoiceStatus::Cleared : ZatcaInvoiceStatus::Reported,
            $document->status,
            "[{$document->document_type?->value} {$document->source_reference}] not cleanly accepted (status {$document->status->value}): {$document->last_error}\n"
                .json_encode($document->last_zatca_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n".$this->lastCallSummary()
        );
        $this->assertNull($document->last_error);
        $this->assertNotNull($clearance ? $document->cleared_at : $document->reported_at);
    }

    /** A note's own document, generated and submitted by the sync queue when the issuer committed. */
    protected function noteDocument(string $column, int $noteId): ZatcaInvoice
    {
        return ZatcaInvoice::query()->where($column, $noteId)->sole();
    }

    protected function assertBillsAgainst(ZatcaInvoice $note, ZatcaInvoice $original): void
    {
        $this->assertStringContainsString(
            '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>'.$original->source_reference.'</cbc:ID>',
            $note->unsigned_xml
        );
    }

    /** @param  list<ZatcaInvoice>  $documents  in issue order, on one EGS */
    protected function assertUnbrokenChain(array $documents, int $firstIcv = 1): void
    {
        foreach ($documents as $position => $document) {
            $this->assertSame($firstIcv + $position, (int) $document->icv, 'ICV out of sequence.');

            $expectedPih = $position === 0 ? null : $documents[$position - 1]->invoice_hash;

            if ($expectedPih !== null) {
                $this->assertSame($expectedPih, $document->previous_invoice_hash, 'PIH does not link to the previous document.');
                $this->assertStringContainsString($expectedPih, (string) base64_decode($document->signed_xml_base64), 'The signed XML carries a different PIH.');
            }
        }
    }

    /** @param  list<string>  $expected  [excl. VAT, VAT, incl. VAT] */
    protected function assertTotals(array $expected, ZatcaInvoice $document): void
    {
        $this->assertSame($expected, [(string) $document->total_excl_vat, (string) $document->total_vat, (string) $document->total_incl_vat]);
    }

    /** Every submission went to the endpoint its model requires, never the other one. */
    protected function assertOnlySentTo(string $endpoint, int $count): void
    {
        $other = $endpoint === '/invoices/clearance/single' ? '/invoices/reporting/single' : '/invoices/clearance/single';
        $calls = $this->callsTo($endpoint);

        $this->assertCount($count, $calls);
        $this->assertSame([], $this->callsTo($other), "Something was sent to [{$other}].");

        foreach ($calls as $call) {
            $this->assertSame($endpoint === '/invoices/clearance/single' ? '1' : '0', $call['headers']['Clearance-Status'] ?? null);
            $this->assertSame('V2', $call['headers']['Accept-Version'] ?? null);
        }
    }

    /** ZATCA issued the compliance certificate for the CSR the package generated: same public key. */
    protected function assertComplianceCertificateMatchesGeneratedKey(string $egs): void
    {
        $compliance = $this->credentials($egs, ZatcaCredentialType::Compliance);

        $this->assertSame(
            $this->ecPointOf(openssl_pkey_get_private($this->privateKeyPem((string) $compliance->privateKey))),
            $this->ecPointOf(openssl_pkey_get_public($this->certificatePem((string) $compliance->binarySecurityToken))),
            "The compliance certificate for EGS [{$egs}] does not belong to the key the package generated."
        );
    }

    /** A CSID's binarySecurityToken is base64 of the certificate's base64 DER. */
    protected function certificatePem(string $binarySecurityToken): string
    {
        $der = base64_decode((string) base64_decode($binarySecurityToken, true), true);

        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode((string) $der), 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /** The package stores the SDK's key without PEM armour. */
    protected function privateKeyPem(string $raw): string
    {
        return str_contains($raw, '-----BEGIN') ? $raw
            : "-----BEGIN EC PRIVATE KEY-----\n".chunk_split($raw, 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /** The EC public point of a key, to compare keys, CSRs and certificates. */
    protected function ecPointOf($key): string
    {
        $this->assertNotFalse($key, 'Could not read the key, CSR or certificate.');
        $details = openssl_pkey_get_details($key);

        return bin2hex($details['ec']['x']).bin2hex($details['ec']['y']);
    }

    protected function credentials(string $egs, ZatcaCredentialType $type): \Zatca\DTOs\ZatcaCredentialSet
    {
        return app(ZatcaCredentialStore::class)->get($egs, ZatcaEnvironment::Sandbox, $type);
    }

    /** @return list<array{method: string, path: string, headers: array<string, string>, status: ?int, body: mixed}> */
    protected function callsTo(string $pathSuffix): array
    {
        return array_values(array_filter(
            $this->zatcaCalls,
            fn (array $call) => str_ends_with($call['path'], $pathSuffix)
        ));
    }

    protected function lastCallSummary(): string
    {
        $last = end($this->zatcaCalls);

        return $last ? 'Last ZATCA call: '.json_encode($last, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : 'No ZATCA call was made.';
    }

    /**
     * Binds the Guzzle client the package's HttpConnector receives. It is the stock client
     * plus two middlewares: a guard that refuses anything but the developer-portal sandbox,
     * and a recorder (headers and redacted bodies only — never Authorization, tokens,
     * secrets, CSRs or invoices).
     */
    private function bindGuardedHttpClient(): void
    {
        $stack = HandlerStack::create();

        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            $uri = $request->getUri();

            if ($uri->getScheme() !== 'https' || $uri->getHost() !== self::HOST || ! str_starts_with($uri->getPath(), self::PATH_PREFIX)) {
                throw new \LogicException("Sandbox guard: refused a request to [{$uri}]. Only the ZATCA developer-portal sandbox is allowed.");
            }

            return $request;
        }), 'sandbox-guard');

        $stack->push(fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler) {
            $requestBody = (string) $request->getBody();
            $request->getBody()->rewind();

            $call = [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'headers' => array_map(fn (array $v) => implode(',', $v), array_intersect_key(
                    $request->getHeaders(),
                    array_flip(['Accept-Version', 'Accept-Language', 'Clearance-Status', 'OTP'])
                )),
                // Whether Basic auth was sent — never its value.
                'authorized' => str_starts_with($request->getHeaderLine('Authorization'), 'Basic '),
                // CSR and invoice are redacted; invoiceHash, uuid and compliance_request_id are kept.
                'request' => self::redact(json_decode($requestBody, true)),
                'status' => null,
                'body' => null,
            ];

            return $handler($request, $options)->then(function (ResponseInterface $response) use ($call) {
                $call['status'] = $response->getStatusCode();
                $call['body'] = self::redact(json_decode((string) $response->getBody(), true));
                $response->getBody()->rewind();
                $this->zatcaCalls[] = $call;

                return $response;
            }, function ($reason) use ($call) {
                $call['body'] = 'transport error: '.(is_object($reason) ? $reason->getMessage() : (string) $reason);
                $this->zatcaCalls[] = $call;

                return Create::rejectionFor($reason);
            });
        }, 'sandbox-recorder');

        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    private static function redact(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (in_array($key, ['binarySecurityToken', 'secret', 'clearedInvoice', 'csr', 'invoice'], true) && is_string($value)) {
                $data[$key] = '<redacted, '.strlen($value).' chars>';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}

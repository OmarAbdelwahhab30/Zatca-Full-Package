<?php

namespace Zatca\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Zatca\Contracts\InvoiceSourceContract;
use Zatca\Contracts\ZatcaSdkInterface;
use Zatca\DTOs\ZatcaInvoiceDiscount;
use Zatca\DTOs\ZatcaInvoiceInput;
use Zatca\DTOs\ZatcaInvoiceItem;
use Zatca\Enums\VatPriceMode;
use Zatca\Enums\ZatcaCredentialType;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\Services\Execute\ProductionInvoiceOrchestrator;
use Zatca\Services\Security\ZatcaCredentialStore;
use Zatca\Tests\TestCase;

/**
 * Generates invoices from prices with the package's VAT calculation, signs them with the
 * REAL ZATCA SDK, and runs the SDK's own validator (`fatoora -validate`) on the result:
 * no amount, VAT-breakdown or decimal rule of ZATCA's schematrons may fail.
 *
 * Opt-in, like ProductionInvoiceOrchestratorTest: skipped unless ZATCA_SDK_EXECUTABLE and
 * ZATCA_SDK_ROOT point at an SDK, for example the copy in SDKs/:
 *
 *   ZATCA_SDK_ROOT=$PWD/SDKs/zatca-einvoicing-sdk-Java-238-R3.4.8 \
 *   ZATCA_SDK_EXECUTABLE=$ZATCA_SDK_ROOT/Apps/zatca-einvoicing-sdk-238-R3.4.8.jar vendor/bin/phpunit
 *
 * Signed with the SDK's public sample certificate, so certificate/signature rules are out
 * of scope here. It proves the SDK accepts the amounts, not that ZATCA's server will.
 */
class VatRealSdkValidationTest extends TestCase
{
    /** Rule families that judge amounts, VAT breakdowns and decimals. */
    private const AMOUNT_RULES = '/\b(BR-CO-\d+|BR-[SZE]-\d+|BR-DEC-\d+|BR-KSA-DEC-\d+|BR-KSA-EN16931-(07|08|09|11|12)|BR-KSA-5[01]|BR-KSA-F-04|BR-\d+)\b/';

    protected function setUp(): void
    {
        parent::setUp();

        $executable = getenv('ZATCA_SDK_EXECUTABLE') ?: null;
        $root = getenv('ZATCA_SDK_ROOT') ?: null;

        if (! $executable || ! is_file($executable) || ! $root || ! is_dir($root) || ! is_file($root.'/Data/Certificates/cert.pem')) {
            $this->markTestSkipped('ZATCA SDK not configured in this environment (set ZATCA_SDK_EXECUTABLE and ZATCA_SDK_ROOT to run).');
        }

        Config::set('zatca.sdk.executable', $executable);
        Config::set('zatca.sdk.root', $root);

        app(ZatcaCredentialStore::class)->store('default', ZatcaEnvironment::Sandbox, ZatcaCredentialType::Production, [
            'binary_security_token' => 'FAKE_TOKEN',
            'secret' => 'FAKE_SECRET',
            'certificate' => trim((string) file_get_contents($root.'/Data/Certificates/cert.pem')),
            'private_key' => trim((string) file_get_contents($root.'/Data/Certificates/ec-secp256k1-priv-key.pem')),
        ]);
    }

    /**
     * @dataProvider invoices
     */
    #[DataProvider('invoices')]
    public function test_the_sdk_validator_finds_no_amount_errors(int $sourceId, VatPriceMode $mode, array $items, array $discounts): void
    {
        $invoice = app(ProductionInvoiceOrchestrator::class)->generate($this->source($items, $discounts, $mode), (object) ['id' => $sourceId], 'default');

        $signed = tempnam(sys_get_temp_dir(), 'zatca-vat-').'.xml';
        file_put_contents($signed, base64_decode((string) $invoice->signed_xml_base64));

        try {
            $result = app(ZatcaSdkInterface::class)->validateInvoice($signed, Config::get('zatca.sdk.root').'/Data/Certificates/cert.pem');
        } finally {
            @unlink($signed);
        }

        // Errors AND warnings: SDK 3.4.8 reports some amount rules, BR-KSA-EN16931-11 among
        // them, as warnings rather than errors.
        $findings = $this->findings($result);
        $amountFindings = array_values(array_filter($findings, fn (string $finding) => preg_match(self::AMOUNT_RULES, $finding) === 1));

        $this->assertSame([], $amountFindings, 'SDK reported amount-rule findings. All findings: '.implode(' | ', $findings));
    }

    /** Control: the check above is live — a wrong line amount IS reported by the SDK. */
    public function test_a_tampered_line_amount_is_reported(): void
    {
        $invoice = app(ProductionInvoiceOrchestrator::class)->generate(
            $this->source([new ZatcaInvoiceItem('Bulk item', 10, '100.00')], [], VatPriceMode::Inclusive),
            (object) ['id' => 9809],
            'default',
        );

        $xml = str_replace(
            '<cbc:PriceAmount currencyID="SAR">86.957</cbc:PriceAmount>',
            '<cbc:PriceAmount currencyID="SAR">86.96</cbc:PriceAmount>',
            base64_decode((string) $invoice->signed_xml_base64),
            $replaced,
        );
        $this->assertSame(1, $replaced);

        $signed = tempnam(sys_get_temp_dir(), 'zatca-vat-').'.xml';
        file_put_contents($signed, $xml);

        try {
            $result = app(ZatcaSdkInterface::class)->validateInvoice($signed, Config::get('zatca.sdk.root').'/Data/Certificates/cert.pem');
        } finally {
            @unlink($signed);
        }

        // 10 × 86.96 = 869.60, three halalas off the line net 869.57.
        $this->assertMatchesRegularExpression('/BR-KSA-EN16931-11/', implode(' | ', $this->findings($result)));
    }

    /** @return string[] every error and warning the SDK reported */
    private function findings(array $result): array
    {
        return array_map('strval', [...$result['errors'], ...$result['warnings']]);
    }

    public static function invoices(): array
    {
        return [
            'inclusive, 10 × 100.00' => [9801, VatPriceMode::Inclusive, [new ZatcaInvoiceItem('Bulk item', 10, '100.00')], []],
            'inclusive, 3 × 33.33 and 7 × 14.99' => [9802, VatPriceMode::Inclusive, [new ZatcaInvoiceItem('Small item', 3, '33.33'), new ZatcaInvoiceItem('Other item', 7, '14.99')], []],
            'inclusive, mixed rates, zero-rated, discount' => [9803, VatPriceMode::Inclusive, [
                new ZatcaInvoiceItem('Bulk item', 10, '100.00'),
                new ZatcaInvoiceItem('Reduced-rate item', 2, '52.50', vatRate: 5),
                ZatcaInvoiceItem::zeroRated('Export shipment', 1, 200, 'VATEX-SA-32', 'Export of goods'),
            ], [new ZatcaInvoiceDiscount('Bulk discount', 23, lineIndexes: [0])]],
            'exclusive, mixed rates, discount' => [9804, VatPriceMode::Exclusive, [
                new ZatcaInvoiceItem('Small item', 3, '33.33'),
                new ZatcaInvoiceItem('Reduced-rate item', 2, '52.50', vatRate: 5),
            ], [new ZatcaInvoiceDiscount('Coupon', 10, lineIndexes: [0])]],
        ];
    }

    private function source(array $items, array $discounts, VatPriceMode $mode): InvoiceSourceContract
    {
        return new class($items, $discounts, $mode) implements InvoiceSourceContract
        {
            public function __construct(private readonly array $items, private readonly array $discounts, private readonly VatPriceMode $mode) {}

            public function toInvoiceInput(mixed $source, string $egsIdentifier): ZatcaInvoiceInput
            {
                return new ZatcaInvoiceInput(
                    egsIdentifier: $egsIdentifier,
                    sourceReference: 'INV-SDK-'.$source->id,
                    buyerName: 'Buyer',
                    buyerVatNumber: null,
                    issueDateTime: new DateTimeImmutable('2026-10-14T10:00:00+03:00'),
                    currency: 'SAR',
                    items: $this->items,
                    discounts: $this->discounts,
                    priceMode: $this->mode,
                );
            }

            public function sourceReference(mixed $source): string
            {
                return 'INV-SDK-'.$source->id;
            }
        };
    }
}

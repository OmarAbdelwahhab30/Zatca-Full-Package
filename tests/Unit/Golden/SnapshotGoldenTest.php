<?php

namespace Zatca\Tests\Unit\Golden;

use ReflectionClass;
use ReflectionNamedType;
use Zatca\DTOs\ZatcaAllowanceCharge;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\DTOs\ZatcaInvoiceLine;
use Zatca\Exceptions\ZatcaException;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Freezes the business snapshot of every golden B2C document, and the DTO
 * contract the host builds documents with.
 *
 * InvoiceDataSnapshot promises that every existing snapshot keeps its exact shape
 * (v1 for invoices, v2 for adjustment notes). B2B adds buyer data and a new
 * snapshot version; this suite proves the B2C shapes did not move, that a stored
 * snapshot still regenerates the very same XML, and that the constructors the host
 * calls still accept exactly what the host passes.
 *
 * Fixtures are canonical JSON (pretty-printed, zero fractions and Unicode kept) so
 * a float silently becoming an int, or a key appearing, shows up in the diff.
 * Rewrite them only with `composer golden:update` and review the diff.
 *
 * @group golden
 */
#[Group('golden')]
class SnapshotGoldenTest extends TestCase
{
    use GoldenDocuments;

    private const FIXTURE_DIR = __DIR__.'/../../Fixtures/Golden/V2/snapshots';

    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @dataProvider documents */
    #[DataProvider('documents')]
    public function test_snapshot_matches_the_golden_fixture(string $fixture): void
    {
        $json = json_encode(InvoiceDataSnapshot::toArray(self::goldenDocuments()[$fixture]['data']), self::JSON_FLAGS)."\n";
        $path = self::FIXTURE_DIR.'/'.$fixture.'.json';

        if (getenv('ZATCA_GOLDEN_UPDATE') === '1') {
            if (! is_dir(self::FIXTURE_DIR)) {
                mkdir(self::FIXTURE_DIR, 0777, true);
            }
            file_put_contents($path, $json);
            $this->markTestIncomplete("Golden snapshot [{$fixture}] rewritten; review the diff before committing.");
        }

        $this->assertFileExists($path, "Golden snapshot [{$fixture}] is missing. Generate it with `composer golden:update` and review it.");
        $this->assertSame(self::normalise((string) file_get_contents($path)), $json, "Snapshot for [{$fixture}] changed.");
    }

    /**
     * Restoring a snapshot and freezing it again must give back exactly the same array.
     *
     * @dataProvider documents
     */
    #[DataProvider('documents')]
    public function test_snapshot_round_trips_without_change(string $fixture): void
    {
        $snapshot = InvoiceDataSnapshot::toArray(self::goldenDocuments()[$fixture]['data']);

        $this->assertTrue(InvoiceDataSnapshot::isUsable($snapshot));
        $this->assertSame($snapshot, InvoiceDataSnapshot::toArray(InvoiceDataSnapshot::fromArray($snapshot)));
    }

    /**
     * The path a real regeneration takes: the snapshot is stored through Eloquent's
     * `array` cast (plain json_encode, so 200.0 is stored as 200), read back, and the
     * XML rebuilt from it. That XML must be the golden XML fixture, byte for byte.
     *
     * @dataProvider documents
     */
    #[DataProvider('documents')]
    public function test_stored_snapshot_regenerates_the_golden_xml(string $fixture): void
    {
        $case = self::goldenDocuments()[$fixture];
        $stored = json_decode(json_encode(InvoiceDataSnapshot::toArray($case['data'])), true);

        $xml = self::normalise(self::goldenXml($case, InvoiceDataSnapshot::fromArray($stored)));

        $this->assertSame(
            self::normalise((string) file_get_contents(SimplifiedXmlGoldenTest::FIXTURE_DIR.'/'.$fixture.'.xml')),
            $xml,
            "XML regenerated from the stored snapshot of [{$fixture}] differs from the golden XML."
        );
    }

    /**
     * Versions 1 and 2 (Simplified) and 3 (Standard, P21) are understood; anything newer
     * is refused, not hydrated with defaults. A Simplified snapshot relabelled as v3 is
     * refused too: v3 must carry a Standard document type and a buyer.
     *
     * @dataProvider unusableVersions
     */
    #[DataProvider('unusableVersions')]
    public function test_an_unknown_snapshot_version_is_refused(int $version): void
    {
        $snapshot = InvoiceDataSnapshot::toArray(self::goldenDocuments()['invoice_single']['data']);
        $snapshot['version'] = $version;

        $this->assertFalse(InvoiceDataSnapshot::isUsable($snapshot));

        $this->expectException(ZatcaException::class);
        InvoiceDataSnapshot::fromArray($snapshot);
    }

    public static function unusableVersions(): array
    {
        return ['v3 holding Simplified data' => [3], 'v4' => [4], 'v0' => [0]];
    }

    public function test_every_fixture_file_has_a_case(): void
    {
        $cases = array_keys(self::goldenDocuments());
        $files = array_map(fn (string $f) => basename($f, '.json'), glob(self::FIXTURE_DIR.'/*.json') ?: []);

        sort($cases);
        sort($files);

        $this->assertSame($cases, $files);
    }

    /**
     * app/Zatca/PaymentInvoiceSource and TransactionInvoiceSource build these DTOs with
     * named arguments. B2B may only ADD optional parameters after the existing ones;
     * renaming, reordering, retyping or making one required would break B2C invoicing
     * at runtime, not at deploy time.
     *
     * @dataProvider dtoContracts
     *
     * @param  array<int, array{string, string, bool}>  $expected  name, type, optional
     */
    #[DataProvider('dtoContracts')]
    public function test_dto_constructor_contract_is_unchanged(string $class, array $expected): void
    {
        $parameters = (new ReflectionClass($class))->getConstructor()->getParameters();

        $this->assertGreaterThanOrEqual(count($expected), count($parameters));

        foreach ($expected as $i => [$name, $type, $optional]) {
            $parameter = $parameters[$i];
            $this->assertSame($name, $parameter->getName(), "{$class} parameter #{$i} renamed or moved.");
            $this->assertInstanceOf(ReflectionNamedType::class, $parameter->getType());
            $this->assertSame($type, ($parameter->getType()->allowsNull() ? '?' : '').$parameter->getType()->getName(), "{$class}::\${$name} type changed.");
            $this->assertSame($optional, $parameter->isOptional(), "{$class}::\${$name} optionality changed.");
        }

        // Anything added after the known parameters must be optional.
        foreach (array_slice($parameters, count($expected)) as $added) {
            $this->assertTrue($added->isOptional(), "{$class}::\${$added->getName()} was added as a required parameter.");
        }
    }

    public static function dtoContracts(): array
    {
        return [
            'ZatcaInvoiceData' => [ZatcaInvoiceData::class, [
                ['egsIdentifier', 'string', false],
                ['sourceReference', 'string', false],
                ['buyerName', '?string', false],
                ['buyerVatNumber', '?string', false],
                ['issueDateTime', 'DateTimeImmutable', false],
                ['currency', 'string', false],
                ['lines', 'array', false],
                ['totalExclVat', 'float', false],
                ['totalVat', 'float', false],
                ['totalInclVat', 'float', false],
                ['documentType', 'Zatca\Enums\ZatcaComplianceDocumentType', true],
                ['billingReferenceId', '?string', true],
                ['adjustmentReason', '?string', true],
                ['allowanceCharges', 'array', true],
            ]],
            'ZatcaInvoiceLine' => [ZatcaInvoiceLine::class, [
                ['description', 'string', false],
                ['quantity', 'int', false],
                ['unitPrice', 'float', false],
                ['vatRate', 'float', false],
                ['lineTotalExclVat', 'float', false],
                ['vatAmount', 'float', false],
            ]],
            'ZatcaAllowanceCharge' => [ZatcaAllowanceCharge::class, [
                ['reason', 'string', false],
                ['amountExclVat', 'float', false],
                ['vatRate', 'float', false],
                ['vatAmount', 'float', false],
                ['poolKey', '?string', true],
                ['lineIndexes', 'array', true],
            ]],
        ];
    }

    public static function documents(): array
    {
        $names = array_keys(self::goldenDocuments());

        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }
}

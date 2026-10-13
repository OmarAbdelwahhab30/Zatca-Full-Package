<?php

namespace Zatca\Tests\Unit\Golden;

use DateTimeImmutable;
use InvalidArgumentException;
use Zatca\DTOs\BuyerAddress;
use Zatca\DTOs\BuyerOtherId;
use Zatca\DTOs\BuyerParty;
use Zatca\DTOs\ZatcaInvoiceData;
use Zatca\Support\InvoiceDataSnapshot;
use Zatca\Tests\TestCase;

/**
 * Blueprint P20: the structured buyer and supply date, and proof that a Simplified
 * document is byte-for-byte the same with or without them.
 *
 * @group golden
 */
class BuyerPartyTest extends TestCase
{
    use GoldenDocuments;

    /** @dataProvider schemes */
    public function test_every_br_ksa_14_scheme_is_accepted(string $scheme): void
    {
        $this->assertSame($scheme, (new BuyerOtherId($scheme, 'ABC123'))->scheme);
    }

    public static function schemes(): array
    {
        return array_combine(BuyerOtherId::SCHEMES, array_map(fn ($s) => [$s], BuyerOtherId::SCHEMES));
    }

    /** @dataProvider badSchemes */
    public function test_an_unknown_scheme_is_refused(string $scheme): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BuyerOtherId($scheme, '1010010000');
    }

    public static function badSchemes(): array
    {
        return ['VAT' => ['VAT'], 'lower case' => ['crn'], 'padded' => [' CRN'], 'empty' => ['']];
    }

    public function test_with_buyer_and_supply_date_return_copies(): void
    {
        $original = self::goldenDocuments()['invoice_single']['data'];
        $buyer = self::buyer();
        $date = new DateTimeImmutable('2026-09-01');

        $withBuyer = $original->withBuyer($buyer);
        $withBoth = $withBuyer->withSupplyDate($date);

        $this->assertNull($original->buyer);
        $this->assertNull($original->supplyDate);
        $this->assertSame($buyer, $withBoth->buyer);
        $this->assertSame($date, $withBoth->supplyDate);
        foreach ((new \ReflectionClass(ZatcaInvoiceData::class))->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (! in_array($name, ['buyer', 'supplyDate'], true)) {
                $this->assertSame($original->{$name}, $withBoth->{$name}, $name);
            }
        }
    }

    /** @dataProvider documents */
    public function test_simplified_output_ignores_buyer_and_supply_date(string $case): void
    {
        $document = self::goldenDocuments()[$case];
        $data = $document['data']->withBuyer(self::buyer())->withSupplyDate(new DateTimeImmutable('2026-09-01'));

        $this->assertSame(self::goldenXml($document), self::goldenXml($document, $data));
        $this->assertSame(InvoiceDataSnapshot::toArray($document['data']), InvoiceDataSnapshot::toArray($data));
        $this->assertSame(
            self::normalise((string) file_get_contents(SimplifiedXmlGoldenTest::FIXTURE_DIR.'/'.$case.'.xml')),
            self::normalise(self::goldenXml($document, $data)),
        );
    }

    public static function documents(): array
    {
        return array_map(fn ($k) => [$k], array_combine(array_keys(self::goldenDocuments()), array_keys(self::goldenDocuments())));
    }

    private static function buyer(): BuyerParty
    {
        return new BuyerParty(
            registrationName: 'شركة تجريبية للتدريب',
            vatNumber: null,
            otherId: BuyerOtherId::commercialRegistration('7000000007'),
            address: new BuyerAddress('شارع تجريبي', '9090', 'حي تجريبي', 'مدينة تجريبية', '90909'),
        );
    }
}

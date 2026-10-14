<?php

namespace Zatca\Tests\Unit\Golden;

use Zatca\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Freezes today's unsigned Simplified (B2C, Reporting) XML byte-for-byte.
 *
 * XmlGeneratorTest and SimplifiedNoteXmlGeneratorTest assert on fragments, so a
 * change anywhere else in the document would pass them. B2B Standard invoicing
 * extends the shared DTOs and adds generators next to these ones; every one of
 * those changes has to leave the documents below exactly as they are.
 *
 * These fixtures record what the generators emit today. They are not a claim
 * that the output is ZATCA-compliant — that is proven by sandbox submission, not
 * by this file.
 *
 * The only normalisation is CRLF -> LF. The signature placeholder is a heredoc,
 * so a Windows checkout (core.autocrlf) emits CRLF inside it while the Linux
 * servers emit LF; the fixtures hold the LF form the servers produce.
 *
 * Note documents are built through the real CreditNoteDataFactory and
 * DebitNoteDataFactory (see GoldenDocuments), so the fixtures also pin how those
 * factories shape a note.
 *
 * To rewrite the fixtures after an intended change: `composer golden:update`,
 * then review the fixture diff line by line. Never run it in CI.
 *
 * @group golden
 */
#[Group('golden')]
class SimplifiedXmlGoldenTest extends TestCase
{
    use GoldenDocuments;

    public const FIXTURE_DIR = __DIR__.'/../../Fixtures/Golden/V2/simplified';

    /** @dataProvider documents */
    #[DataProvider('documents')]
    public function test_generated_xml_matches_the_golden_fixture(string $fixture): void
    {
        $xml = self::normalise(self::goldenXml(self::goldenDocuments()[$fixture]));
        $path = self::FIXTURE_DIR.'/'.$fixture.'.xml';

        if (getenv('ZATCA_GOLDEN_UPDATE') === '1') {
            if (! is_dir(self::FIXTURE_DIR)) {
                mkdir(self::FIXTURE_DIR, 0777, true);
            }
            file_put_contents($path, $xml);
            $this->markTestIncomplete("Golden fixture [{$fixture}] rewritten; review the diff before committing.");
        }

        $this->assertFileExists($path, "Golden fixture [{$fixture}] is missing. Generate it with `composer golden:update` and review it.");
        $this->assertSame(self::normalise((string) file_get_contents($path)), $xml, "Simplified XML for [{$fixture}] changed.");
    }

    /** A fixture nobody generates any more would silently stop protecting anything. */
    public function test_every_fixture_file_has_a_case(): void
    {
        $cases = array_keys(self::goldenDocuments());
        $files = array_map(fn (string $f) => basename($f, '.xml'), glob(self::FIXTURE_DIR.'/*.xml') ?: []);

        sort($cases);
        sort($files);

        $this->assertSame($cases, $files);
        $this->assertGreaterThanOrEqual(8, count($cases));
    }

    public static function documents(): array
    {
        $names = array_keys(self::goldenDocuments());

        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }
}

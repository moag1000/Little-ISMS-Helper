<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Service\Tisax\TisaxCatalogueProvider;
use App\Service\Tisax\TisaxCatalogueVersionDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * ISA 6 and ISA 2027 are both currently certifiable, so they live as two
 * catalogues side by side. These tests pin down the pieces that keep an upload
 * from landing in the wrong one — TisaxRequirementMapper creates control rows
 * it cannot find, so a mis-routed workbook silently grafts foreign controls
 * onto the other standard instead of failing loudly.
 *
 * Unit-level: no DB, no kernel.
 */
final class TisaxCatalogueVersionTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../../fixtures/library/frameworks';

    private TisaxCatalogueVersionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new TisaxCatalogueVersionDetector();
    }

    /** @return list<string> */
    private function controlIds(string $file): array
    {
        $yaml = Yaml::parseFile(self::FIXTURE_DIR . '/' . $file);

        return array_map(
            static fn (array $r): string => (string) $r['controlId'],
            $yaml['requirements'] ?? [],
        );
    }

    #[Test]
    public function isa2027FixtureCarriesTheWorkbookControlSet(): void
    {
        $ids = $this->controlIds('vda-isa-tisax-2027.yaml');

        // 46 Information Security + 20 Prototype Protection + 12 Data Protection,
        // verified against the official ISA2027-EN workbook.
        self::assertCount(78, $ids);
        self::assertSame($ids, array_values(array_unique($ids)), 'duplicate control numbers');
        self::assertContains('6.1.3', $ids);
        self::assertContains('8.1.13', $ids);
        self::assertNotContains('1.2.4', $ids, '1.2.4 was dropped in ISA 2027');
        self::assertNotContains('8.5.2', $ids, '8.5.2 was dropped in ISA 2027');
    }

    #[Test]
    public function bothCataloguesUseDistinctFrameworkCodes(): void
    {
        $isa6 = Yaml::parseFile(self::FIXTURE_DIR . '/vda-isa-tisax-v6.yaml')['metadata'];
        $isa2027 = Yaml::parseFile(self::FIXTURE_DIR . '/vda-isa-tisax-2027.yaml')['metadata'];

        self::assertSame('TISAX', $isa6['code']);
        self::assertSame('TISAX-2027', $isa2027['code']);
        self::assertNotSame($isa6['version'], $isa2027['version']);
    }

    #[Test]
    public function versionSignaturesMatchTheActualFixtureDelta(): void
    {
        $isa6 = $this->controlIds('vda-isa-tisax-v6.yaml');
        $isa2027 = $this->controlIds('vda-isa-tisax-2027.yaml');

        // The detector's signature sets must BE the real delta, otherwise a
        // workbook could hit no signature at all and fall back silently.
        self::assertSame(
            array_values(array_diff($isa2027, $isa6)),
            TisaxCatalogueVersionDetector::ISA2027_ONLY,
        );
        self::assertSame(
            array_values(array_diff($isa6, $isa2027)),
            TisaxCatalogueVersionDetector::ISA6_ONLY,
        );
    }

    #[Test]
    public function detectsIsa2027FromItsOwnControlSet(): void
    {
        $result = $this->detector->detectFromControlIds($this->controlIds('vda-isa-tisax-2027.yaml'));

        self::assertSame(TisaxCatalogueProvider::VERSION_ISA2027, $result['version']);
        self::assertTrue($result['confident']);
        self::assertSame([], $result['isa6Hits']);
    }

    #[Test]
    public function detectsIsa6FromItsOwnControlSet(): void
    {
        $result = $this->detector->detectFromControlIds($this->controlIds('vda-isa-tisax-v6.yaml'));

        self::assertSame(TisaxCatalogueProvider::VERSION_ISA6, $result['version']);
        self::assertTrue($result['confident']);
        self::assertSame([], $result['isa2027Hits']);
    }

    #[Test]
    public function mixedOrSignatureLessWorkbooksAreFlaggedUnconfident(): void
    {
        // Only shared controls — no signature either way.
        $shared = $this->detector->detectFromControlIds(['1.1.1', '5.2.7', '9.1.1']);
        self::assertSame(TisaxCatalogueProvider::VERSION_ISA6, $shared['version']);
        self::assertFalse($shared['confident']);

        // Hand-edited file carrying both signatures — majority wins, still flagged.
        $mixed = $this->detector->detectFromControlIds(['1.2.4', '6.1.3', '8.1.9', '8.1.10']);
        self::assertSame(TisaxCatalogueProvider::VERSION_ISA2027, $mixed['version']);
        self::assertFalse($mixed['confident']);
    }

    #[Test]
    #[DataProvider('versionInputProvider')]
    public function normaliseVersionNeverAddressesAnUnknownCatalogue(?string $input, string $expected): void
    {
        self::assertSame($expected, TisaxCatalogueProvider::normaliseVersion($input));
    }

    /** @return array<string, array{?string, string}> */
    public static function versionInputProvider(): array
    {
        return [
            'null'        => [null, TisaxCatalogueProvider::VERSION_ISA6],
            'empty'       => ['', TisaxCatalogueProvider::VERSION_ISA6],
            'isa6 plain'  => ['6', TisaxCatalogueProvider::VERSION_ISA6],
            'isa6 dotted' => ['6.0.3', TisaxCatalogueProvider::VERSION_ISA6],
            'isa2027'     => ['2027', TisaxCatalogueProvider::VERSION_ISA2027],
            'garbage'     => ['nonsense', TisaxCatalogueProvider::VERSION_ISA6],
        ];
    }
}

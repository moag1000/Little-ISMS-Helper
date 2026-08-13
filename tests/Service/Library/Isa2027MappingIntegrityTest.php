<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Service\MappingValidatorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Integrity of the VDA-ISA 2027 mappings.
 *
 * A mapping row whose source or target id does not exist in the shipped
 * catalogues is skipped at import time without an error — the fixture looks
 * complete in the repo and is dead at runtime. These tests catch that before
 * it ships.
 *
 * Unit-level: no DB, no kernel.
 */
final class Isa2027MappingIntegrityTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';
    private const FRAMEWORK_DIR = __DIR__ . '/../../../fixtures/library/frameworks';

    /** @return list<string> */
    private function catalogueIds(string $file): array
    {
        $yaml = Yaml::parseFile(self::FRAMEWORK_DIR . '/' . $file);

        return array_map(static fn (array $r): string => (string) $r['controlId'], $yaml['requirements']);
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function mapping(string $file): array
    {
        $yaml = Yaml::parseFile(self::MAPPING_DIR . '/' . $file);

        return [$yaml['library'], MappingValidatorService::expandEntries($yaml['mappings'])];
    }

    /** @return array<string, array{string}> */
    public static function isa2027MappingProvider(): array
    {
        return [
            'ISO 27001' => ['tisax-2027_to_iso27001-2022_v1.0.yaml'],
            'NIST CSF'  => ['tisax-2027_to_nist-csf-2-0_v1.0.yaml'],
            'ISO 27017' => ['tisax-2027_to_iso27017_v1.0.yaml'],
        ];
    }

    #[Test]
    #[DataProvider('isa2027MappingProvider')]
    public function everySourceExistsInTheIsa2027Catalogue(string $file): void
    {
        [$library, $entries] = $this->mapping($file);
        $catalogue = $this->catalogueIds('vda-isa-tisax-2027.yaml');

        self::assertSame('TISAX-2027', $library['source_framework']);
        self::assertNotSame([], $entries, 'mapping ships no entries at all');

        $dangling = array_values(array_unique(array_filter(
            array_map(static fn (array $e): string => (string) $e['source'], $entries),
            static fn (string $s): bool => !in_array($s, $catalogue, true),
        )));

        self::assertSame([], $dangling, 'sources absent from the ISA 2027 catalogue: ' . implode(', ', $dangling));
    }

    #[Test]
    public function iso27001TargetsAreAnnexAAnchors(): void
    {
        [, $entries] = $this->mapping('tisax-2027_to_iso27001-2022_v1.0.yaml');

        foreach ($entries as $entry) {
            self::assertMatchesRegularExpression(
                '/^A\.\d+\.\d+$/',
                (string) $entry['target'],
                'clause-level references must not be emitted as mappings',
            );
        }
    }

    #[Test]
    public function nistTargetsUseTheZeroPaddedCatalogueForm(): void
    {
        [, $entries] = $this->mapping('tisax-2027_to_nist-csf-2-0_v1.0.yaml');
        $catalogue = json_decode(
            (string) file_get_contents(self::FRAMEWORK_DIR . '/../catalogues/nist-csf-2-0/csf2_subcategories.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        // The catalogue is keyed by subcategory id (id => description text).
        $known = [];
        foreach (array_keys($catalogue) as $id) {
            if (preg_match('/^[A-Z]{2}\.[A-Z]{2}-\d{2}$/', (string) $id)) {
                $known[(string) $id] = true;
            }
        }
        self::assertGreaterThan(100, count($known), 'CSF catalogue did not parse as expected');

        foreach ($entries as $entry) {
            self::assertArrayHasKey(
                (string) $entry['target'],
                $known,
                sprintf('%s is not a NIST CSF 2.0 subcategory id', (string) $entry['target']),
            );
        }
    }

    #[Test]
    public function versionCrosswalkMatchesTheTwoCatalogues(): void
    {
        [$library, $entries] = $this->mapping('tisax_to_tisax-2027_v1.0.yaml');
        $isa6 = $this->catalogueIds('vda-isa-tisax-v6.yaml');
        $isa2027 = $this->catalogueIds('vda-isa-tisax-2027.yaml');

        self::assertSame('TISAX', $library['source_framework']);
        self::assertSame('TISAX-2027', $library['target_framework']);

        $carried = [];
        $gaps = [];
        foreach ($entries as $entry) {
            self::assertContains((string) $entry['source'], $isa6, 'crosswalk source not in ISA 6');

            if ($entry['target'] === null) {
                $gaps[] = (string) $entry['source'];
                // The validator only accepts a null target as a documented gap.
                self::assertSame('superset', $entry['relationship']);
                self::assertArrayHasKey('gap_warning', $entry);
                continue;
            }

            $carried[] = (string) $entry['target'];
            self::assertContains((string) $entry['target'], $isa2027, 'crosswalk target not in ISA 2027');
            // Never 'equivalent': ENX revises content behind stable numbers.
            self::assertSame('related', $entry['relationship']);
        }

        self::assertSame(array_values(array_diff($isa6, $isa2027)), $gaps);
        self::assertSame(array_values(array_intersect($isa6, $isa2027)), $carried);
    }

    #[Test]
    public function crosswalkDocumentsTheControlsWithoutAnIsa6Predecessor(): void
    {
        $yaml = Yaml::parseFile(self::MAPPING_DIR . '/tisax_to_tisax-2027_v1.0.yaml');
        $isa6 = $this->catalogueIds('vda-isa-tisax-v6.yaml');
        $isa2027 = $this->catalogueIds('vda-isa-tisax-2027.yaml');

        self::assertSame(array_values(array_diff($isa2027, $isa6)), $yaml['added_in_target'] ?? []);
    }
}

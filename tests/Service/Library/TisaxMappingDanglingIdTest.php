<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Service\MappingValidatorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Family-wide dangling-id guard for every TISAX mapping (ISA 6 and ISA 2027).
 *
 * MappingLibraryLoader resolves rows by exact requirement id and silently
 * counts a miss as "skipped": a mapping with a typo'd or differently-formatted
 * id ships looking complete and does nothing at runtime. The NIST subcategory
 * ids are the live example — the workbooks write GV.PO-1, the catalogue keys
 * GV.PO-01.
 *
 * Only frameworks whose catalogue is shipped in this repo can be checked;
 * the rest are listed in UNCHECKABLE_TARGETS with the reason.
 *
 * Unit-level: no DB, no kernel.
 */
final class TisaxMappingDanglingIdTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /**
     * Target frameworks with no catalogue in this repo. The first three are the
     * documented pending loaders from MappingFrameworkCodeResolutionTest (the
     * framework is not loaded at runtime at all, so the mapping is inert by
     * design); ISO 27017 requirement ids live inside their loader command.
     */
    private const UNCHECKABLE_TARGETS = ['iec-isa-62443', 'nist-sp800-53r5', 'ISO27002', 'ISO27017'];

    /** @return list<string> */
    private static function catalogueIds(string $fixture): array
    {
        $yaml = Yaml::parseFile(self::ROOT . '/fixtures/library/frameworks/' . $fixture);

        return array_map(static fn (array $r): string => (string) $r['controlId'], $yaml['requirements']);
    }

    /** @return array<string, list<string>> */
    private static function vocabularies(): array
    {
        // ISO 27001:2022 Annex A — the canonical 93 control ids.
        $annex = [];
        $handle = fopen(self::ROOT . '/fixtures/seeds/mris_annex_a_classification.csv', 'r');
        self::assertNotFalse($handle);
        fgetcsv($handle, 0, ';', '"', '');
        while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
            if (isset($row[0]) && $row[0] !== '') {
                $annex[] = (string) $row[0];
            }
        }
        fclose($handle);

        // NIST CSF 2.0 — catalogue is keyed by subcategory id.
        $csf = array_keys((array) json_decode(
            (string) file_get_contents(self::ROOT . '/fixtures/library/catalogues/nist-csf-2-0/csf2_subcategories.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));

        // BSI IT-Grundschutz — Baustein ids across the ten layer files.
        $bsi = [];
        foreach (glob(self::ROOT . '/fixtures/library/catalogues/bsi-it-grundschutz-2023/*.yml') ?: [] as $layer) {
            foreach ((Yaml::parseFile($layer)['bausteine'] ?? []) as $baustein) {
                if (isset($baustein['id'])) {
                    $bsi[] = (string) $baustein['id'];
                }
            }
        }

        $nis2 = array_map(
            static fn (array $r): string => (string) $r['controlId'],
            Yaml::parseFile(self::ROOT . '/fixtures/library/frameworks/nis2-art21_v1.0.yaml')['requirements'],
        );

        return [
            'ISO27001'      => $annex,
            'NIST-CSF-2.0'  => array_map(strval(...), $csf),
            'BSI_GRUNDSCHUTZ' => $bsi,
            'NIS2'          => $nis2,
            'TISAX'         => self::catalogueIds('vda-isa-tisax-v6.yaml'),
            'TISAX-2027'    => self::catalogueIds('vda-isa-tisax-2027.yaml'),
        ];
    }

    #[Test]
    public function vocabulariesParseAsExpected(): void
    {
        $v = self::vocabularies();

        // Guard the guards: a broken parse would make every check below vacuous.
        self::assertCount(93, $v['ISO27001']);
        self::assertCount(111, $v['BSI_GRUNDSCHUTZ']);
        self::assertGreaterThan(100, count($v['NIST-CSF-2.0']));
        self::assertCount(10, $v['NIS2']);
        self::assertCount(80, $v['TISAX']);
        self::assertCount(78, $v['TISAX-2027']);
    }

    /**
     * Only real mapping libraries — the crosswalk/anchor helper fixtures in the
     * same directory carry no library block and are not mappings.
     *
     * @return iterable<string, array{string}>
     */
    public static function tisaxMappingFiles(): iterable
    {
        foreach (glob(self::ROOT . '/fixtures/library/mappings/tisax*.yaml') ?: [] as $path) {
            $yaml = Yaml::parseFile($path);
            if (is_array($yaml['library'] ?? null) && is_array($yaml['mappings'] ?? null)) {
                yield basename($path) => [$path];
            }
        }
    }

    #[Test]
    #[DataProvider('tisaxMappingFiles')]
    public function noMappingRowPointsAtAnIdThatDoesNotExist(string $path): void
    {
        $yaml = Yaml::parseFile($path);
        $library = $yaml['library'];

        $vocab = self::vocabularies();
        $sourceIds = $vocab[$library['source_framework']] ?? null;
        $targetIds = in_array($library['target_framework'], self::UNCHECKABLE_TARGETS, true)
            ? null
            : ($vocab[$library['target_framework']] ?? null);

        $danglingSources = [];
        $danglingTargets = [];
        foreach (MappingValidatorService::expandEntries($yaml['mappings']) as $entry) {
            $source = (string) ($entry['source'] ?? '');
            if ($sourceIds !== null && $source !== '' && !in_array($source, $sourceIds, true)) {
                $danglingSources[] = $source;
            }

            $target = $entry['target'] ?? null;
            if ($targetIds !== null && is_string($target) && !in_array($target, $targetIds, true)) {
                $danglingTargets[] = $target;
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($danglingSources)),
            basename($path) . ': source ids absent from ' . $library['source_framework'],
        );
        self::assertSame(
            [],
            array_values(array_unique($danglingTargets)),
            basename($path) . ': target ids absent from ' . $library['target_framework'],
        );
    }
}

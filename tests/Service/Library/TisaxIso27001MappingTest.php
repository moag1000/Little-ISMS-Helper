<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests for the authoritative TISAX→ISO 27001:2022 mapping YAML.
 *
 * No xlsx workbook needed — tests load the committed YAML fixture directly.
 */
final class TisaxIso27001MappingTest extends TestCase
{
    private const MAPPING_FILE = __DIR__ . '/../../../fixtures/library/mappings/tisax_to_iso27001-2022_v1.0.yaml';

    /** @var array<mixed> */
    private static array $data = [];

    public static function setUpBeforeClass(): void
    {
        self::$data = Yaml::parseFile(self::MAPPING_FILE);
    }

    #[Test]
    public function yaml_file_exists_and_is_readable(): void
    {
        self::assertFileExists(self::MAPPING_FILE);
        self::assertFileIsReadable(self::MAPPING_FILE);
    }

    #[Test]
    public function top_level_schema_keys_present(): void
    {
        self::assertArrayHasKey('schema_version', self::$data);
        self::assertArrayHasKey('library', self::$data);
        self::assertArrayHasKey('mappings', self::$data);
    }

    #[Test]
    public function library_metadata_has_authoritative_confidence(): void
    {
        $provenance = self::$data['library']['provenance'] ?? [];
        self::assertSame('authoritative_enx_source', $provenance['confidence'] ?? null,
            'confidence must be authoritative_enx_source (upgraded from derived_best_effort)');
    }

    #[Test]
    public function mapping_covers_at_least_50_controls(): void
    {
        self::assertGreaterThanOrEqual(50, count(self::$data['mappings'] ?? []),
            'Authoritative workbook data must cover ≥50 VDA-ISA controls');
    }

    #[Test]
    public function all_sources_are_valid_vda_isa_ids(): void
    {
        foreach (self::$data['mappings'] as $entry) {
            self::assertMatchesRegularExpression(
                '/^\d{1,2}\.\d{1,2}\.\d{1,2}$/',
                $entry['source'],
                "Invalid VDA-ISA ID: {$entry['source']}"
            );
        }
    }

    #[Test]
    public function all_iso_targets_have_valid_annex_a_format(): void
    {
        foreach (self::$data['mappings'] as $entry) {
            foreach ($entry['targets'] as $target) {
                self::assertMatchesRegularExpression(
                    '/^A\.[5-8]\.\d+$/',
                    $target,
                    "Invalid ISO 27001:2022 anchor: $target (must be A.5-8.N, not 2013 format)"
                );
            }
        }
    }

    #[Test]
    public function no_duplicate_source_ids(): void
    {
        $sources = array_column(self::$data['mappings'], 'source');
        $unique  = array_unique($sources);
        self::assertCount(count($unique), $sources, 'Duplicate source IDs found: ' . implode(', ', array_diff_assoc($sources, $unique)));
    }

    #[Test]
    public function all_entries_have_required_fields(): void
    {
        foreach (self::$data['mappings'] as $entry) {
            self::assertArrayHasKey('source', $entry);
            self::assertArrayHasKey('targets', $entry);
            self::assertArrayHasKey('category', $entry);
            self::assertArrayHasKey('maturity', $entry);
            self::assertIsArray($entry['targets']);
            self::assertIsArray($entry['maturity']);
        }
    }

    #[Test]
    public function category_values_are_valid(): void
    {
        $validCategories = ['information_security', 'prototype_protection', 'data_protection'];
        foreach (self::$data['mappings'] as $entry) {
            self::assertContains($entry['category'], $validCategories,
                "Invalid category '{$entry['category']}' for {$entry['source']}");
        }
    }

    #[Test]
    public function is_controls_have_iso_anchors(): void
    {
        $isControls = array_filter(self::$data['mappings'], fn($e) => $e['category'] === 'information_security');
        $withAnchors = array_filter($isControls, fn($e) => !empty($e['targets']));

        self::assertGreaterThan(30, count($withAnchors),
            'IS controls should have >30 entries with ISO 27001:2022 anchors from authoritative workbook');
    }

    #[Test]
    public function pp_controls_have_no_iso_anchors(): void
    {
        $ppControls = array_filter(self::$data['mappings'], fn($e) => $e['category'] === 'prototype_protection');
        self::assertNotEmpty($ppControls, 'PP controls (ISA 8.x) must be present');

        foreach ($ppControls as $entry) {
            self::assertEmpty($entry['targets'],
                "PP control {$entry['source']} should have no ISO 27001:2022 targets by design");
        }
    }

    #[Test]
    public function dp_controls_have_no_iso_anchors(): void
    {
        $dpControls = array_filter(self::$data['mappings'], fn($e) => $e['category'] === 'data_protection');
        self::assertNotEmpty($dpControls, 'DP controls (ISA 9.x) must be present');

        foreach ($dpControls as $entry) {
            self::assertEmpty($entry['targets'],
                "DP control {$entry['source']} should have no ISO 27001:2022 targets by design");
        }
    }

    #[Test]
    public function stats_block_is_consistent_with_mappings(): void
    {
        $stats    = self::$data['library']['stats'] ?? [];
        $mappings = self::$data['mappings'] ?? [];

        $total        = count($mappings);
        $withTargets  = count(array_filter($mappings, fn($e) => !empty($e['targets'])));
        $withoutTargets = $total - $withTargets;

        self::assertSame($stats['total_vda_isa_controls'] ?? null, $total,
            'stats.total_vda_isa_controls must equal actual mapping count');
        self::assertSame($stats['controls_with_iso27001_anchors'] ?? null, $withTargets,
            'stats.controls_with_iso27001_anchors must equal actual count');
        self::assertSame($stats['controls_without_iso27001_anchors'] ?? null, $withoutTargets,
            'stats.controls_without_iso27001_anchors must equal actual count');
    }
}

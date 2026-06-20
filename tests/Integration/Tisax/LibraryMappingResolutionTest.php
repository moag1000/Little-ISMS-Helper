<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tisax;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolution test for the TISAX library mapping graph (spec §9.1 B1).
 *
 * Asserts that every re-keyed mapping file:
 *  1. Uses framework code 'TISAX' (not 'TISAX-VDA-ISA-6') for both source/target.
 *  2. Uses the canonical VDA-ISA control number scheme 'x.y.z' (no 'ISA ' prefix).
 *  3. Has a library.id that matches the filename (no stale tisax-vda-isa-6 id).
 *
 * These tests are unit-level (no DB, no Symfony kernel) and run in CI without
 * any licensed workbook files.
 */
final class LibraryMappingResolutionTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';
    private const METADATA_DIR = __DIR__ . '/../../../fixtures/library/metadata';

    /**
     * @return array<string, array{string}>
     */
    public static function tisaxMappingFileProvider(): array
    {
        $files = [
            'tisax_to_iso27001-2022_v1.0.yaml',
            'tisax_to_iso27002_v1.0.yaml',
            'tisax_to_iso27017_v1.0.yaml',
            'tisax_to_bsi-grundschutz_v1.0.yaml',
            'tisax_to_nist-sp800-53r5_v1.0.yaml',
            'tisax_to_iec-isa-62443_v1.0.yaml',
            'tisax_to_nist-csf-2.0_v1.0.yaml',
            'bsi-grundschutz-2024_to_tisax_v1.0.yaml',
            'iso27001-2022_to_tisax_v1.0.yaml',
        ];

        $cases = [];
        foreach ($files as $file) {
            $cases[$file] = [$file];
        }
        return $cases;
    }

    #[Test]
    #[DataProvider('tisaxMappingFileProvider')]
    public function mapping_file_exists(string $filename): void
    {
        self::assertFileExists(
            self::MAPPING_DIR . '/' . $filename,
            "Re-keyed mapping file missing: $filename"
        );
    }

    #[Test]
    #[DataProvider('tisaxMappingFileProvider')]
    public function mapping_file_has_valid_yaml(string $filename): void
    {
        $path = self::MAPPING_DIR . '/' . $filename;
        if (!file_exists($path)) {
            self::markTestSkipped("File not found: $filename");
        }

        $data = Yaml::parseFile($path);
        self::assertIsArray($data, "YAML parse returned non-array for: $filename");
        self::assertArrayHasKey('library', $data, "Missing 'library' key in: $filename");
        self::assertArrayHasKey('mappings', $data, "Missing 'mappings' key in: $filename");
    }

    #[Test]
    #[DataProvider('tisaxMappingFileProvider')]
    public function mapping_id_matches_filename(string $filename): void
    {
        $path = self::MAPPING_DIR . '/' . $filename;
        if (!file_exists($path)) {
            self::markTestSkipped("File not found: $filename");
        }

        $data = Yaml::parseFile($path);
        $expectedId = str_replace('.yaml', '', $filename);
        $actualId = (string) ($data['library']['id'] ?? '');

        self::assertSame(
            $expectedId,
            $actualId,
            "library.id '$actualId' does not match filename-derived id '$expectedId' in: $filename"
        );
    }

    #[Test]
    #[DataProvider('tisaxMappingFileProvider')]
    public function no_legacy_framework_code_in_id(string $filename): void
    {
        $path = self::MAPPING_DIR . '/' . $filename;
        if (!file_exists($path)) {
            self::markTestSkipped("File not found: $filename");
        }

        $data = Yaml::parseFile($path);
        $id = (string) ($data['library']['id'] ?? '');

        self::assertStringNotContainsString(
            'tisax-vda-isa-6',
            $id,
            "library.id still contains legacy 'tisax-vda-isa-6' in: $filename"
        );
    }

    #[Test]
    #[DataProvider('tisaxMappingFileProvider')]
    public function tisax_side_framework_code_is_canonical(string $filename): void
    {
        $path = self::MAPPING_DIR . '/' . $filename;
        if (!file_exists($path)) {
            self::markTestSkipped("File not found: $filename");
        }

        $data = Yaml::parseFile($path);
        $isTisaxSource = str_starts_with($filename, 'tisax_');

        if ($isTisaxSource) {
            $actual = (string) ($data['library']['source_framework'] ?? '');
            self::assertSame(
                'TISAX',
                $actual,
                "source_framework should be 'TISAX', got '$actual' in: $filename"
            );
        } else {
            $actual = (string) ($data['library']['target_framework'] ?? '');
            self::assertSame(
                'TISAX',
                $actual,
                "target_framework should be 'TISAX', got '$actual' in: $filename"
            );
        }
    }

    #[Test]
    #[DataProvider('tisaxMappingFileProvider')]
    public function no_legacy_tisax_vda_isa_6_framework_code_anywhere(string $filename): void
    {
        $path = self::MAPPING_DIR . '/' . $filename;
        if (!file_exists($path)) {
            self::markTestSkipped("File not found: $filename");
        }

        $content = file_get_contents($path);
        self::assertIsString($content);

        // Check for the old framework code in source_framework / target_framework fields
        // (comments mentioning the old code are acceptable as provenance/rationale prose)
        self::assertStringNotContainsString(
            "framework: 'TISAX-VDA-ISA-6'",
            $content,
            "Found legacy framework code 'TISAX-VDA-ISA-6' in: $filename"
        );
    }

    #[Test]
    #[DataProvider('tisaxMappingFileProvider')]
    public function tisax_control_ids_use_canonical_scheme(string $filename): void
    {
        $path = self::MAPPING_DIR . '/' . $filename;
        if (!file_exists($path)) {
            self::markTestSkipped("File not found: $filename");
        }

        $data = Yaml::parseFile($path);
        $mappings = $data['mappings'] ?? [];
        $isTisaxSource = str_starts_with($filename, 'tisax_');

        $legacyIdsFound = [];
        foreach ($mappings as $entry) {
            $tisaxId = $isTisaxSource
                ? (string) ($entry['source'] ?? '')
                : (string) ($entry['target'] ?? '');

            // Legacy scheme: 'ISA x.y.z' — must NOT appear
            if (preg_match('/^ISA \d+\.\d+\.\d+$/', $tisaxId)) {
                $legacyIdsFound[] = $tisaxId;
            }
        }

        self::assertEmpty(
            $legacyIdsFound,
            sprintf(
                "Found %d legacy 'ISA x.y.z' ids in %s (should be 'x.y.z'): %s",
                count($legacyIdsFound),
                $filename,
                implode(', ', array_unique($legacyIdsFound))
            )
        );
    }

    #[Test]
    #[DataProvider('tisaxMappingFileProvider')]
    public function tisax_control_ids_match_official_vda_isa_pattern(string $filename): void
    {
        $path = self::MAPPING_DIR . '/' . $filename;
        if (!file_exists($path)) {
            self::markTestSkipped("File not found: $filename");
        }

        $data = Yaml::parseFile($path);
        $mappings = $data['mappings'] ?? [];
        $isTisaxSource = str_starts_with($filename, 'tisax_');

        $invalidIds = [];
        foreach ($mappings as $entry) {
            $tisaxId = $isTisaxSource
                ? (string) ($entry['source'] ?? '')
                : (string) ($entry['target'] ?? '');

            if ($tisaxId === '') {
                continue;
            }

            // Valid canonical patterns:
            //   'x.y.z'      — standard IS/PP control (e.g. '1.1.1', '8.2.3')
            //   'x.y.z-a'    — DP sub-requirement (e.g. '9.1.1-a')
            if (!preg_match('/^\d+\.\d+\.\d+(-[a-z])?$/', $tisaxId)) {
                $invalidIds[] = $tisaxId;
            }
        }

        self::assertEmpty(
            $invalidIds,
            sprintf(
                "Found %d non-canonical TISAX ids in %s: %s",
                count($invalidIds),
                $filename,
                implode(', ', array_unique($invalidIds))
            )
        );
    }

    #[Test]
    #[DataProvider('tisaxMappingFileProvider')]
    public function mapping_has_at_least_one_entry(string $filename): void
    {
        $path = self::MAPPING_DIR . '/' . $filename;
        if (!file_exists($path)) {
            self::markTestSkipped("File not found: $filename");
        }

        $data = Yaml::parseFile($path);
        $mappings = $data['mappings'] ?? [];

        self::assertNotEmpty(
            $mappings,
            "Mapping file has zero entries: $filename"
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Old-filename guard: ensure the legacy files no longer exist
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function legacyMappingFileProvider(): array
    {
        $legacyFiles = [
            'tisax-vda-isa-6_to_iso27001-2022_v1.0.yaml',
            'tisax-vda-isa-6_to_iso27002_v1.0.yaml',
            'tisax-vda-isa-6_to_iso27017_v1.0.yaml',
            'tisax-vda-isa-6_to_bsi-grundschutz_v1.0.yaml',
            'tisax-vda-isa-6_to_nist-sp800-53r5_v1.0.yaml',
            'tisax-vda-isa-6_to_iec-isa-62443_v1.0.yaml',
            'tisax-vda-isa-6_to_nist-csf-1.1_v1.0.yaml',
            'bsi-grundschutz-2024_to_tisax-vda-isa-6_v1.0.yaml',
            'iso27001-2022_to_tisax-vda-isa-6_v1.0.yaml',
        ];

        $cases = [];
        foreach ($legacyFiles as $file) {
            $cases[$file] = [$file];
        }
        return $cases;
    }

    #[Test]
    #[DataProvider('legacyMappingFileProvider')]
    public function legacy_mapping_file_does_not_exist(string $filename): void
    {
        self::assertFileDoesNotExist(
            self::MAPPING_DIR . '/' . $filename,
            "Legacy mapping file still present (should have been deleted): $filename"
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Metadata file
    // ────────────────────────────────────────────────────────────────────────────

    #[Test]
    public function metadata_file_uses_canonical_name(): void
    {
        self::assertFileExists(
            self::METADATA_DIR . '/tisax_requirement_levels_v1.0.yaml',
            "Canonical metadata file missing: tisax_requirement_levels_v1.0.yaml"
        );
    }

    #[Test]
    public function legacy_metadata_file_does_not_exist(): void
    {
        self::assertFileDoesNotExist(
            self::METADATA_DIR . '/tisax-vda-isa-6_requirement_levels_v1.0.yaml',
            "Legacy metadata file still present: tisax-vda-isa-6_requirement_levels_v1.0.yaml"
        );
    }

    #[Test]
    public function metadata_file_source_field_is_canonical(): void
    {
        $path = self::METADATA_DIR . '/tisax_requirement_levels_v1.0.yaml';
        if (!file_exists($path)) {
            self::markTestSkipped("Canonical metadata file not found");
        }

        $data = Yaml::parseFile($path);
        $source = (string) ($data['metadata']['source'] ?? '');

        self::assertSame(
            'TISAX',
            $source,
            "metadata.source should be 'TISAX', got '$source'"
        );
    }

    #[Test]
    public function metadata_file_has_controls(): void
    {
        $path = self::METADATA_DIR . '/tisax_requirement_levels_v1.0.yaml';
        if (!file_exists($path)) {
            self::markTestSkipped("Canonical metadata file not found");
        }

        $data = Yaml::parseFile($path);
        $controls = $data['controls'] ?? [];

        self::assertNotEmpty($controls, "Metadata file has no controls");
        self::assertGreaterThanOrEqual(
            70,
            count($controls),
            "Expected at least 70 controls in metadata, got " . count($controls)
        );
    }

    #[Test]
    public function metadata_control_ids_are_canonical(): void
    {
        $path = self::METADATA_DIR . '/tisax_requirement_levels_v1.0.yaml';
        if (!file_exists($path)) {
            self::markTestSkipped("Canonical metadata file not found");
        }

        $data = Yaml::parseFile($path);
        $controls = $data['controls'] ?? [];

        $invalidIds = [];
        foreach ($controls as $control) {
            $id = (string) ($control['controlId'] ?? '');
            if (!preg_match('/^\d+\.\d+\.\d+$/', $id)) {
                $invalidIds[] = $id;
            }
        }

        self::assertEmpty(
            $invalidIds,
            sprintf(
                "Found %d non-canonical control IDs in metadata file: %s",
                count($invalidIds),
                implode(', ', $invalidIds)
            )
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Cross-consistency: metadata control IDs match mapping source IDs
    // ────────────────────────────────────────────────────────────────────────────

    #[Test]
    public function main_iso27001_mapping_sources_all_exist_in_metadata(): void
    {
        $mappingPath = self::MAPPING_DIR . '/tisax_to_iso27001-2022_v1.0.yaml';
        $metaPath = self::METADATA_DIR . '/tisax_requirement_levels_v1.0.yaml';

        if (!file_exists($mappingPath) || !file_exists($metaPath)) {
            self::markTestSkipped("Required files not found for cross-consistency check");
        }

        $mappingData = Yaml::parseFile($mappingPath);
        $metaData = Yaml::parseFile($metaPath);

        $metaIds = array_column($metaData['controls'] ?? [], 'controlId');
        $metaIdSet = array_flip($metaIds);

        $mappingSources = array_column($mappingData['mappings'] ?? [], 'source');

        // IS controls (chapter 1-7) must exist in metadata; PP/DP may differ
        $isControls = array_filter($mappingSources, static fn(string $id): bool =>
            preg_match('/^[1-7]\./', $id) === 1
        );

        $missingFromMeta = [];
        foreach ($isControls as $id) {
            if (!isset($metaIdSet[$id])) {
                $missingFromMeta[] = $id;
            }
        }

        self::assertEmpty(
            $missingFromMeta,
            sprintf(
                "%d IS mapping source ids not found in metadata fixture: %s",
                count($missingFromMeta),
                implode(', ', $missingFromMeta)
            )
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression guard for ISO 27701 ↔ GDPR mapping framework-code alignment.
 *
 * Bug context (#924-pattern): earlier mapping YAMLs used bare clause numbers
 * (e.g. '7.2.1', '6.5', '6.13.1') as requirement IDs, which do not match any
 * DB-stored requirementId in the ISO27701 (2019) or ISO27701_2025 frameworks.
 * MappingLibraryLoader silently skips pairs whose source/target requirement is
 * not found — wrong IDs cause ZERO pairs to load.
 *
 * These tests verify that:
 *  1. framework codes match DB codes ('ISO27701', 'ISO27701_2025', 'GDPR')
 *  2. ISO 27701:2019 requirement IDs use the '27701-A.7.x.x' / '27701-B.8.x.x'
 *     / '27701-5.x' / '27701-6.x.x' / '27701-8.x' prefix schema
 *  3. ISO 27701:2025 requirement IDs use the '27701:2025-A.7.x.x' /
 *     '27701:2025-B.8.x.x' / '27701:2025-5.x' / '27701:2025-6.x.x' schema
 *  4. GDPR requirement IDs use the 'GDPR-' prefix
 *  5. The new official-crt file carries the correct provenance marker
 *
 * No Symfony kernel or DB required — pure file/YAML unit tests.
 */
final class Iso27701GdprMappingCodesAlignmentTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    /** Known valid framework codes for this pair as stored in the DB. */
    private const VALID_FRAMEWORK_CODES = ['ISO27701', 'ISO27701_2025', 'GDPR'];

    // ────────────────────────────────────────────────────────────────────────────
    // Data providers
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{
     *     file: string,
     *     sourceFramework: string,
     *     targetFramework: string,
     *     sourcePattern: string,
     *     targetPattern: string,
     *     minPairs: int,
     *     expectedProvenance: string|null,
     * }>
     */
    public static function gdprIso27701MappingFileProvider(): array
    {
        return [
            'GDPR→ISO27701:2019 (forward)' => [
                'file'               => self::MAPPING_DIR . '/gdpr_to_iso27701-2025_v1.0.yaml',
                'sourceFramework'    => 'GDPR',
                'targetFramework'    => 'ISO27701',
                'sourcePattern'      => '/^GDPR-/',
                'targetPattern'      => '/^27701-/',
                'minPairs'           => 15,
                'expectedProvenance' => null,
            ],
            'ISO27701:2019→GDPR (reverse)' => [
                'file'               => self::MAPPING_DIR . '/iso27701-2025_to_gdpr_v1.0.yaml',
                'sourceFramework'    => 'ISO27701',
                'targetFramework'    => 'GDPR',
                'sourcePattern'      => '/^27701-/',
                'targetPattern'      => '/^GDPR-/',
                'minPairs'           => 10,
                'expectedProvenance' => null,
            ],
            'ISO27701:2025→GDPR official (new)' => [
                'file'               => self::MAPPING_DIR . '/iso27701-2025_to_gdpr_official-crt_v1.yaml',
                'sourceFramework'    => 'ISO27701_2025',
                'targetFramework'    => 'GDPR',
                'sourcePattern'      => '/^27701:2025-/',
                'targetPattern'      => '/^GDPR-/',
                'minPairs'           => 15,
                'expectedProvenance' => 'official_iso27701_gdpr_annex',
            ],
        ];
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: file existence + parseable YAML
    // ────────────────────────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function mapping_file_exists_and_is_readable(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        self::assertFileExists($file, "ISO27701↔GDPR mapping file missing: $file");
        self::assertFileIsReadable($file);
    }

    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function mapping_file_has_valid_yaml_structure(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        $data = Yaml::parseFile($file);
        self::assertIsArray($data, "YAML parse returned non-array for: " . basename($file));
        self::assertArrayHasKey('schema_version', $data, "Missing 'schema_version' key in: " . basename($file));
        self::assertArrayHasKey('library', $data, "Missing 'library' key in: " . basename($file));
        self::assertArrayHasKey('mappings', $data, "Missing 'mappings' key in: " . basename($file));
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: framework-code alignment (the #924 regression guard)
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * CRITICAL: source_framework and target_framework in the YAML must match
     * the exact DB codes. MappingLibraryLoader performs an exact findOneBy(['code' => ...])
     * lookup — a wrong code causes the entire library to be skipped at import time.
     */
    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function source_framework_code_matches_db_code(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        $data   = Yaml::parseFile($file);
        $actual = (string) ($data['library']['source_framework'] ?? '');

        self::assertSame(
            $sourceFramework,
            $actual,
            sprintf(
                '[#924-pattern] %s: source_framework "%s" must equal DB code "%s". '
                . 'MappingLibraryLoader uses findOneBy([\'code\' => ...]) — wrong code '
                . 'silently skips ALL %d mapping pairs at import time.',
                basename($file),
                $actual,
                $sourceFramework,
                count($data['mappings'] ?? []),
            ),
        );
    }

    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function target_framework_code_matches_db_code(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        $data   = Yaml::parseFile($file);
        $actual = (string) ($data['library']['target_framework'] ?? '');

        self::assertSame(
            $targetFramework,
            $actual,
            sprintf(
                '[#924-pattern] %s: target_framework "%s" must equal DB code "%s". '
                . 'MappingLibraryLoader uses findOneBy([\'code\' => ...]) — wrong code '
                . 'silently skips ALL %d mapping pairs at import time.',
                basename($file),
                $actual,
                $targetFramework,
                count($data['mappings'] ?? []),
            ),
        );
    }

    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function framework_codes_are_in_known_valid_set(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        self::assertContains(
            $sourceFramework,
            self::VALID_FRAMEWORK_CODES,
            "Expected source framework code '$sourceFramework' not in known valid set",
        );
        self::assertContains(
            $targetFramework,
            self::VALID_FRAMEWORK_CODES,
            "Expected target framework code '$targetFramework' not in known valid set",
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: requirement ID format (prevents silent skip by MappingLibraryLoader)
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * All source requirement IDs must match the expected prefix pattern for the
     * source framework. Bare clause numbers like '7.2.1' or '6.5' (without prefix)
     * will NEVER match a DB requirementId and are silently skipped.
     */
    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function all_source_ids_match_expected_prefix_pattern(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        $data    = Yaml::parseFile($file);
        $invalid = [];

        foreach ($data['mappings'] as $entry) {
            $sourceId = (string) ($entry['source'] ?? '');
            if (!preg_match($sourcePattern, $sourceId)) {
                $invalid[] = $sourceId;
            }
        }

        self::assertEmpty(
            $invalid,
            sprintf(
                '%s: found %d source IDs not matching pattern %s (these will be silently '
                . 'skipped by MappingLibraryLoader): %s',
                basename($file),
                count($invalid),
                $sourcePattern,
                implode(', ', array_unique($invalid)),
            ),
        );
    }

    /**
     * All target requirement IDs must match the expected prefix pattern for the
     * target framework.
     */
    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function all_target_ids_match_expected_prefix_pattern(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        $data    = Yaml::parseFile($file);
        $invalid = [];

        foreach ($data['mappings'] as $entry) {
            $targetId = (string) ($entry['target'] ?? '');
            if (!preg_match($targetPattern, $targetId)) {
                $invalid[] = $targetId;
            }
        }

        self::assertEmpty(
            $invalid,
            sprintf(
                '%s: found %d target IDs not matching pattern %s (these will be silently '
                . 'skipped by MappingLibraryLoader): %s',
                basename($file),
                count($invalid),
                $targetPattern,
                implode(', ', array_unique($invalid)),
            ),
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: content quality
    // ────────────────────────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function mapping_meets_minimum_pair_count(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        $data  = Yaml::parseFile($file);
        $count = count($data['mappings'] ?? []);

        self::assertGreaterThanOrEqual(
            $minPairs,
            $count,
            sprintf('%s: must contain at least %d mapping pairs (got %d)', basename($file), $minPairs, $count),
        );
    }

    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function no_duplicate_source_ids(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        $data    = Yaml::parseFile($file);
        $sources = array_column($data['mappings'] ?? [], 'source');
        $unique  = array_unique($sources);

        self::assertCount(
            count($unique),
            $sources,
            sprintf(
                '%s: duplicate source IDs found: %s',
                basename($file),
                implode(', ', array_diff_assoc($sources, $unique)),
            ),
        );
    }

    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function all_entries_have_required_fields(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        $data = Yaml::parseFile($file);

        foreach ($data['mappings'] as $i => $entry) {
            self::assertArrayHasKey('source', $entry, basename($file) . " entry[$i] missing 'source'");
            self::assertArrayHasKey('target', $entry, basename($file) . " entry[$i] missing 'target'");
            self::assertArrayHasKey('relationship', $entry, basename($file) . " entry[$i] missing 'relationship'");
            self::assertNotEmpty((string) ($entry['source'] ?? ''), basename($file) . " entry[$i] 'source' is empty");
            self::assertNotEmpty((string) ($entry['target'] ?? ''), basename($file) . " entry[$i] 'target' is empty");
        }
    }

    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function relationship_values_are_valid(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        $validRelationships = ['equivalent', 'subset', 'superset', 'related'];
        $data               = Yaml::parseFile($file);

        foreach ($data['mappings'] as $entry) {
            $rel = (string) ($entry['relationship'] ?? '');
            if ($rel !== '') {
                self::assertContains(
                    $rel,
                    $validRelationships,
                    sprintf("%s: invalid relationship '%s' for source '%s'", basename($file), $rel, $entry['source'] ?? '?'),
                );
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Test: official-crt file specific checks
    // ────────────────────────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('gdprIso27701MappingFileProvider')]
    public function official_crt_file_has_correct_provenance_marker(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $sourcePattern,
        string $targetPattern,
        int $minPairs,
        ?string $expectedProvenance,
    ): void {
        if ($expectedProvenance === null) {
            self::assertTrue(true); // Not applicable for non-official files
            return;
        }

        $data      = Yaml::parseFile($file);
        $provenance = $data['library']['provenance'] ?? [];
        // YAML uses snake_case: provenance_source
        $actual    = (string) ($provenance['provenance_source'] ?? $provenance['provenanceSource'] ?? '');

        self::assertSame(
            $expectedProvenance,
            $actual,
            sprintf(
                '%s: provenanceSource must be "%s" for the official ISO 27701:2025 Annex D mapping '
                . '(got "%s"). This marker is used by audit tools to distinguish amtliche from '
                . 'derived mappings.',
                basename($file),
                $expectedProvenance,
                $actual,
            ),
        );
    }
}

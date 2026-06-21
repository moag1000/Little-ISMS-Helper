<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression guard for NIST CSF 2.0 ↔ ISO 27001:2022 mapping framework-code alignment.
 *
 * MappingLibraryLoader performs exact-string comparisons against DB framework codes
 * (line 64 / line 114 of MappingLibraryLoader.php). A single character difference
 * (e.g. 'NIST-CSF' vs 'NIST-CSF-2.0', or 'ISO-27001' vs 'ISO27001') silently causes
 * ALL mapping pairs to be skipped at import time with no error message.
 *
 * Canonical DB codes (verified from source commands + migration Version20260506213529):
 *   - NIST CSF 2.0:  'NIST-CSF-2.0'  (migrated from 'NIST-CSF' in Version20260506213529)
 *   - ISO 27001:2022: 'ISO27001'       (from SeedNis2Iso27001MappingsCommand::TARGET_FRAMEWORK,
 *                                        SeedDoraIso27001MappingsCommand::TARGET_FRAMEWORK, etc.)
 *
 * These tests cover both directions (ISO→NIST and NIST→ISO) and assert:
 *   1. Files exist and parse cleanly
 *   2. source_framework and target_framework use exact DB codes
 *   3. Minimum entry counts (guards against accidental truncation)
 *   4. Structural integrity (required fields per mapping pair)
 */
final class NistCsf2Iso27001MappingCodesTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    /**
     * Canonical DB code for NIST CSF 2.0, hardcoded here because
     * LoadNistCsf2FullCatalogueCommand does not expose it as a named constant.
     * Verified from: src/Command/LoadNistCsf2FullCatalogueCommand.php line 41
     * and migration Version20260506213529 (NIST-CSF → NIST-CSF-2.0 rename).
     */
    private const EXPECTED_NIST_CODE = 'NIST-CSF-2.0';

    /**
     * Canonical DB code for ISO/IEC 27001:2022.
     * Sourced from SeedNis2Iso27001MappingsCommand::TARGET_FRAMEWORK
     * (and SeedDoraIso27001MappingsCommand::TARGET_FRAMEWORK, etc.) —
     * the multi-seeder commands that all converge on 'ISO27001'.
     */
    private const EXPECTED_ISO_CODE = 'ISO27001';

    // ────────────────────────────────────────────────────────────────────────────
    // Data providers
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Both NIST CSF 2.0 ↔ ISO 27001 mapping YAML files (bidirectional).
     *
     * @return array<string, array{file: string, nistSide: 'source'|'target', isoSide: 'source'|'target', minPairs: int}>
     */
    public static function nistIsoMappingFileProvider(): array
    {
        return [
            'ISO→NIST (forward)' => [
                'file'     => self::MAPPING_DIR . '/iso27001-2022_to_nist-csf-2-0_v1.0.yaml',
                'nistSide' => 'target',
                'isoSide'  => 'source',
                'minPairs' => 100,
            ],
            'NIST→ISO (reverse)' => [
                'file'     => self::MAPPING_DIR . '/nist-csf-2-0_to_iso27001-2022_v1.0.yaml',
                'nistSide' => 'source',
                'isoSide'  => 'target',
                'minPairs' => 100,
            ],
        ];
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: file existence + parseable YAML
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $nistSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nistIsoMappingFileProvider')]
    public function mapping_file_exists_and_is_readable(
        string $file,
        string $nistSide,
        string $isoSide,
        int $minPairs,
    ): void {
        self::assertFileExists($file, "NIST↔ISO mapping file missing: $file");
        self::assertFileIsReadable($file);
    }

    /**
     * @param 'source'|'target' $nistSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nistIsoMappingFileProvider')]
    public function mapping_file_has_required_top_level_keys(
        string $file,
        string $nistSide,
        string $isoSide,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);
        self::assertIsArray($data);
        self::assertArrayHasKey('schema_version', $data, basename($file) . ': missing schema_version');
        self::assertArrayHasKey('library', $data, basename($file) . ': missing library');
        self::assertArrayHasKey('mappings', $data, basename($file) . ': missing mappings');
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: framework-code alignment (critical regression guard)
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * CRITICAL: NIST side must use 'NIST-CSF-2.0' (NOT 'NIST-CSF', 'NIST CSF 2.0',
     * 'nist-csf-2.0', etc.). MappingLibraryLoader exact-match means any variant
     * silently skips ALL mapping pairs.
     *
     * @param 'source'|'target' $nistSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nistIsoMappingFileProvider')]
    public function nist_framework_code_matches_db_code(
        string $file,
        string $nistSide,
        string $isoSide,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);
        $actual = (string) ($data['library']["{$nistSide}_framework"] ?? '');

        self::assertSame(
            self::EXPECTED_NIST_CODE,
            $actual,
            sprintf(
                '%s: %s_framework "%s" must equal DB code "%s" '
                . '(LoadNistCsf2FullCatalogueCommand line 41). '
                . 'A mismatch causes MappingLibraryLoader to silently skip all %d pairs.',
                basename($file),
                $nistSide,
                $actual,
                self::EXPECTED_NIST_CODE,
                count($data['mappings'] ?? []),
            ),
        );
    }

    /**
     * CRITICAL: ISO side must use 'ISO27001' (NOT 'ISO-27001', 'ISO27001:2022', etc.).
     *
     * @param 'source'|'target' $nistSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nistIsoMappingFileProvider')]
    public function iso_framework_code_matches_db_code(
        string $file,
        string $nistSide,
        string $isoSide,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);
        $actual = (string) ($data['library']["{$isoSide}_framework"] ?? '');

        self::assertSame(
            self::EXPECTED_ISO_CODE,
            $actual,
            sprintf(
                '%s: %s_framework "%s" must equal DB code "%s" '
                . '(SeedNis2Iso27001MappingsCommand::TARGET_FRAMEWORK). '
                . 'A mismatch causes MappingLibraryLoader to silently skip all %d pairs.',
                basename($file),
                $isoSide,
                $actual,
                self::EXPECTED_ISO_CODE,
                count($data['mappings'] ?? []),
            ),
        );
    }

    /**
     * Belt-and-suspenders: guard against legacy code like 'NIST-CSF' (pre-migration)
     * or 'NIST CSF' (space variant) leaking into the framework fields.
     *
     * @param 'source'|'target' $nistSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nistIsoMappingFileProvider')]
    public function no_legacy_nist_csf_code_without_version(
        string $file,
        string $nistSide,
        string $isoSide,
        int $minPairs,
    ): void {
        $content = (string) file_get_contents($file);

        // Must NOT contain the old pre-migration code 'NIST-CSF' as a standalone value
        // (i.e. 'NIST-CSF' without '-2.0' suffix) in a framework: line.
        $hasLegacyCode = (bool) preg_match('/framework:\s*[\'"]NIST-CSF[\'"]/m', $content);
        self::assertFalse(
            $hasLegacyCode,
            sprintf(
                '%s: contains legacy "NIST-CSF" (without "-2.0") in a framework field. '
                . 'Migration Version20260506213529 renamed the DB code to "NIST-CSF-2.0". '
                . 'Update to "NIST-CSF-2.0" to avoid silent import failure.',
                basename($file),
            ),
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: content quality
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $nistSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nistIsoMappingFileProvider')]
    public function mapping_meets_minimum_pair_count(
        string $file,
        string $nistSide,
        string $isoSide,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);
        $count = count($data['mappings'] ?? []);

        self::assertGreaterThanOrEqual(
            $minPairs,
            $count,
            sprintf('%s: must contain at least %d mapping pairs (got %d)', basename($file), $minPairs, $count),
        );
    }

    /**
     * @param 'source'|'target' $nistSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nistIsoMappingFileProvider')]
    public function all_mapping_pairs_have_required_fields(
        string $file,
        string $nistSide,
        string $isoSide,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);
        foreach ($data['mappings'] ?? [] as $i => $entry) {
            self::assertArrayHasKey('source', $entry, basename($file) . " pair #{$i}: missing 'source'");
            self::assertArrayHasKey('target', $entry, basename($file) . " pair #{$i}: missing 'target'");
            self::assertArrayHasKey('relationship', $entry, basename($file) . " pair #{$i}: missing 'relationship'");
            self::assertArrayHasKey('confidence', $entry, basename($file) . " pair #{$i}: missing 'confidence'");
        }
    }

    /**
     * NIST CSF 2.0 subcategory IDs follow the pattern FUNCTION.CATEGORY-##
     * where FUNCTION is one of: GV, ID, PR, DE, RS, RC
     * e.g. GV.OC-01, ID.AM-01, PR.AA-01, DE.CM-01, RS.MA-01, RC.RP-01
     *
     * @param 'source'|'target' $nistSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nistIsoMappingFileProvider')]
    public function nist_identifiers_match_csf2_subcategory_pattern(
        string $file,
        string $nistSide,
        string $isoSide,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);
        $invalidIds = [];

        foreach ($data['mappings'] ?? [] as $entry) {
            $id = (string) ($entry[$nistSide] ?? '');
            if ($id === '') {
                continue;
            }
            // CSF 2.0 pattern: FUNCTION.CATEGORY-NN (e.g. GV.OC-01, PR.AA-02)
            if (!preg_match('/^(GV|ID|PR|DE|RS|RC)\.[A-Z]{2,4}-\d{2}$/', $id)) {
                $invalidIds[] = $id;
            }
        }

        self::assertEmpty(
            $invalidIds,
            sprintf(
                '%s: found %d NIST CSF 2.0 identifiers not matching FUNCTION.CATEGORY-NN pattern: %s',
                basename($file),
                count($invalidIds),
                implode(', ', array_unique(array_slice($invalidIds, 0, 10))),
            ),
        );
    }

    /**
     * ISO 27001:2022 Annex A control IDs follow the pattern A.N.M
     * where N is 5-8 and M is 1-37 (per clause).
     * Normative clause IDs (4.x, 5.x, 6.x, 7.x, 8.x, 9.x, 10.x) are also valid.
     *
     * @param 'source'|'target' $nistSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nistIsoMappingFileProvider')]
    public function iso_identifiers_match_known_iso27001_patterns(
        string $file,
        string $nistSide,
        string $isoSide,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);
        $invalidIds = [];

        foreach ($data['mappings'] ?? [] as $entry) {
            $id = (string) ($entry[$isoSide] ?? '');
            if ($id === '') {
                continue;
            }
            // Annex A: A.5.1 - A.8.34
            $isAnnexA = (bool) preg_match('/^A\.[5-8]\.\d+$/', $id);
            // Normative clauses: bare 4.1, 5.1, 6.1.1, 7.5.3 OR the canonical
            // DB-stored prefixed form ISO27001-4.1 (LoadIso27001AnnexAFullCommand
            // stores clauses as 'ISO27001-{clause}'; bare ids do NOT resolve at
            // runtime, so clause targets must use the prefixed form).
            $isClause = (bool) preg_match('/^(ISO27001-)?\d+(\.\d+)+$/', $id);

            if (!$isAnnexA && !$isClause) {
                $invalidIds[] = $id;
            }
        }

        self::assertEmpty(
            $invalidIds,
            sprintf(
                '%s: found %d ISO 27001 identifiers not matching expected patterns (A.5-8.N or clause N.M): %s',
                basename($file),
                count($invalidIds),
                implode(', ', array_unique(array_slice($invalidIds, 0, 10))),
            ),
        );
    }

    /**
     * All relationship values must be valid enum entries understood by MappingLibraryLoader.
     *
     * @param 'source'|'target' $nistSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nistIsoMappingFileProvider')]
    public function all_relationships_are_valid_enum_values(
        string $file,
        string $nistSide,
        string $isoSide,
        int $minPairs,
    ): void {
        $validRelationships = ['equivalent', 'subset', 'superset', 'partial_overlap', 'related'];
        $data = Yaml::parseFile($file);

        foreach ($data['mappings'] ?? [] as $i => $entry) {
            self::assertContains(
                $entry['relationship'] ?? '',
                $validRelationships,
                sprintf('%s pair #%d: invalid relationship "%s"', basename($file), $i, $entry['relationship'] ?? ''),
            );
        }
    }

    /**
     * All confidence values must be valid enum entries.
     *
     * @param 'source'|'target' $nistSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nistIsoMappingFileProvider')]
    public function all_confidence_values_are_valid(
        string $file,
        string $nistSide,
        string $isoSide,
        int $minPairs,
    ): void {
        $validConfidence = ['high', 'medium', 'low'];
        $data = Yaml::parseFile($file);

        foreach ($data['mappings'] ?? [] as $i => $entry) {
            self::assertContains(
                $entry['confidence'] ?? '',
                $validConfidence,
                sprintf('%s pair #%d: invalid confidence "%s"', basename($file), $i, $entry['confidence'] ?? ''),
            );
        }
    }
}

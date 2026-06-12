<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Command\LoadNis2Art21RequirementsCommand;
use App\Command\SeedNis2Iso27001MappingsCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression guard for NIS2 Art.21 ↔ ISO 27001:2022 mapping framework-code and
 * requirement-ID alignment.
 *
 * Bug context (#924-pattern extended): the NIS2↔ISO mapping YAMLs previously used
 * shorthand notation '21.2.a' through '21.2.j' as NIS2 requirement IDs. However,
 * LoadNis2Art21RequirementsCommand stores the DB requirementId as the controlId from
 * fixtures/library/frameworks/nis2-art21_v1.0.yaml — i.e. 'NIS2-ART21-A' through
 * 'NIS2-ART21-J'. MappingLibraryLoader performs an exact findOneBy(['requirementId' => ...])
 * lookup, so '21.2.a' != 'NIS2-ART21-A' causes ALL pairs to be silently skipped at
 * import time with no error message.
 *
 * These tests verify:
 *  1. Framework codes match DB codes: 'NIS2' and 'ISO27001'
 *  2. NIS2 requirement IDs use the 'NIS2-ART21-' prefix (not shorthand '21.2.x')
 *  3. ISO 27001 requirement IDs use the 'A.N.M' Annex-A pattern (matching
 *     LoadIso27001AnnexAFullCommand's CONTROLS map keys)
 *  4. Minimum pair counts (guards against accidental truncation)
 *  5. Required fields present on every mapping pair
 *  6. Relationship and confidence values are valid enum entries
 *
 * No Symfony kernel or DB required — pure file/YAML unit tests.
 */
final class Nis2IsoMappingCodesAlignmentTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    /**
     * Canonical DB code for ISO/IEC 27001:2022.
     * Sourced from SeedNis2Iso27001MappingsCommand::TARGET_FRAMEWORK.
     */
    private const EXPECTED_ISO_CODE = 'ISO27001';

    /**
     * Expected DB code for the NIS2 Art. 21 framework.
     * Sourced from SeedNis2Iso27001MappingsCommand::SOURCE_FRAMEWORK.
     */
    private static function expectedNis2Code(): string
    {
        return SeedNis2Iso27001MappingsCommand::SOURCE_FRAMEWORK;
    }

    /**
     * NIS2 Art. 21(2) requirement ID prefix as stored in DB by
     * LoadNis2Art21RequirementsCommand (uses controlId from the framework YAML).
     * Verified from: fixtures/library/frameworks/nis2-art21_v1.0.yaml controlId fields.
     */
    private const NIS2_REQUIREMENT_PREFIX = 'NIS2-ART21-';

    /**
     * Valid NIS2 Art. 21(2) requirementId values (a-j, uppercase) as stored in DB.
     */
    private const VALID_NIS2_REQUIREMENT_IDS = [
        'NIS2-ART21-A',
        'NIS2-ART21-B',
        'NIS2-ART21-C',
        'NIS2-ART21-D',
        'NIS2-ART21-E',
        'NIS2-ART21-F',
        'NIS2-ART21-G',
        'NIS2-ART21-H',
        'NIS2-ART21-I',
        'NIS2-ART21-J',
    ];

    // ────────────────────────────────────────────────────────────────────────────
    // Data providers
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * Both NIS2 Art.21 ↔ ISO 27001:2022 mapping YAML files (bidirectional).
     *
     * @return array<string, array{
     *     file: string,
     *     nis2Side: 'source'|'target',
     *     isoSide: 'source'|'target',
     *     minPairs: int,
     * }>
     */
    public static function nis2IsoMappingFileProvider(): array
    {
        return [
            'NIS2→ISO (forward)' => [
                'file'     => self::MAPPING_DIR . '/nis2-art21_to_iso27001-2022_v1.0.yaml',
                'nis2Side' => 'source',
                'isoSide'  => 'target',
                'minPairs' => 10,
            ],
            'ISO→NIS2 (reverse)' => [
                'file'     => self::MAPPING_DIR . '/iso27001-2022_to_nis2-art21_v1.0.yaml',
                'nis2Side' => 'target',
                'isoSide'  => 'source',
                'minPairs' => 10,
            ],
        ];
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: file existence + parseable YAML
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function mapping_file_exists_and_is_readable(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        self::assertFileExists($file, "NIS2↔ISO 27001 mapping file missing: $file");
        self::assertFileIsReadable($file);
    }

    /**
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function mapping_file_has_required_top_level_keys(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);
        self::assertIsArray($data, basename($file) . ': YAML parse returned non-array');
        self::assertArrayHasKey('schema_version', $data, basename($file) . ': missing schema_version');
        self::assertArrayHasKey('library', $data, basename($file) . ': missing library');
        self::assertArrayHasKey('mappings', $data, basename($file) . ': missing mappings');
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: framework-code alignment (critical — #924 regression guard)
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * CRITICAL: NIS2 side must use 'NIS2' (exact DB code from
     * SeedNis2Iso27001MappingsCommand::SOURCE_FRAMEWORK). Any variant such as
     * 'NIS-2', 'nis2', 'NIS2-Art21' causes MappingLibraryLoader to silently skip
     * ALL mapping pairs at import time.
     *
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function nis2_framework_code_matches_db_code(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        $data   = Yaml::parseFile($file);
        $actual = (string) ($data['library']["{$nis2Side}_framework"] ?? '');

        self::assertSame(
            self::expectedNis2Code(),
            $actual,
            sprintf(
                '[#924-pattern] %s: %s_framework "%s" must equal DB code "%s" '
                . '(SeedNis2Iso27001MappingsCommand::SOURCE_FRAMEWORK). '
                . 'A mismatch causes MappingLibraryLoader to silently skip all %d pairs.',
                basename($file),
                $nis2Side,
                $actual,
                self::expectedNis2Code(),
                count($data['mappings'] ?? []),
            ),
        );
    }

    /**
     * CRITICAL: ISO 27001 side must use 'ISO27001' (exact DB code from
     * SeedNis2Iso27001MappingsCommand::TARGET_FRAMEWORK). Any variant such as
     * 'ISO-27001', 'ISO27001:2022', 'iso27001' silently drops all pairs.
     *
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function iso_framework_code_matches_db_code(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        $data   = Yaml::parseFile($file);
        $actual = (string) ($data['library']["{$isoSide}_framework"] ?? '');

        self::assertSame(
            self::EXPECTED_ISO_CODE,
            $actual,
            sprintf(
                '[#924-pattern] %s: %s_framework "%s" must equal DB code "%s" '
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

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: requirement ID format alignment
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * CRITICAL: NIS2 requirement IDs must use the 'NIS2-ART21-X' format (uppercase
     * letter suffix), NOT the shorthand '21.2.x' notation.
     *
     * Root cause: LoadNis2Art21RequirementsCommand stores the framework YAML
     * controlId field ('NIS2-ART21-A' through 'NIS2-ART21-J') as the DB
     * requirementId. MappingLibraryLoader calls
     * findOneBy(['framework' => ..., 'requirementId' => entry[nis2Side]]).
     * If entry[nis2Side] is '21.2.a', the lookup returns null and the pair is
     * silently skipped — zero mappings load without any error.
     *
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function all_nis2_ids_use_nis2_art21_prefix(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        $data    = Yaml::parseFile($file);
        $invalid = [];

        foreach ($data['mappings'] ?? [] as $entry) {
            $id = (string) ($entry[$nis2Side] ?? '');
            if ($id === '') {
                continue;
            }
            if (!str_starts_with($id, self::NIS2_REQUIREMENT_PREFIX)) {
                $invalid[] = $id;
            }
        }

        self::assertEmpty(
            $invalid,
            sprintf(
                '[requirementId-mismatch] %s: found %d NIS2 IDs not starting with "%s". '
                . 'DB stores requirementId from framework controlId field (e.g. "NIS2-ART21-A"), '
                . 'NOT shorthand "21.2.a" — wrong IDs cause MappingLibraryLoader to silently '
                . 'skip ALL pairs. Fix by prefixing with "NIS2-ART21-" and uppercasing the letter. '
                . 'Invalid IDs: %s',
                basename($file),
                count($invalid),
                self::NIS2_REQUIREMENT_PREFIX,
                implode(', ', array_unique($invalid)),
            ),
        );
    }

    /**
     * All NIS2 requirement IDs must be from the known valid set
     * ('NIS2-ART21-A' through 'NIS2-ART21-J') — 10 Art. 21(2) measures.
     * IDs outside this set have no matching DB row and will be silently skipped.
     *
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function all_nis2_ids_are_in_known_valid_set(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        $data    = Yaml::parseFile($file);
        $invalid = [];

        foreach ($data['mappings'] ?? [] as $entry) {
            $id = (string) ($entry[$nis2Side] ?? '');
            if ($id === '') {
                continue;
            }
            if (!in_array($id, self::VALID_NIS2_REQUIREMENT_IDS, true)) {
                $invalid[] = $id;
            }
        }

        self::assertEmpty(
            $invalid,
            sprintf(
                '%s: found %d NIS2 IDs not in the known valid set %s. '
                . 'NIS2 Art. 21(2) has exactly 10 measures (a)-(j). '
                . 'Unknown IDs: %s',
                basename($file),
                count($invalid),
                implode(', ', self::VALID_NIS2_REQUIREMENT_IDS),
                implode(', ', array_unique($invalid)),
            ),
        );
    }

    /**
     * Belt-and-suspenders: the old shorthand '21.2.x' format must NOT appear in
     * any NIS2 source/target field after the DB-alignment fix.
     *
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function no_legacy_shorthand_nis2_ids_in_mapping_side(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        $data    = Yaml::parseFile($file);
        $legacy  = [];

        foreach ($data['mappings'] ?? [] as $entry) {
            $id = (string) ($entry[$nis2Side] ?? '');
            // Match shorthand: 21.2.a through 21.2.j (with or without surrounding quotes)
            if (preg_match('/^21\.\d+\.[a-z]$/', $id)) {
                $legacy[] = $id;
            }
        }

        self::assertEmpty(
            $legacy,
            sprintf(
                '[legacy-shorthand guard] %s: found %d NIS2 IDs still in old shorthand format '
                . '(e.g. "21.2.a"). These were replaced by "NIS2-ART21-A" in the DB-alignment '
                . 'fix (2026-06-13). Revert is not permitted — old format silently breaks import. '
                . 'Legacy IDs found: %s',
                basename($file),
                count($legacy),
                implode(', ', array_unique($legacy)),
            ),
        );
    }

    /**
     * ISO 27001 Annex A control IDs must use the 'A.N.M' pattern (A.5.x through A.8.x).
     * LoadIso27001AnnexAFullCommand uses bare 'A.5.1' format as requirementId in DB.
     * Normative clause IDs (4.x, 5.x, etc.) are also valid for clause-level mappings.
     *
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function all_iso_ids_match_known_iso27001_patterns(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        $data    = Yaml::parseFile($file);
        $invalid = [];

        foreach ($data['mappings'] ?? [] as $entry) {
            $id = (string) ($entry[$isoSide] ?? '');
            if ($id === '') {
                continue;
            }
            // Annex A: A.5.1 - A.8.34
            $isAnnexA = (bool) preg_match('/^A\.[5-8]\.\d+$/', $id);
            // Normative clauses: 4.1, 5.2, 6.1.1, 7.5, 8.1, 9.1, 10.1, etc.
            $isClause = (bool) preg_match('/^\d+(\.\d+)+$/', $id);

            if (!$isAnnexA && !$isClause) {
                $invalid[] = $id;
            }
        }

        self::assertEmpty(
            $invalid,
            sprintf(
                '%s: found %d ISO 27001 identifiers not matching expected patterns '
                . '(Annex A: "A.5-8.N" or normative clause "N.M"): %s',
                basename($file),
                count($invalid),
                implode(', ', array_unique(array_slice($invalid, 0, 10))),
            ),
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: content quality
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function mapping_meets_minimum_pair_count(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        $data  = Yaml::parseFile($file);
        $count = count($data['mappings'] ?? []);

        self::assertGreaterThanOrEqual(
            $minPairs,
            $count,
            sprintf('%s: must contain at least %d mapping pairs (got %d)', basename($file), $minPairs, $count),
        );
    }

    /**
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function all_mapping_pairs_have_required_fields(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);

        foreach ($data['mappings'] ?? [] as $i => $entry) {
            self::assertArrayHasKey('source', $entry, basename($file) . " pair #{$i}: missing 'source'");
            self::assertArrayHasKey('target', $entry, basename($file) . " pair #{$i}: missing 'target'");
            self::assertArrayHasKey('relationship', $entry, basename($file) . " pair #{$i}: missing 'relationship'");
            self::assertNotEmpty((string) ($entry['source'] ?? ''), basename($file) . " pair #{$i}: 'source' is empty");
            self::assertNotEmpty((string) ($entry['target'] ?? ''), basename($file) . " pair #{$i}: 'target' is empty");
        }
    }

    /**
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function all_relationships_are_valid_enum_values(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        $validRelationships = ['equivalent', 'subset', 'superset', 'partial_overlap', 'related'];
        $data               = Yaml::parseFile($file);

        foreach ($data['mappings'] ?? [] as $i => $entry) {
            $rel = (string) ($entry['relationship'] ?? '');
            if ($rel !== '') {
                self::assertContains(
                    $rel,
                    $validRelationships,
                    sprintf('%s pair #%d: invalid relationship "%s"', basename($file), $i, $rel),
                );
            }
        }
    }

    /**
     * All NIS2 Art. 21(2) measures (a)-(j) must appear at least once on the NIS2 side.
     * A mapping that drops one of the 10 mandatory measures is incomplete.
     *
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('nis2IsoMappingFileProvider')]
    public function all_10_nis2_art21_measures_appear_at_least_once(
        string $file,
        string $nis2Side,
        string $isoSide,
        int $minPairs,
    ): void {
        $data     = Yaml::parseFile($file);
        $nis2Ids  = array_column($data['mappings'] ?? [], $nis2Side);
        $uniqueIds = array_unique($nis2Ids);

        $missing = array_diff(self::VALID_NIS2_REQUIREMENT_IDS, $uniqueIds);

        self::assertEmpty(
            $missing,
            sprintf(
                '%s: missing NIS2 measures on the %s side: %s. '
                . 'All 10 Art. 21(2)(a)-(j) measures must be represented.',
                basename($file),
                $nis2Side,
                implode(', ', $missing),
            ),
        );
    }
}

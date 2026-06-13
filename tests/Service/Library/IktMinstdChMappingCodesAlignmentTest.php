<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Codes-alignment guard for IKT-MINSTD-CH mapping files.
 *
 * Verification scope:
 *  1. Both mapping files exist and parse as valid YAML with required keys.
 *  2. framework codes match DB codes exactly:
 *       source_framework / target_framework for IKT-MINSTD-CH = 'IKT-MINSTD-CH'
 *       (LoadIktMinstdChFullCommand::getFrameworkCode())
 *       ISO 27001   = 'ISO27001'
 *       NIST CSF 2.0 = 'NIST-CSF-2.0'
 *     MappingLibraryLoader uses exact findOneBy(['code' => ...]) — a single character
 *     mismatch silently skips ALL mapping pairs at import time.
 *  3. IKT-MINSTD-CH source IDs use the NIST CSF 1.1 notation (ID.AM-1, PR.AC-3,
 *     DE.CM-4, RS.RP-1, RC.RP-1 etc.) — never bare numbers or 'IKT-' prefixes.
 *  4. ISO 27001-side IDs use the 'A.' prefix (Annex A controls, e.g. A.5.1, A.8.7).
 *  5. NIST CSF 2.0-side IDs use zero-padded subcategory notation (GV.*, ID.*, PR.*,
 *     DE.*, RS.*, RC.* with two-digit numeric suffix, e.g. ID.AM-01, PR.AA-01).
 *  6. relationship values are from the allowed set.
 *  7. At least 20 mapping pairs in each file.
 *  8. No duplicate (source, target) pairs within each file.
 *  9. provenance.primary_source is non-empty.
 *
 * No Symfony kernel or DB required — pure file/YAML unit tests.
 */
final class IktMinstdChMappingCodesAlignmentTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    private const FILE_TO_NIST = self::MAPPING_DIR . '/ikt-minstd-ch_to_nist-csf-2-0_v1.0.yaml';
    private const FILE_TO_ISO  = self::MAPPING_DIR . '/ikt-minstd-ch_to_iso27001-2022_v1.0.yaml';

    /** Canonical DB code for the IKT-Minimalstandard (NCSC/BWL, CH). */
    private const DB_CODE_IKT = 'IKT-MINSTD-CH';

    /** Canonical DB code for NIST CSF 2.0. */
    private const DB_CODE_NIST = 'NIST-CSF-2.0';

    /** Canonical DB code for ISO 27001:2022. */
    private const DB_CODE_ISO27001 = 'ISO27001';

    /** Relationship values accepted by MappingValidatorService. */
    private const ALLOWED_RELATIONSHIPS = ['equivalent', 'subset', 'superset', 'related', 'partial_overlap'];

    // ─────────────────────────────────────────────────────────────────────────
    // Data providers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{
     *   file: string,
     *   sourceCode: string,
     *   targetCode: string,
     *   iktSide: 'source'|'target',
     *   peerSide: 'source'|'target',
     *   peerType: 'nist'|'iso27001',
     * }>
     */
    public static function mappingFileProvider(): array
    {
        return [
            'IKT-MINSTD-CH → NIST-CSF-2.0' => [
                'file'       => self::FILE_TO_NIST,
                'sourceCode' => self::DB_CODE_IKT,
                'targetCode' => self::DB_CODE_NIST,
                'iktSide'    => 'source',
                'peerSide'   => 'target',
                'peerType'   => 'nist',
            ],
            'IKT-MINSTD-CH → ISO27001' => [
                'file'       => self::FILE_TO_ISO,
                'sourceCode' => self::DB_CODE_IKT,
                'targetCode' => self::DB_CODE_ISO27001,
                'iktSide'    => 'source',
                'peerSide'   => 'target',
                'peerType'   => 'iso27001',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 1 — file existence and required YAML structure
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target'        $iktSide
     * @param 'source'|'target'        $peerSide
     * @param 'nist'|'iso27001'        $peerType
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function file_exists_and_has_required_yaml_structure(
        string $file,
        string $sourceCode,
        string $targetCode,
        string $iktSide,
        string $peerSide,
        string $peerType,
    ): void {
        self::assertFileExists($file, "IKT-MINSTD-CH mapping file missing: {$file}");
        self::assertFileIsReadable($file);

        $data = Yaml::parseFile($file);
        self::assertIsArray($data);
        self::assertArrayHasKey('schema_version', $data, "Missing 'schema_version' in: " . basename($file));
        self::assertArrayHasKey('library', $data, "Missing 'library' key in: " . basename($file));
        self::assertArrayHasKey('mappings', $data, "Missing 'mappings' key in: " . basename($file));
        self::assertIsArray($data['mappings'], "'mappings' must be an array in: " . basename($file));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 2 — framework codes match DB codes exactly
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * CRITICAL: MappingLibraryLoader uses exact string comparison against DB codes.
     * A mismatch (e.g. 'IKT-Minstd-CH' vs 'IKT-MINSTD-CH') silently skips
     * ALL mapping pairs at import time.
     *
     * @param 'source'|'target'        $iktSide
     * @param 'source'|'target'        $peerSide
     * @param 'nist'|'iso27001'        $peerType
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function framework_codes_match_db_codes_exactly(
        string $file,
        string $sourceCode,
        string $targetCode,
        string $iktSide,
        string $peerSide,
        string $peerType,
    ): void {
        $data = Yaml::parseFile($file);

        $actualSource = (string) ($data['library']['source_framework'] ?? '');
        $actualTarget = (string) ($data['library']['target_framework'] ?? '');

        self::assertSame(
            $sourceCode,
            $actualSource,
            sprintf(
                '%s: source_framework "%s" must equal DB code "%s". '
                . 'A mismatch silently skips ALL mapping pairs at import time.',
                basename($file),
                $actualSource,
                $sourceCode,
            ),
        );

        self::assertSame(
            $targetCode,
            $actualTarget,
            sprintf(
                '%s: target_framework "%s" must equal DB code "%s". '
                . 'A mismatch silently skips ALL mapping pairs at import time.',
                basename($file),
                $actualTarget,
                $targetCode,
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 3 — IKT-side IDs use NIST CSF 1.1 notation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * IKT-MINSTD-CH source IDs must follow the NIST CSF 1.1 pattern:
     * <FUNCTION>.<CATEGORY>-<NUMBER>
     * e.g. ID.AM-1, PR.AC-3, DE.CM-4, RS.RP-1, RC.RP-1
     * Functions: ID, PR, DE, RS, RC
     * The standard itself uses this notation (see PDF Abschnitt 2.2–2.6).
     *
     * @param 'source'|'target'        $iktSide
     * @param 'source'|'target'        $peerSide
     * @param 'nist'|'iso27001'        $peerType
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function ikt_side_ids_use_nist_csf_11_notation(
        string $file,
        string $sourceCode,
        string $targetCode,
        string $iktSide,
        string $peerSide,
        string $peerType,
    ): void {
        $data     = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $violations = [];
        foreach ($mappings as $idx => $entry) {
            $id = (string) ($entry[$iktSide] ?? '');
            if ($id === '') {
                continue;
            }
            // Valid pattern: (ID|PR|DE|RS|RC).(2-3 uppercase letters)-(1 or 2 digits)
            if (!preg_match('/^(ID|PR|DE|RS|RC)\.[A-Z]{2,3}-\d{1,2}$/', $id)) {
                $violations[] = sprintf('mapping[%d].%s = "%s"', $idx, $iktSide, $id);
            }
        }

        self::assertEmpty(
            $violations,
            sprintf(
                '%s: IKT-MINSTD-CH side (%s) IDs must use NIST CSF 1.1 notation '
                . '(e.g. ID.AM-1, PR.AC-7, DE.CM-4, RS.RP-1, RC.RP-1). '
                . 'Violations: %s',
                basename($file),
                $iktSide,
                implode(', ', $violations),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 4a — ISO 27001-side IDs use 'A.' prefix (Annex A)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target'        $iktSide
     * @param 'source'|'target'        $peerSide
     * @param 'nist'|'iso27001'        $peerType
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function iso_side_ids_use_annex_a_prefix_when_applicable(
        string $file,
        string $sourceCode,
        string $targetCode,
        string $iktSide,
        string $peerSide,
        string $peerType,
    ): void {
        if ($peerType !== 'iso27001') {
            $this->addToAssertionCount(1); // N/A for NIST mapping
            return;
        }

        $data     = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $violations = [];
        foreach ($mappings as $idx => $entry) {
            $id = (string) ($entry[$peerSide] ?? '');
            if ($id === '') {
                continue;
            }
            if (!str_starts_with($id, 'A.')) {
                $violations[] = sprintf('mapping[%d].%s = "%s"', $idx, $peerSide, $id);
            }
        }

        self::assertEmpty(
            $violations,
            sprintf(
                '%s: ISO 27001-side (%s) IDs must start with "A." (Annex A controls, '
                . 'e.g. A.5.1, A.8.8). Non-Annex-A clause IDs must not appear as targets. '
                . 'Violations: %s',
                basename($file),
                $peerSide,
                implode(', ', $violations),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 4b — NIST CSF 2.0-side IDs use zero-padded subcategory notation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target'        $iktSide
     * @param 'source'|'target'        $peerSide
     * @param 'nist'|'iso27001'        $peerType
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function nist_side_ids_use_csf2_notation_when_applicable(
        string $file,
        string $sourceCode,
        string $targetCode,
        string $iktSide,
        string $peerSide,
        string $peerType,
    ): void {
        if ($peerType !== 'nist') {
            $this->addToAssertionCount(1); // N/A for ISO 27001 mapping
            return;
        }

        $data     = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $violations = [];
        foreach ($mappings as $idx => $entry) {
            $id = (string) ($entry[$peerSide] ?? '');
            if ($id === '') {
                continue;
            }
            // Valid NIST CSF 2.0 pattern: (GV|ID|PR|DE|RS|RC).<2-3 UPPERCASE>-<2 digits>
            if (!preg_match('/^(GV|ID|PR|DE|RS|RC)\.[A-Z]{2,3}-\d{2}$/', $id)) {
                $violations[] = sprintf('mapping[%d].%s = "%s"', $idx, $peerSide, $id);
            }
        }

        self::assertEmpty(
            $violations,
            sprintf(
                '%s: NIST CSF 2.0-side (%s) IDs must use CSF 2.0 notation '
                . '(e.g. GV.OC-01, ID.AM-01, PR.AA-03, DE.CM-01, RS.MA-01, RC.RP-01). '
                . 'CSF 1.1 notation (e.g. ID.AM-1 without zero-padding) is NOT valid here. '
                . 'Violations: %s',
                basename($file),
                $peerSide,
                implode(', ', $violations),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 5 — at least 20 mapping pairs per file
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target'        $iktSide
     * @param 'source'|'target'        $peerSide
     * @param 'nist'|'iso27001'        $peerType
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function mapping_has_at_least_20_pairs(
        string $file,
        string $sourceCode,
        string $targetCode,
        string $iktSide,
        string $peerSide,
        string $peerType,
    ): void {
        $data  = Yaml::parseFile($file);
        $count = count($data['mappings'] ?? []);

        self::assertGreaterThanOrEqual(
            20,
            $count,
            sprintf(
                '%s: expected >= 20 mapping pairs (IKT-MINSTD-CH has 108 measures across '
                . '5 NIST CSF functions, all should have coverage). Got %d.',
                basename($file),
                $count,
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 6 — relationship values are from the allowed set
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target'        $iktSide
     * @param 'source'|'target'        $peerSide
     * @param 'nist'|'iso27001'        $peerType
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function relationship_values_are_valid(
        string $file,
        string $sourceCode,
        string $targetCode,
        string $iktSide,
        string $peerSide,
        string $peerType,
    ): void {
        $data     = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $violations = [];
        foreach ($mappings as $idx => $entry) {
            $rel = (string) ($entry['relationship'] ?? '');
            if (!in_array($rel, self::ALLOWED_RELATIONSHIPS, true)) {
                $violations[] = sprintf(
                    'mapping[%d]: relationship "%s" not in allowed set [%s]',
                    $idx,
                    $rel,
                    implode(', ', self::ALLOWED_RELATIONSHIPS),
                );
            }
        }

        self::assertEmpty($violations, basename($file) . ': invalid relationship values: ' . implode('; ', $violations));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 7 — all mapping entries have non-empty source, target, relationship
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target'        $iktSide
     * @param 'source'|'target'        $peerSide
     * @param 'nist'|'iso27001'        $peerType
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function all_entries_have_required_fields(
        string $file,
        string $sourceCode,
        string $targetCode,
        string $iktSide,
        string $peerSide,
        string $peerType,
    ): void {
        $data     = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $violations = [];
        foreach ($mappings as $idx => $entry) {
            foreach (['source', 'target', 'relationship'] as $field) {
                $val = (string) ($entry[$field] ?? '');
                if ($val === '') {
                    $violations[] = sprintf('mapping[%d] missing or empty field "%s"', $idx, $field);
                }
            }
        }

        self::assertEmpty(
            $violations,
            basename($file) . ': entries with missing required fields: ' . implode('; ', $violations),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 8 — no duplicate (source, target) pairs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target'        $iktSide
     * @param 'source'|'target'        $peerSide
     * @param 'nist'|'iso27001'        $peerType
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function no_duplicate_source_target_pairs(
        string $file,
        string $sourceCode,
        string $targetCode,
        string $iktSide,
        string $peerSide,
        string $peerType,
    ): void {
        $data     = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $seen   = [];
        $dupes  = [];
        foreach ($mappings as $idx => $entry) {
            $key = sprintf('%s|%s', ($entry['source'] ?? ''), ($entry['target'] ?? ''));
            if (isset($seen[$key])) {
                $dupes[] = sprintf('mapping[%d] duplicates [%d]: %s', $idx, $seen[$key], $key);
            } else {
                $seen[$key] = $idx;
            }
        }

        self::assertEmpty(
            $dupes,
            basename($file) . ': duplicate (source, target) pairs found: ' . implode('; ', $dupes),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 9 — provenance.primary_source is non-empty
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target'        $iktSide
     * @param 'source'|'target'        $peerSide
     * @param 'nist'|'iso27001'        $peerType
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function provenance_primary_source_is_non_empty(
        string $file,
        string $sourceCode,
        string $targetCode,
        string $iktSide,
        string $peerSide,
        string $peerType,
    ): void {
        $data          = Yaml::parseFile($file);
        $primarySource = (string) ($data['library']['provenance']['primary_source'] ?? '');

        self::assertNotEmpty(
            $primarySource,
            sprintf(
                '%s: library.provenance.primary_source must not be empty '
                . '(MappingValidatorService rejects anonymous mappings).',
                basename($file),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 10 — framework code 'IKT-MINSTD-CH' is consistent in both files
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function ikt_framework_code_is_ikt_minstd_ch_in_both_files(): void
    {
        foreach ([self::FILE_TO_NIST, self::FILE_TO_ISO] as $file) {
            $data = Yaml::parseFile($file);
            // IKT is always source in these mapping files
            self::assertSame(
                self::DB_CODE_IKT,
                (string) ($data['library']['source_framework'] ?? ''),
                sprintf(
                    '%s: source_framework must be exactly "%s" (no variant spelling). '
                    . 'This is the DB code used by LoadIktMinstdChFullCommand::getFrameworkCode().',
                    basename($file),
                    self::DB_CODE_IKT,
                ),
            );
        }
    }
}

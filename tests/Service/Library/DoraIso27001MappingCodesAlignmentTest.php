<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Codes-alignment guard for the DORA ↔ ISO 27001:2022 mapping files.
 *
 * Verification scope:
 *  1. Files exist and are parseable YAML.
 *  2. Framework codes match DB codes ('DORA' / 'ISO27001') — same guard
 *     pattern as DoraLexSpecialisMappingAlignmentTest (#924 regression).
 *     MappingLibraryLoader performs exact-string comparison against DB codes;
 *     a single-character mismatch silently skips ALL mapping pairs at import.
 *  3. All DORA-side IDs use the canonical 'DORA-' prefix convention
 *     (never bare 'Art.X' for DORA articles — regression fix for Art.7 /
 *     Art.12 entries that were present before this branch).
 *  4. All ISO 27001-side IDs use the canonical 'A.' prefix (Annex A controls).
 *  5. Bidirectionality: forward file has source_framework='DORA' and reverse
 *     has source_framework='ISO27001'.
 *  6. Content sanity: both files have >= 15 mapping pairs (the pair count
 *     before this branch).
 *
 * No Symfony kernel or DB required — pure file/YAML unit tests.
 *
 * DB codes confirmed:
 *   DORA    → compliance_framework.code = 'DORA'   (confirmed from dora_to_nis2-art21_v1.0.yaml)
 *   ISO27001 → compliance_framework.code = 'ISO27001' (confirmed from tisax-vda-isa-6_to_iso27001-2022_v1.0.yaml)
 */
final class DoraIso27001MappingCodesAlignmentTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    private const FILE_DORA_TO_ISO  = self::MAPPING_DIR . '/dora_to_iso27001-2022_v1.0.yaml';
    private const FILE_ISO_TO_DORA  = self::MAPPING_DIR . '/iso27001-2022_to_dora_v1.0.yaml';

    /** Canonical DB code for DORA framework. */
    private const DB_CODE_DORA    = 'DORA';

    /** Canonical DB code for ISO 27001:2022 framework. */
    private const DB_CODE_ISO27001 = 'ISO27001';

    // ─────────────────────────────────────────────────────────────────────────
    // Data providers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Both DORA↔ISO mapping files with their directional metadata.
     *
     * @return array<string, array{
     *   file: string,
     *   sourceFramework: string,
     *   targetFramework: string,
     *   doraSide: 'source'|'target',
     *   isoSide: 'source'|'target',
     * }>
     */
    public static function mappingFileProvider(): array
    {
        return [
            'DORA→ISO27001 (forward)' => [
                'file'            => self::FILE_DORA_TO_ISO,
                'sourceFramework' => self::DB_CODE_DORA,
                'targetFramework' => self::DB_CODE_ISO27001,
                'doraSide'        => 'source',
                'isoSide'         => 'target',
            ],
            'ISO27001→DORA (reverse)' => [
                'file'            => self::FILE_ISO_TO_DORA,
                'sourceFramework' => self::DB_CODE_ISO27001,
                'targetFramework' => self::DB_CODE_DORA,
                'doraSide'        => 'target',
                'isoSide'         => 'source',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 1 — file existence + YAML structure
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function file_exists_and_has_required_yaml_keys(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $isoSide,
    ): void {
        self::assertFileExists($file, "DORA↔ISO27001 mapping file missing: $file");
        self::assertFileIsReadable($file);

        $data = Yaml::parseFile($file);
        self::assertIsArray($data, 'YAML parse returned non-array for: ' . basename($file));
        self::assertArrayHasKey('library', $data, "Missing 'library' key in: " . basename($file));
        self::assertArrayHasKey('mappings', $data, "Missing 'mappings' key in: " . basename($file));
        self::assertArrayHasKey('schema_version', $data, "Missing 'schema_version' in: " . basename($file));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 2 — framework codes match DB codes (regression: #924-style)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * CRITICAL: framework codes must be 'DORA' and 'ISO27001' exactly.
     * MappingLibraryLoader performs exact-string comparison against DB codes.
     * A mismatch silently skips ALL mapping pairs at import time.
     *
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function source_and_target_framework_codes_match_db(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $isoSide,
    ): void {
        $data = Yaml::parseFile($file);

        $actualSource = (string) ($data['library']['source_framework'] ?? '');
        $actualTarget = (string) ($data['library']['target_framework'] ?? '');

        self::assertSame(
            $sourceFramework,
            $actualSource,
            sprintf(
                '%s: source_framework "%s" must equal DB code "%s". '
                . 'A mismatch silently skips ALL mapping pairs at import time.',
                basename($file),
                $actualSource,
                $sourceFramework,
            ),
        );

        self::assertSame(
            $targetFramework,
            $actualTarget,
            sprintf(
                '%s: target_framework "%s" must equal DB code "%s". '
                . 'A mismatch silently skips ALL mapping pairs at import time.',
                basename($file),
                $actualTarget,
                $targetFramework,
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 3 — DORA-side IDs use canonical 'DORA-X' prefix (regression fix)
    // Before this branch: Art.7 and Art.12 used bare 'Art.X' form.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * All DORA-side mapping IDs must use the 'DORA-' prefix convention,
     * never a bare 'Art.X' form.
     *
     * Regression: dora_to_iso27001-2022_v1.0.yaml had entries with
     * source='Art.7' and source='Art.12' instead of 'DORA-7' / 'DORA-12'.
     * iso27001-2022_to_dora_v1.0.yaml had target='Art.7' / target='Art.12'.
     *
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function dora_side_ids_use_dora_prefix_not_bare_art(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $isoSide,
    ): void {
        $data     = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $violations = [];
        foreach ($mappings as $idx => $entry) {
            $doraId = (string) ($entry[$doraSide] ?? '');
            if ($doraId === '') {
                continue;
            }

            // Valid prefixes for DORA-side IDs
            $validPrefixes = ['DORA-', 'RTS-', 'ITS-', 'CDR-'];
            $hasValidPrefix = false;
            foreach ($validPrefixes as $prefix) {
                if (str_starts_with($doraId, $prefix)) {
                    $hasValidPrefix = true;
                    break;
                }
            }

            // Flag bare 'Art.X' forms (single numeric suffix, DORA article range 5-45)
            if (!$hasValidPrefix && preg_match('/^Art\.(\d+)$/', $doraId)) {
                $violations[] = sprintf('mapping[%d].%s = "%s"', $idx, $doraSide, $doraId);
            }
        }

        self::assertEmpty(
            $violations,
            sprintf(
                '%s: DORA-side (%s) IDs must use "DORA-" prefix, not bare "Art.X" form. '
                . 'Regression fix: Art.7 → DORA-7, Art.12 → DORA-12. Violations: %s',
                basename($file),
                $doraSide,
                implode(', ', $violations),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 4 — ISO 27001-side IDs use 'A.' prefix (Annex A controls)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * All ISO 27001 Annex A control IDs on the ISO side must start with 'A.'.
     * The DORA↔ISO mapping exclusively references Annex A controls (A.5.x,
     * A.6.x, A.8.x), not ISO 27001 clause numbers (4.x, 5.x, 6.x etc.).
     *
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function iso_side_ids_use_annex_a_prefix(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $isoSide,
    ): void {
        $data     = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $violations = [];
        foreach ($mappings as $idx => $entry) {
            $isoId = (string) ($entry[$isoSide] ?? '');
            if ($isoId === '') {
                continue;
            }

            if (!str_starts_with($isoId, 'A.')) {
                $violations[] = sprintf('mapping[%d].%s = "%s"', $idx, $isoSide, $isoId);
            }
        }

        self::assertEmpty(
            $violations,
            sprintf(
                '%s: ISO 27001-side (%s) IDs must start with "A." (Annex A controls, '
                . 'e.g. A.5.1, A.8.13). Non-Annex-A clause IDs are not expected in this mapping. '
                . 'Violations: %s',
                basename($file),
                $isoSide,
                implode(', ', $violations),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 5 — content sanity: at least 15 mapping pairs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function mapping_has_at_least_15_pairs(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $isoSide,
    ): void {
        $data  = Yaml::parseFile($file);
        $count = count($data['mappings'] ?? []);

        self::assertGreaterThanOrEqual(
            15,
            $count,
            sprintf(
                '%s: expected >= 15 mapping pairs (DORA chapters 5-15, 17, 24, 28, 30 covered). Got %d.',
                basename($file),
                $count,
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 6 — bidirectionality: forward DORA→ISO, reverse ISO→DORA
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function forward_file_has_dora_as_source_framework(): void
    {
        $data = Yaml::parseFile(self::FILE_DORA_TO_ISO);
        self::assertSame(
            self::DB_CODE_DORA,
            (string) ($data['library']['source_framework'] ?? ''),
            'dora_to_iso27001-2022 file must declare source_framework: DORA',
        );
        self::assertSame(
            self::DB_CODE_ISO27001,
            (string) ($data['library']['target_framework'] ?? ''),
            'dora_to_iso27001-2022 file must declare target_framework: ISO27001',
        );
    }

    #[Test]
    public function reverse_file_has_iso27001_as_source_framework(): void
    {
        $data = Yaml::parseFile(self::FILE_ISO_TO_DORA);
        self::assertSame(
            self::DB_CODE_ISO27001,
            (string) ($data['library']['source_framework'] ?? ''),
            'iso27001-2022_to_dora file must declare source_framework: ISO27001',
        );
        self::assertSame(
            self::DB_CODE_DORA,
            (string) ($data['library']['target_framework'] ?? ''),
            'iso27001-2022_to_dora file must declare target_framework: DORA',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 7 — provenance is present (anonymous mappings are rejected by validator)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $isoSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function library_has_non_empty_provenance_primary_source(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $isoSide,
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
}

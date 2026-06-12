<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Alignment guard for the DORA ↔ NIS2 lex-specialis v2.0 mapping files.
 *
 * Verification scope:
 *  1. Files exist and are parseable YAML.
 *  2. framework codes match DB codes ('DORA' / 'NIS2') — same guard pattern
 *     as Nis2BsiMappingCodesTest (#924 regression).
 *  3. provenanceSource is marked 'official_eu_lex_specialis' (amtlich upgrade).
 *  4. EUR-Lex provenance URLs for the lex-specialis basis are present and
 *     reference the correct CELEX numbers (DORA 32022R2554, NIS2 32022L2555,
 *     Commission Communication 52023XC0918(01)).
 *  5. All DORA-side IDs use the canonical 'DORA-X.Y' prefix convention
 *     (never bare 'Art.X' for DORA articles — only NIS2 sub-IDs use bare
 *     'Art.20.x' / 'Art.21.x' / 'Art.23.x').
 *  6. Both files have >= 50 mapping pairs (content sanity).
 *  7. All 10 NIS2 Art.21(2) measures (a)-(j) appear in both files.
 *  8. The bidirectional pair: forward file has source_framework='DORA' and
 *     reverse file has source_framework='NIS2'.
 *
 * No Symfony kernel or DB required — pure file/YAML unit tests.
 *
 * Legal basis confirmed in provenance:
 *   DORA Art. 1(2) (Reg. (EU) 2022/2554) + NIS2 Art. 4(1) (Dir. (EU) 2022/2555)
 *   + EU Commission Communication 2023/C 328/02 (CELEX:52023XC0918(01)).
 */
final class DoraLexSpecialisMappingAlignmentTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    private const FILE_DORA_TO_NIS2 = self::MAPPING_DIR . '/dora_to_nis2_lex-specialis_v2.0.yaml';
    private const FILE_NIS2_TO_DORA = self::MAPPING_DIR . '/nis2_to_dora_lex-specialis_v2.0.yaml';

    /** Canonical DB code for DORA framework. */
    private const DB_CODE_DORA = 'DORA';

    /** Canonical DB code for NIS2 framework. */
    private const DB_CODE_NIS2 = 'NIS2';

    /** CELEX numbers that MUST appear in the EUR-Lex provenance URLs. */
    private const EXPECTED_CELEX_DORA             = '32022R2554';
    private const EXPECTED_CELEX_NIS2             = '32022L2555';
    private const EXPECTED_CELEX_COMM_COMMUNICATION = '52023XC0918';

    // ─────────────────────────────────────────────────────────────────────────
    // Data providers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Both lex-specialis files with their directional metadata.
     *
     * @return array<string, array{
     *   file: string,
     *   sourceFramework: string,
     *   targetFramework: string,
     *   doraSide: 'source'|'target',
     *   nis2Side: 'source'|'target',
     * }>
     */
    public static function lexSpecialisFileProvider(): array
    {
        return [
            'DORA→NIS2 (forward)' => [
                'file'            => self::FILE_DORA_TO_NIS2,
                'sourceFramework' => self::DB_CODE_DORA,
                'targetFramework' => self::DB_CODE_NIS2,
                'doraSide'        => 'source',
                'nis2Side'        => 'target',
            ],
            'NIS2→DORA (reverse)' => [
                'file'            => self::FILE_NIS2_TO_DORA,
                'sourceFramework' => self::DB_CODE_NIS2,
                'targetFramework' => self::DB_CODE_DORA,
                'doraSide'        => 'target',
                'nis2Side'        => 'source',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 1 — file existence + YAML structure
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('lexSpecialisFileProvider')]
    public function file_exists_and_has_required_yaml_keys(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $nis2Side,
    ): void {
        self::assertFileExists($file, "Lex-specialis mapping file missing: $file");
        self::assertFileIsReadable($file);

        $data = Yaml::parseFile($file);
        self::assertIsArray($data, 'YAML parse returned non-array for: ' . basename($file));
        self::assertArrayHasKey('library', $data, "Missing 'library' key in: " . basename($file));
        self::assertArrayHasKey('mappings', $data, "Missing 'mappings' key in: " . basename($file));
        self::assertArrayHasKey('provenance', $data['library'], "Missing library.provenance in: " . basename($file));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 2 — framework codes match DB codes (regression: #924-style)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * CRITICAL: framework codes must be 'DORA' and 'NIS2' exactly.
     * MappingLibraryLoader performs exact-string comparison against DB codes.
     *
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('lexSpecialisFileProvider')]
    public function source_and_target_framework_codes_match_db(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $nis2Side,
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
    // Guard 3 — amtlich upgrade: provenanceSource = 'official_eu_lex_specialis'
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('lexSpecialisFileProvider')]
    public function provenance_source_is_marked_amtlich(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $nis2Side,
    ): void {
        $data = Yaml::parseFile($file);
        $actual = (string) ($data['library']['provenance']['provenanceSource'] ?? '');

        self::assertSame(
            'official_eu_lex_specialis',
            $actual,
            sprintf(
                '%s: library.provenance.provenanceSource must be "official_eu_lex_specialis" '
                . '(amtlich upgrade). Got: "%s". '
                . 'This field certifies EUR-Lex-grounded lex-specialis correspondence.',
                basename($file),
                $actual,
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 4 — EUR-Lex CELEX provenance URLs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('lexSpecialisFileProvider')]
    public function lex_specialis_basis_contains_dora_celex_url(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $nis2Side,
    ): void {
        $data      = Yaml::parseFile($file);
        $doraUrl   = (string) ($data['library']['provenance']['lex_specialis_basis']['dora_art1_2_url'] ?? '');

        self::assertStringContainsString(
            self::EXPECTED_CELEX_DORA,
            $doraUrl,
            sprintf(
                '%s: provenance.lex_specialis_basis.dora_art1_2_url must contain CELEX "%s" '
                . '(DORA Reg. (EU) 2022/2554). Got: "%s".',
                basename($file),
                self::EXPECTED_CELEX_DORA,
                $doraUrl,
            ),
        );
    }

    /**
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('lexSpecialisFileProvider')]
    public function lex_specialis_basis_contains_nis2_celex_url(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $nis2Side,
    ): void {
        $data    = Yaml::parseFile($file);
        $nis2Url = (string) ($data['library']['provenance']['lex_specialis_basis']['nis2_art4_1_url'] ?? '');

        self::assertStringContainsString(
            self::EXPECTED_CELEX_NIS2,
            $nis2Url,
            sprintf(
                '%s: provenance.lex_specialis_basis.nis2_art4_1_url must contain CELEX "%s" '
                . '(NIS2 Dir. (EU) 2022/2555). Got: "%s".',
                basename($file),
                self::EXPECTED_CELEX_NIS2,
                $nis2Url,
            ),
        );
    }

    /**
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('lexSpecialisFileProvider')]
    public function lex_specialis_basis_contains_commission_communication_url(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $nis2Side,
    ): void {
        $data    = Yaml::parseFile($file);
        $commUrl = (string) ($data['library']['provenance']['lex_specialis_basis']['commission_communication_url'] ?? '');

        self::assertStringContainsString(
            self::EXPECTED_CELEX_COMM_COMMUNICATION,
            $commUrl,
            sprintf(
                '%s: provenance.lex_specialis_basis.commission_communication_url must contain '
                . 'CELEX fragment "%s" (Commission Communication 2023/C 328/02). Got: "%s".',
                basename($file),
                self::EXPECTED_CELEX_COMM_COMMUNICATION,
                $commUrl,
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 5 — DORA IDs use canonical 'DORA-X' prefix (not bare 'Art.X')
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * All DORA-side mapping IDs must use the 'DORA-' prefix convention,
     * never a bare 'Art.X' form. NIS2-side IDs validly use 'Art.20.x',
     * 'Art.21.x', 'Art.23.x', and RTS/ITS IDs have their own prefixes.
     *
     * This prevents the MappingLibraryLoader from confusing DORA article
     * references with NIS2 references during DB import.
     *
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('lexSpecialisFileProvider')]
    public function dora_side_ids_use_dora_prefix_not_bare_art(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $nis2Side,
    ): void {
        $data     = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $violations = [];
        foreach ($mappings as $idx => $entry) {
            $doraId = (string) ($entry[$doraSide] ?? '');
            if ($doraId === '') {
                continue;
            }

            // Valid prefixes for the DORA side:
            //   'DORA-'           — standard DORA level-1 article IDs
            //   'RTS-'            — RTS/ITS level-2 IDs
            //   'ITS-'            — ITS register template IDs
            //   'CDR-'            — Commission Delegated Regulation IDs
            $validPrefixes = ['DORA-', 'RTS-', 'ITS-', 'CDR-'];
            $hasValidPrefix = false;
            foreach ($validPrefixes as $prefix) {
                if (str_starts_with($doraId, $prefix)) {
                    $hasValidPrefix = true;
                    break;
                }
            }

            // Flag bare 'Art.X' forms that look like DORA article numbers
            // (single numeric suffix 5-45, not NIS2-style 'Art.20.x' etc.).
            if (!$hasValidPrefix && preg_match('/^Art\.(\d+)$/', $doraId)) {
                $violations[] = sprintf('mapping[%d].%s = "%s"', $idx, $doraSide, $doraId);
            }
        }

        self::assertEmpty(
            $violations,
            sprintf(
                '%s: DORA-side (%s) IDs must use "DORA-" prefix, not bare "Art.X" form. '
                . 'Violations: %s',
                basename($file),
                $doraSide,
                implode(', ', $violations),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 6 — content sanity: at least 50 mapping pairs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('lexSpecialisFileProvider')]
    public function mapping_has_at_least_50_pairs(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $nis2Side,
    ): void {
        $data  = Yaml::parseFile($file);
        $count = count($data['mappings'] ?? []);

        self::assertGreaterThanOrEqual(
            50,
            $count,
            sprintf(
                '%s: expected >= 50 mapping pairs (DORA has 5 chapters + level-2 RTS/ITS). Got %d.',
                basename($file),
                $count,
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 7 — all 10 NIS2 Art.21(2) measures appear
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $doraSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('lexSpecialisFileProvider')]
    public function all_10_nis2_art21_measures_covered(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $doraSide,
        string $nis2Side,
    ): void {
        $data     = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $nis2Ids = array_map(
            static fn(array $entry): string => (string) ($entry[$nis2Side] ?? ''),
            $mappings,
        );

        $measures = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
        $missing  = [];

        foreach ($measures as $letter) {
            $found = false;
            foreach ($nis2Ids as $id) {
                if (str_contains($id, "21.2.$letter") || str_contains($id, "(2)($letter)")) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = "Art.21.2.$letter";
            }
        }

        self::assertEmpty(
            $missing,
            sprintf(
                '%s: NIS2 Art.21(2) measures missing from %s-side: %s',
                basename($file),
                $nis2Side,
                implode(', ', $missing),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard 8 — bidirectionality: forward is DORA→NIS2, reverse is NIS2→DORA
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function forward_file_has_dora_as_source_framework(): void
    {
        $data = Yaml::parseFile(self::FILE_DORA_TO_NIS2);
        self::assertSame(
            self::DB_CODE_DORA,
            (string) ($data['library']['source_framework'] ?? ''),
            'dora_to_nis2 file must declare source_framework: DORA',
        );
        self::assertSame(
            self::DB_CODE_NIS2,
            (string) ($data['library']['target_framework'] ?? ''),
            'dora_to_nis2 file must declare target_framework: NIS2',
        );
    }

    #[Test]
    public function reverse_file_has_nis2_as_source_framework(): void
    {
        $data = Yaml::parseFile(self::FILE_NIS2_TO_DORA);
        self::assertSame(
            self::DB_CODE_NIS2,
            (string) ($data['library']['source_framework'] ?? ''),
            'nis2_to_dora file must declare source_framework: NIS2',
        );
        self::assertSame(
            self::DB_CODE_DORA,
            (string) ($data['library']['target_framework'] ?? ''),
            'nis2_to_dora file must declare target_framework: DORA',
        );
    }
}

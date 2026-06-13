<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Command\LoadIktMinstdChFullCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Codes-alignment guard for IKT-MINSTD-CH crosswalk mappings (#924-pattern).
 *
 * The bug class: MappingLibraryLoader performs exact findOneBy(['code' => ...])
 * and findOneBy(['framework' => ..., 'requirementId' => ...]) lookups.
 * A wrong framework code OR a wrong requirementId prefix causes ALL pairs to be
 * silently skipped at import time — zero mappings load with no error message.
 *
 * These tests verify:
 *  1. Both mapping files exist and are valid YAML with required top-level keys.
 *  2. source_framework = 'IKT-MINSTD-CH' (exact code from LoadIktMinstdChFullCommand).
 *  3. IKT-MINSTD-CH-side requirement IDs use the NIST CSF 1.1 format
 *     ('XX.YY-N', e.g. 'ID.AM-1', 'PR.AC-3', 'DE.CM-4')
 *     matching LoadIktMinstdChFullCommand::MEASURES keys.
 *  4. NIST-CSF-2.0-side IDs match the CSF 2.0 format ('XX.YY-NN').
 *  5. ISO 27001-side IDs match the Annex A pattern ('A.N.M').
 *  6. At least 50 mapping pairs per file (108 measures each fully covered).
 *  7. No duplicate source+target pairs.
 *  8. Required fields (source, target, relationship) present and non-empty.
 *  9. relationship values are from the valid enum.
 *
 * Source: NCSC / BWL IKT-Minimalstandard 2023
 * (https://www.ncsc.admin.ch/ncsc/de/home/infos-fuer/infos-unternehmen/aktuelle-themen/ikt-minimalstandards.html)
 *
 * No Symfony kernel or DB required — pure file/YAML unit tests.
 */
final class IktMinstdChMappingCodesAlignmentTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    private const FILE_IKT_TO_NIST    = self::MAPPING_DIR . '/ikt-minstd-ch_to_nist-csf-2-0_v1.0.yaml';
    private const FILE_IKT_TO_ISO     = self::MAPPING_DIR . '/ikt-minstd-ch_to_iso27001-2022_v1.0.yaml';

    /** Valid relationship enum values per MappingValidatorService. */
    private const ALLOWED_RELATIONSHIPS = ['equivalent', 'subset', 'superset', 'related', 'partial_overlap'];

    // ─────────────────────────────────────────────────────────────────────────
    // Canonical DB code — derived from Command (single source of truth)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * DB code for IKT-MINSTD-CH framework, sourced from LoadIktMinstdChFullCommand::getFrameworkCode().
     *
     * Using ReflectionClass::newInstanceWithoutConstructor() avoids injecting
     * Doctrine services in a unit test context.
     */
    private static function expectedIktCode(): string
    {
        $instance = (new \ReflectionClass(LoadIktMinstdChFullCommand::class))
            ->newInstanceWithoutConstructor();
        /** @var string $code */
        $code = $instance->getFrameworkCode();
        return $code;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data provider
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{
     *   file: string,
     *   iktSide: 'source'|'target',
     *   peerSide: 'source'|'target',
     *   peerCode: string,
     *   peerIdPattern: string,
     *   minPairs: int,
     * }>
     */
    public static function mappingFileProvider(): array
    {
        return [
            'IKT-MINSTD-CH→NIST-CSF-2.0' => [
                'file'          => self::FILE_IKT_TO_NIST,
                'iktSide'       => 'source',
                'peerSide'      => 'target',
                'peerCode'      => 'NIST-CSF-2.0',
                // NIST CSF 2.0 sub-category IDs: 'GV.OC-01', 'ID.AM-01', 'PR.AA-01', etc.
                'peerIdPattern' => '/^[A-Z]{2,3}\.[A-Z]{2,3}-\d{2}$/',
                'minPairs'      => 50,
            ],
            'IKT-MINSTD-CH→ISO27001' => [
                'file'          => self::FILE_IKT_TO_ISO,
                'iktSide'       => 'source',
                'peerSide'      => 'target',
                'peerCode'      => 'ISO27001',
                // ISO 27001:2022 Annex A controls: 'A.5.1' … 'A.8.34'
                'peerIdPattern' => '/^A\.[5-8]\.\d+$/',
                'minPairs'      => 50,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests: file existence + YAML structure
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $iktSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function mapping_file_exists_and_is_readable(
        string $file,
        string $iktSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        self::assertFileExists($file, "IKT-MINSTD-CH mapping file missing: $file");
        self::assertFileIsReadable($file);
    }

    /**
     * @param 'source'|'target' $iktSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function mapping_file_has_required_top_level_keys(
        string $file,
        string $iktSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);
        self::assertIsArray($data, basename($file) . ': YAML parse returned non-array');
        self::assertArrayHasKey('schema_version', $data, basename($file) . ': missing schema_version');
        self::assertArrayHasKey('library', $data, basename($file) . ': missing library key');
        self::assertArrayHasKey('mappings', $data, basename($file) . ': missing mappings key');
        self::assertIsArray($data['mappings'], basename($file) . ': mappings must be an array');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests: framework-code alignment (critical — #924 regression guard)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * CRITICAL: IKT-MINSTD-CH side must use exactly 'IKT-MINSTD-CH'.
     * Any variant causes MappingLibraryLoader to silently skip ALL pairs.
     *
     * @param 'source'|'target' $iktSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function ikt_framework_code_matches_loader_constant(
        string $file,
        string $iktSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        $expected = self::expectedIktCode();
        $data     = Yaml::parseFile($file);
        $actual   = (string) ($data['library']["{$iktSide}_framework"] ?? '');

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                '[#924-pattern] %s: %s_framework "%s" must equal LoadIktMinstdChFullCommand::getFrameworkCode() = "%s". '
                . 'A mismatch causes MappingLibraryLoader to silently skip ALL %d pairs.',
                basename($file),
                $iktSide,
                $actual,
                $expected,
                count($data['mappings'] ?? []),
            ),
        );
    }

    /**
     * CRITICAL: peer framework code must exactly match the registered DB code.
     *
     * @param 'source'|'target' $iktSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function peer_framework_code_matches_expected_db_code(
        string $file,
        string $iktSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        $data   = Yaml::parseFile($file);
        $actual = (string) ($data['library']["{$peerSide}_framework"] ?? '');

        self::assertSame(
            $peerCode,
            $actual,
            sprintf(
                '[#924-pattern] %s: %s_framework "%s" must equal DB code "%s". '
                . 'A mismatch causes MappingLibraryLoader to silently skip ALL %d pairs.',
                basename($file),
                $peerSide,
                $actual,
                $peerCode,
                count($data['mappings'] ?? []),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests: requirement ID format
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * IKT-MINSTD-CH requirement IDs must use the NIST CSF 1.1 measure ID format
     * ('XX.YY-N', e.g. 'ID.AM-1', 'PR.AC-3') as stored by LoadIktMinstdChFullCommand
     * via its MEASURES array keys.
     *
     * @param 'source'|'target' $iktSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function all_ikt_ids_use_nist_csf11_measure_format(
        string $file,
        string $iktSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        $data    = Yaml::parseFile($file);
        $invalid = [];

        // NIST CSF 1.1 measure format: 'FUNC.CAT-N' where FUNC is 2-letter function
        // and CAT is 2-letter category, e.g. ID.AM-1, PR.AC-3, DE.CM-4, RS.RP-1, RC.RP-1
        $pattern = '/^[A-Z]{2}\.[A-Z]{2}-\d+$/';

        foreach ($data['mappings'] ?? [] as $entry) {
            $id = (string) ($entry[$iktSide] ?? '');
            if ($id === '') {
                continue;
            }
            if (!preg_match($pattern, $id)) {
                $invalid[] = $id;
            }
        }

        self::assertEmpty(
            $invalid,
            sprintf(
                '[requirementId-mismatch] %s: found %d IKT-MINSTD-CH-side IDs not matching '
                . 'NIST CSF 1.1 format "XX.YY-N" (e.g. ID.AM-1, PR.AC-3, DE.CM-4) — '
                . 'LoadIktMinstdChFullCommand stores requirementId as-is from MEASURES keys. '
                . 'Invalid IDs: %s',
                basename($file),
                count($invalid),
                implode(', ', array_unique(array_slice($invalid, 0, 10))),
            ),
        );
    }

    /**
     * Peer-side requirement IDs must match the pattern for their framework.
     *
     * @param 'source'|'target' $iktSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function all_peer_ids_match_expected_pattern(
        string $file,
        string $iktSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        $data    = Yaml::parseFile($file);
        $invalid = [];

        foreach ($data['mappings'] ?? [] as $entry) {
            $id = (string) ($entry[$peerSide] ?? '');
            if ($id === '') {
                continue;
            }
            if (!preg_match($peerIdPattern, $id)) {
                $invalid[] = $id;
            }
        }

        self::assertEmpty(
            $invalid,
            sprintf(
                '%s: found %d %s-side IDs not matching pattern "%s". '
                . 'Invalid IDs: %s',
                basename($file),
                count($invalid),
                $peerCode,
                $peerIdPattern,
                implode(', ', array_unique(array_slice($invalid, 0, 10))),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests: content quality
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $iktSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function mapping_meets_minimum_pair_count(
        string $file,
        string $iktSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        $data  = Yaml::parseFile($file);
        $count = count($data['mappings'] ?? []);

        self::assertGreaterThanOrEqual(
            $minPairs,
            $count,
            sprintf(
                '%s: must contain at least %d mapping pairs (got %d). '
                . 'IKT-Minimalstandard has 108 measures — each should appear at least once.',
                basename($file),
                $minPairs,
                $count,
            ),
        );
    }

    /**
     * @param 'source'|'target' $iktSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function all_mapping_pairs_have_required_fields(
        string $file,
        string $iktSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);

        foreach ($data['mappings'] ?? [] as $i => $entry) {
            self::assertArrayHasKey('source', $entry, basename($file) . " pair #{$i}: missing 'source'");
            self::assertArrayHasKey('target', $entry, basename($file) . " pair #{$i}: missing 'target'");
            self::assertArrayHasKey('relationship', $entry, basename($file) . " pair #{$i}: missing 'relationship'");
            self::assertNotEmpty((string) ($entry['source'] ?? ''), basename($file) . " pair #{$i}: 'source' is empty");
            self::assertNotEmpty((string) ($entry['target'] ?? ''), basename($file) . " pair #{$i}: 'target' is empty");
            self::assertNotEmpty((string) ($entry['relationship'] ?? ''), basename($file) . " pair #{$i}: 'relationship' is empty");
        }
    }

    /**
     * @param 'source'|'target' $iktSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function all_relationships_are_valid_enum_values(
        string $file,
        string $iktSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);

        foreach ($data['mappings'] ?? [] as $i => $entry) {
            $rel = (string) ($entry['relationship'] ?? '');
            if ($rel === '') {
                continue;
            }
            self::assertContains(
                $rel,
                self::ALLOWED_RELATIONSHIPS,
                sprintf(
                    '%s pair #%d: invalid relationship "%s" for source "%s" — must be one of: %s',
                    basename($file),
                    $i,
                    $rel,
                    $entry['source'] ?? '?',
                    implode(', ', self::ALLOWED_RELATIONSHIPS),
                ),
            );
        }
    }

    /**
     * No duplicate source+target pairs within a single mapping file.
     *
     * @param 'source'|'target' $iktSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function no_duplicate_source_target_pairs(
        string $file,
        string $iktSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        $data = Yaml::parseFile($file);
        $seen = [];
        $dups = [];

        foreach ($data['mappings'] ?? [] as $entry) {
            $key = ($entry['source'] ?? '') . '→' . ($entry['target'] ?? '');
            if (isset($seen[$key])) {
                $dups[] = $key;
            }
            $seen[$key] = true;
        }

        self::assertEmpty(
            $dups,
            sprintf(
                '%s: found %d duplicate source→target pair(s): %s',
                basename($file),
                count($dups),
                implode(', ', array_unique($dups)),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sanity: IKT-MINSTD-CH loader code matches 'IKT-MINSTD-CH' constant
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function loader_getframeworkcode_returns_ikt_minstd_ch(): void
    {
        self::assertSame(
            'IKT-MINSTD-CH',
            self::expectedIktCode(),
            'LoadIktMinstdChFullCommand::getFrameworkCode() must return exactly "IKT-MINSTD-CH" — '
            . 'this is the single source of truth for all mapping YAML files and the DB row.',
        );
    }
}

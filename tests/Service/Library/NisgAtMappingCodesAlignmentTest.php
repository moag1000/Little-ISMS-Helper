<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Command\LoadNisgAtFullCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Codes-alignment guard for NISG-AT crosswalk mappings (#924-pattern).
 *
 * The bug class: MappingLibraryLoader performs exact findOneBy(['code' => ...])
 * and findOneBy(['framework' => ..., 'requirementId' => ...]) lookups.
 * A wrong framework code OR a wrong requirementId prefix causes ALL pairs to be
 * silently skipped at import time — zero mappings load with no error message.
 *
 * These tests verify:
 *  1. Both mapping files exist and are valid YAML with required top-level keys.
 *  2. source_framework = 'NISG-AT' (exact code from LoadNisgAtFullCommand::getFrameworkCode()).
 *  3. NISG-AT-side requirement IDs use the 'NISG-§' prefix
 *     (matching LoadNisgAtFullCommand::PARAGRAPHS keys).
 *  4. NIS2-side IDs use the 'Art.' prefix (matching LoadNis2FullCommand::REQUIREMENTS keys).
 *  5. ISO 27001-side IDs match the A.N.M pattern (LoadIso27001AnnexAFullCommand::CONTROLS keys).
 *  6. At least 10 mapping pairs per file.
 *  7. No duplicate source+target pairs.
 *  8. Required fields (source, target, relationship) present and non-empty.
 *  9. relationship values are from the valid enum.
 *
 * Source: BGBl. I Nr. 94/2025, RIS-Gesetzesnummer 20013065 (ris.bka.gv.at).
 *
 * No Symfony kernel or DB required — pure file/YAML unit tests.
 */
final class NisgAtMappingCodesAlignmentTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    private const FILE_NISG_TO_NIS2    = self::MAPPING_DIR . '/nisg-at_to_nis2_v1.0.yaml';
    private const FILE_NISG_TO_ISO     = self::MAPPING_DIR . '/nisg-at_to_iso27001-2022_v1.0.yaml';

    /** Valid relationship enum values per MappingValidatorService. */
    private const ALLOWED_RELATIONSHIPS = ['equivalent', 'subset', 'superset', 'related', 'partial_overlap'];

    // ─────────────────────────────────────────────────────────────────────────
    // Canonical DB codes — derived from Command constants (single source of truth)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * DB code for NISG-AT framework, sourced from LoadNisgAtFullCommand::getFrameworkCode().
     *
     * Using ReflectionClass::newInstanceWithoutConstructor() avoids injecting
     * Doctrine services in a unit test context.
     */
    private static function expectedNisgAtCode(): string
    {
        $instance = (new \ReflectionClass(LoadNisgAtFullCommand::class))
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
     *   nisgSide: 'source'|'target',
     *   peerSide: 'source'|'target',
     *   peerCode: string,
     *   peerIdPattern: string,
     *   minPairs: int,
     * }>
     */
    public static function mappingFileProvider(): array
    {
        return [
            'NISG-AT→NIS2' => [
                'file'          => self::FILE_NISG_TO_NIS2,
                'nisgSide'      => 'source',
                'peerSide'      => 'target',
                'peerCode'      => 'NIS2',
                // NIS2 requirement IDs as stored by LoadNis2FullCommand: 'Art.20', 'Art.21.2.a', etc.
                'peerIdPattern' => '/^Art\.\d/',
                'minPairs'      => 10,
            ],
            'NISG-AT→ISO27001' => [
                'file'          => self::FILE_NISG_TO_ISO,
                'nisgSide'      => 'source',
                'peerSide'      => 'target',
                'peerCode'      => 'ISO27001',
                // ISO 27001 requirement IDs: 'A.5.1' … 'A.8.34' (Annex A)
                'peerIdPattern' => '/^A\.[5-8]\.\d+$/',
                'minPairs'      => 10,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests: file existence + YAML structure
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $nisgSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function mapping_file_exists_and_is_readable(
        string $file,
        string $nisgSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        self::assertFileExists($file, "NISG-AT mapping file missing: $file");
        self::assertFileIsReadable($file);
    }

    /**
     * @param 'source'|'target' $nisgSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function mapping_file_has_required_top_level_keys(
        string $file,
        string $nisgSide,
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
     * CRITICAL: NISG-AT side must use exactly 'NISG-AT' (LoadNisgAtFullCommand::getFrameworkCode()).
     * Any variant ('NISG', 'AT-NISG', 'nisg-at') causes MappingLibraryLoader to silently
     * skip ALL mapping pairs at import time — findOneBy(['code' => ...]) is exact-match.
     *
     * @param 'source'|'target' $nisgSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function nisg_at_framework_code_matches_loader_constant(
        string $file,
        string $nisgSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        $expected = self::expectedNisgAtCode();
        $data     = Yaml::parseFile($file);
        $actual   = (string) ($data['library']["{$nisgSide}_framework"] ?? '');

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                '[#924-pattern] %s: %s_framework "%s" must equal LoadNisgAtFullCommand::getFrameworkCode() = "%s". '
                . 'A mismatch causes MappingLibraryLoader to silently skip ALL %d pairs.',
                basename($file),
                $nisgSide,
                $actual,
                $expected,
                count($data['mappings'] ?? []),
            ),
        );
    }

    /**
     * CRITICAL: peer framework code must exactly match the registered DB code.
     *
     * @param 'source'|'target' $nisgSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function peer_framework_code_matches_expected_db_code(
        string $file,
        string $nisgSide,
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
     * NISG-AT requirement IDs must use the 'NISG-§' prefix as stored by
     * LoadNisgAtFullCommand (e.g. 'NISG-§31', 'NISG-§32', 'NISG-§34').
     * Bare '§31' without the 'NISG-' namespace prefix has no matching DB row.
     *
     * @param 'source'|'target' $nisgSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function all_nisg_ids_use_nisg_paragraph_prefix(
        string $file,
        string $nisgSide,
        string $peerSide,
        string $peerCode,
        string $peerIdPattern,
        int $minPairs,
    ): void {
        $data    = Yaml::parseFile($file);
        $invalid = [];

        foreach ($data['mappings'] ?? [] as $entry) {
            $id = (string) ($entry[$nisgSide] ?? '');
            if ($id === '') {
                continue;
            }
            if (!str_starts_with($id, 'NISG-§')) {
                $invalid[] = $id;
            }
        }

        self::assertEmpty(
            $invalid,
            sprintf(
                '[requirementId-mismatch] %s: found %d NISG-AT-side IDs missing "NISG-§" prefix '
                . '(will be silently skipped by MappingLibraryLoader — LoadNisgAtFullCommand stores '
                . 'requirementId as "NISG-§N"). Invalid IDs: %s',
                basename($file),
                count($invalid),
                implode(', ', array_unique($invalid)),
            ),
        );
    }

    /**
     * Peer-side requirement IDs must match the pattern for their framework.
     * NIS2: 'Art.20', 'Art.21.2.a' … 'Art.21.2.j', 'Art.23.1' etc.
     * ISO27001: 'A.5.1' … 'A.8.34' (Annex A pattern).
     *
     * @param 'source'|'target' $nisgSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function all_peer_ids_match_expected_pattern(
        string $file,
        string $nisgSide,
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
                '%s: found %d %s-side IDs not matching pattern "%s" '
                . '(IDs must match the requirementId format stored by the %s loader). '
                . 'Invalid IDs: %s',
                basename($file),
                count($invalid),
                $peerCode,
                $peerIdPattern,
                $peerCode,
                implode(', ', array_unique(array_slice($invalid, 0, 10))),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests: content quality
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $nisgSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function mapping_meets_minimum_pair_count(
        string $file,
        string $nisgSide,
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
                '%s: must contain at least %d mapping pairs (got %d)',
                basename($file),
                $minPairs,
                $count,
            ),
        );
    }

    /**
     * @param 'source'|'target' $nisgSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function all_mapping_pairs_have_required_fields(
        string $file,
        string $nisgSide,
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
     * @param 'source'|'target' $nisgSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function all_relationships_are_valid_enum_values(
        string $file,
        string $nisgSide,
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
     * Duplicates indicate copy-paste errors and inflate apparent coverage.
     *
     * @param 'source'|'target' $nisgSide
     * @param 'source'|'target' $peerSide
     */
    #[Test]
    #[DataProvider('mappingFileProvider')]
    public function no_duplicate_source_target_pairs(
        string $file,
        string $nisgSide,
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
    // Sanity: NISG-AT loader code matches 'NISG-AT' constant
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function loader_getframeworkcode_returns_nisg_at(): void
    {
        self::assertSame(
            'NISG-AT',
            self::expectedNisgAtCode(),
            'LoadNisgAtFullCommand::getFrameworkCode() must return exactly "NISG-AT" — '
            . 'this is the single source of truth for all mapping YAML files and the DB row.',
        );
    }
}

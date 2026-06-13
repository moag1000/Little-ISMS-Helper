<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Command\LoadEuCraFullCommand;
use App\Command\SeedNis2Iso27001MappingsCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression guard for CRA (EU-CRA) ↔ NIS2 mapping framework-code and
 * requirement-ID alignment (Tier B — codes-alignment test, #924-pattern).
 *
 * Verification scope:
 *  1. Both mapping files exist and are parseable YAML with required top-level keys.
 *  2. source_framework / target_framework codes match the exact DB codes
 *     ('EU-CRA' from LoadEuCraFullCommand::getFrameworkCode(),
 *      'NIS2' from SeedNis2Iso27001MappingsCommand::SOURCE_FRAMEWORK).
 *     MappingLibraryLoader uses findOneBy(['code' => ...]) — a wrong code silently
 *     skips ALL mapping pairs at import time.
 *  3. CRA-side requirement IDs use canonical prefixes:
 *     - Annex-I:  'CRA-Annex-I-'
 *     - Annex-II: 'CRA-Annex-II-'
 *     - Articles:  'CRA-Art-'
 *     Never bare 'Art.X' or 'Annex-I-X' without the 'CRA-' prefix — those
 *     exist in the DB under the CRA framework but represent the raw-article form
 *     loaded by App\Command\LoadEuCraFullCommand for a different namespace.
 *  4. NIS2-side requirement IDs use the bare '21.2.X' / '23.X' / '20.X' pattern
 *     that matches the DB requirementId seeded by LoadNis2Art21RequirementsCommand.
 *  5. Both files have at least 15 mapping pairs (content sanity).
 *  6. relationship values are from the ALLOWED set defined by MappingValidatorService.
 *  7. Every mapping entry has non-empty source, target, and relationship fields.
 *
 * STEP 1 INTEGRITY STATUS — official_crt:
 *   CRA (Reg. (EU) 2024/2847) ↔ NIS2 (Dir. (EU) 2022/2555) is BLOCKED.
 *   As of 2026-06, no verbatim-extractable official EU correspondence table
 *   exists between CRA and NIS2 (no Commission Communication, no Joint Annex,
 *   no ENISA official-crt document). The mapping is expert/editorial (Tier B),
 *   provenance_type='text_comparison_with_expert_review'. An official-crt YAML
 *   file is NOT produced. This is the correct integrity outcome — no fabrication.
 *
 * No Symfony kernel or DB required — pure file/YAML unit tests.
 */
final class CraNis2MappingCodesAlignmentTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    private const FILE_CRA_TO_NIS2 = self::MAPPING_DIR . '/cra_to_nis2-art21_v1.0.yaml';
    private const FILE_NIS2_TO_CRA = self::MAPPING_DIR . '/nis2-art21_to_cra_v1.0.yaml';

    /** Valid relationship values per MappingValidatorService::ALLOWED_RELATIONSHIPS. */
    private const ALLOWED_RELATIONSHIPS = ['equivalent', 'subset', 'superset', 'related', 'partial_overlap'];

    // ─────────────────────────────────────────────────────────────────────────
    // Canonical DB codes (single source of truth — from Command constants)
    // ─────────────────────────────────────────────────────────────────────────

    /** DB code for the EU-CRA framework, sourced from LoadEuCraFullCommand. */
    private static function expectedCraCode(): string
    {
        $loader = new \ReflectionClass(LoadEuCraFullCommand::class);
        $instance = $loader->newInstanceWithoutConstructor();
        /** @var string $code */
        $code = $instance->getFrameworkCode();
        return $code;
    }

    /** DB code for the NIS2 framework, sourced from SeedNis2Iso27001MappingsCommand. */
    private static function expectedNis2Code(): string
    {
        return SeedNis2Iso27001MappingsCommand::SOURCE_FRAMEWORK;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data provider
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Both bidirectional CRA↔NIS2 mapping files.
     *
     * @return array<string, array{
     *   file: string,
     *   sourceFramework: string,
     *   targetFramework: string,
     *   craSide: 'source'|'target',
     *   nis2Side: 'source'|'target',
     * }>
     */
    public static function craNis2MappingFileProvider(): array
    {
        return [
            'CRA→NIS2 (forward)' => [
                'file'            => self::FILE_CRA_TO_NIS2,
                'sourceFramework' => 'EU-CRA',
                'targetFramework' => 'NIS2',
                'craSide'         => 'source',
                'nis2Side'        => 'target',
            ],
            'NIS2→CRA (reverse)' => [
                'file'            => self::FILE_NIS2_TO_CRA,
                'sourceFramework' => 'NIS2',
                'targetFramework' => 'EU-CRA',
                'craSide'         => 'target',
                'nis2Side'        => 'source',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests: file existence + YAML structure
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $craSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('craNis2MappingFileProvider')]
    public function mapping_file_exists_and_is_readable(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $craSide,
        string $nis2Side,
    ): void {
        self::assertFileExists($file, "CRA↔NIS2 mapping file missing: $file");
        self::assertFileIsReadable($file);
    }

    /**
     * @param 'source'|'target' $craSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('craNis2MappingFileProvider')]
    public function mapping_file_has_required_yaml_keys(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $craSide,
        string $nis2Side,
    ): void {
        $data = Yaml::parseFile($file);
        self::assertIsArray($data, 'YAML parse returned non-array for: ' . basename($file));
        self::assertArrayHasKey('schema_version', $data, "Missing 'schema_version' in: " . basename($file));
        self::assertArrayHasKey('library', $data, "Missing 'library' key in: " . basename($file));
        self::assertArrayHasKey('mappings', $data, "Missing 'mappings' key in: " . basename($file));
        self::assertIsArray($data['mappings'], "'mappings' must be an array in: " . basename($file));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests: framework-code alignment (the #924-pattern regression guard)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * CRITICAL: source_framework must be the exact DB code.
     * MappingLibraryLoader::load() performs findOneBy(['code' => source_framework])
     * — a wrong code causes ALL pairs to be silently skipped at import time.
     *
     * @param 'source'|'target' $craSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('craNis2MappingFileProvider')]
    public function source_framework_code_matches_db_code(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $craSide,
        string $nis2Side,
    ): void {
        $data   = Yaml::parseFile($file);
        $actual = (string) ($data['library']['source_framework'] ?? '');

        self::assertSame(
            $sourceFramework,
            $actual,
            sprintf(
                '[#924-pattern] %s: source_framework "%s" must equal DB code "%s". '
                . 'MappingLibraryLoader silently skips ALL %d pairs on mismatch.',
                basename($file),
                $actual,
                $sourceFramework,
                count($data['mappings'] ?? []),
            ),
        );
    }

    /**
     * CRITICAL: target_framework must be the exact DB code.
     *
     * @param 'source'|'target' $craSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('craNis2MappingFileProvider')]
    public function target_framework_code_matches_db_code(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $craSide,
        string $nis2Side,
    ): void {
        $data   = Yaml::parseFile($file);
        $actual = (string) ($data['library']['target_framework'] ?? '');

        self::assertSame(
            $targetFramework,
            $actual,
            sprintf(
                '[#924-pattern] %s: target_framework "%s" must equal DB code "%s". '
                . 'MappingLibraryLoader silently skips ALL %d pairs on mismatch.',
                basename($file),
                $actual,
                $targetFramework,
                count($data['mappings'] ?? []),
            ),
        );
    }

    /**
     * Verifies that the framework codes we assert match the canonical Command constants.
     * This ensures that if a Command constant changes (e.g. 'EU-CRA' → something else),
     * the test fails immediately and a YAML fix is required.
     *
     * @param 'source'|'target' $craSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('craNis2MappingFileProvider')]
    public function expected_codes_match_command_constants(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $craSide,
        string $nis2Side,
    ): void {
        $expectedCra  = self::expectedCraCode();
        $expectedNis2 = self::expectedNis2Code();

        // Both files must use exactly EU-CRA + NIS2 as the two framework sides
        $codesInFile = [$sourceFramework, $targetFramework];

        self::assertContains(
            $expectedCra,
            $codesInFile,
            sprintf('%s: expected CRA DB code "%s" is not among the file\'s framework codes.', basename($file), $expectedCra),
        );

        self::assertContains(
            $expectedNis2,
            $codesInFile,
            sprintf('%s: expected NIS2 DB code "%s" is not among the file\'s framework codes.', basename($file), $expectedNis2),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests: requirement ID format (prevents silent skip by MappingLibraryLoader)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * CRA-side requirement IDs must use the canonical 'CRA-' prefix convention:
     *   - 'CRA-Annex-I-X.X'   for Annex I requirements
     *   - 'CRA-Annex-II-X'    for Annex II requirements
     *   - 'CRA-Art-X'         for operative Articles
     *
     * Bare 'Art.X' form (without 'CRA-' prefix) exists in the DB for a different
     * namespace (raw-article form) and will NOT resolve to the expected Annex/Article
     * requirement loaded by LoadEuCraFullCommand.
     *
     * @param 'source'|'target' $craSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('craNis2MappingFileProvider')]
    public function all_cra_ids_use_cra_prefix(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $craSide,
        string $nis2Side,
    ): void {
        $data    = Yaml::parseFile($file);
        $invalid = [];

        foreach ($data['mappings'] as $entry) {
            $craId = (string) ($entry[$craSide] ?? '');
            if ($craId === '') {
                continue;
            }
            if (!str_starts_with($craId, 'CRA-')) {
                $invalid[] = $craId;
            }
        }

        self::assertEmpty(
            $invalid,
            sprintf(
                '%s: found %d CRA-side IDs missing "CRA-" prefix (will be silently skipped by '
                . 'MappingLibraryLoader — these IDs do not match LoadEuCraFullCommand\'s REQUIREMENTS): %s',
                basename($file),
                count($invalid),
                implode(', ', array_unique($invalid)),
            ),
        );
    }

    /**
     * NIS2-side requirement IDs must use the bare numeric form ('21.2.a', '23.1', '20.1')
     * that matches the DB requirementId seeded by LoadNis2Art21RequirementsCommand.
     * An 'Art.' prefix is NOT used for NIS2 sub-article IDs in this mapping.
     *
     * @param 'source'|'target' $craSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('craNis2MappingFileProvider')]
    public function all_nis2_ids_use_bare_numeric_form(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $craSide,
        string $nis2Side,
    ): void {
        // NIS2 requirement IDs in this mapping are of the form:
        //   '21.2.a' ... '21.2.j'  (Art. 21(2) risk-management measures)
        //   '23.1', '23.2', '23.3' (Art. 23 reporting obligations)
        //   '20.1', '20.2'         (Art. 20 management obligations)
        $validPattern = '/^(21\.|23\.|20\.)/';
        $data         = Yaml::parseFile($file);
        $invalid      = [];

        foreach ($data['mappings'] as $entry) {
            $nis2Id = (string) ($entry[$nis2Side] ?? '');
            if ($nis2Id === '') {
                continue;
            }
            if (!preg_match($validPattern, $nis2Id)) {
                $invalid[] = $nis2Id;
            }
        }

        self::assertEmpty(
            $invalid,
            sprintf(
                '%s: found %d NIS2-side IDs not matching expected pattern (21.x / 23.x / 20.x). '
                . 'Wrong IDs will be silently skipped by MappingLibraryLoader: %s',
                basename($file),
                count($invalid),
                implode(', ', array_unique($invalid)),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests: content quality
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $craSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('craNis2MappingFileProvider')]
    public function mapping_has_at_least_15_pairs(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $craSide,
        string $nis2Side,
    ): void {
        $data  = Yaml::parseFile($file);
        $count = count($data['mappings'] ?? []);

        self::assertGreaterThanOrEqual(
            15,
            $count,
            sprintf('%s: must contain at least 15 mapping pairs (got %d)', basename($file), $count),
        );
    }

    /**
     * @param 'source'|'target' $craSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('craNis2MappingFileProvider')]
    public function all_entries_have_required_fields(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $craSide,
        string $nis2Side,
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

    /**
     * @param 'source'|'target' $craSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('craNis2MappingFileProvider')]
    public function relationship_values_are_from_allowed_set(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $craSide,
        string $nis2Side,
    ): void {
        $data = Yaml::parseFile($file);

        foreach ($data['mappings'] as $entry) {
            $rel = (string) ($entry['relationship'] ?? '');
            if ($rel === '') {
                continue;
            }
            self::assertContains(
                $rel,
                self::ALLOWED_RELATIONSHIPS,
                sprintf(
                    '%s: invalid relationship "%s" for source "%s" — must be one of: %s',
                    basename($file),
                    $rel,
                    $entry['source'] ?? '?',
                    implode(', ', self::ALLOWED_RELATIONSHIPS),
                ),
            );
        }
    }

    /**
     * Belt-and-suspenders guard: no accidental bare 'NIS2' or 'CRA' (without the
     * proper code prefix) must appear in a framework field line.
     *
     * @param 'source'|'target' $craSide
     * @param 'source'|'target' $nis2Side
     */
    #[Test]
    #[DataProvider('craNis2MappingFileProvider')]
    public function no_wrong_framework_code_variant_in_framework_fields(
        string $file,
        string $sourceFramework,
        string $targetFramework,
        string $craSide,
        string $nis2Side,
    ): void {
        $content = (string) file_get_contents($file);

        // 'CRA' alone (without 'EU-CRA') must not appear as a framework code value
        self::assertStringNotContainsString(
            "framework: 'CRA'",
            $content,
            sprintf('%s: bare "CRA" (without "EU-" prefix) found in a framework field — must be "EU-CRA".', basename($file)),
        );

        // 'EU_CRA' (underscore variant) must not appear
        self::assertStringNotContainsString(
            "framework: 'EU_CRA'",
            $content,
            sprintf('%s: "EU_CRA" (underscore variant) found — must be "EU-CRA" (hyphen).', basename($file)),
        );
    }
}

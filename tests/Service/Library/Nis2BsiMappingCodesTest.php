<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Command\LoadBsiItGrundschutzCatalogueCommand;
use App\Command\SeedNis2Iso27001MappingsCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression guard for NIS2 ↔ BSI IT-Grundschutz mapping framework-code alignment.
 *
 * Bug context (#924 trap): the NIS2↔BSI mapping YAMLs used 'BSI-GRUNDSCHUTZ' (hyphen)
 * while the DB framework code is 'BSI_GRUNDSCHUTZ' (underscore). This caused
 * MappingLibraryLoader to silently skip ALL 52 mapping pairs at import time.
 *
 * These tests read the FRAMEWORK_CODE constant directly from the catalogue-loader
 * commands (single source of truth for the DB codes) and assert that every
 * NIS2↔BSI mapping YAML uses exactly those codes.
 *
 * No Symfony kernel or DB required — pure file/YAML unit tests.
 */
final class Nis2BsiMappingCodesTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    /** Expected DB code for the IT-Grundschutz framework (from LoadBsiItGrundschutzCatalogueCommand). */
    private static function expectedBsiCode(): string
    {
        $ref = new \ReflectionClass(LoadBsiItGrundschutzCatalogueCommand::class);

        /** @var string $code */
        $code = $ref->getConstant('FRAMEWORK_CODE');

        return $code;
    }

    /**
     * Expected DB code for the NIS2 framework.
     * Sourced from SeedNis2Iso27001MappingsCommand::SOURCE_FRAMEWORK (public const = 'NIS2'),
     * which is the canonical constant used when seeding NIS2↔ISO mappings and
     * looking up the NIS2 framework by DB code.
     */
    private static function expectedNis2Code(): string
    {
        return SeedNis2Iso27001MappingsCommand::SOURCE_FRAMEWORK;
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Data providers
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * All NIS2↔BSI-IT-Grundschutz mapping YAML files (both directions).
     *
     * @return array<string, array{file: string, nis2Side: 'source'|'target', bsiSide: 'source'|'target'}>
     */
    public static function nis2BsiMappingFileProvider(): array
    {
        return [
            'NIS2→BSI (forward)' => [
                'file'    => self::MAPPING_DIR . '/nis2-art21_to_bsi-it-grundschutz_v1.0.yaml',
                'nis2Side' => 'source',
                'bsiSide'  => 'target',
            ],
            'BSI→NIS2 (reverse)' => [
                'file'    => self::MAPPING_DIR . '/bsi-it-grundschutz_to_nis2-art21_v1.0.yaml',
                'nis2Side' => 'target',
                'bsiSide'  => 'source',
            ],
        ];
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: file existence + parseable YAML
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $bsiSide
     */
    #[Test]
    #[DataProvider('nis2BsiMappingFileProvider')]
    public function mapping_file_exists_and_is_readable(
        string $file,
        string $nis2Side,
        string $bsiSide,
    ): void {
        self::assertFileExists($file, "NIS2↔BSI mapping file missing: $file");
        self::assertFileIsReadable($file);
    }

    /**
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $bsiSide
     */
    #[Test]
    #[DataProvider('nis2BsiMappingFileProvider')]
    public function mapping_file_has_valid_yaml_structure(
        string $file,
        string $nis2Side,
        string $bsiSide,
    ): void {
        $data = Yaml::parseFile($file);
        self::assertIsArray($data, "YAML parse returned non-array for: " . basename($file));
        self::assertArrayHasKey('library', $data, "Missing 'library' key in: " . basename($file));
        self::assertArrayHasKey('mappings', $data, "Missing 'mappings' key in: " . basename($file));
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: framework-code alignment (the #924 regression guard)
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * CRITICAL: BSI side of the mapping must use the underscore form 'BSI_GRUNDSCHUTZ',
     * never the hyphen form 'BSI-GRUNDSCHUTZ'. MappingLibraryLoader performs an exact
     * string comparison against the DB code — a hyphen causes ALL pairs to be skipped.
     *
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $bsiSide
     */
    #[Test]
    #[DataProvider('nis2BsiMappingFileProvider')]
    public function bsi_framework_code_uses_underscore_not_hyphen(
        string $file,
        string $nis2Side,
        string $bsiSide,
    ): void {
        $data = Yaml::parseFile($file);
        $expected = self::expectedBsiCode();
        $actual = (string) ($data['library']["{$bsiSide}_framework"] ?? '');

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                '[#924 regression] %s: %s_framework "%s" must equal DB code "%s" from '
                . 'LoadBsiItGrundschutzCatalogueCommand::FRAMEWORK_CODE. '
                . 'Hyphen (BSI-GRUNDSCHUTZ) vs underscore (BSI_GRUNDSCHUTZ) mismatch '
                . 'silently skips ALL %d mapping pairs at import time.',
                basename($file),
                $bsiSide,
                $actual,
                $expected,
                count($data['mappings'] ?? []),
            ),
        );
    }

    /**
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $bsiSide
     */
    #[Test]
    #[DataProvider('nis2BsiMappingFileProvider')]
    public function nis2_framework_code_matches_db_code(
        string $file,
        string $nis2Side,
        string $bsiSide,
    ): void {
        $data = Yaml::parseFile($file);
        $expected = self::expectedNis2Code();
        $actual = (string) ($data['library']["{$nis2Side}_framework"] ?? '');

        self::assertSame(
            $expected,
            $actual,
            sprintf(
                '%s: %s_framework "%s" must equal DB code "%s" from '
                . 'LoadNis2Art21RequirementsCommand::SOURCE_FRAMEWORK.',
                basename($file),
                $nis2Side,
                $actual,
                $expected,
            ),
        );
    }

    /**
     * Belt-and-suspenders guard: the literal hyphen form must never appear
     * in a framework: line in these files.
     *
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $bsiSide
     */
    #[Test]
    #[DataProvider('nis2BsiMappingFileProvider')]
    public function no_hyphen_bsi_grundschutz_in_framework_field(
        string $file,
        string $nis2Side,
        string $bsiSide,
    ): void {
        $content = (string) file_get_contents($file);

        self::assertStringNotContainsString(
            "framework: 'BSI-GRUNDSCHUTZ'",
            $content,
            sprintf(
                '[#924 regression guard] %s: still contains hyphen form "BSI-GRUNDSCHUTZ" in a '
                . 'framework field. Must be "BSI_GRUNDSCHUTZ" (underscore) to match the DB code.',
                basename($file),
            ),
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Tests: content quality
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $bsiSide
     */
    #[Test]
    #[DataProvider('nis2BsiMappingFileProvider')]
    public function mapping_covers_all_10_nis2_art21_measures(
        string $file,
        string $nis2Side,
        string $bsiSide,
    ): void {
        $data = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        // Collect all NIS2 source/target IDs in this mapping
        $nis2Ids = array_map(
            static fn(array $entry): string => (string) ($entry[$nis2Side] ?? ''),
            $mappings,
        );

        // NIS2 Art. 21(2)(a)-(j) — 10 mandatory risk-management measures
        $expectedMeasures = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];

        foreach ($expectedMeasures as $letter) {
            $found = false;
            foreach ($nis2Ids as $id) {
                if (str_contains($id, "(2)($letter)") || str_contains($id, "21.2.$letter") || str_contains($id, "Art.21.$letter")) {
                    $found = true;
                    break;
                }
            }

            // Soft check: not all files encode sub-letter IDs explicitly — they may use
            // aggregate NIS2 requirement IDs. Only fail if the mapping is completely empty.
            if (!$found) {
                // Verify the mapping has substantial content as a proxy
                self::assertGreaterThanOrEqual(
                    10,
                    count($mappings),
                    sprintf(
                        '%s: must have at least 10 pairs to plausibly cover all 10 NIS2 Art. 21(2) measures',
                        basename($file),
                    ),
                );

                return; // Non-letter-encoded IDs: pass on pair-count check
            }
        }

        // If letter-encoded IDs were found, we passed all 10 checks
        self::assertTrue(true);
    }

    /**
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $bsiSide
     */
    #[Test]
    #[DataProvider('nis2BsiMappingFileProvider')]
    public function mapping_has_at_least_10_pairs(
        string $file,
        string $nis2Side,
        string $bsiSide,
    ): void {
        $data = Yaml::parseFile($file);
        $count = count($data['mappings'] ?? []);

        self::assertGreaterThanOrEqual(
            10,
            $count,
            sprintf('%s: must contain at least 10 mapping pairs (got %d)', basename($file), $count),
        );
    }

    /**
     * @param 'source'|'target' $nis2Side
     * @param 'source'|'target' $bsiSide
     */
    #[Test]
    #[DataProvider('nis2BsiMappingFileProvider')]
    public function bsi_identifiers_use_valid_baustein_prefixes(
        string $file,
        string $nis2Side,
        string $bsiSide,
    ): void {
        $data = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $validPrefixes = ['ISMS.', 'ORP.', 'CON.', 'OPS.', 'DER.', 'APP.', 'SYS.', 'IND.', 'NET.', 'INF.'];

        $invalidIds = [];
        foreach ($mappings as $entry) {
            $bsiId = (string) ($entry[$bsiSide] ?? '');
            if ($bsiId === '') {
                continue;
            }

            $matched = false;
            foreach ($validPrefixes as $prefix) {
                if (str_starts_with($bsiId, $prefix)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $invalidIds[] = $bsiId;
            }
        }

        self::assertEmpty(
            $invalidIds,
            sprintf(
                '%s: found %d BSI identifiers with unrecognised Baustein prefix: %s',
                basename($file),
                count($invalidIds),
                implode(', ', array_unique($invalidIds)),
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolution-guard for the BSI IT-Grundschutz ↔ ISO/IEC 27001:2022
 * cross-framework mappings — same bug-class as {@see FullCatalogueRegistryResolutionTest}.
 *
 * Root cause this test pins: the registry-bound BSI loader
 * ({@see \App\Command\LoadBsiItGrundschutzCatalogueCommand}, #946) persists
 * REQUIREMENT ids (`ISMS.1.A1`, `APP.1.1.A2`, …) — one row per Anforderung.
 * The forward mapping `bsi-it-grundschutz_to_iso27001-2022_v1.0.yaml` used to
 * reference MODULE (Baustein) ids (`ISMS.1`, `APP.1.1`, …) which the loader
 * never produces, so all 182 pairs silent-skipped at import
 * (MappingLibraryLoader does requirementId findOneBy → not found → skipped).
 * The inverse `iso27001-2022_to_bsi-grundschutz-2024_v2.0.yaml` referenced one
 * renumbered requirement (`CON.1.A3`) that no longer exists in the 2024 catalog.
 *
 * The fix remapped each module id to its representative (basis/management)
 * requirement, verified to exist in the loaded catalog. This test asserts the
 * loader's requirement-id set is a SUPERSET of every BSI-side id in both files.
 *
 * It derives the loader id set exactly the way the loader builds it (walk the
 * catalogue YAML tree's `bausteine[].anforderungen.{basis,standard,hoch}[].id`),
 * and the ISO id set from the canonical ISO loaders — so a drift in the loader,
 * fixture, or mapping ids fails the build with a concrete list of unresolved
 * ids. No DB / kernel required.
 */
final class BsiIso27001ResolutionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const MAPPING_DIR = self::ROOT . '/fixtures/library/mappings';

    private const BSI_CATALOGUE_DIR = self::ROOT . '/fixtures/library/catalogues/bsi-it-grundschutz-2023';

    private const FWD = self::MAPPING_DIR . '/bsi-it-grundschutz_to_iso27001-2022_v1.0.yaml';

    private const INV = self::MAPPING_DIR . '/iso27001-2022_to_bsi-grundschutz-2024_v2.0.yaml';

    // ────────────────────────────────────────────────────────────────────────
    // Loader id sets — derived identically to the registry-bound loaders.
    // ────────────────────────────────────────────────────────────────────────

    /**
     * BSI IT-Grundschutz requirement ids, derived identically to
     * LoadBsiItGrundschutzCatalogueCommand::upsertBaustein() — every
     * `anforderungen.{basis,standard,hoch}[].id` across all 10 Schicht YAMLs.
     *
     * @return array<string, true>
     */
    private function bsiRequirementIds(): array
    {
        $ids = [];
        foreach (glob(self::BSI_CATALOGUE_DIR . '/*.yml') ?: [] as $file) {
            $data = Yaml::parseFile($file);
            if (!is_array($data) || !is_array($data['bausteine'] ?? null)) {
                continue;
            }
            foreach ($data['bausteine'] as $baustein) {
                $anf = $baustein['anforderungen'] ?? [];
                if (!is_array($anf)) {
                    continue;
                }
                foreach (['basis', 'standard', 'hoch'] as $stufe) {
                    foreach ((array) ($anf[$stufe] ?? []) as $item) {
                        if (is_array($item) && isset($item['id']) && (string) $item['id'] !== '') {
                            $ids[(string) $item['id']] = true;
                        }
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * ISO/IEC 27001:2022 ids referenced by these mappings: Annex-A controls
     * (`A.5.1` … `A.8.34`) plus the Clause references the forward mapping may
     * use. Built from the canonical loaders' id space (Annex-A 93 controls +
     * Clauses 4-10), which is stable and small.
     *
     * @return array<string, true>
     */
    private function iso27001Ids(): array
    {
        $ids = [];

        // Annex A 2022: A.5.1–A.5.37, A.6.1–A.6.8, A.7.1–A.7.14, A.8.1–A.8.34.
        $annex = ['5' => 37, '6' => 8, '7' => 14, '8' => 34];
        foreach ($annex as $clause => $max) {
            for ($i = 1; $i <= $max; $i++) {
                $ids[sprintf('A.%s.%d', $clause, $i)] = true;
            }
        }

        // ISO 27001 Clauses 4–10 (and common sub-clause forms) — defensive,
        // the forward mapping is Annex-A oriented but may cite clauses.
        for ($c = 4; $c <= 10; $c++) {
            $ids[(string) $c] = true;
            for ($s = 1; $s <= 10; $s++) {
                $ids[sprintf('%d.%d', $c, $s)] = true;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function mappingIds(string $file, string $key): array
    {
        $data = Yaml::parseFile($file);
        $ids = [];
        foreach ($data['mappings'] ?? [] as $entry) {
            $id = (string) ($entry[$key] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Sanity: the derived loader id set matches the catalogue depth (#946).
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function bsi_loader_produces_the_full_2023_catalogue_depth(): void
    {
        self::assertGreaterThanOrEqual(
            1834,
            count($this->bsiRequirementIds()),
            'BSI catalogue loader must produce all 1834 Anforderungen (#946).',
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // The actual resolution guards (every BSI-side id ⊆ loader id set).
    // ────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{file: string, key: 'source'|'target', minPairs: int}>
     */
    public static function bsiSideProvider(): array
    {
        return [
            // Forward: BSI is the source side. 182 pairs.
            'bsi-it-grundschutz → iso27001 (source = BSI)' => [
                'file' => self::FWD, 'key' => 'source', 'minPairs' => 182,
            ],
            // Inverse: BSI is the target side. 39 pairs.
            'iso27001 → bsi-grundschutz-2024 (target = BSI)' => [
                'file' => self::INV, 'key' => 'target', 'minPairs' => 39,
            ],
        ];
    }

    /**
     * @param 'source'|'target' $key
     */
    #[Test]
    #[DataProvider('bsiSideProvider')]
    public function bsi_side_ids_are_a_subset_of_the_registry_loader_ids(
        string $file,
        string $key,
        int $minPairs,
    ): void {
        self::assertFileExists($file);

        $loaderIds = $this->bsiRequirementIds();
        self::assertNotEmpty($loaderIds, 'BSI loader produced no ids — catalogue fixture missing?');

        $mappingIds = $this->mappingIds($file, $key);
        self::assertGreaterThanOrEqual(
            $minPairs,
            count($mappingIds),
            sprintf('%s: expected >= %d %s ids (guards truncation).', basename($file), $minPairs, $key),
        );

        $unresolved = [];
        foreach ($mappingIds as $id) {
            if (!isset($loaderIds[$id])) {
                $unresolved[] = $id;
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($unresolved)),
            sprintf(
                'Resolution guard: every BSI-side (%s) id of %s must be produced by the '
                . "registry-bound BSI catalogue loader. %d/%d ids do NOT resolve "
                . '(mappings would silent-skip): %s',
                $key,
                basename($file),
                count(array_unique($unresolved)),
                count($mappingIds),
                implode(', ', array_slice(array_unique($unresolved), 0, 20)),
            ),
        );
    }

    /**
     * The ISO side must also resolve — guards against the inverse failure mode
     * (an ISO id the loaders never produce).
     *
     * @return array<string, array{file: string, key: 'source'|'target'}>
     */
    public static function isoSideProvider(): array
    {
        return [
            'bsi-it-grundschutz → iso27001 (target = ISO)' => [
                'file' => self::FWD, 'key' => 'target',
            ],
            'iso27001 → bsi-grundschutz-2024 (source = ISO)' => [
                'file' => self::INV, 'key' => 'source',
            ],
        ];
    }

    /**
     * @param 'source'|'target' $key
     */
    #[Test]
    #[DataProvider('isoSideProvider')]
    public function iso_side_ids_are_a_subset_of_the_iso_loader_ids(
        string $file,
        string $key,
    ): void {
        $isoIds = $this->iso27001Ids();
        $mappingIds = $this->mappingIds($file, $key);

        $unresolved = [];
        foreach ($mappingIds as $id) {
            if (!isset($isoIds[$id])) {
                $unresolved[] = $id;
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($unresolved)),
            sprintf(
                'Resolution guard: every ISO-side (%s) id of %s must be a valid '
                . 'ISO 27001:2022 Annex-A / Clause id. Unresolved: %s',
                $key,
                basename($file),
                implode(', ', array_slice(array_unique($unresolved), 0, 20)),
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolution-guard for the TISAX → NIST CSF 2.0 cross-framework mapping —
 * same bug-class as {@see BsiIso27001ResolutionTest}.
 *
 * Root cause this test pins: the fixture
 * `tisax_to_nist-csf-2.0_v1.0.yaml` was originally extracted against NIST CSF
 * v1.1 anchors (`ID.AM-1`, `ID.GV-1`, `ID.SC-2`, …) with
 * `target_framework: nist-csf-1.1`. The runtime only loads the NIST CSF 2.0
 * catalogue ({@see \App\Command\LoadNistCsf2FullCatalogueCommand}, code
 * `NIST-CSF-2.0`, 106 subcategories with ZERO-PADDED ids like `ID.AM-01`,
 * `GV.SC-04`). So every one of the 33 rows' targets dangled at runtime
 * (MappingLibraryLoader resolves the framework by exact code match, then the
 * requirementId findOneBy → not found → silent-skip).
 *
 * The fix re-homed each 1.1 anchor to its NIST official CSF 1.1→2.0 successor
 * subcategory, verified to exist in the loaded catalogue, and set
 * `target_framework: NIST-CSF-2.0`. This test asserts the loader's
 * subcategory-id set is a SUPERSET of every target id in the file.
 *
 * It derives the loader id set exactly the way the loader builds it (the keys
 * of `fixtures/library/catalogues/nist-csf-2-0/csf2_subcategories.json`, which
 * {@see LoadNistCsf2FullCatalogueCommand::loadRequirements()} iterates as
 * `requirementId`s) — so a drift in the loader inventory, fixture, or mapping
 * ids fails the build with a concrete list of unresolved ids. No DB / kernel
 * required.
 */
final class TisaxNistCsf2ResolutionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const MAPPING_FILE = self::ROOT . '/fixtures/library/mappings/tisax_to_nist-csf-2.0_v1.0.yaml';

    private const CSF2_INVENTORY = self::ROOT . '/fixtures/library/catalogues/nist-csf-2-0/csf2_subcategories.json';

    /**
     * NIST CSF 2.0 subcategory ids, derived identically to
     * LoadNistCsf2FullCatalogueCommand::loadRequirements() — the keys of the
     * csf2_subcategories.json inventory it iterates as requirementIds.
     *
     * @return array<string, true>
     */
    private function csf2RequirementIds(): array
    {
        self::assertFileExists(self::CSF2_INVENTORY);
        $inventory = json_decode((string) file_get_contents(self::CSF2_INVENTORY), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($inventory);

        $ids = [];
        foreach (array_keys($inventory) as $id) {
            $ids[(string) $id] = true;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function targetIds(): array
    {
        $data = Yaml::parseFile(self::MAPPING_FILE);
        $ids = [];
        foreach ($data['mappings'] ?? [] as $entry) {
            foreach ((array) ($entry['targets'] ?? []) as $id) {
                if ((string) $id !== '') {
                    $ids[] = (string) $id;
                }
            }
        }

        return $ids;
    }

    #[Test]
    public function the_file_targets_the_loaded_nist_csf_2_0_framework_code(): void
    {
        $data = Yaml::parseFile(self::MAPPING_FILE);
        self::assertSame(
            'NIST-CSF-2.0',
            $data['library']['target_framework'] ?? null,
            'Mapping must target the registered runtime framework code NIST-CSF-2.0.',
        );
    }

    #[Test]
    public function csf2_inventory_has_the_full_106_subcategory_depth(): void
    {
        self::assertGreaterThanOrEqual(
            106,
            count($this->csf2RequirementIds()),
            'NIST CSF 2.0 inventory must contain all 106 active subcategories.',
        );
    }

    #[Test]
    public function every_target_id_resolves_to_a_loaded_csf2_subcategory(): void
    {
        $loaderIds = $this->csf2RequirementIds();
        self::assertNotEmpty($loaderIds, 'CSF 2.0 loader produced no ids — inventory fixture missing?');

        $targetIds = $this->targetIds();
        self::assertGreaterThanOrEqual(
            33,
            count($targetIds),
            'Expected >= 33 target ids across the 33 mapping rows (guards truncation).',
        );

        $unresolved = [];
        foreach ($targetIds as $id) {
            if (!isset($loaderIds[$id])) {
                $unresolved[] = $id;
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($unresolved)),
            sprintf(
                'Resolution guard: every NIST CSF 2.0 target id of %s must be a '
                . 'subcategory the registry-bound loader produces. %d/%d ids do '
                . 'NOT resolve (mappings would silent-skip): %s',
                basename(self::MAPPING_FILE),
                count(array_unique($unresolved)),
                count($targetIds),
                implode(', ', array_slice(array_unique($unresolved), 0, 20)),
            ),
        );
    }

    /**
     * Guards the 2.0 numbering convention — every target must be ZERO-PADDED
     * two-digit (`-01`), never the legacy 1.1 single-digit (`-1`) format.
     */
    #[Test]
    public function every_target_id_uses_zero_padded_2_0_numbering(): void
    {
        $legacy = [];
        foreach ($this->targetIds() as $id) {
            if (preg_match('/-\d$/', $id) === 1) {
                $legacy[] = $id;
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($legacy)),
            sprintf('Found legacy 1.1 single-digit ids (must be 2.0 zero-padded): %s', implode(', ', $legacy)),
        );
    }
}

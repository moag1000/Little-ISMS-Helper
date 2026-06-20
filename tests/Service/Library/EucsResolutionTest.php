<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Command\LoadEucsFullCommand;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolution-guard for the EUCS ↔ {ISO/IEC 27001:2022, BSI C5:2020} cross-framework
 * mappings — same bug-class as {@see BsiIso27001ResolutionTest}.
 *
 * Root cause this test pins: two of the four EUCS mapping files used a
 * NON-AUTHENTIC blind-label scheme on the EUCS side —
 * `eucs_to_iso27001-2022_v1.0.yaml` + `iso27001-2022_to_eucs_v1.0.yaml` referenced
 * `EUCS-A1` … `EUCS-A20` (area-level placeholders), and the two C5↔EUCS files
 * carried a handful of pre-canonical sub-ids (`OS-01`, `AT-01`, `A2-1`, …). The
 * registry-bound loader ({@see LoadEucsFullCommand}) only ever produces the
 * canonical EUCS control ids (`OIS-01`, `OPS-04`, `INQ-01`, …), so every mapping
 * row referencing a blind label silent-skipped at import
 * (MappingLibraryLoader does requirementId findOneBy → not found → skipped),
 * leaving the EUCS framework with dangling cross-walks at runtime.
 *
 * The fix remapped every EUCS-side id onto the canonical `<CATEGORY>-NN` control
 * that semantically anchors the area/sub-criterion (area name → category anchor).
 * This test asserts the loader's control-id set is a SUPERSET of every EUCS-side
 * id across all four mapping files.
 *
 * It derives the loader id set EXACTLY the way the loader builds it — reflecting
 * the curated `CONTROLS` const on {@see LoadEucsFullCommand} (the data source the
 * load path iterates) — so a drift in the loader, fixture, or mapping ids fails
 * the build with a concrete list of unresolved ids. No DB / kernel required.
 */
final class EucsResolutionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const MAPPING_DIR = self::ROOT . '/fixtures/library/mappings';

    // ────────────────────────────────────────────────────────────────────────
    // Loader id set — derived identically to LoadEucsFullCommand::loadRequirements()
    // (it iterates self::CONTROLS, keyed by bare control id).
    // ────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, true>
     */
    private function eucsControlIds(): array
    {
        /** @var array<string,string> $controls */
        $controls = (new ReflectionClass(LoadEucsFullCommand::class))->getConstant('CONTROLS');

        $ids = [];
        foreach (array_keys($controls) as $id) {
            $ids[(string) $id] = true;
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
    // Smoke: the loader catalogue is the authentic 20-category / 120-control set,
    // and exposes the canonical framework code the mapping files reference.
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function loader_exposes_the_canonical_eucs_framework_code(): void
    {
        $command = new LoadEucsFullCommand(
            $this->createMock(\App\Repository\ComplianceFrameworkRepository::class),
            $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
        );

        self::assertSame('EUCS', $command->getFrameworkCode());
    }

    #[Test]
    public function loader_produces_the_full_eucs_catalogue_depth(): void
    {
        $ids = $this->eucsControlIds();

        self::assertCount(
            120,
            $ids,
            'EUCS catalogue loader must produce all 120 controls (20 categories).',
        );

        // 20 distinct category prefixes (OIS … PSS).
        $prefixes = [];
        foreach (array_keys($ids) as $id) {
            $prefixes[explode('-', $id)[0]] = true;
        }
        self::assertCount(20, $prefixes, 'EUCS controls must span exactly 20 distinct category prefixes.');
    }

    // ────────────────────────────────────────────────────────────────────────
    // The actual resolution guards (every EUCS-side id ⊆ loader id set).
    // ────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{file: string, key: 'source'|'target', minPairs: int}>
     */
    public static function eucsSideProvider(): array
    {
        $dir = self::MAPPING_DIR;

        return [
            // EUCS is the SOURCE side.
            'eucs → iso27001-2022 (source = EUCS)' => [
                'file' => $dir . '/eucs_to_iso27001-2022_v1.0.yaml', 'key' => 'source', 'minPairs' => 54,
            ],
            'eucs → bsi-c5-2020 (source = EUCS)' => [
                'file' => $dir . '/eucs_to_bsi-c5-2020_v1.0.yaml', 'key' => 'source', 'minPairs' => 29,
            ],
            // EUCS is the TARGET side.
            'iso27001-2022 → eucs (target = EUCS)' => [
                'file' => $dir . '/iso27001-2022_to_eucs_v1.0.yaml', 'key' => 'target', 'minPairs' => 59,
            ],
            'bsi-c5-2020 → eucs (target = EUCS)' => [
                'file' => $dir . '/bsi-c5-2020_to_eucs_v1.0.yaml', 'key' => 'target', 'minPairs' => 24,
            ],
        ];
    }

    /**
     * @param 'source'|'target' $key
     */
    #[Test]
    #[DataProvider('eucsSideProvider')]
    public function eucs_side_ids_are_a_subset_of_the_registry_loader_ids(
        string $file,
        string $key,
        int $minPairs,
    ): void {
        self::assertFileExists($file);

        $loaderIds = $this->eucsControlIds();
        self::assertNotEmpty($loaderIds, 'EUCS loader produced no ids — CONTROLS const missing?');

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
                'Resolution guard: every EUCS-side (%s) id of %s must be produced by the '
                . 'registry-bound EUCS catalogue loader (LoadEucsFullCommand). %d/%d ids do NOT '
                . 'resolve (mappings would silent-skip): %s',
                $key,
                basename($file),
                count(array_unique($unresolved)),
                count($mappingIds),
                implode(', ', array_slice(array_unique($unresolved), 0, 20)),
            ),
        );
    }

    /**
     * Belt-and-braces: no EUCS-side id may still carry the deprecated blind-label
     * scheme (`EUCS-A<n>`, `A<n>-<m>`, `OS-<n>`, `AT-<n>`). This pins the unify-step
     * so a future copy-paste of the old scheme fails loudly.
     *
     * @param 'source'|'target' $key
     */
    #[Test]
    #[DataProvider('eucsSideProvider')]
    public function eucs_side_ids_do_not_use_the_deprecated_blind_label_scheme(
        string $file,
        string $key,
        int $minPairs,
    ): void {
        $blind = [];
        foreach ($this->mappingIds($file, $key) as $id) {
            if (
                preg_match('/^EUCS-A\d+$/', $id) === 1
                || preg_match('/^A\d+-\d+$/', $id) === 1
                || preg_match('/^OS-\d+$/', $id) === 1
                || preg_match('/^AT-\d+$/', $id) === 1
            ) {
                $blind[] = $id;
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($blind)),
            sprintf(
                '%s (%s) still uses the non-authentic EUCS blind-label scheme: %s',
                basename($file),
                $key,
                implode(', ', array_unique($blind)),
            ),
        );
    }
}

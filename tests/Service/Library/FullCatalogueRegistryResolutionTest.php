<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolution-guard for the BSI C5:2020, BSI C5:2026 and NIST CSF 2.0
 * cross-framework mappings — the same class of bug as the DORA RTS/ITS guard
 * ({@see DoraRtsItsIso27001MappingCodesAlignmentTest}).
 *
 * Root cause this test pins: runtime framework loading goes through
 * FrameworkLoaderRegistry (one `app.framework_loader` per framework code). For
 * these three frameworks the registry used to route to a PARTIAL hardcoded
 * `*Requirements` loader whose ids the mappings never reference, while the
 * COMPLETE catalogue lived in a `*FullCatalogue` command that was NOT
 * registry-bound. Result: every mapping pair silent-skipped at import
 * (MappingLibraryLoader does requirementId findOneBy → not found → skipped).
 *
 * The fix made the `*FullCatalogue` command the single registry loader per code.
 * This test asserts the registry-resolved loader's requirementId set is a
 * SUPERSET of each mapping's source (or target) ids — i.e. the mappings now
 * resolve. It derives the loader id set exactly the way the loader builds it
 * (regex over the BSI YAML / JSON inventory keys), so a drift in the loader,
 * fixture, or mapping ids fails the build with a concrete list of unresolved
 * ids — no DB/kernel required.
 */
final class FullCatalogueRegistryResolutionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const MAPPING_DIR = self::ROOT . '/fixtures/library/mappings';

    private const CATALOGUE_DIR = self::ROOT . '/fixtures/library/catalogues';

    private const COMMAND_DIR = self::ROOT . '/src/Command';

    // ────────────────────────────────────────────────────────────────────────
    // Loader id sets — derived identically to the registry-bound loaders.
    // ────────────────────────────────────────────────────────────────────────

    /**
     * BSI C5:2026 full catalogue ids (LoadC52026FullCatalogueCommand): both the
     * anchored schema (`identifier: &ID '01'` → AREA-01) and the plain schema
     * (`id: 'GC-01'`). ~174 criteria.
     *
     * @return array<string, true>
     */
    private function c52026FullIds(): array
    {
        $ids = [];
        foreach (glob(self::CATALOGUE_DIR . '/bsi-c5-2026-en/*.yml') ?: [] as $f) {
            $area = basename($f, '.yml');
            $content = (string) file_get_contents($f);
            preg_match_all(
                '/^  identifier:\s+&\S+\s+\'(\d+)\'\n  name:\s+\'([^\']+)\'/m',
                $content,
                $m,
                PREG_SET_ORDER,
            );
            foreach ($m as [$_, $num]) {
                $ids[sprintf('%s-%s', $area, $num)] = true;
            }
            preg_match_all(
                '/^  id:\s+\'([^\']+)\'\n  name:\s+\'([^\']+)\'/m',
                $content,
                $idm,
                PREG_SET_ORDER,
            );
            foreach ($idm as [$_, $fullId]) {
                $ids[$fullId] = true;
            }
        }

        return $ids;
    }

    /**
     * BSI C5:2020 full catalogue ids (LoadC52020FullCatalogueCommand): JSON
     * inventory keys. 121 criteria.
     *
     * @return array<string, true>
     */
    private function c52020FullIds(): array
    {
        $path = self::CATALOGUE_DIR . '/bsi-c5-2020-de/inventory.json';
        $inv = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return array_fill_keys(array_keys((array) $inv), true);
    }

    /**
     * NIST CSF 2.0 full catalogue ids (LoadNistCsf2FullCatalogueCommand): JSON
     * subcategory keys. 106 active subcategories.
     *
     * @return array<string, true>
     */
    private function nistCsf2FullIds(): array
    {
        $path = self::CATALOGUE_DIR . '/nist-csf-2-0/csf2_subcategories.json';
        $inv = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return array_fill_keys(array_keys((array) $inv), true);
    }

    /**
     * NIS2UmsuCG full §§-catalogue ids (LoadNis2UmsuCGFullCommand): the §N keys of
     * the PARAGRAPHS const. The registry binds this command for code NIS2UMSUCG;
     * the partial LoadNis2UmsuCGRequirementsCommand (NIS2UMSUCG-N delta ids) is
     * intentionally NOT registry-bound, so the §N cross-framework mappings resolve.
     *
     * @return array<string, true>
     */
    private function nis2UmsuCgFullIds(): array
    {
        return $this->phpConstKeyIds(self::COMMAND_DIR . '/LoadNis2UmsuCGFullCommand.php', 'PARAGRAPHS');
    }

    /**
     * ISO/IEC 27017:2015 loaded ids (LoadIso27017FullCommand): the 7 CLD.* keys +
     * the decomposed ISO 27002:2013 reference keys. Registry-bound for code
     * ISO27017; the iso27017↔bsi-c5-2026 crosswalks reference these 2013 ids.
     *
     * @return array<string, true>
     */
    private function iso27017FullIds(): array
    {
        return $this->phpConstKeyIds(self::COMMAND_DIR . '/LoadIso27017FullCommand.php', 'CLD_CONTROLS')
            + $this->phpConstKeyIds(self::COMMAND_DIR . '/LoadIso27017FullCommand.php', 'REFERENCED_27002');
    }

    /**
     * Derive the array KEYS of a `private const NAME = [ 'key' => 'value', … ];`
     * declaration directly from the command source — no kernel/DB required, and
     * a drift in the loader const fails the build with a concrete id list.
     *
     * @return array<string, true>
     */
    private function phpConstKeyIds(string $file, string $constName): array
    {
        $src = (string) file_get_contents($file);
        $start = strpos($src, 'const ' . $constName);
        self::assertNotFalse($start, "const {$constName} not found in " . basename($file));
        $open = strpos($src, '[', $start);
        self::assertNotFalse($open);
        // Match the const block up to the first '];' after the opener.
        $end = strpos($src, '];', $open);
        self::assertNotFalse($end);
        $block = substr($src, $open, $end - $open);
        preg_match_all("/'([^']+)'\\s*=>/", $block, $m);

        return array_fill_keys($m[1], true);
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
    // Sanity: the derived loader id sets match the expected catalogue depth.
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function full_loaders_produce_their_expected_catalogue_depth(): void
    {
        self::assertGreaterThanOrEqual(
            168,
            count($this->c52026FullIds()),
            'C5:2026 full loader must produce the full catalogue (~174 criteria).',
        );
        self::assertGreaterThanOrEqual(
            121,
            count($this->c52020FullIds()),
            'C5:2020 full loader must produce 121 criteria.',
        );
        self::assertGreaterThanOrEqual(
            106,
            count($this->nistCsf2FullIds()),
            'NIST CSF 2.0 full loader must produce 106 active subcategories.',
        );
        self::assertGreaterThanOrEqual(
            38,
            count($this->nis2UmsuCgFullIds()),
            'NIS2UmsuCG full loader must produce the §§-catalogue (>= 38 paragraphs).',
        );
        self::assertGreaterThanOrEqual(
            95,
            count($this->iso27017FullIds()),
            'ISO 27017 loader must cover the 7 CLD + decomposed 27002:2013 set (>= 95 ids).',
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // The actual resolution guards (mapping ids ⊆ registry-loader ids).
    // ────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{file: string, key: 'source'|'target', loader: 'c52026'|'c52020'|'nist'|'nis2umsucg', minPairs: int}>
     */
    public static function resolutionProvider(): array
    {
        return [
            // BSI C5:2026 (code BSI-C5-2026) — full catalogue ids OIS-/IAM-/…
            'bsi-c5-2026 → iso27001 (source)' => [
                'file' => self::MAPPING_DIR . '/bsi-c5-2026_to_iso27001-2022_v1.0.yaml',
                'key' => 'source', 'loader' => 'c52026', 'minPairs' => 100,
            ],
            'bsi-c5-2026 → nis2-art21 (source)' => [
                'file' => self::MAPPING_DIR . '/bsi-c5-2026_to_nis2-art21_v1.0.yaml',
                'key' => 'source', 'loader' => 'c52026', 'minPairs' => 60,
            ],
            'iso27001 → bsi-c5-2026 (target)' => [
                'file' => self::MAPPING_DIR . '/iso27001-2022_to_bsi-c5-2026_v1.0.yaml',
                'key' => 'target', 'loader' => 'c52026', 'minPairs' => 60,
            ],

            // BSI C5:2020 (code BSI-C5) — official short codes OPS-/OIS-/AM-/…
            'bsi-c5-2020 → iso27001 (source)' => [
                'file' => self::MAPPING_DIR . '/bsi-c5-2020_to_iso27001-2022_v1.0.yaml',
                'key' => 'source', 'loader' => 'c52020', 'minPairs' => 10,
            ],
            'iso27001 → bsi-c5-2020 (target)' => [
                'file' => self::MAPPING_DIR . '/iso27001-2022_to_bsi-c5-2020_v1.0.yaml',
                'key' => 'target', 'loader' => 'c52020', 'minPairs' => 10,
            ],
            'bsi-c5-2020 → eucs (source)' => [
                'file' => self::MAPPING_DIR . '/bsi-c5-2020_to_eucs_v1.0.yaml',
                'key' => 'source', 'loader' => 'c52020', 'minPairs' => 10,
            ],
            'bsi-it-grundschutz → bsi-c5-2020 (target)' => [
                'file' => self::MAPPING_DIR . '/bsi-it-grundschutz_to_bsi-c5-2020_v1.0.yaml',
                'key' => 'target', 'loader' => 'c52020', 'minPairs' => 10,
            ],

            // NIST CSF 2.0 (code NIST-CSF-2.0) — GV.OC-01 … subcategory ids
            'nist-csf-2-0 → iso27001 (source)' => [
                'file' => self::MAPPING_DIR . '/nist-csf-2-0_to_iso27001-2022_v1.0.yaml',
                'key' => 'source', 'loader' => 'nist', 'minPairs' => 100,
            ],
            'iso27001 → nist-csf-2-0 (target)' => [
                'file' => self::MAPPING_DIR . '/iso27001-2022_to_nist-csf-2-0_v1.0.yaml',
                'key' => 'target', 'loader' => 'nist', 'minPairs' => 60,
            ],

            // NIS2UmsuCG (code NIS2UMSUCG) — §N ids. The registry binds the FULL
            // §§-loader, NOT the partial NIS2UMSUCG-N requirements loader; every
            // §N id of these mappings must resolve against the §§-catalogue.
            'nis2-umsucg → dora (source)' => [
                'file' => self::MAPPING_DIR . '/nis2-umsucg_to_dora_v1.0.yaml',
                'key' => 'source', 'loader' => 'nis2umsucg', 'minPairs' => 70,
            ],
            'dora → nis2-umsucg (target)' => [
                'file' => self::MAPPING_DIR . '/dora_to_nis2-umsucg_v1.0.yaml',
                'key' => 'target', 'loader' => 'nis2umsucg', 'minPairs' => 40,
            ],
            'nis2-umsucg → nis2 (source)' => [
                'file' => self::MAPPING_DIR . '/nis2-umsucg_to_nis2_v1.0.yaml',
                'key' => 'source', 'loader' => 'nis2umsucg', 'minPairs' => 40,
            ],
            'nis2 → nis2-umsucg (target)' => [
                'file' => self::MAPPING_DIR . '/nis2_to_nis2-umsucg_v1.0.yaml',
                'key' => 'target', 'loader' => 'nis2umsucg', 'minPairs' => 40,
            ],
        ];
    }

    /**
     * @param 'source'|'target'                          $key
     * @param 'c52026'|'c52020'|'nist'|'nis2umsucg'      $loader
     */
    #[Test]
    #[DataProvider('resolutionProvider')]
    public function mapping_ids_are_a_subset_of_the_registry_loader_ids(
        string $file,
        string $key,
        string $loader,
        int $minPairs,
    ): void {
        self::assertFileExists($file);

        $loaderIds = match ($loader) {
            'c52026' => $this->c52026FullIds(),
            'c52020' => $this->c52020FullIds(),
            'nist' => $this->nistCsf2FullIds(),
            'nis2umsucg' => $this->nis2UmsuCgFullIds(),
        };
        self::assertNotEmpty($loaderIds, "Loader '{$loader}' produced no ids — fixture missing?");

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
                'Resolution guard: every %s id of %s must be produced by the registry-bound full '
                . "loader for code '%s'. %d/%d ids do NOT resolve (mappings would silent-skip): %s",
                $key,
                basename($file),
                $loader,
                count($unresolved),
                count($mappingIds),
                implode(', ', array_slice(array_unique($unresolved), 0, 15)),
            ),
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // ISO 27017 (code ISO27017) — the loader must cover the full vetted
    // ISO 27017 → ISO 27001 decomposition (so the iso27017↔bsi-c5-2026 crosswalks
    // resolve). Unlike the strict subset guards above this is NOT a full subset
    // check: a small residual of ISO 27002:2013 control numbers is referenced by
    // the crosswalk but absent from the vetted decomposition (physical/HR
    // controls where ISO 27017 layers no cloud guidance per the decomposition) —
    // those are reported as a content gap, not silently fabricated into the loader.
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function iso27017_loader_covers_the_full_vetted_decomposition(): void
    {
        $loaderIds = $this->iso27017FullIds();

        $decompPath = self::ROOT . '/fixtures/library/decompositions/decomp_iso27017_iso27001.json';
        self::assertFileExists($decompPath);
        /** @var list<array{source: string}> $decomp */
        $decomp = json_decode((string) file_get_contents($decompPath), true, 512, JSON_THROW_ON_ERROR);

        $missing = [];
        foreach ($decomp as $entry) {
            $src = (string) $entry['source'];
            if (!str_starts_with($src, '27017-')) {
                continue;
            }
            $id = substr($src, strlen('27017-'));
            if (!isset($loaderIds[$id])) {
                $missing[$id] = true;
            }
        }

        self::assertSame(
            [],
            array_keys($missing),
            'Every ISO 27017 control documented in the vetted decomposition must be loaded so the '
            . 'iso27017↔bsi-c5-2026 crosswalks resolve. Missing from loader: '
            . implode(', ', array_slice(array_keys($missing), 0, 20)),
        );
    }

    #[Test]
    public function iso27017_crosswalk_resolution_holds_at_post_fix_level(): void
    {
        $loaderIds = $this->iso27017FullIds();

        $forward = $this->mappingIds(
            self::MAPPING_DIR . '/iso27017_to_bsi-c5-2026_v1.0.yaml',
            'source',
        );
        $resolving = array_filter($forward, static fn (string $id): bool => isset($loaderIds[$id]));

        // Pre-fix the loader produced only 31 ids → 31 resolving. Post-fix it
        // covers the decomposed catalogue (95 ids). Guard against regression.
        self::assertGreaterThanOrEqual(
            95,
            count($resolving),
            sprintf(
                'iso27017_to_bsi-c5-2026 must resolve >= 95 source ids against the ISO27017 loader '
                . '(post-fix level). Only %d/%d resolve — loader coverage regressed.',
                count($resolving),
                count($forward),
            ),
        );
    }
}

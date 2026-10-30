<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Integrity guard for the BSI Grundschutz++ Anwenderkatalog (BSI-Standard 300-1)
 * and the mapping fixtures generated alongside it by
 * scripts/library/build_bsi_grundschutz_plusplus.py.
 *
 * Every GS++ ID a mapping cites must exist in the catalogue — otherwise the
 * import skips the pair and the reuse path silently loses it.
 */
final class BsiGrundschutzPlusPlusCatalogueTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../fixtures/library/';
    private const CATALOGUE = self::ROOT . 'catalogues/bsi-grundschutz-plusplus/catalogue.json';

    /** @var array<string, array<string, mixed>> */
    private static array $requirements = [];

    public static function setUpBeforeClass(): void
    {
        $catalogue = json_decode((string) file_get_contents(self::CATALOGUE), true, 512, JSON_THROW_ON_ERROR);
        foreach ($catalogue['requirements'] as $row) {
            self::$requirements[$row['id']] = $row;
        }
    }

    #[Test]
    public function catalogue_holds_all_1000_requirements_with_unique_ids(): void
    {
        self::assertCount(1000, self::$requirements);
        $kinds = array_count_values(array_column(self::$requirements, 'kind'));
        ksort($kinds);
        self::assertSame(['kernel' => 901, 'methodik' => 95, 'risikomanagement' => 4], $kinds);
    }

    #[Test]
    public function every_requirement_has_text_tier_and_modal_verb(): void
    {
        foreach (self::$requirements as $id => $row) {
            self::assertNotSame('', trim((string) $row['title']), "{$id}: title");
            self::assertNotSame('', trim((string) $row['statement']), "{$id}: statement");
            self::assertContains($row['tier'], ['basis', 'standard', 'hoch'], "{$id}: tier");
            self::assertContains($row['modal_verb'], ['MUSS', 'SOLLTE', 'KANN'], "{$id}: modal verb");
            self::assertStringNotContainsString('{{', (string) $row['statement'], "{$id}: unresolved parameter");
        }
    }

    #[Test]
    public function asset_types_use_the_app_asset_vocabulary(): void
    {
        $allowed = ['Hardware', 'Software', 'Information', 'Service', 'Personnel', 'Physical'];
        foreach (self::$requirements as $id => $row) {
            foreach ($row['asset_types'] as $type) {
                self::assertContains($type, $allowed, "{$id}: asset type");
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mappingFiles(): iterable
    {
        yield 'ISO Annex A → GS++' => ['iso27001-2022_to_bsi-gspp_official_v1.yaml', 'target'];
        yield 'GS++ → ISO Annex A' => ['bsi-gspp_to_iso27001-2022_v1.yaml', 'source'];
        yield 'ISO clauses → GS++' => ['iso27001-2022-clauses_to_bsi-gspp_v1.yaml', 'target'];
        yield 'GS++ → ISO clauses' => ['bsi-gspp_to_iso27001-2022-clauses_v1.yaml', 'source'];
        yield 'IT-Grundschutz → GS++' => ['bsi-it-grundschutz_to_bsi-gspp_official_v1.yaml', 'target'];
    }

    #[Test]
    #[DataProvider('mappingFiles')]
    public function every_gspp_id_in_mapping_exists_in_catalogue(string $file, string $gsppSide): void
    {
        $data = Yaml::parseFile(self::ROOT . 'mappings/' . $file);
        self::assertSame('published', $data['library']['lifecycle']['state']);
        self::assertNotEmpty($data['mappings']);

        $missing = [];
        foreach ($data['mappings'] as $entry) {
            $ids = $gsppSide === 'target'
                ? ($entry['targets'] ?? [$entry['target']])
                : [$entry['source']];
            foreach ($ids as $id) {
                if (!isset(self::$requirements[$id])) {
                    $missing[] = $id;
                }
            }
        }
        self::assertSame([], $missing, "{$file}: GS++ IDs missing from the catalogue");
    }

    #[Test]
    public function official_annex_a_mapping_covers_all_93_controls(): void
    {
        $data = Yaml::parseFile(self::ROOT . 'mappings/iso27001-2022_to_bsi-gspp_official_v1.yaml');
        self::assertSame('official_bsi_crosswalk', $data['library']['provenance']['primary_source']);
        $sources = array_unique(array_column($data['mappings'], 'source'));
        self::assertCount(93, $sources);
        foreach ($sources as $source) {
            self::assertMatchesRegularExpression('/^A\.[5-8]\.\d+$/', $source);
        }
    }

    #[Test]
    public function it_grundschutz_sources_are_anforderung_level(): void
    {
        $data = Yaml::parseFile(self::ROOT . 'mappings/bsi-it-grundschutz_to_bsi-gspp_official_v1.yaml');
        foreach ($data['mappings'] as $entry) {
            self::assertMatchesRegularExpression('/^[A-Z]{2,4}(\.\d+)+\.A\d+$/', $entry['source']);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Command\SeedIsoPolicyTemplatesCommand;
use App\Service\Bsi\GsppReuseMap;
use App\Service\PolicyWizard\SectionExtension\GsppSectionCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the generated Grundschutz++ reuse map and policy sections
 * (scripts/library/build_gspp_policy_sections.py) against drift: the requirement IDs a
 * policy section lists, the IDs used for cross-coverage and the official BSI mapping
 * must stay in step.
 */
final class GsppSectionCatalogueTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private GsppReuseMap $map;
    private GsppSectionCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->map = new GsppReuseMap(self::ROOT);
        $this->catalogue = new GsppSectionCatalogue($this->map);
    }

    #[Test]
    public function every_iso_topic_policy_gets_a_gspp_section(): void
    {
        $topics = array_column(SeedIsoPolicyTemplatesCommand::TEMPLATES, 'topic');
        self::assertEqualsCanonicalizing($topics, array_keys($this->map->policyTopics()));

        $sections = $this->catalogue->sectionsForTopic('access_control');
        self::assertCount(1, $sections);
        self::assertSame('gspp', $sections[0]->standard);
        self::assertSame('body_extension', $sections[0]->renderMode);
        self::assertContains('BER.2.1', $sections[0]->controlRefs);
        self::assertSame([], $this->catalogue->sectionsForTopic('no_such_topic'));
    }

    #[Test]
    public function section_ids_exist_in_the_catalogue_and_appear_in_both_translations(): void
    {
        $catalogue = json_decode(
            (string) file_get_contents(self::ROOT . '/fixtures/library/catalogues/bsi-grundschutz-plusplus/catalogue.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $known = array_flip(array_column($catalogue['requirements'], 'id'));
        $bodies = [];
        foreach (['de', 'en'] as $lang) {
            $bodies[$lang] = Yaml::parseFile(self::ROOT . "/translations/policy_gspp.{$lang}.yaml")['policy']['iso27001'];
        }

        foreach ($this->map->policyTopics() as $topic => $ids) {
            foreach ($ids as $id) {
                self::assertArrayHasKey($id, $known, "{$topic}: {$id}");
                foreach ($bodies as $lang => $topics) {
                    self::assertStringContainsString("| {$id} |", $topics[$topic]['v1']['gspp_extension']['body'], "{$lang}/{$topic}");
                }
            }
        }
    }

    /**
     * The practice → ISO control map must be exactly what the official BSI mapping yields,
     * because the compliance wizard scores GS++ kernel practices from those controls.
     */
    #[Test]
    public function practice_controls_match_the_official_bsi_mapping(): void
    {
        $catalogue = json_decode(
            (string) file_get_contents(self::ROOT . '/fixtures/library/catalogues/bsi-grundschutz-plusplus/catalogue.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $practiceOf = array_column($catalogue['requirements'], 'practice', 'id');
        $mapping = Yaml::parseFile(self::ROOT . '/fixtures/library/mappings/iso27001-2022_to_bsi-gspp_official_v1.yaml');

        $expected = [];
        foreach ($mapping['mappings'] as $entry) {
            foreach ($entry['targets'] ?? [$entry['target']] as $target) {
                $expected[$practiceOf[$target]][substr((string) $entry['source'], 2)] = true;
            }
        }

        foreach ($expected as $practice => $controls) {
            self::assertEqualsCanonicalizing(array_keys($controls), $this->map->isoControlsFor([$practice]), $practice);
        }
        self::assertSame([], $this->map->isoControlsFor(['NOPE']));
    }
}

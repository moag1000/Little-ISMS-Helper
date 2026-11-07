<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Integrity guard for the supplement to the BSI mapping Kompendium 2023 →
 * Grundschutz++ (scripts/library/derive_itgs_gspp_review.py).
 *
 * The supplement must never compete with the BSI: it only covers requirements
 * the BSI mapping leaves out, cites existing IDs on both sides, stays in the
 * heuristic tier, and matches the review fixture it was built from.
 */
final class KompendiumGsppSupplementTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../fixtures/library/';
    private const OFFICIAL = self::ROOT . 'mappings/bsi-it-grundschutz_to_bsi-gspp_official_v1.yaml';
    private const DERIVED = self::ROOT . 'mappings/bsi-it-grundschutz_to_bsi-gspp_derived_v1.yaml';
    private const REVIEW = self::ROOT . 'mappings/panel_verdicts/bsi-it-grundschutz_to_bsi-gspp_review_v1.json';

    /** @return array<string, true> */
    private static function kompendiumIds(): array
    {
        $ids = [];
        foreach (glob(self::ROOT . 'catalogues/bsi-it-grundschutz-2023/*.yml') ?: [] as $file) {
            foreach ((Yaml::parseFile($file)['bausteine'] ?? []) as $baustein) {
                foreach (($baustein['anforderungen'] ?? []) as $items) {
                    foreach ($items ?? [] as $a) {
                        $ids[(string) $a['id']] = true;
                    }
                }
            }
        }

        return $ids;
    }

    /** @return array<string, true> */
    private static function gsppIds(): array
    {
        $catalogue = json_decode((string) file_get_contents(self::ROOT . 'catalogues/bsi-grundschutz-plusplus/catalogue.json'), true, 512, JSON_THROW_ON_ERROR);

        return array_fill_keys(array_column($catalogue['requirements'], 'id'), true);
    }

    /** @return array<string, true> */
    private static function officialSources(): array
    {
        return array_fill_keys(array_map('strval', array_column(Yaml::parseFile(self::OFFICIAL)['mappings'], 'source')), true);
    }

    #[Test]
    public function supplement_only_covers_requirements_the_bsi_left_out(): void
    {
        $kompendium = self::kompendiumIds();
        $gspp = self::gsppIds();
        $official = self::officialSources();
        $doc = Yaml::parseFile(self::DERIVED);

        self::assertSame('derived_expert_review', $doc['library']['provenance']['primary_source']);
        self::assertNotEmpty($doc['mappings']);
        foreach ($doc['mappings'] as $m) {
            $pair = $m['source'] . ' → ' . $m['target'];
            self::assertArrayHasKey($m['source'], $kompendium, "{$pair}: unknown Kompendium requirement");
            self::assertArrayHasKey($m['target'], $gspp, "{$pair}: unknown Grundschutz++ requirement");
            self::assertArrayNotHasKey($m['source'], $official, "{$pair}: the BSI mapping covers this requirement");
            self::assertContains($m['relationship'], ['equivalent', 'subset', 'superset', 'partial_overlap'], $pair);
            self::assertContains($m['confidence'], ['low', 'medium'], "{$pair}: supplement never claims high confidence");
        }
    }

    #[Test]
    public function review_fixture_matches_supplement(): void
    {
        $review = json_decode((string) file_get_contents(self::REVIEW), true, 512, JSON_THROW_ON_ERROR);
        $official = self::officialSources();
        $derivedSources = array_fill_keys(array_column(Yaml::parseFile(self::DERIVED)['mappings'], 'source'), true);

        foreach ($review['requirements'] as $id => $reviews) {
            self::assertCount(2, $reviews, "{$id}: two independent reviews");
            self::assertArrayNotHasKey($id, $official, "{$id}: reviewed although the BSI maps it");
        }
        foreach ($review['no_counterpart'] as $id) {
            self::assertArrayNotHasKey($id, $derivedSources, "{$id}: marked without counterpart but has supplement pairs");
            foreach ($review['requirements'][$id] as $r) {
                self::assertSame([], $r['targets'], "{$id}: a review picked a target");
            }
        }
    }
}

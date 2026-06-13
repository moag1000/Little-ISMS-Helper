<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Consistency guard for the BSI-C5-2026<->ISO27001 AI-expert-panel verdict fixtures.
 *
 * Asserts the panel verdicts reference real YAML mapping rows (catches ID drift
 * between the operational mapping and the shipped quality evidence), that the
 * library verdict_counts match the actual verdict tally, and that completeness
 * candidates are well-formed. No database required — pure fixture structure.
 */
final class C52026IsoPanelVerdictConsistencyTest extends TestCase
{
    private const FIX = __DIR__ . '/../../../fixtures/library/mappings/';
    private const YAML = self::FIX . 'bsi-c5-2026_to_iso27001-2022_v1.0.yaml';
    private const PANEL = self::FIX . 'panel_verdicts/bsi-c5-2026_to_iso27001-2022_panel_v1.json';
    private const COMPLETE = self::FIX . 'panel_verdicts/bsi-c5-2026_to_iso27001-2022_completeness_candidates_v1.json';
    private const SRC_KEY = 'c5';
    private const TGT_KEY = 'iso';

    /** @return array<string, bool> */
    private function yamlPairs(): array
    {
        $yaml = Yaml::parseFile(self::YAML);
        $pairs = [];
        foreach ($yaml['mappings'] as $m) {
            $pairs[$m['source'] . '||' . ($m['target'] ?? '')] = true;
        }

        return $pairs;
    }

    /** @return array<string, mixed> */
    private function panel(): array
    {
        return json_decode((string) file_get_contents(self::PANEL), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function panel_verdicts_reference_existing_yaml_pairs(): void
    {
        $pairs = $this->yamlPairs();
        $panel = $this->panel();
        self::assertNotEmpty($panel['verdicts'], 'panel fixture has no verdicts');
        foreach ($panel['verdicts'] as $v) {
            $key = $v[self::SRC_KEY] . '||' . $v[self::TGT_KEY];
            self::assertArrayHasKey($key, $pairs, "panel verdict {$key} has no matching YAML mapping row");
        }
    }

    #[Test]
    public function verdict_counts_match_array(): void
    {
        $panel = $this->panel();
        $counts = $panel['library']['verdict_counts'];
        $tally = ['ki_validiert' => 0, 'needs_review' => 0, 'reject' => 0];
        foreach ($panel['verdicts'] as $v) {
            self::assertArrayHasKey($v['state'], $tally, "unexpected verdict state {$v['state']}");
            $tally[$v['state']]++;
        }
        self::assertSame($counts['ki_validiert'], $tally['ki_validiert'], 'ki_validiert count drift');
        self::assertSame($counts['needs_review'], $tally['needs_review'], 'needs_review count drift');
        self::assertSame($counts['reject'], $tally['reject'], 'reject count drift');
        self::assertSame($counts['total'], count($panel['verdicts']), 'total count drift');
    }

    #[Test]
    public function completeness_candidates_are_well_formed(): void
    {
        $comp = json_decode((string) file_get_contents(self::COMPLETE), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('candidates', $comp);
        self::assertArrayHasKey('provenance', $comp);
        foreach ($comp['candidates'] as $c) {
            self::assertNotEmpty($c['target'], 'completeness candidate missing target');
            self::assertNotEmpty($c['rationale'], 'completeness candidate missing rationale');
            self::assertGreaterThanOrEqual(0, $c['mappingPercentage']);
            self::assertLessThanOrEqual(100, $c['mappingPercentage']);
        }
        self::assertSame(
            $comp['provenance']['candidate_count'],
            count($comp['candidates']),
            'completeness candidate_count drift',
        );
    }
}

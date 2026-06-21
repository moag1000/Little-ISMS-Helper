<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Consistency guard for the 5 AT/CH cross-framework panel-verdict fixtures
 * (DACH Phase 4 — trust-tiering of the Austrian NISG-2026 and Swiss
 * IKT-Minimalstandard / revDSG mappings).
 *
 * For each pair the verdict fixture was authored via judge->refute grading over
 * the EXISTING operative YAML mapping rows. These guards assert:
 *   1. every panel verdict references a real YAML mapping row (verdict ⊆ YAML)
 *      — catches source/target ID drift between the mapping and its evidence;
 *   2. the library.verdict_counts block matches the actual verdict tally;
 *   3. the fixture is valid JSON with the expected shape (states, percentages).
 *
 * Pure fixture-structure assertions — no database required. The verdicts use the
 * generic `source`/`target` entry keys, which {@see \App\Service\Bsi\PanelVerdictApplier}
 * reads via its `source`/`target` auto-fallback.
 *
 * @see \App\Service\Bsi\PanelVerdictAutoApplier — runtime auto-applier
 */
final class AtChPanelVerdictConsistencyTest extends TestCase
{
    private const FIX = __DIR__ . '/../../../fixtures/library/mappings/';

    /** Valid verdict states the AT/CH grading emits. */
    private const VALID_STATES = ['ki_validiert', 'reject', 'needs_review'];

    /**
     * The 5 AT/CH pairs: base => [yaml file, panel-verdict file].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pairProvider(): array
    {
        $cases = [
            'nisg-at_to_iso27001-2022',
            'nisg-at_to_nis2',
            'ikt-minstd-ch_to_iso27001-2022',
            'ikt-minstd-ch_to_nist-csf-2-0',
            'revdsg-ch_to_gdpr',
        ];

        $out = [];
        foreach ($cases as $base) {
            $out[$base] = [
                self::FIX . $base . '_v1.0.yaml',
                self::FIX . 'panel_verdicts/' . $base . '_panel_v1.json',
            ];
        }

        return $out;
    }

    /**
     * Build the set of valid (source||target) keys from the operative YAML.
     *
     * @return array<string, true>
     */
    private function yamlPairs(string $yamlPath): array
    {
        $yaml = Yaml::parseFile($yamlPath);
        self::assertIsArray($yaml['mappings'] ?? null, "YAML {$yamlPath} has no mappings array");

        $pairs = [];
        foreach ($yaml['mappings'] as $m) {
            self::assertNotEmpty($m['source'] ?? null, 'YAML mapping row missing source');
            self::assertNotEmpty($m['target'] ?? null, 'YAML mapping row missing target');
            $pairs[$m['source'] . '||' . $m['target']] = true;
        }

        return $pairs;
    }

    /** @return array<string, mixed> */
    private function panel(string $panelPath): array
    {
        self::assertFileExists($panelPath, "panel fixture missing: {$panelPath}");
        $decoded = json_decode((string) file_get_contents($panelPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, "panel fixture is not a JSON object: {$panelPath}");

        return $decoded;
    }

    #[Test]
    #[DataProvider('pairProvider')]
    public function panel_verdicts_reference_existing_yaml_pairs(string $yamlPath, string $panelPath): void
    {
        $pairs = $this->yamlPairs($yamlPath);
        $panel = $this->panel($panelPath);

        self::assertNotEmpty($panel['verdicts'] ?? [], "panel fixture has no verdicts: {$panelPath}");

        foreach ($panel['verdicts'] as $v) {
            self::assertArrayHasKey('source', $v, 'verdict missing source key');
            self::assertArrayHasKey('target', $v, 'verdict missing target key');
            $key = $v['source'] . '||' . $v['target'];
            self::assertArrayHasKey(
                $key,
                $pairs,
                "panel verdict {$key} has no matching YAML mapping row (verdict ⊄ YAML)",
            );
        }
    }

    #[Test]
    #[DataProvider('pairProvider')]
    public function verdict_counts_match_tally(string $yamlPath, string $panelPath): void
    {
        $panel = $this->panel($panelPath);
        self::assertArrayHasKey('library', $panel, 'panel fixture missing library block');
        $counts = $panel['library']['verdict_counts'] ?? null;
        self::assertIsArray($counts, 'panel fixture missing library.verdict_counts');

        $tally = ['ki_validiert' => 0, 'needs_review' => 0, 'reject' => 0];
        foreach ($panel['verdicts'] as $v) {
            self::assertContains($v['state'], self::VALID_STATES, "unexpected verdict state {$v['state']}");
            $tally[$v['state']]++;
        }

        self::assertSame($counts['ki_validiert'], $tally['ki_validiert'], 'ki_validiert count drift');
        self::assertSame($counts['needs_review'], $tally['needs_review'], 'needs_review count drift');
        self::assertSame($counts['reject'], $tally['reject'], 'reject count drift');
        self::assertSame($counts['total'], count($panel['verdicts']), 'total count drift');
    }

    #[Test]
    #[DataProvider('pairProvider')]
    public function verdicts_are_well_formed(string $yamlPath, string $panelPath): void
    {
        $panel = $this->panel($panelPath);

        self::assertArrayHasKey('source_framework', $panel['library'], 'library.source_framework missing');
        self::assertArrayHasKey('target_framework', $panel['library'], 'library.target_framework missing');
        self::assertNotEmpty($panel['library']['panel'] ?? [], 'library.panel (lenses) missing');
        self::assertSame('2026-06-21', $panel['library']['run_date'] ?? null, 'run_date drift');

        foreach ($panel['verdicts'] as $v) {
            self::assertContains($v['state'], self::VALID_STATES, "invalid state {$v['state']}");
            self::assertArrayHasKey('mappingPercentage', $v, 'verdict missing mappingPercentage');
            self::assertGreaterThanOrEqual(0, $v['mappingPercentage'], 'mappingPercentage < 0');
            self::assertLessThanOrEqual(100, $v['mappingPercentage'], 'mappingPercentage > 100');
            self::assertSame(2, $v['realVotes'] ?? null, 'realVotes must be 2 (AT/CH grading)');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;

/**
 * Guard test: NIS2-side ids in the Art.21 mapping pair must use the loaded
 * catalog form Art.21.2.{letter} — the synthetic NIS2-ART21-{LETTER} scheme
 * was a pre-load convention and must NOT appear after normalization (2026-06-21).
 *
 * Both YAML files use flat mapping entries with a single 'source' and 'target'
 * scalar field per entry (not a 'targets' list).
 */
class Nis2Art21IdResolutionTest extends TestCase
{
    private function fixturesDir(): string
    {
        return dirname(__DIR__, 3) . '/fixtures/library/mappings';
    }

    #[Test]
    public function nis2SourceIdsUseLoadedCatalogForm(): void
    {
        $file = $this->fixturesDir() . '/nis2-art21_to_iso27001-2022_v1.0.yaml';
        $data = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $synthetic = [];
        foreach ($mappings as $mapping) {
            $sourceId = (string) ($mapping['source'] ?? '');
            if ($sourceId !== '' && preg_match('/^NIS2-ART21-/', $sourceId)) {
                $synthetic[] = $sourceId;
            }
        }

        $this->assertEmpty(
            $synthetic,
            'Synthetic NIS2-ART21-* source ids found in nis2-art21_to_iso27001-2022 mapping: '
            . implode(', ', array_unique($synthetic))
        );
    }

    #[Test]
    public function nis2TargetIdsUseLoadedCatalogForm(): void
    {
        $file = $this->fixturesDir() . '/iso27001-2022_to_nis2-art21_v1.0.yaml';
        $data = Yaml::parseFile($file);
        $mappings = $data['mappings'] ?? [];

        $synthetic = [];
        foreach ($mappings as $mapping) {
            $targetId = (string) ($mapping['target'] ?? '');
            if ($targetId !== '' && preg_match('/^NIS2-ART21-/', $targetId)) {
                $synthetic[] = $targetId;
            }
        }

        $this->assertEmpty(
            $synthetic,
            'Synthetic NIS2-ART21-* target ids found in iso27001-2022_to_nis2-art21 mapping: '
            . implode(', ', array_unique($synthetic))
        );
    }

    #[Test]
    public function allNis2SideIdsMatchExpectedPattern(): void
    {
        // File 1: source ids are NIS2 side
        $file1 = $this->fixturesDir() . '/nis2-art21_to_iso27001-2022_v1.0.yaml';
        $data1 = Yaml::parseFile($file1);
        $invalidSources = [];
        foreach ($data1['mappings'] ?? [] as $mapping) {
            $id = (string) ($mapping['source'] ?? '');
            if ($id !== '' && !preg_match('/^Art\.(20|21|23)\./', $id)) {
                $invalidSources[] = $id;
            }
        }

        // File 2: target ids are NIS2 side
        $file2 = $this->fixturesDir() . '/iso27001-2022_to_nis2-art21_v1.0.yaml';
        $data2 = Yaml::parseFile($file2);
        $invalidTargets = [];
        foreach ($data2['mappings'] ?? [] as $mapping) {
            $id = (string) ($mapping['target'] ?? '');
            if ($id !== '' && !preg_match('/^Art\.(20|21|23)\./', $id)) {
                $invalidTargets[] = $id;
            }
        }

        $this->assertEmpty(
            $invalidSources,
            'NIS2 source ids not matching Art.(20|21|23). pattern: '
            . implode(', ', array_unique($invalidSources))
        );
        $this->assertEmpty(
            $invalidTargets,
            'NIS2 target ids not matching Art.(20|21|23). pattern: '
            . implode(', ', array_unique($invalidTargets))
        );
    }
}

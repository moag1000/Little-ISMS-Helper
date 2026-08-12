<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Service\MappingValidatorService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression guard for the plural `targets:` key used by the tisax_to_*
 * mapping fixtures.
 *
 * MappingLibraryLoader and MappingValidatorService only ever read a singular
 * `target:`, so every fixture written with `targets: [...]` produced zero
 * importable pairs. These tests pin the normalisation down and assert that no
 * shipped fixture is left with entries the importer cannot see.
 *
 * Unit-level: no DB, no kernel.
 */
final class MappingTargetsExpansionTest extends TestCase
{
    private const MAPPING_DIR = __DIR__ . '/../../../fixtures/library/mappings';

    #[Test]
    public function pluralTargetsExpandToOneEntryPerPair(): void
    {
        $expanded = MappingValidatorService::expandEntries([
            ['source' => '1.1.1', 'targets' => ['A.5.1', 'A.5.2'], 'category' => 'information_security'],
        ]);

        self::assertCount(2, $expanded);
        self::assertSame('A.5.1', $expanded[0]['target']);
        self::assertSame('A.5.2', $expanded[1]['target']);
        self::assertSame('1.1.1', $expanded[0]['source']);
        // Sibling keys survive the expansion.
        self::assertSame('information_security', $expanded[1]['category']);
        self::assertArrayNotHasKey('targets', $expanded[0]);
    }

    #[Test]
    public function emptyTargetsListIsDroppedRatherThanBecomingANullTarget(): void
    {
        $expanded = MappingValidatorService::expandEntries([
            ['source' => '1.2.1', 'targets' => []],
            ['source' => '1.2.2', 'targets' => ['A.5.4']],
        ]);

        self::assertCount(1, $expanded);
        self::assertSame('1.2.2', $expanded[0]['source']);
    }

    #[Test]
    public function singularTargetEntriesArePassedThroughUntouched(): void
    {
        $entries = [
            ['source' => 'X', 'target' => 'Y', 'relationship' => 'equivalent'],
            ['source' => 'Z', 'target' => null, 'relationship' => 'superset'],
        ];

        self::assertSame($entries, MappingValidatorService::expandEntries($entries));
    }

    #[Test]
    public function everyShippedFixtureYieldsEntriesTheImporterCanRead(): void
    {
        $files = glob(self::MAPPING_DIR . '/*.yaml') ?: [];
        self::assertNotEmpty($files, 'No mapping fixtures found.');

        $blind = [];
        foreach ($files as $file) {
            $payload = Yaml::parseFile($file);
            $mappings = $payload['mappings'] ?? null;
            if (!is_array($mappings) || $mappings === []) {
                continue;
            }

            $expanded = MappingValidatorService::expandEntries($mappings);
            $readable = array_filter(
                $expanded,
                static fn (array $e): bool => array_key_exists('target', $e),
            );

            if ($readable === []) {
                $blind[] = basename($file);
            }
        }

        self::assertSame(
            [],
            $blind,
            'These fixtures expose no importer-readable target key: ' . implode(', ', $blind),
        );
    }
}

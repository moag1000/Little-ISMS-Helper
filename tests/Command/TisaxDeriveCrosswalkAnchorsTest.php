<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the legacy-ISO anchors fixture that feeds app:tisax:derive-crosswalk.
 * The fixture is extracted from SeedTisaxIso27001MappingsCommand and must stay a
 * complete, well-formed map (every legacy id carries at least one ISO anchor) so
 * the ISO-anchor bridge can resolve it — no silent gaps.
 */
final class TisaxDeriveCrosswalkAnchorsTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/library/mappings/tisax-legacy-iso-anchors.yaml';

    #[Test]
    public function anchors_fixture_is_complete_and_well_formed(): void
    {
        self::assertFileExists(self::FIXTURE);
        $doc = Yaml::parseFile(self::FIXTURE);

        self::assertSame(1, $doc['version'] ?? null);
        self::assertIsArray($doc['anchors'] ?? null);
        self::assertGreaterThanOrEqual(90, count($doc['anchors']), 'expected ~98 legacy ids');

        foreach ($doc['anchors'] as $legacyId => $isos) {
            self::assertIsString($legacyId);
            self::assertNotEmpty($isos, "legacy id {$legacyId} has no ISO anchor (silent gap)");
            foreach ((array) $isos as $iso) {
                self::assertMatchesRegularExpression(
                    '/^(A\.\d|\d)/',
                    (string) $iso,
                    "legacy id {$legacyId} anchor '{$iso}' is not an ISO clause/Annex-A ref",
                );
            }
        }
    }

    #[Test]
    public function known_anchor_mappings_are_present(): void
    {
        $anchors = Yaml::parseFile(self::FIXTURE)['anchors'];
        // Spot-check a few stable seed assertions.
        self::assertContains('A.5.15', (array) $anchors['ACC-1.1']);
        self::assertContains('A.5.16', (array) $anchors['ACC-2.1']);
    }
}

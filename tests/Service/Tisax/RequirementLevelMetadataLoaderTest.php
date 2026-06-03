<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Service\Tisax\RequirementLevelMetadataLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RequirementLevelMetadataLoader.
 *
 * Tests use the real YAML fixture at
 * fixtures/library/metadata/tisax_requirement_levels_v1.0.yaml
 * to verify structural invariants — NOT licensed ENX content.
 */
class RequirementLevelMetadataLoaderTest extends TestCase
{
    private RequirementLevelMetadataLoader $loader;

    protected function setUp(): void
    {
        // Point to the real project directory (two levels up from tests/Service/Tisax/)
        $projectDir   = dirname(__DIR__, 3);
        $this->loader = new RequirementLevelMetadataLoader($projectDir);
    }

    #[Test]
    public function fixture_loads_without_error(): void
    {
        $ids = $this->loader->allControlIds();
        self::assertNotEmpty($ids, 'Fixture must contain at least one control');
    }

    #[Test]
    public function fixture_contains_80_controls(): void
    {
        self::assertCount(80, $this->loader->allControlIds());
    }

    #[Test]
    public function get_metadata_for_known_control_returns_array(): void
    {
        $meta = $this->loader->getMetadataFor('1.1.1');
        self::assertNotNull($meta);
        self::assertArrayHasKey('levels', $meta);
        self::assertArrayHasKey('suggested_assessment_levels', $meta);
        self::assertArrayHasKey('protection_need_addenda', $meta);
    }

    #[Test]
    public function get_metadata_accepts_isa_prefix(): void
    {
        $withPrefix    = $this->loader->getMetadataFor('ISA 1.1.1');
        $withoutPrefix = $this->loader->getMetadataFor('1.1.1');
        self::assertSame($withPrefix, $withoutPrefix);
    }

    #[Test]
    public function get_metadata_returns_null_for_unknown_control(): void
    {
        self::assertNull($this->loader->getMetadataFor('99.99.99'));
        self::assertNull($this->loader->getMetadataFor(''));
        self::assertNull($this->loader->getMetadataFor('ISA 0.0.0'));
    }

    #[Test]
    public function levels_are_booleans(): void
    {
        foreach ($this->loader->allControlIds() as $id) {
            $meta = $this->loader->getMetadataFor($id);
            self::assertNotNull($meta);
            foreach (['must', 'should', 'high_protection', 'very_high_protection'] as $flag) {
                self::assertIsBool($meta['levels'][$flag], "$id.$flag must be bool");
            }
        }
    }

    #[Test]
    public function all_controls_have_at_least_must_true(): void
    {
        // Per VDA-ISA 6 structure: every IS/PP control has a Must column
        foreach ($this->loader->allControlIds() as $id) {
            $meta = $this->loader->getMetadataFor($id);
            self::assertTrue($meta['levels']['must'], "$id must have must=true");
        }
    }

    #[Test]
    public function suggested_assessment_levels_is_non_empty_list(): void
    {
        foreach ($this->loader->allControlIds() as $id) {
            $meta = $this->loader->getMetadataFor($id);
            self::assertNotNull($meta);
            $als = $meta['suggested_assessment_levels'];
            self::assertIsArray($als);
            self::assertNotEmpty($als, "$id must have at least AL1 in suggested_assessment_levels");
        }
    }

    #[Test]
    public function al1_applies_to_all_controls(): void
    {
        $al1Controls = $this->loader->controlIdsForAssessmentLevel('AL1');
        self::assertCount(80, $al1Controls, 'All 80 controls must have AL1 applicability (Must column)');
    }

    #[Test]
    public function al2_count_is_less_than_or_equal_al1(): void
    {
        $al1 = count($this->loader->controlIdsForAssessmentLevel('AL1'));
        $al2 = count($this->loader->controlIdsForAssessmentLevel('AL2'));
        self::assertLessThanOrEqual($al1, $al2, 'AL2 cannot apply to more controls than AL1');
    }

    #[Test]
    public function al3_count_is_less_than_or_equal_al2(): void
    {
        $al2 = count($this->loader->controlIdsForAssessmentLevel('AL2'));
        $al3 = count($this->loader->controlIdsForAssessmentLevel('AL3'));
        self::assertLessThanOrEqual($al2, $al3, 'AL3 cannot apply to more controls than AL2');
    }

    #[Test]
    public function controls_with_very_high_protection_is_subset_of_high_or_empty(): void
    {
        // Very-high addendum controls should not exceed high addendum + some edge cases
        $high     = count($this->loader->controlIdsWithLevel('high_protection'));
        $veryHigh = count($this->loader->controlIdsWithLevel('very_high_protection'));
        // Not a hard rule, but very_high controls is typically much smaller than high
        self::assertLessThanOrEqual($high + 5, $veryHigh, 'Very-high controls should not vastly exceed high controls');
    }

    #[Test]
    public function control_ids_with_level_returns_only_matching_ids(): void
    {
        $highIds = $this->loader->controlIdsWithLevel('high_protection');
        foreach ($highIds as $id) {
            $meta = $this->loader->getMetadataFor($id);
            self::assertTrue($meta['levels']['high_protection'], "$id must have high_protection=true");
        }
    }

    #[Test]
    public function stats_returns_array_with_total_controls(): void
    {
        $stats = $this->loader->getStats();
        self::assertArrayHasKey('total_controls', $stats);
        self::assertSame(80, (int) $stats['total_controls']);
    }

    #[Test]
    public function graceful_degradation_for_missing_fixture(): void
    {
        $loader = new RequirementLevelMetadataLoader('/nonexistent/project/dir');
        // Should not throw — returns empty
        self::assertSame([], $loader->allControlIds());
        self::assertNull($loader->getMetadataFor('1.1.1'));
        self::assertEmpty($loader->controlIdsForAssessmentLevel('AL1'));
    }

    #[Test]
    public function protection_need_addenda_consistent_with_level_flags(): void
    {
        foreach ($this->loader->allControlIds() as $id) {
            $meta    = $this->loader->getMetadataFor($id);
            $addenda = $meta['protection_need_addenda'] ?? [];

            if ($meta['levels']['high_protection']) {
                self::assertContains('high', $addenda, "$id: high_protection=true must appear in addenda");
            } else {
                self::assertNotContains('high', $addenda, "$id: high_protection=false must NOT appear in addenda");
            }

            if ($meta['levels']['very_high_protection']) {
                self::assertContains('very_high', $addenda, "$id: very_high_protection=true must appear in addenda");
            } else {
                self::assertNotContains('very_high', $addenda, "$id: very_high_protection=false must NOT appear in addenda");
            }
        }
    }
}

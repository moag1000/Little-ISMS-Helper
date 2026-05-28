<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Service\Tisax\TisaxMaturityAssessmentService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TisaxMaturityAssessmentService.
 *
 * These tests cover pure business logic (no DB) via the static helpers
 * and the level-map contract.
 */
class TisaxMaturityAssessmentServiceTest extends TestCase
{
    #[Test]
    public function level_map_covers_all_six_levels(): void
    {
        self::assertCount(6, TisaxMaturityAssessmentService::LEVEL_MAP);

        foreach (TisaxMaturityAssessmentService::REIFEGRAD_LEVELS as $level) {
            self::assertArrayHasKey($level, TisaxMaturityAssessmentService::LEVEL_MAP,
                sprintf('Level %d missing from LEVEL_MAP', $level));
        }
    }

    #[Test]
    public function level_for_string_returns_correct_int(): void
    {
        self::assertSame(0, TisaxMaturityAssessmentService::levelForString('incomplete'));
        self::assertSame(1, TisaxMaturityAssessmentService::levelForString('performed'));
        self::assertSame(2, TisaxMaturityAssessmentService::levelForString('managed'));
        self::assertSame(3, TisaxMaturityAssessmentService::levelForString('established'));
        self::assertSame(4, TisaxMaturityAssessmentService::levelForString('predictable'));
        self::assertSame(5, TisaxMaturityAssessmentService::levelForString('optimising'));
    }

    #[Test]
    public function level_for_string_returns_null_for_unknown(): void
    {
        self::assertNull(TisaxMaturityAssessmentService::levelForString('unknown_value'));
        self::assertNull(TisaxMaturityAssessmentService::levelForString(''));
    }

    #[Test]
    public function reifegrad_levels_constant_contains_zero_to_five(): void
    {
        self::assertSame([0, 1, 2, 3, 4, 5], TisaxMaturityAssessmentService::REIFEGRAD_LEVELS);
    }

    #[Test]
    public function level_map_values_are_unique_strings(): void
    {
        $values = array_values(TisaxMaturityAssessmentService::LEVEL_MAP);
        self::assertCount(count(array_unique($values)), $values, 'LEVEL_MAP values must be unique');
    }

    #[Test]
    public function level_map_is_ordered_ascending(): void
    {
        $keys = array_keys(TisaxMaturityAssessmentService::LEVEL_MAP);
        $expected = range(0, 5);
        self::assertSame($expected, $keys, 'LEVEL_MAP keys must be 0..5 in order');
    }
}

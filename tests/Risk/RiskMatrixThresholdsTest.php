<?php

declare(strict_types=1);

namespace App\Tests\Risk;

use App\Risk\RiskMatrixThresholds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pin-test for the risk-matrix score thresholds (Audit P-11).
 *
 * Guards ISO 27001 Cl. 6.1.2 b consistency — if a future change shifts
 * the band edges, this test fails first and forces an explicit decision
 * rather than silent UI drift.
 */
final class RiskMatrixThresholdsTest extends TestCase
{
    #[Test]
    public function testConstantsArePinnedToSsotValues(): void
    {
        self::assertSame(20, RiskMatrixThresholds::CRITICAL_MIN);
        self::assertSame(12, RiskMatrixThresholds::HIGH_MIN);
        self::assertSame(6, RiskMatrixThresholds::MEDIUM_MIN);
        self::assertSame(1, RiskMatrixThresholds::LOW_MIN);
        self::assertSame(25, RiskMatrixThresholds::SCORE_MAX);
    }

    #[Test]
    public function testClassifyBucketsScoresIntoTheCorrectBand(): void
    {
        // critical: 20..25
        self::assertSame('critical', RiskMatrixThresholds::classify(25));
        self::assertSame('critical', RiskMatrixThresholds::classify(20));

        // high: 12..19
        self::assertSame('high', RiskMatrixThresholds::classify(19));
        self::assertSame('high', RiskMatrixThresholds::classify(12));

        // medium: 6..11
        self::assertSame('medium', RiskMatrixThresholds::classify(11));
        self::assertSame('medium', RiskMatrixThresholds::classify(6));

        // low: 1..5
        self::assertSame('low', RiskMatrixThresholds::classify(5));
        self::assertSame('low', RiskMatrixThresholds::classify(1));
    }

    #[Test]
    public function testClassifyHandlesOutOfRangeScoresGracefully(): void
    {
        // Defensive: classifier should still bucket pathological values.
        self::assertSame('low', RiskMatrixThresholds::classify(0));
        self::assertSame('low', RiskMatrixThresholds::classify(-5));
        self::assertSame('critical', RiskMatrixThresholds::classify(99));
    }

    #[Test]
    public function testGetBandsReturnsContiguousNonOverlappingRanges(): void
    {
        $bands = RiskMatrixThresholds::getBands();

        self::assertSame(['min' => 20, 'max' => 25], $bands['critical']);
        self::assertSame(['min' => 12, 'max' => 19], $bands['high']);
        self::assertSame(['min' => 6, 'max' => 11], $bands['medium']);
        self::assertSame(['min' => 1, 'max' => 5], $bands['low']);

        // Bands must tile [1..25] without gaps or overlaps.
        self::assertSame($bands['low']['max'] + 1, $bands['medium']['min']);
        self::assertSame($bands['medium']['max'] + 1, $bands['high']['min']);
        self::assertSame($bands['high']['max'] + 1, $bands['critical']['min']);
        self::assertSame(RiskMatrixThresholds::SCORE_MAX, $bands['critical']['max']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Kpi;

use App\Service\Kpi\ObjectiveKpiProgress;
use App\Service\Location\LocationMapView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ObjectiveKpiProgressTest extends TestCase
{
    #[Test]
    public function higherIsBetterProgressIsCappedAtHundred(): void
    {
        self::assertSame(80, ObjectiveKpiProgress::progress('location_checks_rate', 72.0, '90'));
        self::assertSame(100, ObjectiveKpiProgress::progress('location_checks_rate', 95.0, 90));
    }

    #[Test]
    public function lowerIsBetterProgressUsesTargetOverCurrent(): void
    {
        self::assertSame(100, ObjectiveKpiProgress::progress('location_open_gaps', 2.0, 3));
        self::assertSame(50, ObjectiveKpiProgress::progress('location_open_gaps', 6.0, 3));
        self::assertSame(0, ObjectiveKpiProgress::progress('location_critical_sites', 2.0, 0));
    }

    #[Test]
    public function noProgressWithoutValueOrNumericTarget(): void
    {
        self::assertNull(ObjectiveKpiProgress::progress('location_checks_rate', null, 90));
        self::assertNull(ObjectiveKpiProgress::progress('location_checks_rate', 50.0, 'alle'));
    }

    #[Test]
    public function leversAreSortedByImpactAcrossSites(): void
    {
        $sites = [
            ['id' => 1, 'name' => 'A', 'levers' => [['title' => 'r1', 'impact' => 10], ['title' => 'r2', 'impact' => 2]]],
            ['id' => 2, 'name' => 'B', 'levers' => [['title' => 'r3', 'impact' => 30]]],
        ];

        $levers = LocationMapView::levers($sites, 2);

        self::assertSame(['r3', 'r1'], array_column($levers, 'title'));
        self::assertSame('B', $levers[0]['siteName']);
    }
}

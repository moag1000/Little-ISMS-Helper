<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Service\Location\LocationLayers;
use App\Service\Location\LocationMapView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Multi-site audit sample (IAF MD 1): √n of the active sites, never audited
 * first, then the longest unaudited, then the higher risk score.
 */
final class LocationAuditSampleTest extends TestCase
{
    #[Test]
    public function picksSquareRootOfActiveSitesByAuditAge(): void
    {
        $site = static fn (int $id, ?int $months, int $score, bool $active = true): array => [
            'id' => $id, 'score' => $score, 'active' => $active,
            'layers' => [LocationLayers::AUDIT => ['months' => $months]],
        ];
        $sample = LocationMapView::auditSample([
            $site(1, 3, 20),
            $site(2, null, 5),
            $site(3, 30, 10),
            $site(4, 30, 15),
            $site(5, null, 1, false),
            $site(6, 2, 1),
        ]);

        self::assertSame(3, $sample['size'], 'ceil(√5) of five active sites');
        self::assertSame([2, 4, 3], $sample['ids']);
    }

    #[Test]
    public function emptyWithoutActiveSites(): void
    {
        self::assertSame(['size' => 0, 'ids' => []], LocationMapView::auditSample([]));
    }

    #[Test]
    public function monthsBetweenNeverNegative(): void
    {
        self::assertSame(14, LocationLayers::monthsBetween(new \DateTimeImmutable('2025-01-15'), new \DateTimeImmutable('2026-03-20')));
        self::assertSame(0, LocationLayers::monthsBetween(new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2026-01-01')));
    }
}

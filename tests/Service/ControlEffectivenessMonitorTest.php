<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use App\Service\ControlEffectivenessMonitor;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ControlEffectivenessMonitor.
 *
 * Covers:
 *   - findOverdueControls: null lastEffectivenessTest and past-threshold dates
 *   - findUpcomingDueControls: nextEffectivenessTest within 30-day window
 *   - calculateSummaryStats: aggregate % breakdown
 */
#[AllowMockObjectsWithoutExpectations]
class ControlEffectivenessMonitorTest extends TestCase
{
    private MockObject $repository;
    private ControlEffectivenessMonitor $monitor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ControlRepository::class);
        $this->monitor    = new ControlEffectivenessMonitor($this->repository);
        $this->tenant     = new Tenant();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function makeControl(bool $applicable, ?DateTimeImmutable $last, ?DateTimeImmutable $next = null): Control
    {
        $control = $this->createMock(Control::class);
        $control->method('isApplicable')->willReturn($applicable);
        $control->method('getLastEffectivenessTest')->willReturn($last);
        $control->method('getNextEffectivenessTest')->willReturn($next);
        return $control;
    }

    // ── findOverdueControls ─────────────────────────────────────────────────

    #[Test]
    public function testFindOverdueControlsIncludesNeverTestedControls(): void
    {
        $never = $this->makeControl(true, null);
        $this->repository->method('findByTenant')->willReturn([$never]);

        $result = $this->monitor->findOverdueControls($this->tenant);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['lastChecked']);
        $this->assertNull($result[0]['overdueDays']);
    }

    #[Test]
    public function testFindOverdueControlsIncludesOldChecks(): void
    {
        $old = $this->makeControl(true, new DateTimeImmutable('-18 months'));
        $this->repository->method('findByTenant')->willReturn([$old]);

        $result = $this->monitor->findOverdueControls($this->tenant, 12);

        $this->assertCount(1, $result);
        $this->assertNotNull($result[0]['overdueDays']);
        $this->assertGreaterThan(0, $result[0]['overdueDays']);
    }

    #[Test]
    public function testFindOverdueControlsExcludesRecentlyChecked(): void
    {
        $recent = $this->makeControl(true, new DateTimeImmutable('-6 months'));
        $this->repository->method('findByTenant')->willReturn([$recent]);

        $result = $this->monitor->findOverdueControls($this->tenant, 12);

        $this->assertCount(0, $result);
    }

    #[Test]
    public function testFindOverdueControlsSkipsNonApplicableControls(): void
    {
        $notApplicable = $this->makeControl(false, null);
        $this->repository->method('findByTenant')->willReturn([$notApplicable]);

        $result = $this->monitor->findOverdueControls($this->tenant);

        $this->assertCount(0, $result, 'Non-applicable controls must be excluded');
    }

    #[Test]
    public function testFindOverdueControlsSortsNeverTestedFirst(): void
    {
        $old   = $this->makeControl(true, new DateTimeImmutable('-24 months'));
        $never = $this->makeControl(true, null);
        $this->repository->method('findByTenant')->willReturn([$old, $never]);

        $result = $this->monitor->findOverdueControls($this->tenant);

        $this->assertCount(2, $result);
        $this->assertNull($result[0]['lastChecked'], 'Never-tested must sort first');
    }

    // ── findUpcomingDueControls ─────────────────────────────────────────────

    #[Test]
    public function testFindUpcomingDueControlsDetectsDueSoonControls(): void
    {
        $soon = $this->makeControl(true, null, new DateTimeImmutable('+15 days'));
        $this->repository->method('findByTenant')->willReturn([$soon]);

        $result = $this->monitor->findUpcomingDueControls($this->tenant, 30);

        $this->assertCount(1, $result);
        $this->assertLessThanOrEqual(30, $result[0]['daysUntilDue']);
        $this->assertGreaterThanOrEqual(0, $result[0]['daysUntilDue']);
    }

    #[Test]
    public function testFindUpcomingDueControlsExcludesFarFuture(): void
    {
        $far = $this->makeControl(true, null, new DateTimeImmutable('+90 days'));
        $this->repository->method('findByTenant')->willReturn([$far]);

        $result = $this->monitor->findUpcomingDueControls($this->tenant, 30);

        $this->assertCount(0, $result);
    }

    #[Test]
    public function testFindUpcomingDueControlsExcludesOverdue(): void
    {
        $overdue = $this->makeControl(true, null, new DateTimeImmutable('-5 days'));
        $this->repository->method('findByTenant')->willReturn([$overdue]);

        $result = $this->monitor->findUpcomingDueControls($this->tenant, 30);

        $this->assertCount(0, $result, 'Past-due nextEffectivenessTest is not "upcoming"');
    }

    // ── calculateSummaryStats ───────────────────────────────────────────────

    #[Test]
    public function testCalculateSummaryStatsReturnsZeroForEmptyTenant(): void
    {
        $this->repository->method('findByTenant')->willReturn([]);

        $stats = $this->monitor->calculateSummaryStats($this->tenant);

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0.0, $stats['overduePct']);
        $this->assertSame(0.0, $stats['neverCheckedPct']);
    }

    #[Test]
    public function testCalculateSummaryStatsCountsNeverCheckedProperly(): void
    {
        $never   = $this->makeControl(true, null);
        $checked = $this->makeControl(true, new DateTimeImmutable('-6 months'));
        $notApp  = $this->makeControl(false, null);
        $this->repository->method('findByTenant')->willReturn([$never, $checked, $notApp]);

        $stats = $this->monitor->calculateSummaryStats($this->tenant);

        $this->assertSame(2, $stats['total'], 'Only applicable controls count');
        $this->assertSame(1, $stats['neverChecked']);
        $this->assertSame(50.0, $stats['neverCheckedPct']);
    }

    #[Test]
    public function testCalculateSummaryStatsOverduePctReflectsThreshold(): void
    {
        $old1 = $this->makeControl(true, new DateTimeImmutable('-18 months'));
        $old2 = $this->makeControl(true, new DateTimeImmutable('-15 months'));
        $ok   = $this->makeControl(true, new DateTimeImmutable('-3 months'));
        $this->repository->method('findByTenant')->willReturn([$old1, $old2, $ok]);

        $stats = $this->monitor->calculateSummaryStats($this->tenant);

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['overdue']);
        $this->assertEqualsWithDelta(66.7, $stats['overduePct'], 0.2);
    }
}

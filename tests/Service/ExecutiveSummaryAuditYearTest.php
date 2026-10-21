<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InternalAudit;
use App\Repository\AssetRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ControlRepository;
use App\Repository\DataBreachRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\TrainingRepository;
use App\Service\Audit\AuditProgramFigures;
use App\Service\DashboardStatisticsService;
use App\Service\ManagementReportService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Completed this year" counts audits of the current calendar year only
 * (planned-date window shared with the audit KPI), not every completed audit.
 */
final class ExecutiveSummaryAuditYearTest extends TestCase
{
    private function audit(string $planned, string $status, ?string $actual = null): InternalAudit
    {
        $audit = new InternalAudit();
        $audit->setPlannedDate(new \DateTimeImmutable($planned));
        $audit->setStatus($status);
        if ($actual !== null) {
            $audit->setActualDate(new \DateTimeImmutable($actual));
        }

        return $audit;
    }

    #[Test]
    public function yearBoundaryFollowsTheInjectedClock(): void
    {
        $audits = [
            $this->audit('2025-12-31', 'completed'),
            $this->audit('2026-01-01', 'completed'),
            $this->audit('2026-12-31', 'planned'),
        ];

        $dec = AuditProgramFigures::calendarYear($audits, new \DateTimeImmutable('2025-12-31 23:59:59'));
        self::assertSame(['planned_audits' => 1, 'completed_audits' => 1], $dec);

        $jan = AuditProgramFigures::calendarYear($audits, new \DateTimeImmutable('2026-01-01 00:00:00'));
        self::assertSame(['planned_audits' => 2, 'completed_audits' => 1], $jan);
    }

    #[Test]
    public function completedInYearUsesTheCompletionDateAroundTheYearBoundary(): void
    {
        $audits = [
            $this->audit('2025-11-01', 'completed', '2026-01-02'), // planned last year, done this year
            $this->audit('2026-02-01', 'completed', '2025-12-31'), // planned this year, done last year
            $this->audit('2026-03-01', 'completed'),               // no actual date: planned date
            $this->audit('2026-04-01', 'planned', '2026-04-02'),   // not completed
        ];

        self::assertSame(2, AuditProgramFigures::completedInYear($audits, new \DateTimeImmutable('2026-06-01')));
        self::assertSame(1, AuditProgramFigures::completedInYear($audits, new \DateTimeImmutable('2025-12-31')));
    }

    #[Test]
    public function calendarYearCompletedFigureUsesTheSameCompletionDateRule(): void
    {
        $audits = [
            $this->audit('2025-11-01', 'completed', '2026-01-02'), // planned last year, done this year
            $this->audit('2026-02-01', 'completed', '2025-12-31'), // planned this year, done last year
            $this->audit('2026-03-01', 'planned'),
        ];
        $now = new \DateTimeImmutable('2026-06-01');

        $figures = AuditProgramFigures::calendarYear($audits, $now);

        self::assertSame(AuditProgramFigures::completedInYear($audits, $now), $figures['completed_audits']);
        self::assertSame(1, $figures['completed_audits']);
        self::assertSame(2, $figures['planned_audits'], 'planned stays on the planned date');
    }

    #[Test]
    public function executiveSummaryCountsOnlyCompletedAuditsOfThisYear(): void
    {
        $year = (int) (new \DateTimeImmutable())->format('Y');
        $dashboard = $this->createMock(DashboardStatisticsService::class);
        $dashboard->method('auditsFor')->willReturn([
            $this->audit(($year - 1) . '-06-01', 'completed'),
            $this->audit(($year - 2) . '-06-01', 'completed'),
            $this->audit($year . '-03-01', 'completed'),
            $this->audit($year . '-04-01', 'planned'),
        ]);

        $service = new ManagementReportService(
            $this->createStub(AssetRepository::class),
            $this->createStub(RiskRepository::class),
            $this->createStub(ControlRepository::class),
            $this->createStub(IncidentRepository::class),
            $this->createStub(TrainingRepository::class),
            $this->createStub(BusinessContinuityPlanRepository::class),
            $this->createStub(BCExerciseRepository::class),
            $this->createStub(BusinessProcessRepository::class),
            $this->createStub(ComplianceFrameworkRepository::class),
            $this->createStub(DataBreachRepository::class),
            $this->createStub(RiskTreatmentPlanRepository::class),
            $dashboard,
            $this->createStub(TranslatorInterface::class),
        );

        $summary = $service->getExecutiveSummary();

        self::assertSame(1, $summary['audit_summary']['completed_this_year']);
        self::assertSame(4, $summary['audit_summary']['total_audits']);
    }
}

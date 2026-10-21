<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditFinding;
use App\Entity\InternalAudit;
use App\Enum\AuditFindingStatus;
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
use App\Service\DashboardStatisticsService;
use App\Service\ManagementReportService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The management-review auto-collector writes the nonconformity status from
 * audit_data['total_findings'] / ['open_findings'] — keys the audit report
 * never produced, so the narrative always said "0 findings". The report now
 * carries them with the audit-KPI definition: open = not done
 * (AuditFindingStatus::isDone()), across all audits of every year.
 */
final class ManagementReportAuditFindingCountsTest extends TestCase
{
    #[Test]
    public function auditReportCarriesTotalAndOpenFindingCounts(): void
    {
        $lastYear = new InternalAudit();
        $lastYear->setPlannedDate(new \DateTimeImmutable('-2 years'));
        $thisYear = new InternalAudit();
        $thisYear->setPlannedDate(new \DateTimeImmutable());
        foreach ([AuditFindingStatus::Open, AuditFindingStatus::Closed] as $status) {
            $lastYear->addStructuredFinding((new AuditFinding())->setStatus($status));
        }
        foreach ([AuditFindingStatus::InProgress, AuditFindingStatus::Verified, AuditFindingStatus::Resolved] as $status) {
            $thisYear->addStructuredFinding((new AuditFinding())->setStatus($status));
        }

        $tenant = new \App\Entity\Tenant();
        $dashboard = $this->createMock(DashboardStatisticsService::class);
        $dashboard->expects(self::once())->method('auditsFor')->with($tenant)->willReturn([$lastYear, $thisYear]);
        $dashboard->method('managementReviewsFor')->willReturn([]);

        $report = $this->service($dashboard)->getAuditManagementReport($tenant);

        self::assertSame(5, $report['total_findings']);
        self::assertSame(2, $report['open_findings'], 'open + in_progress, all years');
        // Review M-4: the activity count is named for what it is; "this year"
        // (planned date) lives in planned_audits / completed_audits.
        self::assertArrayHasKey('with_activity_this_year', $report['audits']);
        self::assertArrayNotHasKey('this_year', $report['audits']);
        self::assertSame(1, $report['planned_audits']);
    }

    private function service(DashboardStatisticsService $dashboard): ManagementReportService
    {
        return new ManagementReportService(
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
    }
}

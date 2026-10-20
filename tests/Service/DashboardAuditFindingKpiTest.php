<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditFinding;
use App\Entity\InternalAudit;
use App\Enum\AuditFindingStatus;
use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\RiskRepository;
use App\Service\DashboardStatisticsService;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * "Open audit findings" on the dashboard used the deprecated free-text
 * InternalAudit::getFindings() and counted everything but `closed` as open.
 * It now counts structured AuditFinding records that are not done
 * (AuditFindingStatus::isDone(): resolved / verified / closed).
 *
 * Open findings are a backlog metric: they count regardless of the year
 * the audit was planned in, while the sibling "completed (YTD)" and
 * "planned (this year)" KPIs keep the calendar-year window.
 */
final class DashboardAuditFindingKpiTest extends TestCase
{
    #[Test]
    public function openFindingsExcludeResolvedVerifiedAndClosed(): void
    {
        $audit = new InternalAudit();
        $audit->setPlannedDate(new \DateTimeImmutable());
        foreach (AuditFindingStatus::cases() as $status) {
            $finding = new AuditFinding();
            $finding->setStatus($status);
            $audit->addStructuredFinding($finding);
        }

        $audits = $this->createStub(InternalAuditRepository::class);
        $audits->method('findAll')->willReturn([$audit]);

        $service = new DashboardStatisticsService(
            $this->createStub(AssetRepository::class),
            $this->createStub(RiskRepository::class),
            $this->createStub(IncidentRepository::class),
            $this->createStub(ControlRepository::class),
            $this->createStub(Security::class),
            $this->createStub(ModuleConfigurationService::class),
            auditRepository: $audits,
        );

        $kpis = (new \ReflectionMethod($service, 'getAuditKPIs'))->invoke($service);

        // open + in_progress
        self::assertSame(2, $kpis['open_audit_findings']['value']);
    }

    #[Test]
    public function openFindingsOfEarlierYearsStayInTheBacklog(): void
    {
        $lastYear = new InternalAudit();
        $lastYear->setPlannedDate(new \DateTimeImmutable('-1 year'));
        $lastYear->setStatus('completed');
        $open = new AuditFinding();
        $open->setStatus(AuditFindingStatus::Open);
        $lastYear->addStructuredFinding($open);

        $undated = new InternalAudit();
        $inProgress = new AuditFinding();
        $inProgress->setStatus(AuditFindingStatus::InProgress);
        $undated->addStructuredFinding($inProgress);

        $thisYear = new InternalAudit();
        $thisYear->setPlannedDate(new \DateTimeImmutable());
        $thisYear->setStatus('completed');

        $kpis = $this->auditKpis([$lastYear, $undated, $thisYear]);

        self::assertSame(2, $kpis['open_audit_findings']['value'], 'backlog: all years');
        self::assertSame(1, $kpis['planned_audits']['value'], 'window: this year only');
        self::assertSame(1, $kpis['audits_completed_ytd']['value'], 'window: this year only');
    }

    #[Test]
    public function explicitTenantScopesTheAudits(): void
    {
        $tenant = new \App\Entity\Tenant();
        $audits = $this->createMock(InternalAuditRepository::class);
        $audits->expects(self::once())->method('findBy')->with(['tenant' => $tenant])->willReturn([]);
        $audits->expects(self::never())->method('findAll');

        $kpis = (new \ReflectionMethod($this->service($audits), 'getAuditKPIs'))->invoke($this->service($audits), $tenant);

        self::assertSame(0, $kpis['open_audit_findings']['value']);
    }

    /**
     * @param list<InternalAudit> $list
     * @return array<string, array{value: int}>
     */
    private function auditKpis(array $list): array
    {
        $audits = $this->createStub(InternalAuditRepository::class);
        $audits->method('findAll')->willReturn($list);
        $service = $this->service($audits);

        return (new \ReflectionMethod($service, 'getAuditKPIs'))->invoke($service);
    }

    private function service(InternalAuditRepository $audits): DashboardStatisticsService
    {
        return new DashboardStatisticsService(
            $this->createStub(AssetRepository::class),
            $this->createStub(RiskRepository::class),
            $this->createStub(IncidentRepository::class),
            $this->createStub(ControlRepository::class),
            $this->createStub(Security::class),
            $this->createStub(ModuleConfigurationService::class),
            auditRepository: $audits,
        );
    }
}

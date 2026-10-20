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
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Entity\AuditFinding;
use App\Entity\InternalAudit;
use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ConsentRepository;
use App\Repository\ControlRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckRegistry;
use App\Service\ComplianceWizard\CoverageCheckService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression: checkAuditStatus() ran count()/foreach on
 * InternalAudit::getFindings() — the deprecated free-text ?string field — so
 * any audit with legacy findings text threw a TypeError, and the closed check
 * (`$f['status'] ?? '' === 'closed'`) had broken precedence. It now reads the
 * AuditFinding entities.
 */
#[AllowMockObjectsWithoutExpectations]
final class CoverageCheckServiceAuditStatusTest extends TestCase
{
    /**
     * @param list<InternalAudit> $audits
     */
    private function buildService(array $audits): CoverageCheckService
    {
        $auditRepo = $this->createMock(InternalAuditRepository::class);
        $auditRepo->method('findAll')->willReturn($audits);

        return new CoverageCheckService(
            $this->createMock(ControlRepository::class),
            $this->createMock(RiskRepository::class),
            $this->createMock(AssetRepository::class),
            $this->createMock(IncidentRepository::class),
            $this->createMock(BusinessProcessRepository::class),
            $this->createMock(BusinessContinuityPlanRepository::class),
            $auditRepo,
            $this->createMock(TrainingRepository::class),
            $this->createMock(RiskTreatmentPlanRepository::class),
            $this->createMock(SupplierRepository::class),
            $this->createMock(ConsentRepository::class),
            $this->createMock(DataSubjectRequestRepository::class),
            $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(ComplianceFrameworkRepository::class),
            $this->createMock(ComplianceRequirementFulfillmentRepository::class),
            new PolicyWizardCheckRegistry([]),
            $this->createMock(ISMSContextRepository::class),
            $this->createMock(InterestedPartyRepository::class),
            $this->createMock(ISMSObjectiveRepository::class),
            $this->createMock(ManagementReviewRepository::class),
            $this->createMock(DocumentRepository::class),
            $this->createMock(VulnerabilityRepository::class),
            null,
        );
    }

    private function finding(string $status, string $severity, string $title): AuditFinding
    {
        $finding = new AuditFinding();
        $finding->setTitle($title);
        $finding->setStatus($status);
        $finding->setSeverity($severity);

        return $finding;
    }

    #[Test]
    public function countsStructuredFindingsAndIgnoresLegacyText(): void
    {
        $audit = new InternalAudit();
        $audit->setTitle('ISMS audit');
        $audit->setStatus('completed');
        $audit->setFindings('Legacy free-text findings from before v3.6');
        $audit->addStructuredFinding($this->finding(AuditFinding::STATUS_CLOSED, AuditFinding::SEVERITY_HIGH, 'Done'));
        $audit->addStructuredFinding($this->finding(AuditFinding::STATUS_OPEN, AuditFinding::SEVERITY_CRITICAL, 'Open critical'));

        $result = $this->buildService([$audit])->checkAuditStatus([], null);

        // completion 100 % * 0.5 + closure 50 % * 0.5
        self::assertSame(75.0, $result['score']);
        self::assertNotNull($result['gap']);
        self::assertSame(1, $result['gap']['description_params']['%count%']);
    }

    #[Test]
    public function auditWithoutFindingsHasNoGap(): void
    {
        $audit = new InternalAudit();
        $audit->setTitle('Clean audit');
        $audit->setStatus('completed');

        $result = $this->buildService([$audit])->checkAuditStatus([], null);

        self::assertSame(100.0, $result['score']);
        self::assertNull($result['gap']);
    }
}

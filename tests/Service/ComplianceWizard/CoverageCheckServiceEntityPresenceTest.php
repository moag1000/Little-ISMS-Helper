<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Entity\Tenant;
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
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckRegistry;
use App\Service\ComplianceWizard\CoverageCheckService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the entity_presence check — the fix for the wizard reporting
 * filled-in clauses (Kontext, Interessierte Parteien …) as false critical gaps.
 */
#[AllowMockObjectsWithoutExpectations]
final class CoverageCheckServiceEntityPresenceTest extends TestCase
{
    private ISMSContextRepository $ismsContextRepo;
    private InterestedPartyRepository $interestedPartyRepo;

    private function buildService(): CoverageCheckService
    {
        $this->ismsContextRepo = $this->createMock(ISMSContextRepository::class);
        $this->interestedPartyRepo = $this->createMock(InterestedPartyRepository::class);

        return new CoverageCheckService(
            $this->createMock(ControlRepository::class),
            $this->createMock(RiskRepository::class),
            $this->createMock(AssetRepository::class),
            $this->createMock(IncidentRepository::class),
            $this->createMock(BusinessProcessRepository::class),
            $this->createMock(BusinessContinuityPlanRepository::class),
            $this->createMock(InternalAuditRepository::class),
            $this->createMock(TrainingRepository::class),
            $this->createMock(RiskTreatmentPlanRepository::class),
            $this->createMock(SupplierRepository::class),
            $this->createMock(ConsentRepository::class),
            $this->createMock(DataSubjectRequestRepository::class),
            $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(ComplianceFrameworkRepository::class),
            $this->createMock(ComplianceRequirementFulfillmentRepository::class),
            new PolicyWizardCheckRegistry([]),
            $this->ismsContextRepo,
            $this->interestedPartyRepo,
            $this->createMock(ISMSObjectiveRepository::class),
            $this->createMock(ManagementReviewRepository::class),
            $this->createMock(DocumentRepository::class),
            null,
        );
    }

    #[Test]
    public function populatedContextScores100AndHasNoGap(): void
    {
        $service = $this->buildService();
        $this->ismsContextRepo->method('count')->willReturn(2);

        $result = $service->checkEntityPresence(
            ['entity' => 'isms_context', 'name' => 'wizard.check.iso_4_1_organization'],
            $this->createMock(Tenant::class),
        );

        self::assertSame(100.0, $result['score']);
        self::assertNull($result['gap']);
        self::assertSame(2, $result['details']['count']);
    }

    #[Test]
    public function emptyContextScores0AndYieldsGap(): void
    {
        $service = $this->buildService();
        $this->ismsContextRepo->method('count')->willReturn(0);

        $result = $service->checkEntityPresence(
            ['entity' => 'isms_context', 'name' => 'wizard.check.iso_4_1_organization', 'priority' => 'critical'],
            $this->createMock(Tenant::class),
        );

        self::assertSame(0.0, $result['score']);
        self::assertNotNull($result['gap']);
        self::assertSame('critical', $result['gap']['priority']);
    }

    #[Test]
    public function nullTenantScores0WithoutHittingRepository(): void
    {
        $service = $this->buildService();
        $this->ismsContextRepo->expects(self::never())->method('count');

        $result = $service->checkEntityPresence(['entity' => 'isms_context'], null);

        self::assertSame(0.0, $result['score']);
        self::assertSame(0, $result['details']['count']);
    }

    #[Test]
    public function unknownEntityKeyScores0(): void
    {
        $service = $this->buildService();

        $result = $service->checkEntityPresence(['entity' => 'does_not_exist'], $this->createMock(Tenant::class));

        self::assertSame(0.0, $result['score']);
    }
}

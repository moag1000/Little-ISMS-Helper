<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\DocumentRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\SupplierRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\ComplianceWizard\EntityPresenceChecker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the entity_presence check — the fix for the wizard reporting
 * filled-in clauses (Kontext, Interessierte Parteien …) as false critical gaps.
 */
#[AllowMockObjectsWithoutExpectations]
final class EntityPresenceCheckerTest extends TestCase
{
    private ISMSContextRepository $ismsContextRepo;
    private InterestedPartyRepository $interestedPartyRepo;
    private BusinessContinuityPlanRepository $bcPlanRepo;
    private VulnerabilityRepository $vulnerabilityRepo;

    private function buildService(): EntityPresenceChecker
    {
        $this->ismsContextRepo = $this->createMock(ISMSContextRepository::class);
        $this->interestedPartyRepo = $this->createMock(InterestedPartyRepository::class);
        $this->bcPlanRepo = $this->createMock(BusinessContinuityPlanRepository::class);
        $this->vulnerabilityRepo = $this->createMock(VulnerabilityRepository::class);

        return new EntityPresenceChecker(
            $this->ismsContextRepo,
            $this->interestedPartyRepo,
            $this->createMock(ISMSObjectiveRepository::class),
            $this->createMock(ManagementReviewRepository::class),
            $this->createMock(ProcessingActivityRepository::class),
            $this->createMock(SupplierRepository::class),
            $this->createMock(AssetRepository::class),
            $this->createMock(DocumentRepository::class),
            $this->bcPlanRepo,
            $this->vulnerabilityRepo,
        );
    }

    #[Test]
    public function populatedContextScores100AndHasNoGap(): void
    {
        $service = $this->buildService();
        $this->ismsContextRepo->method('count')->willReturn(2);

        $result = $service->check(
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

        $result = $service->check(
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

        $result = $service->check(['entity' => 'isms_context'], null);

        self::assertSame(0.0, $result['score']);
        self::assertSame(0, $result['details']['count']);
    }

    #[Test]
    public function unknownEntityKeyScores0(): void
    {
        $service = $this->buildService();

        $result = $service->check(['entity' => 'does_not_exist'], $this->createMock(Tenant::class));

        self::assertSame(0.0, $result['score']);
    }

    #[Test]
    public function bcPlanAndVulnerabilityRegistryKeysResolve(): void
    {
        $service = $this->buildService();
        $this->bcPlanRepo->method('count')->willReturn(1);
        $this->vulnerabilityRepo->method('count')->willReturn(0);
        $tenant = $this->createMock(Tenant::class);

        $bc = $service->check(['entity' => 'bc_plan'], $tenant);
        self::assertSame(100.0, $bc['score']);
        self::assertNull($bc['gap']);

        $vuln = $service->check(['entity' => 'vulnerability', 'name' => 'wizard.check.cra_vuln_handling'], $tenant);
        self::assertSame(0.0, $vuln['score']);
        self::assertNotNull($vuln['gap']);
    }
}

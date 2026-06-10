<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Enum\AbsicherungsStufe;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\BsiGrundschutzCheckService;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * WS-1 review fix: unit tests for BsiGrundschutzCheckService.
 *
 * Covers:
 *  - Tenant-scoped fulfillment: getCheckReport() with an explicit Tenant uses
 *    ComplianceRequirementFulfillmentRepository, NOT calculateFulfillmentFromControls().
 *  - No-tenant fallback: when no tenant is provided, falls back to mappedControls.
 *  - tiersForLevel filter: 'standard' level includes basis+standard requirements.
 *  - 'kern' level filter: includes basis+standard+hoch requirements.
 */
#[AllowMockObjectsWithoutExpectations]
final class BsiGrundschutzCheckServiceTest extends TestCase
{
    /** @var MockObject&ComplianceFrameworkRepository */
    private MockObject $frameworkRepo;

    /** @var MockObject&ComplianceRequirementRepository */
    private MockObject $requirementRepo;

    /** @var MockObject&ComplianceRequirementFulfillmentRepository */
    private MockObject $fulfillmentRepo;

    /** @var MockObject&TenantContext */
    private MockObject $tenantContext;

    private BsiGrundschutzCheckService $service;

    protected function setUp(): void
    {
        $this->frameworkRepo    = $this->createMock(ComplianceFrameworkRepository::class);
        $this->requirementRepo  = $this->createMock(ComplianceRequirementRepository::class);
        $this->fulfillmentRepo  = $this->createMock(ComplianceRequirementFulfillmentRepository::class);
        $this->tenantContext    = $this->createMock(TenantContext::class);

        $this->service = new BsiGrundschutzCheckService(
            $this->frameworkRepo,
            $this->requirementRepo,
            $this->fulfillmentRepo,
            $this->tenantContext,
        );
    }

    // ── helper factory methods ────────────────────────────────────────────────

    private function makeFramework(): ComplianceFramework
    {
        $fw = new ComplianceFramework();
        $fw->setCode('BSI_GRUNDSCHUTZ');
        $fw->setName('BSI IT-Grundschutz');
        $fw->setApplicableIndustry('all');
        $fw->setRegulatoryBody('BSI');
        $fw->setMandatory(false);
        return $fw;
    }

    private function makeTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Test Org');
        // Set id via reflection (no public setter)
        $rp = new ReflectionProperty(Tenant::class, 'id');
        $rp->setValue($tenant, $id);
        return $tenant;
    }

    /**
     * Build a ComplianceRequirement with a fixed entity-id (needed for fulfillmentMap lookup).
     */
    private function makeRequirement(int $entityId, string $requirementId, string $absicherungsStufe): ComplianceRequirement
    {
        $req = new ComplianceRequirement();
        $req->setRequirementId($requirementId);
        $req->setTitle('Test: ' . $requirementId);
        $req->setDescription('MUSS gesichert werden.');
        $req->setCategory(explode('.A', $requirementId)[0] . ' Sicherheitsmanagement');
        $req->setPriority('critical');
        $req->setAbsicherungsStufe($absicherungsStufe);

        $rp = new ReflectionProperty(ComplianceRequirement::class, 'id');
        $rp->setValue($req, $entityId);

        return $req;
    }

    private function makeFulfillment(ComplianceRequirement $req, int $pct): ComplianceRequirementFulfillment
    {
        $f = new ComplianceRequirementFulfillment();
        $f->setRequirement($req);
        $f->setFulfillmentPercentage($pct);
        return $f;
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    #[Test]
    public function noFrameworkReturnsEmptyReport(): void
    {
        $this->frameworkRepo->method('findOneBy')->willReturn(null);
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $report = $this->service->getCheckReport();

        self::assertSame('BSI_GRUNDSCHUTZ', $report['framework']['code']);
        self::assertSame(0, $report['overall']['total']);
        self::assertSame([], $report['bausteine']);
    }

    #[Test]
    public function tenantScopedFulfillmentIsUsedWhenTenantProvided(): void
    {
        $fw     = $this->makeFramework();
        $tenant = $this->makeTenant(42);

        $req1 = $this->makeRequirement(1, 'ISMS.1.A1', 'basis');
        $req2 = $this->makeRequirement(2, 'ISMS.1.A2', 'standard');

        $fulfillment1 = $this->makeFulfillment($req1, 90); // above threshold
        $fulfillment2 = $this->makeFulfillment($req2, 50); // below threshold

        $this->frameworkRepo->method('findOneBy')->willReturn($fw);
        $this->requirementRepo->method('findByFramework')->willReturn([$req1, $req2]);

        // Fulfillment repo MUST be called with the tenant (canonical multi-tenant source)
        $this->fulfillmentRepo
            ->expects($this->once())
            ->method('findByFrameworkAndTenant')
            ->with($fw, $tenant)
            ->willReturn([$fulfillment1, $fulfillment2]);

        // TenantContext NOT consulted when tenant is passed explicitly
        $this->tenantContext->expects($this->never())->method('getCurrentTenant');

        $report = $this->service->getCheckReport(null, $tenant);

        self::assertSame(2, $report['overall']['total']);
        // req1 at 90% (fulfilled), req2 at 50% (not fulfilled)
        self::assertSame(1, $report['overall']['fulfilled']);
    }

    #[Test]
    public function tenantContextUsedWhenNoExplicitTenantPassed(): void
    {
        $fw     = $this->makeFramework();
        $tenant = $this->makeTenant(7);
        $req    = $this->makeRequirement(1, 'ISMS.1.A1', 'basis');
        $f      = $this->makeFulfillment($req, 100);

        $this->frameworkRepo->method('findOneBy')->willReturn($fw);
        $this->requirementRepo->method('findByFramework')->willReturn([$req]);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->fulfillmentRepo
            ->expects($this->once())
            ->method('findByFrameworkAndTenant')
            ->with($fw, $tenant)
            ->willReturn([$f]);

        $report = $this->service->getCheckReport();

        self::assertSame(1, $report['overall']['total']);
        self::assertSame(1, $report['overall']['fulfilled']);
    }

    #[Test]
    public function noTenantFallsBackToMappedControls(): void
    {
        $fw  = $this->makeFramework();
        $req = $this->makeRequirement(1, 'ISMS.1.A1', 'basis');
        // calculateFulfillmentFromControls() returns 0 when no controls are mapped (default)

        $this->frameworkRepo->method('findOneBy')->willReturn($fw);
        $this->requirementRepo->method('findByFramework')->willReturn([$req]);
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        // fulfillmentRepo MUST NOT be called when no tenant is available
        $this->fulfillmentRepo
            ->expects($this->never())
            ->method('findByFrameworkAndTenant');

        $report = $this->service->getCheckReport(null, null);

        self::assertSame(1, $report['overall']['total']);
        // calculateFulfillmentFromControls() returns 0 (no controls mapped)
        self::assertSame(0, $report['overall']['fulfilled']);
    }

    #[Test]
    public function standardLevelFilterIncludesBasisAndStandard(): void
    {
        $fw = $this->makeFramework();

        $reqBasis    = $this->makeRequirement(1, 'ISMS.1.A1', 'basis');
        $reqStandard = $this->makeRequirement(2, 'ISMS.1.A2', 'standard');
        $reqHoch     = $this->makeRequirement(3, 'ISMS.1.A3', 'hoch');

        $this->frameworkRepo->method('findOneBy')->willReturn($fw);
        $this->requirementRepo->method('findByFramework')->willReturn([$reqBasis, $reqStandard, $reqHoch]);
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);
        $this->fulfillmentRepo->method('findByFrameworkAndTenant')->willReturn([]);

        // 'standard' level is cumulative: must include basis + standard, but NOT hoch
        $report = $this->service->getCheckReport('standard');

        self::assertSame(2, $report['overall']['total']);
        self::assertArrayHasKey('filter', $report);
        self::assertSame('standard', $report['filter']['absicherungsStufe']);
    }

    #[Test]
    public function kernLevelFilterIncludesAllThreeTiers(): void
    {
        $fw = $this->makeFramework();

        $reqBasis    = $this->makeRequirement(1, 'ISMS.1.A1', 'basis');
        $reqStandard = $this->makeRequirement(2, 'ISMS.1.A2', 'standard');
        $reqHoch     = $this->makeRequirement(3, 'ISMS.1.A3', 'hoch');

        $this->frameworkRepo->method('findOneBy')->willReturn($fw);
        $this->requirementRepo->method('findByFramework')->willReturn([$reqBasis, $reqStandard, $reqHoch]);
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);
        $this->fulfillmentRepo->method('findByFrameworkAndTenant')->willReturn([]);

        // 'kern' level is cumulative: covers basis + standard + hoch (all three)
        $report = $this->service->getCheckReport('kern');

        self::assertSame(3, $report['overall']['total']);
    }

    #[Test]
    public function basisLevelFilterIncludesOnlyBasis(): void
    {
        $fw = $this->makeFramework();

        $reqBasis    = $this->makeRequirement(1, 'ISMS.1.A1', 'basis');
        $reqStandard = $this->makeRequirement(2, 'ISMS.1.A2', 'standard');

        $this->frameworkRepo->method('findOneBy')->willReturn($fw);
        $this->requirementRepo->method('findByFramework')->willReturn([$reqBasis, $reqStandard]);
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);
        $this->fulfillmentRepo->method('findByFrameworkAndTenant')->willReturn([]);

        $report = $this->service->getCheckReport('basis');

        self::assertSame(1, $report['overall']['total']);
    }

    #[Test]
    public function tiersForLevelEncodesCorrectCumulativeSemantics(): void
    {
        self::assertSame(['basis'], AbsicherungsStufe::tiersForLevel('basis'));
        self::assertSame(['basis', 'standard'], AbsicherungsStufe::tiersForLevel('standard'));
        self::assertSame(['basis', 'standard', 'hoch'], AbsicherungsStufe::tiersForLevel('kern'));
    }
}

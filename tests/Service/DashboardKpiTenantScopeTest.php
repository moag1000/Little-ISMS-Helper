<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;
use App\Service\DashboardStatisticsService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use App\Service\TenantRecords;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * KPIs follow TenantContext exactly like the record pages: a SUPER_ADMIN
 * without an organisation gets the context (default) tenant, never a hidden
 * instance-wide aggregate; with no tenant at all the KPIs fail closed.
 */
final class DashboardKpiTenantScopeTest extends TestCase
{
    private function service(?Tenant $userTenant, ?Tenant $contextTenant, ?RiskRepository $risks = null): DashboardStatisticsService
    {
        $user = $this->createStub(User::class);
        $user->method('getTenant')->willReturn($userTenant);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        // A super admin: must make no difference any more.
        $security->method('isGranted')->willReturn(true);
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($contextTenant);
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('getActiveModules')->willReturn([]);

        return new DashboardStatisticsService(
            $this->createStub(AssetRepository::class),
            $risks ?? $this->createStub(RiskRepository::class),
            $this->createStub(IncidentRepository::class),
            $this->createStub(ControlRepository::class),
            $security,
            $modules,
            tenantRecords: new TenantRecords($context),
        );
    }

    #[Test]
    public function superAdminWithoutOrganisationAndWithoutTenantContextGetsNoInstanceWideKpis(): void
    {
        $risks = $this->createMock(RiskRepository::class);
        $risks->expects(self::never())->method('findAll');

        self::assertSame([], $this->service(null, null, $risks)->getManagementKPIs());
    }

    #[Test]
    public function superAdminWithoutOrganisationFollowsTheTenantContextTenant(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn(7);
        $tenant->method('getParent')->willReturn(null);
        $tenant->method('getSubsidiaries')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $kpis = $this->service(null, $tenant)->getManagementKPIs();

        self::assertArrayHasKey('core', $kpis, 'scoped to the context tenant, not refused');
    }

    private function doraService(?Tenant $tenant, BCExerciseRepository $exercises, SupplierRepository $suppliers): DashboardStatisticsService
    {
        $user = $this->createStub(User::class);
        $user->method('getTenant')->willReturn($tenant);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn(null);
        $modules = $this->createStub(ModuleConfigurationService::class);

        return new DashboardStatisticsService(
            $this->createStub(AssetRepository::class),
            $this->createStub(RiskRepository::class),
            $this->createStub(IncidentRepository::class),
            $this->createStub(ControlRepository::class),
            $security,
            $modules,
            supplierRepository: $suppliers,
            bcExerciseRepository: $exercises,
            tenantRecords: new TenantRecords($context),
        );
    }

    #[Test]
    public function doraResilienceAndThirdPartyBlocksReadOnlyTheTenantsRecords(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getParent')->willReturn(null);
        $tenant->method('getSubsidiaries')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $exercise = $this->createStub(\App\Entity\BCExercise::class);
        $exercise->method('getExerciseDate')->willReturn(new \DateTime());
        $exercises = $this->createMock(BCExerciseRepository::class);
        $exercises->expects(self::once())->method('findBy')->with(['tenant' => $tenant])->willReturn([$exercise]);
        $exercises->expects(self::never())->method('findAll');
        $suppliers = $this->createMock(SupplierRepository::class);
        $suppliers->expects(self::once())->method('findBy')->with(['tenant' => $tenant])->willReturn([]);
        $suppliers->expects(self::never())->method('findAll');

        $dora = $this->doraService($tenant, $exercises, $suppliers)->getDoraKPIs();

        self::assertSame(1, $dora['resilience_testing']['resilience_tests_ytd']['value']);
        self::assertSame(0, $dora['third_party']['ict_providers']['value']);
    }

    #[Test]
    public function doraBlocksFailClosedWithoutAnyTenant(): void
    {
        $exercises = $this->createMock(BCExerciseRepository::class);
        $exercises->expects(self::never())->method('findAll');
        $exercises->expects(self::never())->method('findBy');
        $suppliers = $this->createMock(SupplierRepository::class);
        $suppliers->expects(self::never())->method('findAll');
        $suppliers->expects(self::never())->method('findBy');

        $dora = $this->doraService(null, $exercises, $suppliers)->getDoraKPIs();

        self::assertSame(0, $dora['resilience_testing']['resilience_tests_ytd']['value']);
        self::assertSame(0, $dora['third_party']['ict_providers']['value']);
        self::assertSame(0, $dora['ict_risk']['ict_risks_total']['value']);
    }

    #[Test]
    public function dashboardStatisticsAndHealthNeverReadUnscopedWithoutTenant(): void
    {
        $risks = $this->createMock(RiskRepository::class);
        $risks->expects(self::never())->method('findAll');
        $incidents = $this->createMock(IncidentRepository::class);
        $incidents->expects(self::never())->method('findAll');
        $assets = $this->createMock(AssetRepository::class);
        $assets->expects(self::never())->method('findAll');
        $user = $this->createStub(User::class);
        $user->method('getTenant')->willReturn(null);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn(null);
        $service = new DashboardStatisticsService(
            $assets,
            $risks,
            $incidents,
            $this->createStub(ControlRepository::class),
            $security,
            $this->createStub(ModuleConfigurationService::class),
            tenantRecords: new TenantRecords($context),
        );

        $stats = $service->getDashboardStatistics();

        self::assertSame(0, $stats['risks_high']);
        self::assertSame(0, $stats['assets_critical']);
    }
}

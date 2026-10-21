<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
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
}

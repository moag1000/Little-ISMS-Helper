<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\DashboardStatisticsService;
use App\Service\ModuleConfigurationService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * ISMS health score: inactive modules are excluded and the weights renormalised,
 * active modules without rows score 0 (no data is not evidence).
 */
#[AllowMockObjectsWithoutExpectations]
final class DashboardHealthScoreNoPhantomTest extends TestCase
{
    private function healthScore(array $coreKpis, array $activeModules): array
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getParent')->willReturn(null);
        $tenant->method('getSubsidiaries')->willReturn(new ArrayCollection());

        $assets = $this->createMock(AssetRepository::class);
        $assets->method('findByTenant')->willReturn([]);
        $assets->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $risks = $this->createMock(RiskRepository::class);
        $risks->method('findByTenant')->willReturn([]);
        $risks->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $incidents = $this->createMock(IncidentRepository::class);
        $incidents->method('findByTenant')->willReturn([]);
        $incidents->method('findByTenantIncludingSubsidiaries')->willReturn([]);

        $service = new DashboardStatisticsService(
            $assets,
            $risks,
            $incidents,
            $this->createMock(ControlRepository::class),
            $this->createMock(Security::class),
            $this->createMock(ModuleConfigurationService::class),
        );

        $method = new \ReflectionMethod($service, 'calculateIsmsHealthScore');

        return $method->invoke($service, $tenant, $coreKpis, $activeModules);
    }

    #[Test]
    public function inactiveModulesAreExcludedAndWeightsRenormalised(): void
    {
        $result = $this->healthScore(['control_compliance_weighted' => ['value' => 50]], []);

        self::assertSame(50, $result['score']);
        self::assertSame(['compliance_weighted' => 50.0], $result['breakdown']);
    }

    #[Test]
    public function activeModulesWithoutRowsScoreZero(): void
    {
        $result = $this->healthScore(
            ['control_compliance_weighted' => ['value' => 0]],
            ['risks', 'incidents', 'assets'],
        );

        self::assertSame(0, $result['score']);
        self::assertSame(0.0, $result['breakdown']['risk_exposure']);
        self::assertSame(0.0, $result['breakdown']['incident_backlog']);
        self::assertSame(0.0, $result['breakdown']['asset_classification']);
    }
}

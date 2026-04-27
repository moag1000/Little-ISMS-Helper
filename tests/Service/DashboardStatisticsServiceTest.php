<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\DashboardStatisticsService;
use App\Service\ModuleConfigurationService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\SecurityBundle\Security;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class DashboardStatisticsServiceTest extends TestCase
{
    private MockObject $assetRepository;
    private MockObject $riskRepository;
    private MockObject $incidentRepository;
    private MockObject $controlRepository;
    private MockObject $security;
    private MockObject $moduleConfigurationService;
    private MockObject $tenant;
    private DashboardStatisticsService $service;

    protected function setUp(): void
    {
        $this->assetRepository = $this->createMock(AssetRepository::class);
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->incidentRepository = $this->createMock(IncidentRepository::class);
        $this->controlRepository = $this->createMock(ControlRepository::class);
        $this->security = $this->createMock(Security::class);
        $this->moduleConfigurationService = $this->createMock(ModuleConfigurationService::class);

        // Create tenant mock
        $this->tenant = $this->createMock(Tenant::class);
        $this->tenant->method('getParent')->willReturn(null);
        $this->tenant->method('getSubsidiaries')->willReturn(new ArrayCollection());

        // Create user mock with tenant
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($this->tenant);
        $this->security->method('getUser')->willReturn($user);

        // Default: return empty active modules
        $this->moduleConfigurationService->method('getActiveModules')->willReturn([]);

        $this->service = new DashboardStatisticsService(
            $this->assetRepository,
            $this->riskRepository,
            $this->incidentRepository,
            $this->controlRepository,
            $this->security,
            $this->moduleConfigurationService
        );
    }

    #[Test]
    public function testGetDashboardStatisticsWithEmptyData(): void
    {
        $this->assetRepository->method('findByTenant')->willReturn([]);
        $this->assetRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->riskRepository->method('findByTenant')->willReturn([]);
        $this->riskRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->incidentRepository->method('findByTenant')->willReturn([]);
        $this->incidentRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->controlRepository->method('findApplicableControls')->willReturn([]);

        $stats = $this->service->getDashboardStatistics();

        $this->assertSame(0, $stats['assetCount']);
        $this->assertSame(0, $stats['riskCount']);
        $this->assertSame(0, $stats['openIncidentCount']);
        $this->assertSame(0, $stats['compliancePercentage']);
        $this->assertSame(0, $stats['assets_critical']);
        $this->assertSame(0, $stats['risks_high']);
        $this->assertSame(0, $stats['controls_total']);
        $this->assertSame(0, $stats['controls_implemented']);
    }

    #[Test]
    public function testCompliancePercentageCalculation(): void
    {
        $this->assetRepository->method('findByTenant')->willReturn([]);
        $this->assetRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->riskRepository->method('findByTenant')->willReturn([]);
        $this->riskRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->incidentRepository->method('findByTenant')->willReturn([]);
        $this->incidentRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);

        // 3 out of 4 controls implemented = 75%
        $implementedControl = $this->createMockControl('implemented');
        $notImplementedControl = $this->createMockControl('planned');

        $this->controlRepository->method('findApplicableControls')->willReturn([
            $implementedControl,
            $implementedControl,
            $implementedControl,
            $notImplementedControl,
        ]);

        $stats = $this->service->getDashboardStatistics();

        $this->assertSame(75, $stats['compliancePercentage']);
        $this->assertSame(4, $stats['controls_total']);
        $this->assertSame(3, $stats['controls_implemented']);
    }

    #[Test]
    public function testCriticalAssetsCounting(): void
    {
        $criticalAsset = $this->createMockAsset(4);
        $veryHighAsset = $this->createMockAsset(5);
        $normalAsset = $this->createMockAsset(3);
        $lowAsset = $this->createMockAsset(1);

        $this->assetRepository->method('findByTenant')->willReturn([
            $criticalAsset,
            $veryHighAsset,
            $normalAsset,
            $lowAsset,
        ]);
        $this->assetRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);

        $this->riskRepository->method('findByTenant')->willReturn([]);
        $this->riskRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->incidentRepository->method('findByTenant')->willReturn([]);
        $this->incidentRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->controlRepository->method('findApplicableControls')->willReturn([]);

        $stats = $this->service->getDashboardStatistics();

        $this->assertSame(4, $stats['assets_total']);
        $this->assertSame(2, $stats['assets_critical']); // Only confidentialityValue >= 4
    }

    #[Test]
    public function testHighRisksCounting(): void
    {
        $highRisk = $this->createMockRisk(15);
        $mediumHighRisk = $this->createMockRisk(12);
        $mediumRisk = $this->createMockRisk(8);
        $lowRisk = $this->createMockRisk(4);

        $this->assetRepository->method('findByTenant')->willReturn([]);
        $this->assetRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->riskRepository->method('findByTenant')->willReturn([
            $highRisk,
            $mediumHighRisk,
            $mediumRisk,
            $lowRisk,
        ]);
        $this->riskRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->incidentRepository->method('findByTenant')->willReturn([]);
        $this->incidentRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->controlRepository->method('findApplicableControls')->willReturn([]);

        $stats = $this->service->getDashboardStatistics();

        $this->assertSame(4, $stats['risks_total']);
        $this->assertSame(2, $stats['risks_high']); // Only inherentRiskLevel >= 12
    }

    #[Test]
    public function testOpenIncidentsCounting(): void
    {
        $incident1 = $this->createMock(Incident::class);
        $incident1->method('getStatus')->willReturn(\App\Enum\IncidentStatus::tryFrom('open'));
        $incident1->method('getId')->willReturn(1);

        $incident2 = $this->createMock(Incident::class);
        $incident2->method('getStatus')->willReturn(\App\Enum\IncidentStatus::tryFrom('open'));
        $incident2->method('getId')->willReturn(2);

        $this->assetRepository->method('findByTenant')->willReturn([]);
        $this->assetRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->riskRepository->method('findByTenant')->willReturn([]);
        $this->riskRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->incidentRepository->method('findByTenant')->willReturn([
            $incident1,
            $incident2,
        ]);
        $this->incidentRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->controlRepository->method('findApplicableControls')->willReturn([]);

        $stats = $this->service->getDashboardStatistics();

        $this->assertSame(2, $stats['openIncidentCount']);
        $this->assertSame(2, $stats['incidents_open']);
    }

    #[Test]
    public function testCompliancePercentageRounding(): void
    {
        $this->assetRepository->method('findByTenant')->willReturn([]);
        $this->assetRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->riskRepository->method('findByTenant')->willReturn([]);
        $this->riskRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);
        $this->incidentRepository->method('findByTenant')->willReturn([]);
        $this->incidentRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);

        // 1 out of 3 = 33.333... should round to 33
        $implementedControl = $this->createMockControl('implemented');
        $notImplementedControl = $this->createMockControl('planned');

        $this->controlRepository->method('findApplicableControls')->willReturn([
            $implementedControl,
            $notImplementedControl,
            $notImplementedControl,
        ]);

        $stats = $this->service->getDashboardStatistics();

        $this->assertSame(33, $stats['compliancePercentage']);
    }

    #[Test]
    public function testFullDashboardStatistics(): void
    {
        $asset1 = $this->createMockAsset(5);
        $asset1->method('getStatus')->willReturn('active');
        $asset1->method('getId')->willReturn(1);

        $asset2 = $this->createMockAsset(4);
        $asset2->method('getStatus')->willReturn('active');
        $asset2->method('getId')->willReturn(2);

        $asset3 = $this->createMockAsset(3);
        $asset3->method('getStatus')->willReturn('active');
        $asset3->method('getId')->willReturn(3);

        // Setup comprehensive test data
        $this->assetRepository->method('findByTenant')->willReturn([
            $asset1, // critical
            $asset2, // critical
            $asset3, // normal
        ]);
        $this->assetRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);

        $this->riskRepository->method('findByTenant')->willReturn([
            $this->createMockRisk(16), // high
            $this->createMockRisk(9),  // medium
        ]);
        $this->riskRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);

        $incident1 = $this->createMock(Incident::class);
        $incident1->method('getStatus')->willReturn(\App\Enum\IncidentStatus::tryFrom('open'));
        $incident1->method('getId')->willReturn(1);

        $this->incidentRepository->method('findByTenant')->willReturn([
            $incident1,
        ]);
        $this->incidentRepository->method('findByTenantIncludingSubsidiaries')->willReturn([]);

        $this->controlRepository->method('findApplicableControls')->willReturn([
            $this->createMockControl('implemented'),
            $this->createMockControl('implemented'),
            $this->createMockControl('planned'),
            $this->createMockControl('not_applicable'),
        ]);

        $stats = $this->service->getDashboardStatistics();

        // Verify all statistics
        $this->assertSame(3, $stats['assetCount']);
        $this->assertSame(2, $stats['assets_critical']);
        $this->assertSame(2, $stats['riskCount']);
        $this->assertSame(1, $stats['risks_high']);
        $this->assertSame(1, $stats['openIncidentCount']);
        $this->assertSame(4, $stats['controls_total']);
        $this->assertSame(2, $stats['controls_implemented']);
        $this->assertSame(50, $stats['compliancePercentage']); // 2/4 = 50%
    }

    private function createMockAsset(int $confidentialityValue): MockObject
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getConfidentialityValue')->willReturn($confidentialityValue);
        $asset->method('getStatus')->willReturn('active');
        $asset->method('getId')->willReturn(random_int(100, 99999));
        return $asset;
    }

    private function createMockRisk(int $inherentRiskLevel): MockObject
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn($inherentRiskLevel);
        $risk->method('getId')->willReturn(random_int(100, 99999));
        return $risk;
    }

    private function createMockControl(string $implementationStatus): MockObject
    {
        $control = $this->createMock(Control::class);
        $control->method('getImplementationStatus')->willReturn($implementationStatus);
        return $control;
    }
}

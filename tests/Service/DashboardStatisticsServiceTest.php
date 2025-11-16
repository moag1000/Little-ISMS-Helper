<?php

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\DashboardStatisticsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DashboardStatisticsServiceTest extends TestCase
{
    private MockObject $assetRepository;
    private MockObject $riskRepository;
    private MockObject $incidentRepository;
    private MockObject $controlRepository;
    private DashboardStatisticsService $service;

    protected function setUp(): void
    {
        $this->assetRepository = $this->createMock(AssetRepository::class);
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->incidentRepository = $this->createMock(IncidentRepository::class);
        $this->controlRepository = $this->createMock(ControlRepository::class);

        $this->service = new DashboardStatisticsService(
            $this->assetRepository,
            $this->riskRepository,
            $this->incidentRepository,
            $this->controlRepository
        );
    }

    public function testGetDashboardStatisticsWithEmptyData(): void
    {
        $this->assetRepository->method('findActiveAssets')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findOpenIncidents')->willReturn([]);
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

    public function testCompliancePercentageCalculation(): void
    {
        $this->assetRepository->method('findActiveAssets')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findOpenIncidents')->willReturn([]);

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

    public function testCriticalAssetsCounting(): void
    {
        $criticalAsset = $this->createMockAsset(4);
        $veryHighAsset = $this->createMockAsset(5);
        $normalAsset = $this->createMockAsset(3);
        $lowAsset = $this->createMockAsset(1);

        $this->assetRepository->method('findActiveAssets')->willReturn([
            $criticalAsset,
            $veryHighAsset,
            $normalAsset,
            $lowAsset,
        ]);

        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findOpenIncidents')->willReturn([]);
        $this->controlRepository->method('findApplicableControls')->willReturn([]);

        $stats = $this->service->getDashboardStatistics();

        $this->assertSame(4, $stats['assets_total']);
        $this->assertSame(2, $stats['assets_critical']); // Only confidentialityValue >= 4
    }

    public function testHighRisksCounting(): void
    {
        $highRisk = $this->createMockRisk(15);
        $mediumHighRisk = $this->createMockRisk(12);
        $mediumRisk = $this->createMockRisk(8);
        $lowRisk = $this->createMockRisk(4);

        $this->assetRepository->method('findActiveAssets')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([
            $highRisk,
            $mediumHighRisk,
            $mediumRisk,
            $lowRisk,
        ]);
        $this->incidentRepository->method('findOpenIncidents')->willReturn([]);
        $this->controlRepository->method('findApplicableControls')->willReturn([]);

        $stats = $this->service->getDashboardStatistics();

        $this->assertSame(4, $stats['risks_total']);
        $this->assertSame(2, $stats['risks_high']); // Only inherentRiskLevel >= 12
    }

    public function testOpenIncidentsCounting(): void
    {
        $incident1 = $this->createMock(Incident::class);
        $incident2 = $this->createMock(Incident::class);

        $this->assetRepository->method('findActiveAssets')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findOpenIncidents')->willReturn([
            $incident1,
            $incident2,
        ]);
        $this->controlRepository->method('findApplicableControls')->willReturn([]);

        $stats = $this->service->getDashboardStatistics();

        $this->assertSame(2, $stats['openIncidentCount']);
        $this->assertSame(2, $stats['incidents_open']);
    }

    public function testCompliancePercentageRounding(): void
    {
        $this->assetRepository->method('findActiveAssets')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findOpenIncidents')->willReturn([]);

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

    public function testFullDashboardStatistics(): void
    {
        // Setup comprehensive test data
        $this->assetRepository->method('findActiveAssets')->willReturn([
            $this->createMockAsset(5), // critical
            $this->createMockAsset(4), // critical
            $this->createMockAsset(3), // normal
        ]);

        $this->riskRepository->method('findAll')->willReturn([
            $this->createMockRisk(16), // high
            $this->createMockRisk(9),  // medium
        ]);

        $this->incidentRepository->method('findOpenIncidents')->willReturn([
            $this->createMock(Incident::class),
        ]);

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
        return $asset;
    }

    private function createMockRisk(int $inherentRiskLevel): MockObject
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn($inherentRiskLevel);
        return $risk;
    }

    private function createMockControl(string $implementationStatus): MockObject
    {
        $control = $this->createMock(Control::class);
        $control->method('getImplementationStatus')->willReturn($implementationStatus);
        return $control;
    }
}

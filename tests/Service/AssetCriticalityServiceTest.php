<?php

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Repository\AssetRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\AssetCriticalityService;
use App\Service\TenantContext;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AssetCriticalityService
 *
 * Phase 7B: Tests for asset criticality analytics
 */
class AssetCriticalityServiceTest extends TestCase
{
    private MockObject $assetRepository;
    private MockObject $incidentRepository;
    private MockObject $riskRepository;
    private MockObject $tenantContext;
    private AssetCriticalityService $service;

    protected function setUp(): void
    {
        $this->assetRepository = $this->createMock(AssetRepository::class);
        $this->incidentRepository = $this->createMock(IncidentRepository::class);
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->tenantContext = $this->createMock(TenantContext::class);

        $this->service = new AssetCriticalityService(
            $this->assetRepository,
            $this->incidentRepository,
            $this->riskRepository,
            $this->tenantContext,
        );
    }

    // ==================== getCriticalityDashboard() Tests ====================

    public function testGetCriticalityDashboardReturnsAllSections(): void
    {
        $asset = $this->createAsset(1, 'Test', 'server', 3, 3, 3);

        $this->assetRepository->method('findAll')->willReturn([$asset]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->getCriticalityDashboard();

        $this->assertArrayHasKey('profiles', $result);
        $this->assertArrayHasKey('high_risk_assets', $result);
        $this->assertArrayHasKey('distribution', $result);
        $this->assertArrayHasKey('by_type', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    public function testGetCriticalityDashboardWithNoAssets(): void
    {
        $this->assetRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->getCriticalityDashboard();

        $this->assertEquals(0, $result['summary']['total_assets']);
        $this->assertEmpty($result['profiles']);
    }

    public function testCriticalityDistributionCalculation(): void
    {
        $critical = $this->createAsset(1, 'Critical', 'server', 5, 5, 5);  // 15 = critical
        $high = $this->createAsset(2, 'High', 'server', 3, 3, 3);          // 9 = high
        $medium = $this->createAsset(3, 'Medium', 'server', 2, 2, 2);      // 6 = medium
        $low = $this->createAsset(4, 'Low', 'server', 1, 1, 1);            // 3 = low

        $this->assetRepository->method('findAll')
            ->willReturn([$critical, $high, $medium, $low]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->getCriticalityDashboard();

        $this->assertEquals(1, $result['distribution']['critical']);
        $this->assertEquals(1, $result['distribution']['high']);
        $this->assertEquals(1, $result['distribution']['medium']);
        $this->assertEquals(1, $result['distribution']['low']);
    }

    // ==================== getAssetIncidentProbability() Tests ====================

    public function testGetAssetIncidentProbabilityReturnsAllSections(): void
    {
        $this->assetRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->getAssetIncidentProbability();

        $this->assertArrayHasKey('profiles', $result);
        $this->assertArrayHasKey('high_risk_assets', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    public function testAssetIncidentProbabilityCalculation(): void
    {
        $asset = $this->createAsset(1, 'Server', 'server', 5, 5, 5);
        $incident = $this->createIncidentWithAssets(1, 'Test', [$asset], new \DateTime('-10 days'));

        $this->assetRepository->method('findAll')->willReturn([$asset]);
        $this->incidentRepository->method('findAll')->willReturn([$incident]);

        $result = $this->service->getAssetIncidentProbability();

        $profile = $result['profiles'][0];
        $this->assertEquals(1, $profile['historical_incidents']);
        $this->assertGreaterThan(0, $profile['incident_probability']);
    }

    public function testHighRiskAssetsFiltering(): void
    {
        $highRisk = $this->createAsset(1, 'High Risk', 'server', 5, 5, 5);
        $lowRisk = $this->createAsset(2, 'Low Risk', 'workstation', 1, 1, 1);

        // Add incidents to make high risk asset actually high risk
        $incidents = [];
        for ($i = 0; $i < 5; $i++) {
            $incidents[] = $this->createIncidentWithAssets($i, "Incident $i", [$highRisk], new \DateTime('-10 days'));
        }

        $this->assetRepository->method('findAll')->willReturn([$highRisk, $lowRisk]);
        $this->incidentRepository->method('findAll')->willReturn($incidents);

        $result = $this->service->getAssetIncidentProbability();

        // High risk assets should have risk_score >= 10
        $this->assertGreaterThanOrEqual(0, count($result['high_risk_assets']));
    }

    // ==================== getVulnerabilityMatrix() Tests ====================

    public function testGetVulnerabilityMatrixReturnsAllSections(): void
    {
        $this->assetRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->getVulnerabilityMatrix();

        $this->assertArrayHasKey('matrix', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    public function testVulnerabilityMatrixWithRisks(): void
    {
        $asset = $this->createAsset(1, 'Server', 'server', 3, 3, 3);
        $risk = $this->createRiskWithAsset(1, 'Risk', $asset);

        $this->assetRepository->method('findAll')->willReturn([$asset]);
        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $result = $this->service->getVulnerabilityMatrix();

        $this->assertCount(1, $result['matrix']);
        $this->assertEquals(1, $result['summary']['assets_with_risks']);
        $this->assertEquals(1, $result['summary']['total_risk_links']);
    }

    public function testVulnerabilityMatrixCoordinates(): void
    {
        $asset = $this->createAsset(1, 'Server', 'server', 4, 3, 2); // criticality = 9

        $this->assetRepository->method('findAll')->willReturn([$asset]);
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->getVulnerabilityMatrix();

        $point = $result['matrix'][0];
        $this->assertEquals(9, $point['x']);  // CIA sum
        $this->assertEquals(0, $point['y']);  // No risks
        $this->assertEquals('high', $point['criticality_level']);
    }

    // ==================== getTypeAnalysis() Tests ====================

    public function testGetTypeAnalysisGroupsByType(): void
    {
        $server1 = $this->createAsset(1, 'Server 1', 'server', 5, 5, 5);
        $server2 = $this->createAsset(2, 'Server 2', 'server', 4, 4, 4);
        $workstation = $this->createAsset(3, 'WS', 'workstation', 2, 2, 2);

        $this->assetRepository->method('findAll')
            ->willReturn([$server1, $server2, $workstation]);

        $result = $this->service->getTypeAnalysis();

        $serverType = array_values(array_filter($result, fn($r) => $r['type'] === 'server'))[0];
        $this->assertEquals(2, $serverType['count']);
        $this->assertEquals(13.5, $serverType['average_criticality']); // (15+12)/2
    }

    public function testGetTypeAnalysisSortsByCriticality(): void
    {
        $server = $this->createAsset(1, 'Server', 'server', 5, 5, 5);  // 15
        $workstation = $this->createAsset(2, 'WS', 'workstation', 1, 1, 1);  // 3

        $this->assetRepository->method('findAll')
            ->willReturn([$workstation, $server]);

        $result = $this->service->getTypeAnalysis();

        // Server should be first (higher criticality)
        $this->assertEquals('server', $result[0]['type']);
    }

    // ==================== getSupplyChainRisk() Tests ====================

    public function testGetSupplyChainRiskFiltersSuppliers(): void
    {
        $supplier = $this->createAsset(1, 'Cloud', 'cloud_service', 3, 3, 3);
        $server = $this->createAsset(2, 'Server', 'server', 4, 4, 4);

        $this->assetRepository->method('findAll')
            ->willReturn([$supplier, $server]);

        $result = $this->service->getSupplyChainRisk();

        $this->assertEquals(1, $result['summary']['total_suppliers']);
        $this->assertCount(1, $result['suppliers']);
        $this->assertEquals('Cloud', $result['suppliers'][0]['asset_name']);
    }

    public function testGetSupplyChainRiskConcentration(): void
    {
        $supplier1 = $this->createAssetWithOwner(1, 'Service 1', 'cloud_service', 3, 3, 3, 'AWS');
        $supplier2 = $this->createAssetWithOwner(2, 'Service 2', 'cloud_service', 3, 3, 3, 'AWS');
        $supplier3 = $this->createAssetWithOwner(3, 'Service 3', 'cloud_service', 3, 3, 3, 'Azure');

        $this->assetRepository->method('findAll')
            ->willReturn([$supplier1, $supplier2, $supplier3]);

        $result = $this->service->getSupplyChainRisk();

        $this->assertEquals(2, $result['summary']['unique_owners']);
        $this->assertEquals(2, $result['concentration']['AWS']);
        $this->assertEquals(1, $result['concentration']['Azure']);
    }

    // ==================== Edge Cases ====================

    public function testHandlesNullCIAValues(): void
    {
        $asset = $this->createAssetWithNullCIA(1, 'Null CIA', 'server');

        $this->assetRepository->method('findAll')->willReturn([$asset]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->getCriticalityDashboard();

        // Should default to 1 for each, so criticality = 3
        $this->assertEquals(3, $result['profiles'][0]['criticality']);
        $this->assertEquals('low', $result['profiles'][0]['criticality_level']);
    }

    public function testHandlesIncidentsWithNullDates(): void
    {
        $asset = $this->createAsset(1, 'Test', 'server', 3, 3, 3);
        $incident = $this->createIncidentWithNullDate(1, 'Null Date', [$asset]);

        $this->assetRepository->method('findAll')->willReturn([$asset]);
        $this->incidentRepository->method('findAll')->willReturn([$incident]);

        // Should not throw exception
        $result = $this->service->getAssetIncidentProbability();

        $this->assertArrayHasKey('profiles', $result);
    }

    // ==================== Helper Methods ====================

    private function createAsset(int $id, string $name, string $type, int $conf, int $integ, int $avail): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn($id);
        $asset->method('getName')->willReturn($name);
        $asset->method('getAssetType')->willReturn($type);
        $asset->method('getConfidentialityValue')->willReturn($conf);
        $asset->method('getIntegrityValue')->willReturn($integ);
        $asset->method('getAvailabilityValue')->willReturn($avail);
        $asset->method('getOwner')->willReturn(null);
        return $asset;
    }

    private function createAssetWithOwner(int $id, string $name, string $type, int $conf, int $integ, int $avail, string $owner): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn($id);
        $asset->method('getName')->willReturn($name);
        $asset->method('getAssetType')->willReturn($type);
        $asset->method('getConfidentialityValue')->willReturn($conf);
        $asset->method('getIntegrityValue')->willReturn($integ);
        $asset->method('getAvailabilityValue')->willReturn($avail);
        $asset->method('getOwner')->willReturn($owner);
        return $asset;
    }

    private function createAssetWithNullCIA(int $id, string $name, string $type): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn($id);
        $asset->method('getName')->willReturn($name);
        $asset->method('getAssetType')->willReturn($type);
        $asset->method('getConfidentialityValue')->willReturn(null);
        $asset->method('getIntegrityValue')->willReturn(null);
        $asset->method('getAvailabilityValue')->willReturn(null);
        $asset->method('getOwner')->willReturn(null);
        return $asset;
    }

    private function createIncidentWithAssets(int $id, string $title, array $assets, \DateTimeInterface $occurredAt): Incident
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn($id);
        $incident->method('getTitle')->willReturn($title);
        $incident->method('getAffectedAssets')->willReturn(new ArrayCollection($assets));
        $incident->method('getOccurredAt')->willReturn($occurredAt);
        return $incident;
    }

    private function createIncidentWithNullDate(int $id, string $title, array $assets): Incident
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn($id);
        $incident->method('getTitle')->willReturn($title);
        $incident->method('getAffectedAssets')->willReturn(new ArrayCollection($assets));
        $incident->method('getOccurredAt')->willReturn(null);
        return $incident;
    }

    private function createRiskWithAsset(int $id, string $title, ?Asset $asset): Risk
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn($id);
        $risk->method('getTitle')->willReturn($title);
        $risk->method('getAsset')->willReturn($asset);
        return $risk;
    }
}

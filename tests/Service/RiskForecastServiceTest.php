<?php

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Repository\AssetRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\RiskForecastService;
use App\Service\TenantContext;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RiskForecastService
 *
 * Phase 7B: Tests for risk forecasting and predictive analytics
 */
class RiskForecastServiceTest extends TestCase
{
    private MockObject $riskRepository;
    private MockObject $incidentRepository;
    private MockObject $assetRepository;
    private MockObject $tenantContext;
    private RiskForecastService $service;

    protected function setUp(): void
    {
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->incidentRepository = $this->createMock(IncidentRepository::class);
        $this->assetRepository = $this->createMock(AssetRepository::class);
        $this->tenantContext = $this->createMock(TenantContext::class);

        $this->service = new RiskForecastService(
            $this->riskRepository,
            $this->incidentRepository,
            $this->assetRepository,
            $this->tenantContext,
        );
    }

    // ==================== getRiskForecast() Tests ====================

    public function testGetRiskForecastReturnsAllSections(): void
    {
        $risk = $this->createRisk(1, 'Test Risk', 10, 'open', new \DateTime('-2 months'));

        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $result = $this->service->getRiskForecast();

        $this->assertArrayHasKey('historical', $result);
        $this->assertArrayHasKey('forecast', $result);
        $this->assertArrayHasKey('trend', $result);
        $this->assertArrayHasKey('confidence_interval', $result);
    }

    public function testGetRiskForecastWithNoRisks(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->getRiskForecast();

        $this->assertArrayHasKey('historical', $result);
        $this->assertCount(12, $result['historical']); // 12 months of historical data
        $this->assertEquals(0, $result['historical'][0]['total']);
    }

    public function testGetRiskForecastDefaultSixMonths(): void
    {
        $risks = [
            $this->createRisk(1, 'Risk 1', 5, 'open', new \DateTime('-6 months')),
            $this->createRisk(2, 'Risk 2', 10, 'open', new \DateTime('-4 months')),
            $this->createRisk(3, 'Risk 3', 15, 'open', new \DateTime('-2 months')),
        ];

        $this->riskRepository->method('findAll')->willReturn($risks);

        $result = $this->service->getRiskForecast(6);

        $this->assertArrayHasKey('forecast', $result);
        $this->assertLessThanOrEqual(6, count($result['forecast']));
    }

    public function testGetRiskForecastHistoricalDataStructure(): void
    {
        $risk = $this->createRisk(1, 'Test', 10, 'open', new \DateTime('-1 month'));

        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $result = $this->service->getRiskForecast();
        $historical = $result['historical'][0];

        $this->assertArrayHasKey('month', $historical);
        $this->assertArrayHasKey('label', $historical);
        $this->assertArrayHasKey('total', $historical);
        $this->assertArrayHasKey('low', $historical);
        $this->assertArrayHasKey('medium', $historical);
        $this->assertArrayHasKey('high', $historical);
        $this->assertArrayHasKey('critical', $historical);
        $this->assertArrayHasKey('open', $historical);
        $this->assertArrayHasKey('closed', $historical);
    }

    public function testGetRiskForecastRiskLevelClassification(): void
    {
        $lowRisk = $this->createRisk(1, 'Low', 3, 'open', new \DateTime('-1 month'));      // < 6
        $mediumRisk = $this->createRisk(2, 'Medium', 8, 'open', new \DateTime('-1 month')); // 6-11
        $highRisk = $this->createRisk(3, 'High', 15, 'open', new \DateTime('-1 month'));    // 12-19
        $criticalRisk = $this->createRisk(4, 'Critical', 22, 'open', new \DateTime('-1 month')); // >= 20

        $this->riskRepository->method('findAll')
            ->willReturn([$lowRisk, $mediumRisk, $highRisk, $criticalRisk]);

        $result = $this->service->getRiskForecast();

        // Check the most recent month
        $latestMonth = end($result['historical']);

        $this->assertEquals(4, $latestMonth['total']);
        $this->assertEquals(1, $latestMonth['low']);
        $this->assertEquals(1, $latestMonth['medium']);
        $this->assertEquals(1, $latestMonth['high']);
        $this->assertEquals(1, $latestMonth['critical']);
    }

    // ==================== getRiskVelocity() Tests ====================

    public function testGetRiskVelocityReturnsAllMetrics(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->getRiskVelocity();

        $this->assertArrayHasKey('last_30_days', $result);
        $this->assertArrayHasKey('last_90_days', $result);
        $this->assertArrayHasKey('trend', $result);
        $this->assertArrayHasKey('status', $result);
    }

    public function testGetRiskVelocityWithNoRisks(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->getRiskVelocity();

        $this->assertEquals(0, $result['last_30_days']['new_risks']);
        $this->assertEquals(0, $result['last_30_days']['closed_risks']);
        $this->assertEquals(0, $result['last_30_days']['net_change']);
        $this->assertEquals('stable', $result['trend']);
    }

    public function testGetRiskVelocityPositiveVelocity(): void
    {
        // Create recent risks (in last 30 days)
        $recentRisks = [
            $this->createRisk(1, 'New 1', 10, 'open', new \DateTime('-10 days')),
            $this->createRisk(2, 'New 2', 10, 'open', new \DateTime('-5 days')),
            $this->createRisk(3, 'New 3', 10, 'open', new \DateTime('-2 days')),
        ];

        $this->riskRepository->method('findAll')->willReturn($recentRisks);

        $result = $this->service->getRiskVelocity();

        $this->assertEquals(3, $result['last_30_days']['new_risks']);
        $this->assertEquals(0, $result['last_30_days']['closed_risks']);
        $this->assertEquals(3, $result['last_30_days']['net_change']);
        $this->assertEquals('increasing', $result['trend']);
    }

    public function testGetRiskVelocityNegativeVelocity(): void
    {
        // Create a closed risk
        $closedRisk = $this->createRiskWithUpdate(
            1, 'Closed', 10, 'closed',
            new \DateTime('-60 days'), // created
            new \DateTime('-5 days')   // updated/closed
        );

        $this->riskRepository->method('findAll')->willReturn([$closedRisk]);

        $result = $this->service->getRiskVelocity();

        $this->assertEquals(0, $result['last_30_days']['new_risks']);
        $this->assertEquals(1, $result['last_30_days']['closed_risks']);
        $this->assertEquals(-1, $result['last_30_days']['net_change']);
        $this->assertEquals('decreasing', $result['trend']);
    }

    public function testGetRiskVelocityStatusWarning(): void
    {
        // Create many new risks (velocity > 5)
        $risks = [];
        for ($i = 0; $i < 7; $i++) {
            $risks[] = $this->createRisk($i, "Risk $i", 10, 'open', new \DateTime('-10 days'));
        }

        $this->riskRepository->method('findAll')->willReturn($risks);

        $result = $this->service->getRiskVelocity();

        $this->assertEquals('warning', $result['status']);
    }

    public function testGetRiskVelocityStatusExcellent(): void
    {
        // Create many closed risks (velocity < -5)
        $risks = [];
        for ($i = 0; $i < 7; $i++) {
            $risks[] = $this->createRiskWithUpdate(
                $i, "Risk $i", 10, 'closed',
                new \DateTime('-60 days'),
                new \DateTime('-5 days')
            );
        }

        $this->riskRepository->method('findAll')->willReturn($risks);

        $result = $this->service->getRiskVelocity();

        $this->assertEquals('excellent', $result['status']);
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

    public function testGetAssetIncidentProbabilityWithNoAssets(): void
    {
        $this->assetRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->getAssetIncidentProbability();

        $this->assertEquals(0, $result['summary']['total_assets']);
        $this->assertEquals(0, $result['summary']['high_risk_count']);
        $this->assertEquals(0, $result['summary']['average_probability']);
    }

    public function testGetAssetIncidentProbabilityCalculation(): void
    {
        $asset = $this->createAsset(1, 'Test Server', 'server', 5, 5, 5);

        $this->assetRepository->method('findAll')->willReturn([$asset]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->getAssetIncidentProbability();

        $this->assertCount(1, $result['profiles']);
        $profile = $result['profiles'][0];

        $this->assertEquals(1, $profile['asset_id']);
        $this->assertEquals('Test Server', $profile['asset_name']);
        $this->assertEquals('server', $profile['asset_type']);
        $this->assertEquals(15, $profile['criticality']); // 5+5+5
        $this->assertEquals('critical', $profile['criticality_level']);
        $this->assertEquals(0, $profile['historical_incidents']);
    }

    public function testGetAssetIncidentProbabilityWithIncidents(): void
    {
        $asset = $this->createAsset(1, 'Test Server', 'server', 3, 3, 3);
        $incident = $this->createIncidentWithAssets(1, 'Test Incident', [$asset], new \DateTime('-10 days'));

        $this->assetRepository->method('findAll')->willReturn([$asset]);
        $this->incidentRepository->method('findAll')->willReturn([$incident]);

        $result = $this->service->getAssetIncidentProbability();

        $profile = $result['profiles'][0];
        $this->assertEquals(1, $profile['historical_incidents']);
        $this->assertGreaterThan(0, $profile['incident_probability']);
    }

    public function testGetAssetIncidentProbabilitySortsByRiskScore(): void
    {
        $lowRiskAsset = $this->createAsset(1, 'Low Risk', 'workstation', 1, 1, 1);
        $highRiskAsset = $this->createAsset(2, 'High Risk', 'server', 5, 5, 5);

        $this->assetRepository->method('findAll')->willReturn([$lowRiskAsset, $highRiskAsset]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->getAssetIncidentProbability();

        // High risk asset should be first
        $this->assertEquals('High Risk', $result['profiles'][0]['asset_name']);
    }

    public function testGetAssetIncidentProbabilityCriticalityLevels(): void
    {
        $criticalAsset = $this->createAsset(1, 'Critical', 'server', 5, 5, 5);   // 15 = critical
        $highAsset = $this->createAsset(2, 'High', 'server', 3, 3, 3);           // 9 = high
        $mediumAsset = $this->createAsset(3, 'Medium', 'server', 2, 2, 2);       // 6 = medium
        $lowAsset = $this->createAsset(4, 'Low', 'server', 1, 1, 1);             // 3 = low

        $this->assetRepository->method('findAll')
            ->willReturn([$criticalAsset, $highAsset, $mediumAsset, $lowAsset]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->getAssetIncidentProbability();
        $profiles = $result['profiles'];

        // Find each profile by name
        $criticalProfile = array_values(array_filter($profiles, fn($p) => $p['asset_name'] === 'Critical'))[0];
        $highProfile = array_values(array_filter($profiles, fn($p) => $p['asset_name'] === 'High'))[0];
        $mediumProfile = array_values(array_filter($profiles, fn($p) => $p['asset_name'] === 'Medium'))[0];
        $lowProfile = array_values(array_filter($profiles, fn($p) => $p['asset_name'] === 'Low'))[0];

        $this->assertEquals('critical', $criticalProfile['criticality_level']);
        $this->assertEquals('high', $highProfile['criticality_level']);
        $this->assertEquals('medium', $mediumProfile['criticality_level']);
        $this->assertEquals('low', $lowProfile['criticality_level']);
    }

    // ==================== getAnomalyDetection() Tests ====================

    public function testGetAnomalyDetectionReturnsAllSections(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->getAnomalyDetection();

        $this->assertArrayHasKey('anomalies', $result);
        $this->assertArrayHasKey('total_count', $result);
        $this->assertArrayHasKey('by_type', $result);
    }

    public function testGetAnomalyDetectionWithNoData(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);

        $result = $this->service->getAnomalyDetection();

        $this->assertEquals(0, $result['total_count']);
    }

    public function testGetAnomalyDetectionDetectsIncidentPatterns(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);

        // Create incidents with concentrated category
        $incidents = [];
        for ($i = 0; $i < 10; $i++) {
            $incidents[] = $this->createIncident($i, 'Incident', 'security'); // All same category
        }

        $this->incidentRepository->method('findAll')->willReturn($incidents);

        $result = $this->service->getAnomalyDetection();

        // Should detect pattern (> 40% in one category)
        $incidentPatterns = array_filter($result['anomalies'], fn($a) => $a['type'] === 'incident_pattern');
        $this->assertGreaterThanOrEqual(1, count($incidentPatterns));
    }

    public function testGetAnomalyDetectionSortsBySeverity(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);

        // Create varied incidents
        $incidents = [];
        for ($i = 0; $i < 10; $i++) {
            $incidents[] = $this->createIncident($i, 'Incident', 'category_a');
        }
        for ($i = 0; $i < 3; $i++) {
            $incidents[] = $this->createIncident(10 + $i, 'Incident', 'category_b');
        }

        $this->incidentRepository->method('findAll')->willReturn($incidents);

        $result = $this->service->getAnomalyDetection();

        // Always assert structure exists
        $this->assertArrayHasKey('anomalies', $result);

        if (count($result['anomalies']) >= 2) {
            $this->assertGreaterThanOrEqual(
                $result['anomalies'][1]['severity_score'] ?? 0,
                $result['anomalies'][0]['severity_score'] ?? 0
            );
        }
    }

    // ==================== getRiskAppetiteCompliance() Tests ====================

    public function testGetRiskAppetiteComplianceReturnsAllSections(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->getRiskAppetiteCompliance();

        $this->assertArrayHasKey('appetite', $result);
        $this->assertArrayHasKey('actual', $result);
        $this->assertArrayHasKey('breaches', $result);
        $this->assertArrayHasKey('is_compliant', $result);
        $this->assertArrayHasKey('compliance_score', $result);
    }

    public function testGetRiskAppetiteComplianceWithNoRisks(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->getRiskAppetiteCompliance();

        $this->assertEquals(0, $result['actual']['low']);
        $this->assertEquals(0, $result['actual']['medium']);
        $this->assertEquals(0, $result['actual']['high']);
        $this->assertEquals(0, $result['actual']['critical']);
        $this->assertTrue($result['is_compliant']);
        $this->assertEquals(100, $result['compliance_score']);
    }

    public function testGetRiskAppetiteComplianceWithinLimits(): void
    {
        // Create risks within appetite limits
        $risks = [];
        for ($i = 0; $i < 5; $i++) {
            $risks[] = $this->createRisk($i, "Risk $i", 3, 'open', new \DateTime()); // Low
        }

        $this->riskRepository->method('findAll')->willReturn($risks);

        $result = $this->service->getRiskAppetiteCompliance();

        $this->assertTrue($result['is_compliant']);
        $this->assertEmpty($result['breaches']);
    }

    public function testGetRiskAppetiteComplianceBreachesDetected(): void
    {
        // Create many critical risks (> 2 critical_max)
        $risks = [];
        for ($i = 0; $i < 5; $i++) {
            $risks[] = $this->createRisk($i, "Critical $i", 22, 'open', new \DateTime()); // Critical level
        }

        $this->riskRepository->method('findAll')->willReturn($risks);

        $result = $this->service->getRiskAppetiteCompliance();

        $this->assertFalse($result['is_compliant']);
        $this->assertNotEmpty($result['breaches']);

        $criticalBreach = array_values(array_filter($result['breaches'], fn($b) => $b['level'] === 'critical'))[0];
        $this->assertEquals(5, $criticalBreach['actual']);
        $this->assertEquals(2, $criticalBreach['threshold']);
        $this->assertEquals(3, $criticalBreach['excess']);
    }

    public function testGetRiskAppetiteComplianceScoreCalculation(): void
    {
        // Create risks that breach limits
        $risks = [];

        // 3 critical risks (1 over limit = -15 points)
        for ($i = 0; $i < 3; $i++) {
            $risks[] = $this->createRisk($i, "Critical $i", 22, 'open', new \DateTime());
        }

        $this->riskRepository->method('findAll')->willReturn($risks);

        $result = $this->service->getRiskAppetiteCompliance();

        $this->assertLessThan(100, $result['compliance_score']);
    }

    // ==================== Edge Cases ====================

    public function testHandlesNullDateValues(): void
    {
        $risk = $this->createRiskWithNullDates(1, 'Null Dates', 10, 'open');

        $this->riskRepository->method('findAll')->willReturn([$risk]);

        // Should not throw exception
        $result = $this->service->getRiskForecast();

        $this->assertArrayHasKey('historical', $result);
    }

    public function testHandlesEmptyIncidentTimestamps(): void
    {
        $asset = $this->createAsset(1, 'Test', 'server', 3, 3, 3);
        $incidentWithNullDate = $this->createIncidentWithNullDate(1, 'Null Date', [$asset]);

        $this->assetRepository->method('findAll')->willReturn([$asset]);
        $this->incidentRepository->method('findAll')->willReturn([$incidentWithNullDate]);

        // Should not throw exception
        $result = $this->service->getAssetIncidentProbability();

        $this->assertArrayHasKey('profiles', $result);
    }

    // ==================== Helper Methods ====================

    private function createRisk(int $id, string $title, int $inherentLevel, string $status, \DateTimeInterface $createdAt): Risk
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn($id);
        $risk->method('getTitle')->willReturn($title);
        $risk->method('getInherentRiskLevel')->willReturn($inherentLevel);
        $risk->method('getStatus')->willReturn($status);
        $risk->method('getCreatedAt')->willReturn($createdAt);
        $risk->method('getUpdatedAt')->willReturn(null);
        return $risk;
    }

    private function createRiskWithUpdate(int $id, string $title, int $inherentLevel, string $status, \DateTimeInterface $createdAt, \DateTimeInterface $updatedAt): Risk
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn($id);
        $risk->method('getTitle')->willReturn($title);
        $risk->method('getInherentRiskLevel')->willReturn($inherentLevel);
        $risk->method('getStatus')->willReturn($status);
        $risk->method('getCreatedAt')->willReturn($createdAt);
        $risk->method('getUpdatedAt')->willReturn($updatedAt);
        return $risk;
    }

    private function createRiskWithNullDates(int $id, string $title, int $inherentLevel, string $status): Risk
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn($id);
        $risk->method('getTitle')->willReturn($title);
        $risk->method('getInherentRiskLevel')->willReturn($inherentLevel);
        $risk->method('getStatus')->willReturn($status);
        $risk->method('getCreatedAt')->willReturn(new \DateTime()); // createdAt typically not null
        $risk->method('getUpdatedAt')->willReturn(null);
        return $risk;
    }

    private function createAsset(int $id, string $name, string $type, int $conf, int $integ, int $avail): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn($id);
        $asset->method('getName')->willReturn($name);
        $asset->method('getAssetType')->willReturn($type);
        $asset->method('getConfidentialityValue')->willReturn($conf);
        $asset->method('getIntegrityValue')->willReturn($integ);
        $asset->method('getAvailabilityValue')->willReturn($avail);
        return $asset;
    }

    private function createIncident(int $id, string $title, string $category): Incident
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn($id);
        $incident->method('getTitle')->willReturn($title);
        $incident->method('getCategory')->willReturn($category);
        $incident->method('getAffectedAssets')->willReturn(new ArrayCollection());
        $incident->method('getOccurredAt')->willReturn(new \DateTime('-30 days'));
        return $incident;
    }

    private function createIncidentWithAssets(int $id, string $title, array $assets, \DateTimeInterface $occurredAt): Incident
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn($id);
        $incident->method('getTitle')->willReturn($title);
        $incident->method('getCategory')->willReturn('security');
        $incident->method('getAffectedAssets')->willReturn(new ArrayCollection($assets));
        $incident->method('getOccurredAt')->willReturn($occurredAt);
        return $incident;
    }

    private function createIncidentWithNullDate(int $id, string $title, array $assets): Incident
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn($id);
        $incident->method('getTitle')->willReturn($title);
        $incident->method('getCategory')->willReturn('security');
        $incident->method('getAffectedAssets')->willReturn(new ArrayCollection($assets));
        $incident->method('getOccurredAt')->willReturn(null);
        return $incident;
    }
}

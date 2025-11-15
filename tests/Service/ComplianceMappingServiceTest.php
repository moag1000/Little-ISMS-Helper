<?php

namespace App\Tests\Service;

use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Repository\AssetRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Service\ComplianceMappingService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

class ComplianceMappingServiceTest extends TestCase
{
    private ComplianceMappingService $service;
    private ControlRepository $controlRepo;
    private AssetRepository $assetRepo;
    private BusinessProcessRepository $businessProcessRepo;
    private IncidentRepository $incidentRepo;
    private InternalAuditRepository $auditRepo;

    protected function setUp(): void
    {
        $this->controlRepo = $this->createMock(ControlRepository::class);
        $this->assetRepo = $this->createMock(AssetRepository::class);
        $this->businessProcessRepo = $this->createMock(BusinessProcessRepository::class);
        $this->incidentRepo = $this->createMock(IncidentRepository::class);
        $this->auditRepo = $this->createMock(InternalAuditRepository::class);

        $this->service = new ComplianceMappingService(
            $this->controlRepo,
            $this->assetRepo,
            $this->businessProcessRepo,
            $this->incidentRepo,
            $this->auditRepo
        );
    }

    public function testMapControlsToRequirementWithNoMapping(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getDataSourceMapping')->willReturn(null);

        $result = $this->service->mapControlsToRequirement($requirement);

        $this->assertEmpty($result);
    }

    public function testMapControlsToRequirementWithEmptyIsoControls(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getDataSourceMapping')->willReturn(['iso_controls' => []]);

        $result = $this->service->mapControlsToRequirement($requirement);

        $this->assertEmpty($result);
    }

    public function testMapControlsToRequirementMapsCorrectly(): void
    {
        $control1 = $this->createMock(Control::class);
        $control1->method('getControlId')->willReturn('A.5.1');

        $control2 = $this->createMock(Control::class);
        $control2->method('getControlId')->willReturn('A.5.2');

        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getDataSourceMapping')->willReturn([
            'iso_controls' => ['A.5.1', 'A.5.2']
        ]);

        $this->controlRepo->expects($this->exactly(2))
            ->method('findBy')
            ->willReturnCallback(function ($criteria) use ($control1, $control2) {
                if ($criteria['controlId'] === 'A.5.1') {
                    return [$control1];
                }
                return [$control2];
            });

        $requirement->expects($this->exactly(2))->method('addMappedControl');

        $result = $this->service->mapControlsToRequirement($requirement);

        $this->assertCount(2, $result);
        $this->assertSame($control1, $result[0]);
        $this->assertSame($control2, $result[1]);
    }

    public function testGetDataReuseAnalysisWithNoData(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-001');
        $requirement->method('getTitle')->willReturn('Test Requirement');
        $requirement->method('getFulfillmentPercentage')->willReturn(0);
        $requirement->method('getDataSourceMapping')->willReturn(null);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        $result = $this->service->getDataReuseAnalysis($requirement);

        $this->assertEquals('REQ-001', $result['requirement_id']);
        $this->assertEquals('Test Requirement', $result['title']);
        $this->assertEquals('low', $result['confidence']);
        $this->assertEquals(0, $result['fulfillment_percentage']);
        $this->assertEmpty($result['sources']);
    }

    public function testGetDataReuseAnalysisWithControlsSource(): void
    {
        $control1 = $this->createMock(Control::class);
        $control1->method('getControlId')->willReturn('A.5.1');
        $control1->method('getName')->willReturn('Access Control');
        $control1->method('getImplementationStatus')->willReturn('implemented');
        $control1->method('getImplementationPercentage')->willReturn(80);

        $control2 = $this->createMock(Control::class);
        $control2->method('getControlId')->willReturn('A.5.2');
        $control2->method('getName')->willReturn('Authentication');
        $control2->method('getImplementationStatus')->willReturn('in_progress');
        $control2->method('getImplementationPercentage')->willReturn(50);

        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-001');
        $requirement->method('getTitle')->willReturn('Test Requirement');
        $requirement->method('getFulfillmentPercentage')->willReturn(65);
        $requirement->method('getDataSourceMapping')->willReturn([]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection([$control1, $control2]));

        $result = $this->service->getDataReuseAnalysis($requirement);

        $this->assertArrayHasKey('controls', $result['sources']);
        $this->assertEquals(2, $result['sources']['controls']['count']);
        $this->assertEquals(1, $result['sources']['controls']['implemented']);
        $this->assertEquals(65, $result['sources']['controls']['average_implementation']);
        $this->assertEquals('high', $result['confidence']); // 1/1 = 100% contributing
    }

    public function testGetDataReuseAnalysisWithAssetsSource(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-002');
        $requirement->method('getTitle')->willReturn('Asset Requirement');
        $requirement->method('getFulfillmentPercentage')->willReturn(50);
        $requirement->method('getDataSourceMapping')->willReturn([
            'asset_types' => ['server', 'database']
        ]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        $this->assetRepo->method('count')->willReturnCallback(function ($criteria) {
            if (empty($criteria)) {
                return 100; // Total assets
            }
            if ($criteria['assetType'] === 'server') {
                return 20;
            }
            if ($criteria['assetType'] === 'database') {
                return 10;
            }
            return 0;
        });

        $result = $this->service->getDataReuseAnalysis($requirement);

        $this->assertArrayHasKey('assets', $result['sources']);
        $this->assertEquals(100, $result['sources']['assets']['total_assets']);
        $this->assertEquals(30, $result['sources']['assets']['relevant_assets']); // 20 + 10
        $this->assertEquals(100, $result['sources']['assets']['contribution']);
        $this->assertEquals('high', $result['confidence']); // 1/1 = 100%
    }

    public function testGetDataReuseAnalysisWithBCMSource(): void
    {
        $process1 = $this->createMock(BusinessProcess::class);
        $process2 = $this->createMock(BusinessProcess::class);

        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-003');
        $requirement->method('getTitle')->willReturn('BCM Requirement');
        $requirement->method('getFulfillmentPercentage')->willReturn(75);
        $requirement->method('getDataSourceMapping')->willReturn(['bcm_required' => true]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        $this->businessProcessRepo->method('count')->willReturn(5);
        $this->businessProcessRepo->method('findCriticalProcesses')->willReturn([$process1, $process2]);

        $result = $this->service->getDataReuseAnalysis($requirement);

        $this->assertArrayHasKey('bcm', $result['sources']);
        $this->assertEquals(5, $result['sources']['bcm']['total_processes']);
        $this->assertEquals(2, $result['sources']['bcm']['critical_processes']);
        $this->assertTrue($result['sources']['bcm']['has_bia_data']);
        $this->assertEquals(100, $result['sources']['bcm']['contribution']);
        $this->assertEquals('high', $result['confidence']);
    }

    public function testGetDataReuseAnalysisWithIncidentsSource(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-004');
        $requirement->method('getTitle')->willReturn('Incident Requirement');
        $requirement->method('getFulfillmentPercentage')->willReturn(60);
        $requirement->method('getDataSourceMapping')->willReturn(['incident_management' => true]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        $this->incidentRepo->method('count')->willReturnCallback(function ($criteria) {
            if (empty($criteria)) {
                return 10; // Total incidents
            }
            if ($criteria['status'] === 'resolved') {
                return 8; // Resolved
            }
            return 0;
        });

        $result = $this->service->getDataReuseAnalysis($requirement);

        $this->assertArrayHasKey('incidents', $result['sources']);
        $this->assertEquals(10, $result['sources']['incidents']['total_incidents']);
        $this->assertEquals(8, $result['sources']['incidents']['resolved_incidents']);
        $this->assertTrue($result['sources']['incidents']['has_incident_process']);
        $this->assertEquals(100, $result['sources']['incidents']['contribution']);
        $this->assertEquals('high', $result['confidence']);
    }

    public function testGetDataReuseAnalysisWithAuditsSource(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-005');
        $requirement->method('getTitle')->willReturn('Audit Requirement');
        $requirement->method('getFulfillmentPercentage')->willReturn(80);
        $requirement->method('getDataSourceMapping')->willReturn(['audit_evidence' => true]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        $this->auditRepo->method('count')->willReturnCallback(function ($criteria) {
            if (empty($criteria)) {
                return 5; // Total audits
            }
            if ($criteria['status'] === 'completed') {
                return 3; // Completed
            }
            return 0;
        });

        $result = $this->service->getDataReuseAnalysis($requirement);

        $this->assertArrayHasKey('audits', $result['sources']);
        $this->assertEquals(5, $result['sources']['audits']['total_audits']);
        $this->assertEquals(3, $result['sources']['audits']['completed_audits']);
        $this->assertTrue($result['sources']['audits']['has_audit_program']);
        $this->assertEquals(100, $result['sources']['audits']['contribution']);
        $this->assertEquals('high', $result['confidence']);
    }

    public function testGetDataReuseAnalysisWithMultipleSources(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('getControlId')->willReturn('A.5.1');
        $control->method('getName')->willReturn('Control');
        $control->method('getImplementationStatus')->willReturn('implemented');
        $control->method('getImplementationPercentage')->willReturn(100);

        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-006');
        $requirement->method('getTitle')->willReturn('Multi-Source Requirement');
        $requirement->method('getFulfillmentPercentage')->willReturn(90);
        $requirement->method('getDataSourceMapping')->willReturn([
            'asset_types' => ['server'],
            'bcm_required' => true,
            'incident_management' => true
        ]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection([$control]));

        $this->assetRepo->method('count')->willReturnCallback(fn($c) => empty($c) ? 50 : 10);
        $this->businessProcessRepo->method('count')->willReturn(3);
        $this->businessProcessRepo->method('findCriticalProcesses')->willReturn([]);
        $this->incidentRepo->method('count')->willReturn(5);

        $result = $this->service->getDataReuseAnalysis($requirement);

        $this->assertArrayHasKey('controls', $result['sources']);
        $this->assertArrayHasKey('assets', $result['sources']);
        $this->assertArrayHasKey('bcm', $result['sources']);
        $this->assertArrayHasKey('incidents', $result['sources']);
        $this->assertEquals('high', $result['confidence']); // All sources contributing
    }

    public function testConfidenceCalculationMedium(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('getControlId')->willReturn('A.5.1');
        $control->method('getName')->willReturn('Control');
        $control->method('getImplementationStatus')->willReturn('implemented');
        $control->method('getImplementationPercentage')->willReturn(100);

        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-007');
        $requirement->method('getTitle')->willReturn('Medium Confidence');
        $requirement->method('getFulfillmentPercentage')->willReturn(50);
        $requirement->method('getDataSourceMapping')->willReturn([
            'asset_types' => ['server'],
            'bcm_required' => true
        ]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection([$control]));

        // Make assets contribute (100), but BCM not contribute (0)
        $this->assetRepo->method('count')->willReturnCallback(fn($c) => empty($c) ? 50 : 10);
        $this->businessProcessRepo->method('count')->willReturn(0); // No BCM data
        $this->businessProcessRepo->method('findCriticalProcesses')->willReturn([]);

        $result = $this->service->getDataReuseAnalysis($requirement);

        // 2 of 3 sources contributing = 66.7% = medium confidence (>= 0.5)
        $this->assertEquals('medium', $result['confidence']);
    }

    public function testConfidenceCalculationLow(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-008');
        $requirement->method('getTitle')->willReturn('Low Confidence');
        $requirement->method('getFulfillmentPercentage')->willReturn(20);
        $requirement->method('getDataSourceMapping')->willReturn([
            'asset_types' => ['server'],
            'bcm_required' => true,
            'incident_management' => true
        ]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        // Only assets contribute
        $this->assetRepo->method('count')->willReturnCallback(fn($c) => empty($c) ? 50 : 10);
        $this->businessProcessRepo->method('count')->willReturn(0);
        $this->businessProcessRepo->method('findCriticalProcesses')->willReturn([]);
        $this->incidentRepo->method('count')->willReturn(0);

        $result = $this->service->getDataReuseAnalysis($requirement);

        // 1 of 3 sources = 33% = low confidence (< 0.5)
        $this->assertEquals('low', $result['confidence']);
    }

    public function testGetCrossFrameworkInsights(): void
    {
        $control1 = $this->createMock(Control::class);
        $control1->method('getControlId')->willReturn('A.5.1');
        $control1->method('getName')->willReturn('Access Control Policy');

        $control2 = $this->createMock(Control::class);
        $control2->method('getControlId')->willReturn('A.8.1');
        $control2->method('getName')->willReturn('Asset Responsibility');

        $this->controlRepo->method('findAll')->willReturn([$control1, $control2]);

        $result = $this->service->getCrossFrameworkInsights();

        $this->assertCount(2, $result);
        $this->assertEquals('A.5.1', $result[0]['control_id']);
        $this->assertEquals('Access Control Policy', $result[0]['control_name']);
        $this->assertArrayHasKey('frameworks_satisfied', $result[0]);
        $this->assertEquals('A.8.1', $result[1]['control_id']);
    }

    public function testCalculateDataReuseValueWithNoSources(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-009');
        $requirement->method('getTitle')->willReturn('No Sources');
        $requirement->method('getFulfillmentPercentage')->willReturn(0);
        $requirement->method('getDataSourceMapping')->willReturn(null);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        $result = $this->service->calculateDataReuseValue($requirement);

        $this->assertEquals('REQ-009', $result['requirement_id']);
        $this->assertEquals(0, $result['data_sources_reused']);
        $this->assertEquals(0, $result['estimated_hours_saved']); // 0 * 4
        $this->assertEquals('low', $result['confidence']);
    }

    public function testCalculateDataReuseValueWithMultipleSources(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('getControlId')->willReturn('A.5.1');
        $control->method('getName')->willReturn('Control');
        $control->method('getImplementationStatus')->willReturn('implemented');
        $control->method('getImplementationPercentage')->willReturn(100);

        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-010');
        $requirement->method('getTitle')->willReturn('Multi-Source Value');
        $requirement->method('getFulfillmentPercentage')->willReturn(80);
        $requirement->method('getDataSourceMapping')->willReturn([
            'asset_types' => ['server'],
            'bcm_required' => true,
            'incident_management' => true
        ]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection([$control]));

        $this->assetRepo->method('count')->willReturnCallback(fn($c) => empty($c) ? 50 : 10);
        $this->businessProcessRepo->method('count')->willReturn(5);
        $this->businessProcessRepo->method('findCriticalProcesses')->willReturn([]);
        $this->incidentRepo->method('count')->willReturn(3);

        $result = $this->service->calculateDataReuseValue($requirement);

        $this->assertEquals('REQ-010', $result['requirement_id']);
        $this->assertEquals(4, $result['data_sources_reused']); // controls, assets, bcm, incidents
        $this->assertEquals(16, $result['estimated_hours_saved']); // 4 sources * 4 hours
        $this->assertEquals('high', $result['confidence']);
    }

    public function testAnalyzeControlsContributionWithNoControls(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-011');
        $requirement->method('getTitle')->willReturn('No Controls');
        $requirement->method('getFulfillmentPercentage')->willReturn(0);
        $requirement->method('getDataSourceMapping')->willReturn([]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        $result = $this->service->getDataReuseAnalysis($requirement);

        // No controls source in result
        $this->assertArrayNotHasKey('controls', $result['sources']);
    }

    public function testAnalyzeAssetsContributionWithNoAssets(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-012');
        $requirement->method('getTitle')->willReturn('No Assets');
        $requirement->method('getFulfillmentPercentage')->willReturn(0);
        $requirement->method('getDataSourceMapping')->willReturn(['asset_types' => ['server']]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        $this->assetRepo->method('count')->willReturn(0);

        $result = $this->service->getDataReuseAnalysis($requirement);

        $this->assertArrayHasKey('assets', $result['sources']);
        $this->assertEquals(0, $result['sources']['assets']['total_assets']);
        $this->assertEquals(0, $result['sources']['assets']['relevant_assets']);
        $this->assertEquals(0, $result['sources']['assets']['contribution']);
    }

    public function testAnalyzeBCMContributionWithNoProcesses(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-013');
        $requirement->method('getTitle')->willReturn('No BCM');
        $requirement->method('getFulfillmentPercentage')->willReturn(0);
        $requirement->method('getDataSourceMapping')->willReturn(['bcm_required' => true]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        $this->businessProcessRepo->method('count')->willReturn(0);
        $this->businessProcessRepo->method('findCriticalProcesses')->willReturn([]);

        $result = $this->service->getDataReuseAnalysis($requirement);

        $this->assertArrayHasKey('bcm', $result['sources']);
        $this->assertEquals(0, $result['sources']['bcm']['total_processes']);
        $this->assertEquals(0, $result['sources']['bcm']['critical_processes']);
        $this->assertFalse($result['sources']['bcm']['has_bia_data']);
        $this->assertEquals(0, $result['sources']['bcm']['contribution']);
    }

    public function testAnalyzeIncidentContributionWithNoIncidents(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-014');
        $requirement->method('getTitle')->willReturn('No Incidents');
        $requirement->method('getFulfillmentPercentage')->willReturn(0);
        $requirement->method('getDataSourceMapping')->willReturn(['incident_management' => true]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        $this->incidentRepo->method('count')->willReturn(0);

        $result = $this->service->getDataReuseAnalysis($requirement);

        $this->assertArrayHasKey('incidents', $result['sources']);
        $this->assertEquals(0, $result['sources']['incidents']['total_incidents']);
        $this->assertEquals(0, $result['sources']['incidents']['resolved_incidents']);
        $this->assertFalse($result['sources']['incidents']['has_incident_process']);
        $this->assertEquals(0, $result['sources']['incidents']['contribution']);
    }

    public function testAnalyzeAuditContributionWithNoAudits(): void
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn('REQ-015');
        $requirement->method('getTitle')->willReturn('No Audits');
        $requirement->method('getFulfillmentPercentage')->willReturn(0);
        $requirement->method('getDataSourceMapping')->willReturn(['audit_evidence' => true]);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());

        $this->auditRepo->method('count')->willReturn(0);

        $result = $this->service->getDataReuseAnalysis($requirement);

        $this->assertArrayHasKey('audits', $result['sources']);
        $this->assertEquals(0, $result['sources']['audits']['total_audits']);
        $this->assertEquals(0, $result['sources']['audits']['completed_audits']);
        $this->assertFalse($result['sources']['audits']['has_audit_program']);
        $this->assertEquals(0, $result['sources']['audits']['contribution']);
    }
}

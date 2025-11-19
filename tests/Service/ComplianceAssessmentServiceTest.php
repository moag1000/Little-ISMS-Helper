<?php

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Repository\ComplianceRequirementRepository;
use App\Service\ComplianceAssessmentService;
use App\Service\ComplianceMappingService;
use App\Service\ComplianceRequirementFulfillmentService;
use App\Service\TenantContext;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ComplianceAssessmentServiceTest extends TestCase
{
    private MockObject $requirementRepository;
    private MockObject $mappingService;
    private MockObject $entityManager;
    private MockObject $fulfillmentService;
    private MockObject $tenantContext;
    private ComplianceAssessmentService $service;

    protected function setUp(): void
    {
        $this->requirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->mappingService = $this->createMock(ComplianceMappingService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->fulfillmentService = $this->createMock(ComplianceRequirementFulfillmentService::class);
        $this->tenantContext = $this->createMock(TenantContext::class);

        $this->service = new ComplianceAssessmentService(
            $this->requirementRepository,
            $this->mappingService,
            $this->entityManager,
            $this->fulfillmentService,
            $this->tenantContext
        );
    }

    public function testAssessFrameworkWithNoRequirements(): void
    {
        $framework = $this->createFramework('Test Framework', 100.0);

        $this->requirementRepository->method('findByFramework')
            ->with($framework)
            ->willReturn([]);

        $result = $this->service->assessFramework($framework);

        $this->assertSame('Test Framework', $result['framework']);
        $this->assertSame(0, $result['total_requirements']);
        $this->assertSame(0, $result['requirements_assessed']);
        $this->assertEmpty($result['details']);
    }

    public function testAssessFrameworkWithRequirements(): void
    {
        $framework = $this->createFramework('NIS2', 75.0);

        $req1 = $this->createRequirement('NIS2-1', 'Req 1', true);
        $req2 = $this->createRequirement('NIS2-2', 'Req 2', true);

        $this->requirementRepository->method('findByFramework')
            ->willReturn([$req1, $req2]);

        $this->mappingService->method('getDataReuseAnalysis')
            ->willReturn([
                'sources' => ['controls' => ['contribution' => 80]],
                'confidence' => 'high',
            ]);

        $req1->expects($this->once())
            ->method('setFulfillmentPercentage')
            ->with(80);

        $req2->expects($this->once())
            ->method('setFulfillmentPercentage')
            ->with(80);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->service->assessFramework($framework);

        $this->assertSame(2, $result['total_requirements']);
        $this->assertSame(2, $result['requirements_assessed']);
        $this->assertCount(2, $result['details']);
    }

    public function testAssessRequirementNotApplicable(): void
    {
        $requirement = $this->createRequirement('REQ-1', 'Test Req', false);
        $requirement->method('isApplicable')->willReturn(false);

        $result = $this->service->assessRequirement($requirement);

        $this->assertSame('REQ-1', $result['requirement_id']);
        $this->assertSame(0, $result['calculated_fulfillment']);
        $this->assertSame('Not applicable', $result['reason']);
        $this->assertEmpty($result['data_sources']);
    }

    public function testAssessRequirementWithDataSources(): void
    {
        $requirement = $this->createRequirement('REQ-1', 'Test Req', true);
        $requirement->method('isApplicable')->willReturn(true);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());
        $requirement->method('getDataSourceMapping')->willReturn([]);

        $this->mappingService->method('getDataReuseAnalysis')
            ->with($requirement)
            ->willReturn([
                'sources' => [
                    'controls' => ['contribution' => 70],
                    'incidents' => ['contribution' => 90],
                ],
                'confidence' => 'medium',
            ]);

        $result = $this->service->assessRequirement($requirement);

        $this->assertSame('REQ-1', $result['requirement_id']);
        $this->assertSame(80, $result['calculated_fulfillment']); // Average of 70 and 90
        $this->assertSame('medium', $result['confidence']);
        $this->assertCount(2, $result['data_sources']);
    }

    public function testAssessRequirementWithNoSources(): void
    {
        $requirement = $this->createRequirement('REQ-1', 'Test Req', true);
        $requirement->method('isApplicable')->willReturn(true);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());
        $requirement->method('getDataSourceMapping')->willReturn([]);

        $this->mappingService->method('getDataReuseAnalysis')
            ->willReturn([
                'sources' => [],
                'confidence' => 'low',
            ]);

        $result = $this->service->assessRequirement($requirement);

        $this->assertSame(0, $result['calculated_fulfillment']);
    }

    public function testAssessRequirementIdentifiesNoControlsGap(): void
    {
        $requirement = $this->createRequirement('REQ-1', 'Test Req', true);
        $requirement->method('isApplicable')->willReturn(true);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());
        $requirement->method('getDataSourceMapping')->willReturn([]);

        $this->mappingService->method('getDataReuseAnalysis')
            ->willReturn([
                'sources' => ['controls' => ['contribution' => 50]],
                'confidence' => 'medium',
            ]);

        $result = $this->service->assessRequirement($requirement);

        $this->assertNotEmpty($result['gaps']);
        $gap = $result['gaps'][0];
        $this->assertSame('no_controls_mapped', $gap['type']);
        $this->assertSame('high', $gap['severity']);
    }

    public function testAssessRequirementIdentifiesIncompleteControlsGap(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('getControlId')->willReturn('A.5.1');
        $control->method('getImplementationStatus')->willReturn('partial');
        $control->method('getImplementationPercentage')->willReturn(60);

        $requirement = $this->createRequirement('REQ-1', 'Test Req', true);
        $requirement->method('isApplicable')->willReturn(true);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection([$control]));
        $requirement->method('getDataSourceMapping')->willReturn([]);

        $this->mappingService->method('getDataReuseAnalysis')
            ->willReturn([
                'sources' => ['controls' => ['contribution' => 60]],
                'confidence' => 'medium',
            ]);

        $result = $this->service->assessRequirement($requirement);

        $this->assertNotEmpty($result['gaps']);
        $gap = $result['gaps'][0];
        $this->assertSame('incomplete_controls', $gap['type']);
        $this->assertSame('medium', $gap['severity']); // 40 gap < 50
        $this->assertArrayHasKey('details', $gap);
        $this->assertSame('A.5.1', $gap['details'][0]['control_id']);
    }

    public function testAssessRequirementIdentifiesHighSeverityGap(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('getControlId')->willReturn('A.5.1');
        $control->method('getImplementationStatus')->willReturn('not_started');
        $control->method('getImplementationPercentage')->willReturn(0);

        $requirement = $this->createRequirement('REQ-1', 'Test Req', true);
        $requirement->method('isApplicable')->willReturn(true);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection([$control]));
        $requirement->method('getDataSourceMapping')->willReturn([]);

        $this->mappingService->method('getDataReuseAnalysis')
            ->willReturn([
                'sources' => ['controls' => ['contribution' => 20]],
                'confidence' => 'low',
            ]);

        $result = $this->service->assessRequirement($requirement);

        $gap = $result['gaps'][0];
        $this->assertSame('high', $gap['severity']); // 80 gap > 50
    }

    public function testAssessRequirementIdentifiesBCMGap(): void
    {
        $requirement = $this->createRequirement('REQ-1', 'Test Req', true);
        $requirement->method('isApplicable')->willReturn(true);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());
        $requirement->method('getDataSourceMapping')->willReturn(['bcm_required' => true]);

        $this->mappingService->method('getDataReuseAnalysis')
            ->willReturn([
                'sources' => ['controls' => ['contribution' => 80]],
                'confidence' => 'medium',
            ]);

        $result = $this->service->assessRequirement($requirement);

        $bcmGap = null;
        foreach ($result['gaps'] as $gap) {
            if ($gap['type'] === 'bcm_data_needed') {
                $bcmGap = $gap;
                break;
            }
        }

        $this->assertNotNull($bcmGap);
        $this->assertSame('medium', $bcmGap['severity']);
    }

    public function testAssessRequirementIdentifiesIncidentGap(): void
    {
        $requirement = $this->createRequirement('REQ-1', 'Test Req', true);
        $requirement->method('isApplicable')->willReturn(true);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());
        $requirement->method('getDataSourceMapping')->willReturn(['incident_management' => true]);

        $this->mappingService->method('getDataReuseAnalysis')
            ->willReturn([
                'sources' => ['controls' => ['contribution' => 80]],
                'confidence' => 'medium',
            ]);

        $result = $this->service->assessRequirement($requirement);

        $incidentGap = null;
        foreach ($result['gaps'] as $gap) {
            if ($gap['type'] === 'incident_data_needed') {
                $incidentGap = $gap;
                break;
            }
        }

        $this->assertNotNull($incidentGap);
        $this->assertSame('medium', $incidentGap['severity']);
    }

    public function testAssessRequirementWithFullCompliance(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('getControlId')->willReturn('A.5.1');
        $control->method('getImplementationStatus')->willReturn('implemented');
        $control->method('getImplementationPercentage')->willReturn(100);

        $requirement = $this->createRequirement('REQ-1', 'Test Req', true);
        $requirement->method('isApplicable')->willReturn(true);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection([$control]));
        $requirement->method('getDataSourceMapping')->willReturn([]);

        $this->mappingService->method('getDataReuseAnalysis')
            ->willReturn([
                'sources' => ['controls' => ['contribution' => 100]],
                'confidence' => 'high',
            ]);

        $result = $this->service->assessRequirement($requirement);

        $this->assertSame(100, $result['calculated_fulfillment']);
        $this->assertEmpty($result['gaps']);
    }

    public function testGetComplianceDashboard(): void
    {
        $framework = $this->createFramework('DORA', 85.0);

        $this->requirementRepository->method('getFrameworkStatistics')
            ->with($framework)
            ->willReturn([
                'total' => 100,
                'applicable' => 90,
                'fulfilled' => 80,
            ]);

        $this->requirementRepository->method('findGapsByFramework')
            ->willReturn([
                $this->createMockGapRequirement('critical'),
                $this->createMockGapRequirement('high'),
            ]);

        $this->requirementRepository->method('findByFrameworkAndPriority')
            ->with($framework, 'critical')
            ->willReturn([$this->createMockGapRequirement('critical')]);

        $this->requirementRepository->method('findApplicableByFramework')
            ->willReturn([]);

        $dashboard = $this->service->getComplianceDashboard($framework);

        $this->assertArrayHasKey('framework', $dashboard);
        $this->assertSame('DORA', $dashboard['framework']['name']);
        $this->assertArrayHasKey('statistics', $dashboard);
        $this->assertSame(85.0, $dashboard['compliance_percentage']);
        $this->assertArrayHasKey('gaps', $dashboard);
        $this->assertSame(2, $dashboard['gaps']['total']);
        $this->assertSame(1, $dashboard['gaps']['critical']);
    }

    public function testGetComplianceDashboardWithTimeSavings(): void
    {
        $framework = $this->createFramework('NIS2', 90.0);
        $requirement = $this->createRequirement('NIS2-1', 'Req 1', true);

        $this->requirementRepository->method('getFrameworkStatistics')
            ->willReturn(['total' => 50, 'applicable' => 50, 'fulfilled' => 45]);

        $this->requirementRepository->method('findGapsByFramework')
            ->willReturn([]);

        $this->requirementRepository->method('findByFrameworkAndPriority')
            ->willReturn([]);

        $this->requirementRepository->method('findApplicableByFramework')
            ->willReturn([$requirement, $requirement, $requirement]);

        $this->mappingService->method('calculateDataReuseValue')
            ->willReturn(['estimated_hours_saved' => 8]);

        $dashboard = $this->service->getComplianceDashboard($framework);

        $this->assertSame(24, $dashboard['data_reuse']['total_hours_saved']);
        $this->assertSame(3.0, $dashboard['data_reuse']['total_days_saved']);
    }

    public function testGetComplianceDashboardRecommendationsWithGaps(): void
    {
        $framework = $this->createFramework('DORA', 60.0);

        $this->requirementRepository->method('getFrameworkStatistics')
            ->willReturn(['total' => 100, 'applicable' => 90, 'fulfilled' => 54]);

        $unmappedGap = $this->createMockGapRequirement('critical');
        $unmappedGap->method('getMappedControls')->willReturn(new ArrayCollection());

        $this->requirementRepository->method('findGapsByFramework')
            ->willReturn([$unmappedGap]);

        $this->requirementRepository->method('findByFrameworkAndPriority')
            ->willReturn([$unmappedGap]);

        $this->requirementRepository->method('findApplicableByFramework')
            ->willReturn([]);

        $dashboard = $this->service->getComplianceDashboard($framework);

        $this->assertNotEmpty($dashboard['recommendations']);
        $recommendations = $dashboard['recommendations'];

        // Should have recommendation for critical gaps
        $hasCriticalRec = false;
        $hasUnmappedRec = false;
        foreach ($recommendations as $rec) {
            if (str_contains($rec['title'], 'Critical')) {
                $hasCriticalRec = true;
            }
            if (str_contains($rec['title'], 'Map Requirements')) {
                $hasUnmappedRec = true;
            }
        }

        $this->assertTrue($hasCriticalRec);
        $this->assertTrue($hasUnmappedRec);
    }

    public function testGetComplianceDashboardRecommendationsWithNoGaps(): void
    {
        $framework = $this->createFramework('ISO 27001', 100.0);

        $this->requirementRepository->method('getFrameworkStatistics')
            ->willReturn(['total' => 100, 'applicable' => 100, 'fulfilled' => 100]);

        $this->requirementRepository->method('findGapsByFramework')
            ->willReturn([]);

        $this->requirementRepository->method('findByFrameworkAndPriority')
            ->willReturn([]);

        $this->requirementRepository->method('findApplicableByFramework')
            ->willReturn([]);

        $dashboard = $this->service->getComplianceDashboard($framework);

        $this->assertNotEmpty($dashboard['recommendations']);
        $rec = $dashboard['recommendations'][0];
        $this->assertSame('low', $rec['priority']);
        $this->assertStringContainsString('Maintain', $rec['title']);
    }

    public function testCompareFrameworks(): void
    {
        $framework1 = $this->createFramework('NIS2', 85.0);
        $framework2 = $this->createFramework('DORA', 90.0);

        $this->requirementRepository->method('getFrameworkStatistics')
            ->willReturnCallback(function ($framework) {
                if ($framework->getName() === 'NIS2') {
                    return ['total' => 100, 'applicable' => 90, 'fulfilled' => 76];
                }
                return ['total' => 80, 'applicable' => 75, 'fulfilled' => 67];
            });

        $result = $this->service->compareFrameworks([$framework1, $framework2]);

        $this->assertCount(2, $result);

        $this->assertSame('NIS2', $result[0]['framework']);
        $this->assertSame(85.0, $result[0]['compliance_percentage']);
        $this->assertSame(100, $result[0]['total_requirements']);
        $this->assertSame(76, $result[0]['fulfilled']);
        $this->assertSame(14, $result[0]['gaps']); // 90 applicable - 76 fulfilled

        $this->assertSame('DORA', $result[1]['framework']);
        $this->assertSame(90.0, $result[1]['compliance_percentage']);
    }

    public function testCompareEmptyFrameworks(): void
    {
        $result = $this->service->compareFrameworks([]);

        $this->assertEmpty($result);
    }

    private function createFramework(string $name, float $compliance): MockObject
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('getId')->willReturn(1);
        $framework->method('getName')->willReturn($name);
        $framework->method('getCode')->willReturn(strtoupper(str_replace(' ', '_', $name)));
        $framework->method('getVersion')->willReturn('1.0');
        $framework->method('isMandatory')->willReturn(true);
        $framework->method('getCompliancePercentage')->willReturn($compliance);
        return $framework;
    }

    private function createRequirement(string $id, string $title, bool $applicable): MockObject
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn($id);
        $requirement->method('getTitle')->willReturn($title);
        $requirement->method('isApplicable')->willReturn($applicable);
        return $requirement;
    }

    private function createMockGapRequirement(string $priority): MockObject
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getPriority')->willReturn($priority);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());
        return $requirement;
    }
}

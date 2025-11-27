<?php

namespace App\Tests\Service;

use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\RiskTreatmentPlan;
use App\Entity\User;
use App\Service\DocumentApprovalService;
use App\Service\IncidentEscalationWorkflowService;
use App\Service\RiskAcceptanceWorkflowService;
use App\Service\RiskTreatmentPlanApprovalService;
use App\Service\WorkflowAutoTriggerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integration tests for Workflow Auto-Trigger Service
 *
 * Tests workflow triggering logic and applicable workflow detection
 */
class WorkflowAutoTriggerServiceTest extends TestCase
{
    private MockObject $incidentEscalationService;
    private MockObject $riskAcceptanceService;
    private MockObject $treatmentPlanApprovalService;
    private MockObject $documentApprovalService;
    private MockObject $logger;
    private WorkflowAutoTriggerService $service;

    protected function setUp(): void
    {
        $this->incidentEscalationService = $this->createMock(IncidentEscalationWorkflowService::class);
        $this->riskAcceptanceService = $this->createMock(RiskAcceptanceWorkflowService::class);
        $this->treatmentPlanApprovalService = $this->createMock(RiskTreatmentPlanApprovalService::class);
        $this->documentApprovalService = $this->createMock(DocumentApprovalService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new WorkflowAutoTriggerService(
            $this->incidentEscalationService,
            $this->riskAcceptanceService,
            $this->treatmentPlanApprovalService,
            $this->documentApprovalService,
            $this->logger
        );
    }

    public function testShouldTriggerWorkflowForNewIncident(): void
    {
        $incident = $this->createIncident(1, 'INC-001', 'high', false);

        // Empty change set = new entity
        $result = $this->service->shouldTriggerWorkflow($incident, []);

        $this->assertTrue($result);
    }

    public function testShouldTriggerWorkflowForSeverityChange(): void
    {
        $incident = $this->createIncident(2, 'INC-002', 'critical', false);

        // Change set with severity change
        $changeSet = [
            'severity' => ['medium', 'critical'],
        ];

        $result = $this->service->shouldTriggerWorkflow($incident, $changeSet);

        $this->assertTrue($result);
    }

    public function testShouldTriggerWorkflowForDataBreachChange(): void
    {
        $incident = $this->createIncident(3, 'INC-003', 'medium', true);

        // Change set with data breach flag change
        $changeSet = [
            'dataBreachOccurred' => [false, true],
        ];

        $result = $this->service->shouldTriggerWorkflow($incident, $changeSet);

        $this->assertTrue($result);
    }

    public function testShouldNotTriggerForIrrelevantIncidentChange(): void
    {
        $incident = $this->createIncident(4, 'INC-004', 'low', false);

        // Change set with only description update
        $changeSet = [
            'description' => ['Old', 'New'],
            'status' => ['open', 'investigating'],
        ];

        $result = $this->service->shouldTriggerWorkflow($incident, $changeSet);

        // Should still trigger because empty changeSet is not required
        // But with changeSet that doesn't include severity/dataBreachOccurred, should not trigger
        $this->assertFalse($result);
    }

    public function testShouldTriggerForPlannedRiskTreatmentPlan(): void
    {
        $plan = $this->createRiskTreatmentPlan(5, 'planned');

        $result = $this->service->shouldTriggerWorkflow($plan, []);

        $this->assertTrue($result);
    }

    public function testShouldNotTriggerForNonPlannedRiskTreatmentPlan(): void
    {
        $plan = $this->createRiskTreatmentPlan(6, 'in_progress');

        $result = $this->service->shouldTriggerWorkflow($plan, []);

        $this->assertFalse($result);
    }

    public function testShouldTriggerForPolicyDocument(): void
    {
        $document = $this->createDocument(7, 'policy');

        $result = $this->service->shouldTriggerWorkflow($document, []);

        $this->assertTrue($result);
    }

    public function testShouldTriggerForProcedureDocument(): void
    {
        $document = $this->createDocument(8, 'procedure');

        $result = $this->service->shouldTriggerWorkflow($document, []);

        $this->assertTrue($result);
    }

    public function testShouldTriggerForGuidelineDocument(): void
    {
        $document = $this->createDocument(9, 'guideline');

        $result = $this->service->shouldTriggerWorkflow($document, []);

        $this->assertTrue($result);
    }

    public function testShouldNotTriggerForOtherDocumentCategories(): void
    {
        $document = $this->createDocument(10, 'contract');

        $result = $this->service->shouldTriggerWorkflow($document, []);

        $this->assertFalse($result);
    }

    public function testShouldNotTriggerForRiskEntity(): void
    {
        // Risk entities require manual workflow triggering via controller
        $risk = $this->createMock(Risk::class);

        $result = $this->service->shouldTriggerWorkflow($risk, []);

        $this->assertFalse($result);
    }

    public function testShouldNotTriggerForNonWorkflowEntities(): void
    {
        $randomEntity = new \stdClass();

        $result = $this->service->shouldTriggerWorkflow($randomEntity, []);

        $this->assertFalse($result);
    }

    public function testTriggerIncidentWorkflowsForNewIncident(): void
    {
        $incident = $this->createIncident(11, 'INC-011', 'high', false);

        // Mock escalation service
        $this->incidentEscalationService->expects($this->once())
            ->method('autoEscalate')
            ->with($incident)
            ->willReturn([
                'escalation_level' => 'high',
                'workflow_started' => true,
                'sla_hours' => 8,
            ]);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        $results = $this->service->triggerIncidentWorkflows($incident, true);

        $this->assertArrayHasKey('escalation', $results);
        $this->assertSame('high', $results['escalation']['escalation_level']);
        $this->assertTrue($results['escalation']['workflow_started']);
    }

    public function testTriggerIncidentWorkflowsHandlesException(): void
    {
        $incident = $this->createIncident(12, 'INC-012', 'critical', false);

        $exception = new \RuntimeException('Escalation service error');
        $this->incidentEscalationService->method('autoEscalate')
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Triggering incident workflows', $this->isType('array'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to auto-escalate incident',
                $this->callback(function ($context) {
                    return isset($context['incident_id']) && isset($context['error']);
                })
            );

        $results = $this->service->triggerIncidentWorkflows($incident, true);

        // Should return error in results instead of throwing
        $this->assertArrayHasKey('escalation', $results);
        $this->assertArrayHasKey('error', $results['escalation']);
        $this->assertSame('Escalation service error', $results['escalation']['error']);
    }

    public function testTriggerRiskAcceptanceWorkflow(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(13);
        $risk->method('getRiskScore')->willReturn(75);
        $risk->method('getTreatmentStrategy')->willReturn('accept');

        $requester = $this->createMock(User::class);
        $justification = 'Business decision - low impact';

        $this->riskAcceptanceService->expects($this->once())
            ->method('requestAcceptance')
            ->with($risk, $requester, $justification)
            ->willReturn([
                'approval_level' => 'management',
                'workflow_started' => true,
            ]);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        $results = $this->service->triggerRiskAcceptanceWorkflow($risk, $requester, $justification);

        $this->assertArrayHasKey('acceptance', $results);
        $this->assertSame('management', $results['acceptance']['approval_level']);
        $this->assertTrue($results['acceptance']['workflow_started']);
    }

    public function testTriggerRiskAcceptanceWorkflowHandlesException(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(14);
        $risk->method('getRiskScore')->willReturn(80);
        $risk->method('getTreatmentStrategy')->willReturn('accept');

        $requester = $this->createMock(User::class);
        $justification = 'Test justification';

        $exception = new \RuntimeException('Risk acceptance service error');
        $this->riskAcceptanceService->method('requestAcceptance')
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('info');

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to trigger risk acceptance workflow',
                $this->callback(function ($context) {
                    return isset($context['risk_id']) && isset($context['error']);
                })
            );

        $results = $this->service->triggerRiskAcceptanceWorkflow($risk, $requester, $justification);

        $this->assertArrayHasKey('acceptance', $results);
        $this->assertArrayHasKey('error', $results['acceptance']);
    }

    public function testGetApplicableWorkflowsForIncident(): void
    {
        $incident = $this->createIncident(15, 'INC-015', 'medium', false);

        $workflows = $this->service->getApplicableWorkflows($incident);

        $this->assertIsArray($workflows);
        $this->assertCount(1, $workflows); // Only escalation workflow

        $this->assertSame('incident_escalation', $workflows[0]['type']);
        $this->assertSame('automatic', $workflows[0]['trigger']);
        $this->assertStringContainsString('ISO 27001:2022', $workflows[0]['compliance']);
    }

    public function testGetApplicableWorkflowsForDataBreachIncident(): void
    {
        $incident = $this->createIncident(16, 'INC-016', 'critical', true);

        $workflows = $this->service->getApplicableWorkflows($incident);

        $this->assertIsArray($workflows);
        $this->assertCount(2, $workflows); // Escalation + GDPR breach workflows

        // First workflow: incident escalation
        $this->assertSame('incident_escalation', $workflows[0]['type']);

        // Second workflow: GDPR breach notification
        $this->assertSame('gdpr_breach_notification', $workflows[1]['type']);
        $this->assertSame('automatic', $workflows[1]['trigger']);
        $this->assertStringContainsString('GDPR', $workflows[1]['compliance']);
        $this->assertSame(72, $workflows[1]['deadline_hours']);
    }

    public function testGetApplicableWorkflowsForPlannedTreatmentPlan(): void
    {
        $plan = $this->createRiskTreatmentPlan(17, 'planned');

        $workflows = $this->service->getApplicableWorkflows($plan);

        $this->assertIsArray($workflows);
        $this->assertCount(1, $workflows);

        $this->assertSame('treatment_plan_approval', $workflows[0]['type']);
        $this->assertSame('automatic', $workflows[0]['trigger']);
        $this->assertStringContainsString('ISO 27005:2022', $workflows[0]['compliance']);
    }

    public function testGetApplicableWorkflowsForNonPlannedTreatmentPlan(): void
    {
        $plan = $this->createRiskTreatmentPlan(18, 'completed');

        $workflows = $this->service->getApplicableWorkflows($plan);

        $this->assertIsArray($workflows);
        $this->assertEmpty($workflows); // No workflows for non-planned status
    }

    public function testGetApplicableWorkflowsForPolicyDocument(): void
    {
        $document = $this->createDocument(19, 'policy');

        $workflows = $this->service->getApplicableWorkflows($document);

        $this->assertIsArray($workflows);
        $this->assertCount(1, $workflows);

        $this->assertSame('document_approval', $workflows[0]['type']);
        $this->assertSame('automatic', $workflows[0]['trigger']);
        $this->assertStringContainsString('ISO 27001:2022', $workflows[0]['compliance']);
    }

    public function testGetApplicableWorkflowsForNonPolicyDocument(): void
    {
        $document = $this->createDocument(20, 'report');

        $workflows = $this->service->getApplicableWorkflows($document);

        $this->assertIsArray($workflows);
        $this->assertEmpty($workflows);
    }

    public function testGetApplicableWorkflowsForNonWorkflowEntity(): void
    {
        $randomEntity = new \stdClass();

        $workflows = $this->service->getApplicableWorkflows($randomEntity);

        $this->assertIsArray($workflows);
        $this->assertEmpty($workflows);
    }

    /**
     * Helper: Create mock incident
     */
    private function createIncident(int $id, string $incidentNumber, string $severity, bool $dataBreach): MockObject
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn($id);
        $incident->method('getIncidentNumber')->willReturn($incidentNumber);
        $incident->method('getSeverity')->willReturn($severity);
        $incident->method('isDataBreachOccurred')->willReturn($dataBreach);
        return $incident;
    }

    /**
     * Helper: Create mock document
     */
    private function createDocument(int $id, string $category): MockObject
    {
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn($id);
        $document->method('getCategory')->willReturn($category);
        return $document;
    }

    /**
     * Helper: Create mock risk treatment plan
     */
    private function createRiskTreatmentPlan(int $id, string $status): MockObject
    {
        $plan = $this->createMock(RiskTreatmentPlan::class);
        $plan->method('getId')->willReturn($id);
        $plan->method('getStatus')->willReturn($status);
        return $plan;
    }
}

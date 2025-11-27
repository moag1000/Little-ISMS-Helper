<?php

namespace App\Tests\EventListener;

use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\RiskTreatmentPlan;
use App\EventListener\WorkflowAutoTriggerListener;
use App\Service\WorkflowAutoTriggerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integration tests for Workflow Auto-Trigger Listener
 *
 * Tests Doctrine lifecycle event handling for automatic workflow triggering
 */
class WorkflowAutoTriggerListenerTest extends TestCase
{
    private MockObject $workflowAutoTriggerService;
    private MockObject $logger;
    private WorkflowAutoTriggerListener $listener;

    protected function setUp(): void
    {
        $this->workflowAutoTriggerService = $this->createMock(WorkflowAutoTriggerService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new WorkflowAutoTriggerListener(
            $this->workflowAutoTriggerService,
            $this->logger
        );
    }

    public function testPostPersistTriggersWorkflow(): void
    {
        $incident = $this->createIncident(1, 'INC-001', 'high');
        $args = $this->createPostPersistArgs($incident);

        // Service should determine if workflow triggering is needed
        $this->workflowAutoTriggerService->expects($this->once())
            ->method('shouldTriggerWorkflow')
            ->with($incident)
            ->willReturn(true);

        // Service should trigger incident workflows
        $this->workflowAutoTriggerService->expects($this->once())
            ->method('triggerIncidentWorkflows')
            ->with($incident, true) // true = isNew
            ->willReturn([
                'escalation' => [
                    'escalation_level' => 'high',
                    'workflow_started' => true,
                ]
            ]);

        // Logger should record the workflow triggering
        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->with(
                $this->logicalOr(
                    $this->equalTo('Auto-triggering incident workflows'),
                    $this->equalTo('Incident workflows triggered')
                ),
                $this->isType('array')
            );

        $this->listener->postPersist($incident, $args);
    }

    public function testPostUpdateTriggersOnSeverityChange(): void
    {
        $incident = $this->createIncident(2, 'INC-002', 'critical');

        // Create mock change set showing severity change
        $changeSet = [
            'severity' => ['medium', 'critical'],
        ];

        $args = $this->createPostUpdateArgs($incident, $changeSet);

        // Service should determine workflow is needed for severity change
        $this->workflowAutoTriggerService->expects($this->once())
            ->method('shouldTriggerWorkflow')
            ->with($incident, $changeSet)
            ->willReturn(true);

        // Service should trigger workflows for updated incident
        $this->workflowAutoTriggerService->expects($this->once())
            ->method('triggerIncidentWorkflows')
            ->with($incident, false) // false = not new
            ->willReturn([
                'escalation' => [
                    'escalation_level' => 'critical',
                    'workflow_started' => true,
                ]
            ]);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        $this->listener->postUpdate($incident, $args);
    }

    public function testPostUpdateTriggersOnBreachFlagChange(): void
    {
        $incident = $this->createIncident(3, 'INC-003', 'high');
        $incident->method('isDataBreachOccurred')->willReturn(true);

        // Change set showing dataBreachOccurred flag change
        $changeSet = [
            'dataBreachOccurred' => [false, true],
        ];

        $args = $this->createPostUpdateArgs($incident, $changeSet);

        // Service should determine GDPR workflow is needed
        $this->workflowAutoTriggerService->expects($this->once())
            ->method('shouldTriggerWorkflow')
            ->with($incident, $changeSet)
            ->willReturn(true);

        // Service should trigger data breach workflow
        $this->workflowAutoTriggerService->expects($this->once())
            ->method('triggerIncidentWorkflows')
            ->with($incident, false)
            ->willReturn([
                'escalation' => [
                    'escalation_level' => 'data_breach',
                    'workflow_started' => true,
                    'deadline' => new \DateTimeImmutable('+72 hours'),
                ]
            ]);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        $this->listener->postUpdate($incident, $args);
    }

    public function testListenerDoesNotTriggerForIrrelevantChanges(): void
    {
        $incident = $this->createIncident(4, 'INC-004', 'medium');

        // Change set showing only description update (not severity or breach flag)
        $changeSet = [
            'description' => ['Old description', 'New description'],
        ];

        $args = $this->createPostUpdateArgs($incident, $changeSet);

        // Service should determine workflow is NOT needed
        $this->workflowAutoTriggerService->expects($this->once())
            ->method('shouldTriggerWorkflow')
            ->with($incident, $changeSet)
            ->willReturn(false);

        // No workflows should be triggered
        $this->workflowAutoTriggerService->expects($this->never())
            ->method('triggerIncidentWorkflows');

        // No info logging should occur
        $this->logger->expects($this->never())
            ->method('info');

        $this->listener->postUpdate($incident, $args);
    }

    public function testPostPersistHandlesServiceException(): void
    {
        $incident = $this->createIncident(5, 'INC-005', 'critical');
        $args = $this->createPostPersistArgs($incident);

        $this->workflowAutoTriggerService->method('shouldTriggerWorkflow')
            ->willReturn(true);

        // Service throws exception during workflow triggering
        $exception = new \RuntimeException('Workflow service unavailable');
        $this->workflowAutoTriggerService->method('triggerIncidentWorkflows')
            ->willThrowException($exception);

        // Logger should record the error
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to trigger workflow for new entity',
                $this->callback(function ($context) {
                    return isset($context['entity_class'])
                        && isset($context['entity_id'])
                        && isset($context['error'])
                        && isset($context['trace']);
                })
            );

        // Should not throw exception - error is logged but transaction continues
        $this->listener->postPersist($incident, $args);
    }

    public function testPostUpdateHandlesServiceException(): void
    {
        $incident = $this->createIncident(6, 'INC-006', 'high');
        $changeSet = ['severity' => ['medium', 'high']];
        $args = $this->createPostUpdateArgs($incident, $changeSet);

        $this->workflowAutoTriggerService->method('shouldTriggerWorkflow')
            ->willReturn(true);

        $exception = new \RuntimeException('Email service failure');
        $this->workflowAutoTriggerService->method('triggerIncidentWorkflows')
            ->willThrowException($exception);

        // Logger should record the error
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to trigger workflow for updated entity',
                $this->callback(function ($context) use ($changeSet) {
                    return isset($context['entity_class'])
                        && isset($context['entity_id'])
                        && isset($context['changed_fields'])
                        && $context['changed_fields'] === array_keys($changeSet)
                        && isset($context['error']);
                })
            );

        $this->listener->postUpdate($incident, $args);
    }

    public function testPostPersistHandlesDocumentEntity(): void
    {
        $document = $this->createDocument(7, 'policy');
        $args = $this->createPostPersistArgs($document);

        $this->workflowAutoTriggerService->expects($this->once())
            ->method('shouldTriggerWorkflow')
            ->with($document)
            ->willReturn(true);

        // Should trigger document workflows
        $this->workflowAutoTriggerService->expects($this->once())
            ->method('triggerDocumentWorkflows')
            ->with($document, true)
            ->willReturn([
                'approval' => [
                    'workflow_started' => true,
                ]
            ]);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        $this->listener->postPersist($document, $args);
    }

    public function testPostPersistHandlesRiskTreatmentPlanEntity(): void
    {
        $plan = $this->createRiskTreatmentPlan(8, 'planned');
        $args = $this->createPostPersistArgs($plan);

        $this->workflowAutoTriggerService->expects($this->once())
            ->method('shouldTriggerWorkflow')
            ->with($plan)
            ->willReturn(true);

        // Should trigger risk treatment plan workflows
        $this->workflowAutoTriggerService->expects($this->once())
            ->method('triggerRiskTreatmentPlanWorkflows')
            ->with($plan)
            ->willReturn([
                'approval' => [
                    'workflow_started' => true,
                ]
            ]);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        $this->listener->postPersist($plan, $args);
    }

    public function testPostPersistSkipsNonWorkflowEntities(): void
    {
        // Create some random entity that doesn't require workflows
        $randomEntity = new \stdClass();
        $args = $this->createPostPersistArgs($randomEntity);

        $this->workflowAutoTriggerService->expects($this->once())
            ->method('shouldTriggerWorkflow')
            ->with($randomEntity)
            ->willReturn(false);

        // No workflows should be triggered
        $this->workflowAutoTriggerService->expects($this->never())
            ->method('triggerIncidentWorkflows');

        $this->listener->postPersist($randomEntity, $args);
    }

    /**
     * Helper: Create mock incident
     */
    private function createIncident(int $id, string $incidentNumber, string $severity): MockObject
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn($id);
        $incident->method('getIncidentNumber')->willReturn($incidentNumber);
        $incident->method('getSeverity')->willReturn($severity);
        $incident->method('isDataBreachOccurred')->willReturn(false);
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

    /**
     * Helper: Create PostPersistEventArgs stub
     * Since PostPersistEventArgs is final, we create a real instance
     */
    private function createPostPersistArgs(object $entity): PostPersistEventArgs
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        return new PostPersistEventArgs($entity, $entityManager);
    }

    /**
     * Helper: Create PostUpdateEventArgs stub with change set
     * Since PostUpdateEventArgs is final, we create a real instance
     */
    private function createPostUpdateArgs(object $entity, array $changeSet): PostUpdateEventArgs
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $unitOfWork = $this->createMock(UnitOfWork::class);

        $unitOfWork->method('getEntityChangeSet')
            ->with($entity)
            ->willReturn($changeSet);

        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return new PostUpdateEventArgs($entity, $entityManager);
    }
}

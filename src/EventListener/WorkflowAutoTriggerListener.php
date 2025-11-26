<?php

namespace App\EventListener;

use App\Entity\Incident;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Document;
use App\Service\WorkflowAutoTriggerService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Workflow Auto-Trigger Doctrine Listener
 *
 * Automatically triggers workflows when entities are created or updated.
 * Listens to Doctrine lifecycle events and delegates to WorkflowAutoTriggerService.
 *
 * Supported entities:
 * - Incident: Auto-escalation + GDPR breach workflows
 * - RiskTreatmentPlan: Approval workflows
 * - Document: Policy/Procedure approval workflows
 *
 * Events:
 * - postPersist: After entity is inserted into database
 * - postUpdate: After entity is updated in database
 */
#[AsEntityListener(event: Events::postPersist, entity: Incident::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Incident::class)]
#[AsEntityListener(event: Events::postPersist, entity: RiskTreatmentPlan::class)]
#[AsEntityListener(event: Events::postPersist, entity: Document::class)]
class WorkflowAutoTriggerListener
{
    public function __construct(
        private WorkflowAutoTriggerService $workflowAutoTriggerService,
        private LoggerInterface $logger
    ) {}

    /**
     * Handle post-persist events (new entities)
     *
     * Entity listeners receive the entity as first argument, then the event args
     */
    public function postPersist(object $entity, PostPersistEventArgs $args): void
    {

        // Check if this entity type requires workflow triggering
        if (!$this->workflowAutoTriggerService->shouldTriggerWorkflow($entity)) {
            return;
        }

        try {
            $this->triggerWorkflowForEntity($entity, true);
        } catch (\Exception $e) {
            // Log error but don't fail the transaction
            $this->logger->error('Failed to trigger workflow for new entity', [
                'entity_class' => get_class($entity),
                'entity_id' => method_exists($entity, 'getId') ? $entity->getId() : null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle post-update events (entity updates)
     *
     * Entity listeners receive the entity as first argument, then the event args
     */
    public function postUpdate(object $entity, PostUpdateEventArgs $args): void
    {
        $changeSet = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);

        // Check if this entity change requires workflow triggering
        if (!$this->workflowAutoTriggerService->shouldTriggerWorkflow($entity, $changeSet)) {
            return;
        }

        try {
            $this->triggerWorkflowForEntity($entity, false, $changeSet);
        } catch (\Exception $e) {
            // Log error but don't fail the transaction
            $this->logger->error('Failed to trigger workflow for updated entity', [
                'entity_class' => get_class($entity),
                'entity_id' => method_exists($entity, 'getId') ? $entity->getId() : null,
                'changed_fields' => array_keys($changeSet),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Trigger appropriate workflow based on entity type
     *
     * @param object $entity
     * @param bool $isNew
     * @param array $changeSet
     */
    private function triggerWorkflowForEntity(object $entity, bool $isNew, array $changeSet = []): void
    {
        if ($entity instanceof Incident) {
            $this->handleIncidentWorkflow($entity, $isNew, $changeSet);
        } elseif ($entity instanceof RiskTreatmentPlan) {
            $this->handleRiskTreatmentPlanWorkflow($entity);
        } elseif ($entity instanceof Document) {
            $this->handleDocumentWorkflow($entity, $isNew);
        }
    }

    /**
     * Handle Incident workflow triggering
     *
     * @param Incident $incident
     * @param bool $isNew
     * @param array $changeSet
     */
    private function handleIncidentWorkflow(Incident $incident, bool $isNew, array $changeSet = []): void
    {
        // Trigger on new incident or when severity/breach status changes
        if ($isNew || isset($changeSet['severity']) || isset($changeSet['dataBreachOccurred'])) {
            $this->logger->info('Auto-triggering incident workflows', [
                'incident_id' => $incident->getId(),
                'incident_number' => $incident->getIncidentNumber(),
                'severity' => $incident->getSeverity(),
                'is_new' => $isNew,
                'changed_fields' => array_keys($changeSet),
            ]);

            $results = $this->workflowAutoTriggerService->triggerIncidentWorkflows($incident, $isNew);

            $this->logger->info('Incident workflows triggered', [
                'incident_id' => $incident->getId(),
                'results' => $results,
            ]);
        }
    }

    /**
     * Handle RiskTreatmentPlan workflow triggering
     *
     * @param RiskTreatmentPlan $plan
     */
    private function handleRiskTreatmentPlanWorkflow(RiskTreatmentPlan $plan): void
    {
        // Only trigger for new plans in 'planned' status
        if ($plan->getStatus() === 'planned') {
            $this->logger->info('Auto-triggering risk treatment plan workflows', [
                'plan_id' => $plan->getId(),
                'risk_id' => $plan->getRisk()?->getId(),
            ]);

            $results = $this->workflowAutoTriggerService->triggerRiskTreatmentPlanWorkflows($plan);

            $this->logger->info('Risk treatment plan workflows triggered', [
                'plan_id' => $plan->getId(),
                'results' => $results,
            ]);
        }
    }

    /**
     * Handle Document workflow triggering
     *
     * @param Document $document
     * @param bool $isNew
     */
    private function handleDocumentWorkflow(Document $document, bool $isNew): void
    {
        // Only trigger for policy/procedure documents
        if (in_array($document->getCategory(), ['policy', 'procedure', 'guideline'])) {
            $this->logger->info('Auto-triggering document workflows', [
                'document_id' => $document->getId(),
                'category' => $document->getCategory(),
                'is_new' => $isNew,
            ]);

            $results = $this->workflowAutoTriggerService->triggerDocumentWorkflows($document, $isNew);

            $this->logger->info('Document workflows triggered', [
                'document_id' => $document->getId(),
                'results' => $results,
            ]);
        }
    }
}

<?php

namespace App\EventSubscriber;

use App\Entity\AuditLog;
use App\Service\AuditLogger;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Audit Log Subscriber
 *
 * Doctrine Event Listener that automatically logs all entity lifecycle changes for compliance and forensic analysis.
 * Implements OWASP A9: Security Logging and Monitoring Failures prevention.
 *
 * Monitored Events:
 * - postPersist: Entity creation - logs new entity with all field values
 * - preUpdate: Before entity update - captures old values for change tracking
 * - postUpdate: After entity update - logs changes with old/new value comparison
 * - postRemove: Entity deletion - logs final state before removal
 *
 * Security Features:
 * - Infinite loop prevention: Skips AuditLog entities
 * - Selective auditing: Only logs configured entity types
 * - Change set tracking: Captures field-level changes
 * - Temporal storage: Uses pendingUpdates array for pre/post update coordination
 *
 * Audited Entities:
 * - Core ISMS: Asset, Risk, Control, Incident
 * - Governance: ISMSContext, ISMSObjective, ManagementReview
 * - Compliance: ComplianceFramework, ComplianceRequirement, ComplianceMapping
 * - Operations: Training, BusinessProcess, InternalAudit, AuditChecklist
 *
 * ISO 27001 Compliance:
 * - A.12.4.1: Event logging - Complete audit trail
 * - A.12.4.3: Administrator and operator logs
 * - A.16.1.7: Collection of evidence
 *
 * Workflow (Update):
 * 1. preUpdate: Captures old values and stores in pendingUpdates
 * 2. Entity is persisted to database
 * 3. postUpdate: Retrieves stored values and creates audit log entry
 * 4. Cleanup: Removes temporary data from pendingUpdates
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
class AuditLogSubscriber
{
    private array $pendingUpdates = [];

    public function __construct(
        private AuditLogger $auditLogger
    ) {}

    /**
     * Log entity creation
     */
    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();

        // Don't log AuditLog entities themselves to prevent infinite loops
        if ($entity instanceof AuditLog) {
            return;
        }

        // Check if entity should be audited
        if (!$this->shouldAudit($entity)) {
            return;
        }

        $entityType = $this->auditLogger->getEntityTypeName($entity);
        $entityId = $this->getEntityId($entity);
        $values = $this->auditLogger->extractEntityValues($entity);

        $this->auditLogger->logCreate(
            $entityType,
            $entityId,
            $values,
            sprintf('%s created', $entityType)
        );
    }

    /**
     * Capture old values before update
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        // Don't log AuditLog entities themselves
        if ($entity instanceof AuditLog) {
            return;
        }

        // Check if entity should be audited
        if (!$this->shouldAudit($entity)) {
            return;
        }

        $entityId = $this->getEntityId($entity);
        $entityHash = spl_object_hash($entity);

        // Store old and new values
        $oldValues = [];
        $newValues = [];

        foreach ($args->getEntityChangeSet() as $field => $changes) {
            $oldValues[$field] = $changes[0];
            $newValues[$field] = $changes[1];
        }

        $this->pendingUpdates[$entityHash] = [
            'entityType' => $this->auditLogger->getEntityTypeName($entity),
            'entityId' => $entityId,
            'oldValues' => $oldValues,
            'newValues' => $newValues
        ];
    }

    /**
     * Log entity update after it's been saved
     */
    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        // Don't log AuditLog entities themselves
        if ($entity instanceof AuditLog) {
            return;
        }

        $entityHash = spl_object_hash($entity);

        // Check if we have pending update data
        if (!isset($this->pendingUpdates[$entityHash])) {
            return;
        }

        $updateData = $this->pendingUpdates[$entityHash];

        $this->auditLogger->logUpdate(
            $updateData['entityType'],
            $updateData['entityId'],
            $updateData['oldValues'],
            $updateData['newValues'],
            sprintf('%s updated', $updateData['entityType'])
        );

        // Clean up
        unset($this->pendingUpdates[$entityHash]);
    }

    /**
     * Log entity deletion
     */
    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        // Don't log AuditLog entities themselves
        if ($entity instanceof AuditLog) {
            return;
        }

        // Check if entity should be audited
        if (!$this->shouldAudit($entity)) {
            return;
        }

        $entityType = $this->auditLogger->getEntityTypeName($entity);
        $entityId = $this->getEntityId($entity);
        $values = $this->auditLogger->extractEntityValues($entity);

        $this->auditLogger->logDelete(
            $entityType,
            $entityId,
            $values,
            sprintf('%s deleted', $entityType)
        );
    }

    /**
     * Determine if an entity should be audited
     */
    private function shouldAudit(object $entity): bool
    {
        // Get the class name without namespace
        $className = $this->auditLogger->getEntityTypeName($entity);

        // List of entities to audit
        $auditedEntities = [
            'Asset',
            'Risk',
            'Control',
            'Incident',
            'InternalAudit',
            'ManagementReview',
            'ISMSContext',
            'ISMSObjective',
            'Training',
            'BusinessProcess',
            'AuditChecklist',
            'ComplianceRequirement',
            'ComplianceFramework',
            'ComplianceMapping',
            // New entities for 100% ISO compliance
            'Supplier',
            'InterestedParty',
            'BusinessContinuityPlan',
            'BCExercise',
            'ChangeRequest'
        ];

        return in_array($className, $auditedEntities);
    }

    /**
     * Get entity ID
     */
    private function getEntityId(object $entity): ?int
    {
        if (method_exists($entity, 'getId')) {
            return $entity->getId();
        }

        return null;
    }
}

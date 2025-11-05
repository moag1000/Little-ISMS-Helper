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
 * Doctrine Event Subscriber that automatically logs entity changes
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
            'ComplianceMapping'
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

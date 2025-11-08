<?php

namespace App\EventListener;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Doctrine Event Listener for automatic audit logging
 * Tracks all changes to entities for compliance and security auditing
 */
#[AsDoctrineListener(event: Events::prePersist, priority: 500)]
#[AsDoctrineListener(event: Events::preUpdate, priority: 500)]
#[AsDoctrineListener(event: Events::postPersist, priority: 500)]
#[AsDoctrineListener(event: Events::postUpdate, priority: 500)]
#[AsDoctrineListener(event: Events::postRemove, priority: 500)]
class AuditLogListener
{
    private array $changesets = [];
    private array $auditableEntities = [
        'App\Entity\Asset',
        'App\Entity\Risk',
        'App\Entity\Control',
        'App\Entity\Incident',
        'App\Entity\InternalAudit',
        'App\Entity\BusinessProcess',
        'App\Entity\Training',
        'App\Entity\ComplianceFramework',
        'App\Entity\ComplianceRequirement',
        'App\Entity\User',
        'App\Entity\Role',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack
    ) {
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->isAuditable($entity)) {
            return;
        }

        // Store entity for post-persist
        $oid = spl_object_id($entity);
        $this->changesets[$oid] = [
            'action' => 'created',
            'entity' => $entity,
            'new_values' => $this->getEntityData($entity),
        ];
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->isAuditable($entity)) {
            return;
        }

        $changeSet = $args->getEntityChangeSet();
        $oldValues = [];
        $newValues = [];
        $changedFields = [];

        foreach ($changeSet as $field => $values) {
            // Skip certain fields
            if (in_array($field, ['updatedAt', 'lastLoginAt'])) {
                continue;
            }

            $changedFields[] = $field;
            $oldValues[$field] = $this->normalizeValue($values[0]);
            $newValues[$field] = $this->normalizeValue($values[1]);
        }

        if (empty($changedFields)) {
            return;
        }

        $oid = spl_object_id($entity);
        $this->changesets[$oid] = [
            'action' => 'updated',
            'entity' => $entity,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_fields' => $changedFields,
        ];
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $oid = spl_object_id($entity);

        if (!isset($this->changesets[$oid])) {
            return;
        }

        $this->createAuditLog($args, $this->changesets[$oid]);
        unset($this->changesets[$oid]);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $oid = spl_object_id($entity);

        if (!isset($this->changesets[$oid])) {
            return;
        }

        $this->createAuditLog($args, $this->changesets[$oid]);
        unset($this->changesets[$oid]);
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->isAuditable($entity)) {
            return;
        }

        $changeset = [
            'action' => 'deleted',
            'entity' => $entity,
            'old_values' => $this->getEntityData($entity),
        ];

        $this->createAuditLog($args, $changeset);
    }

    private function createAuditLog(LifecycleEventArgs $args, array $changeset): void
    {
        $entity = $changeset['entity'];
        $entityManager = $args->getObjectManager();

        // Don't log AuditLog changes (avoid infinite loop)
        if ($entity instanceof AuditLog) {
            return;
        }

        $auditLog = new AuditLog();
        $auditLog->setEntityType(get_class($entity));
        $auditLog->setEntityId($this->getEntityId($entity));
        $auditLog->setAction($changeset['action']);

        // Set user
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $auditLog->setUser($user);
        }

        // Set values (serialize arrays to JSON strings)
        if (isset($changeset['old_values'])) {
            $auditLog->setOldValues(
                is_array($changeset['old_values'])
                    ? json_encode($changeset['old_values'], JSON_UNESCAPED_UNICODE)
                    : $changeset['old_values']
            );
        }

        if (isset($changeset['new_values'])) {
            $auditLog->setNewValues(
                is_array($changeset['new_values'])
                    ? json_encode($changeset['new_values'], JSON_UNESCAPED_UNICODE)
                    : $changeset['new_values']
            );
        }

        if (isset($changeset['changed_fields'])) {
            $auditLog->setChangedFields(
                is_array($changeset['changed_fields'])
                    ? json_encode($changeset['changed_fields'], JSON_UNESCAPED_UNICODE)
                    : $changeset['changed_fields']
            );
        }

        // Set request information
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $auditLog->setIpAddress($request->getClientIp());
            $auditLog->setUserAgent($request->headers->get('User-Agent'));
        }

        // Persist audit log
        $entityManager->persist($auditLog);
        $entityManager->flush();
    }

    private function isAuditable(object $entity): bool
    {
        $class = get_class($entity);
        return in_array($class, $this->auditableEntities, true);
    }

    private function getEntityId(object $entity): ?int
    {
        if (method_exists($entity, 'getId')) {
            return $entity->getId();
        }

        return null;
    }

    private function getEntityData(object $entity): array
    {
        $data = [];
        $reflection = new \ReflectionClass($entity);

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $name = $property->getName();

            // Skip certain properties
            if (in_array($name, ['id', '__initializer__', '__cloner__', '__isInitialized__'])) {
                continue;
            }

            $value = $property->getValue($entity);
            $data[$name] = $this->normalizeValue($value);
        }

        return $data;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value)) {
            if (method_exists($value, 'getId')) {
                return get_class($value) . '#' . $value->getId();
            }
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            return get_class($value);
        }

        if (is_array($value)) {
            return array_map([$this, 'normalizeValue'], $value);
        }

        return $value;
    }
}

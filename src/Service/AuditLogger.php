<?php

namespace App\Service;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditLogger
{
    private const ACTION_CREATE = 'create';
    private const ACTION_UPDATE = 'update';
    private const ACTION_DELETE = 'delete';
    private const ACTION_VIEW = 'view';
    private const ACTION_LOGIN = 'login';
    private const ACTION_LOGOUT = 'logout';
    private const ACTION_EXPORT = 'export';
    private const ACTION_IMPORT = 'import';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack
    ) {}

    /**
     * Log a create action
     */
    public function logCreate(string $entityType, int $entityId, array $newValues, ?string $description = null): void
    {
        $this->log(self::ACTION_CREATE, $entityType, $entityId, null, $newValues, $description);
    }

    /**
     * Log an update action
     */
    public function logUpdate(string $entityType, int $entityId, array $oldValues, array $newValues, ?string $description = null): void
    {
        // Only log if there are actual changes
        $changes = $this->getChanges($oldValues, $newValues);
        if (!empty($changes)) {
            $this->log(self::ACTION_UPDATE, $entityType, $entityId, $changes['old'], $changes['new'], $description);
        }
    }

    /**
     * Log a delete action
     */
    public function logDelete(string $entityType, int $entityId, array $oldValues, ?string $description = null): void
    {
        $this->log(self::ACTION_DELETE, $entityType, $entityId, $oldValues, null, $description);
    }

    /**
     * Log a view action (for sensitive data)
     */
    public function logView(string $entityType, int $entityId, ?string $description = null): void
    {
        $this->log(self::ACTION_VIEW, $entityType, $entityId, null, null, $description);
    }

    /**
     * Log an export action
     */
    public function logExport(string $entityType, ?int $entityId = null, ?string $description = null): void
    {
        $this->log(self::ACTION_EXPORT, $entityType, $entityId, null, null, $description);
    }

    /**
     * Log an import action
     */
    public function logImport(string $entityType, int $count, ?string $description = null): void
    {
        $this->log(self::ACTION_IMPORT, $entityType, null, null, ['count' => $count], $description);
    }

    /**
     * Log a custom action
     */
    public function logCustom(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null
    ): void {
        $this->log($action, $entityType, $entityId, $oldValues, $newValues, $description);
    }

    /**
     * Core logging method
     */
    private function log(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $oldValues,
        ?array $newValues,
        ?string $description
    ): void {
        $request = $this->requestStack->getCurrentRequest();

        $auditLog = new AuditLog();
        $auditLog->setAction($action);
        $auditLog->setEntityType($entityType);
        $auditLog->setEntityId($entityId);
        $auditLog->setDescription($description);

        // Set user information
        $userName = $this->getCurrentUserName();
        $auditLog->setUserName($userName);

        // Set request information if available
        if ($request) {
            $auditLog->setIpAddress($request->getClientIp());
            $auditLog->setUserAgent($request->headers->get('User-Agent'));
        }

        // Set values as JSON
        if ($oldValues !== null) {
            $auditLog->setOldValues(json_encode($this->sanitizeValues($oldValues), JSON_UNESCAPED_UNICODE));
        }

        if ($newValues !== null) {
            $auditLog->setNewValues(json_encode($this->sanitizeValues($newValues), JSON_UNESCAPED_UNICODE));
        }

        // Persist the audit log
        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();
    }

    /**
     * Get the current user name
     */
    private function getCurrentUserName(): string
    {
        // For now, we'll use a simple approach.
        // In a real application, you would get this from the security context
        // e.g., $this->security->getUser()->getUserIdentifier()

        // Check if there's a request and if it has session data
        $request = $this->requestStack->getCurrentRequest();
        if ($request && $request->hasSession()) {
            $session = $request->getSession();
            if ($session->has('_security_main')) {
                // Try to extract user from session
                return 'authenticated_user'; // Placeholder
            }
        }

        return 'system';
    }

    /**
     * Compare old and new values and return only the changes
     */
    private function getChanges(array $oldValues, array $newValues): array
    {
        $changedOld = [];
        $changedNew = [];

        foreach ($newValues as $key => $newValue) {
            $oldValue = $oldValues[$key] ?? null;

            // Convert objects to strings for comparison
            if ($oldValue instanceof \DateTimeInterface) {
                $oldValue = $oldValue->format('Y-m-d H:i:s');
            }
            if ($newValue instanceof \DateTimeInterface) {
                $newValue = $newValue->format('Y-m-d H:i:s');
            }

            // Only include if values are different
            if ($oldValue !== $newValue) {
                $changedOld[$key] = $oldValue;
                $changedNew[$key] = $newValue;
            }
        }

        return [
            'old' => $changedOld,
            'new' => $changedNew
        ];
    }

    /**
     * Sanitize values for storage (remove sensitive data, convert objects, etc.)
     */
    private function sanitizeValues(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            // Skip password fields
            if (stripos($key, 'password') !== false || stripos($key, 'token') !== false) {
                $sanitized[$key] = '***';
                continue;
            }

            // Convert DateTime objects
            if ($value instanceof \DateTimeInterface) {
                $sanitized[$key] = $value->format('Y-m-d H:i:s');
                continue;
            }

            // Convert arrays and objects to JSON
            if (is_array($value) || is_object($value)) {
                $sanitized[$key] = json_encode($value);
                continue;
            }

            // Truncate very long strings
            if (is_string($value) && strlen($value) > 1000) {
                $sanitized[$key] = substr($value, 0, 1000) . '... (truncated)';
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Get entity type name from entity object
     */
    public function getEntityTypeName(object $entity): string
    {
        $className = get_class($entity);
        return substr($className, strrpos($className, '\\') + 1);
    }

    /**
     * Extract values from an entity for logging
     */
    public function extractEntityValues(object $entity): array
    {
        $values = [];
        $reflection = new \ReflectionClass($entity);

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $name = $property->getName();

            // Skip certain properties
            if (in_array($name, ['risks', '__initializer__', '__cloner__', '__isInitialized__'])) {
                continue;
            }

            try {
                $value = $property->getValue($entity);

                // Skip collections and complex objects
                if ($value instanceof \Doctrine\Common\Collections\Collection) {
                    continue;
                }

                $values[$name] = $value;
            } catch (\Exception $e) {
                // Skip properties that can't be accessed
                continue;
            }
        }

        return $values;
    }
}

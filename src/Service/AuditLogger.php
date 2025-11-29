<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use DateTimeInterface;
use ReflectionClass;
use Doctrine\Common\Collections\Collection;
use Exception;
use App\Entity\AuditLog;
use App\Entity\Risk;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditLogger
{
    private const string ACTION_CREATE = 'create';
    private const string ACTION_UPDATE = 'update';
    private const string ACTION_DELETE = 'delete';
    private const string ACTION_VIEW = 'view';
    private const string ACTION_EXPORT = 'export';
    private const string ACTION_IMPORT = 'import';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly Security $security
    ) {}

    /**
     * Log a create action
     */
    public function logCreate(string $entityType, ?int $entityId, array $newValues, ?string $description = null): void
    {
        $this->log(self::ACTION_CREATE, $entityType, $entityId, null, $newValues, $description);
    }

    /**
     * Log an update action
     */
    public function logUpdate(string $entityType, ?int $entityId, array $oldValues, array $newValues, ?string $description = null): void
    {
        // Only log if there are actual changes
        $changes = $this->getChanges($oldValues, $newValues);
        if (!empty($changes['old']) || !empty($changes['new'])) {
            $this->log(self::ACTION_UPDATE, $entityType, $entityId, $changes['old'], $changes['new'], $description);
        }
    }

    /**
     * Log a delete action
     */
    public function logDelete(string $entityType, ?int $entityId, array $oldValues, ?string $description = null): void
    {
        $this->log(self::ACTION_DELETE, $entityType, $entityId, $oldValues, null, $description);
    }

    /**
     * Log a view action (for sensitive data)
     */
    public function logView(string $entityType, ?int $entityId, ?string $description = null): void
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
        ?string $description = null,
        ?string $userName = null
    ): void {
        $this->log($action, $entityType, $entityId, $oldValues, $newValues, $description, $userName);
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
        ?string $description,
        ?string $userName = null
    ): void {
        $request = $this->requestStack->getCurrentRequest();

        $auditLog = new AuditLog();
        $auditLog->setAction($action);
        $auditLog->setEntityType($entityType);
        $auditLog->setEntityId($entityId);
        $auditLog->setDescription($description);

        // Set user information (use provided userName or get from security context)
        $userName ??= $this->getCurrentUserName();
        $auditLog->setUserName($userName);

        // Set request information if available
        if ($request instanceof Request) {
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

        // Persist and flush the audit log immediately
        // This is necessary because we're in a Doctrine event listener (postPersist, postUpdate, etc.)
        // which runs AFTER the main flush(). Without this flush(), the audit log would never be saved.
        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();
    }

    /**
     * Get the current user name from the security context
     */
    private function getCurrentUserName(): string
    {
        $user = $this->security->getUser();

        if ($user instanceof User) {
            return $user->getEmail();
        }

        // For CLI operations (e.g., setup commands, migrations) or unauthenticated requests
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
            if ($oldValue instanceof DateTimeInterface) {
                $oldValue = $oldValue->format('Y-m-d H:i:s');
            }
            if ($newValue instanceof DateTimeInterface) {
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
            if (stripos((string) $key, 'password') !== false || stripos((string) $key, 'token') !== false) {
                $sanitized[$key] = '***';
                continue;
            }

            // Convert DateTime objects
            if ($value instanceof DateTimeInterface) {
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
        $className = $entity::class;
        return substr($className, strrpos($className, '\\') + 1);
    }

    /**
     * Extract values from an entity for logging
     */
    public function extractEntityValues(object $entity): array
    {
        $values = [];
        $reflectionClass = new ReflectionClass($entity);

        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            $name = $reflectionProperty->getName();

            // Skip certain properties
            if (in_array($name, ['risks', '__initializer__', '__cloner__', '__isInitialized__'])) {
                continue;
            }

            try {
                $value = $reflectionProperty->getValue($entity);

                // Skip collections and complex objects
                if ($value instanceof Collection) {
                    continue;
                }

                $values[$name] = $value;
            } catch (Exception) {
                // Skip properties that can't be accessed
                continue;
            }
        }

        return $values;
    }

    /**
     * Log risk acceptance (automatic)
     * Priority 2.1 - Risk Acceptance Workflow
     */
    public function logRiskAcceptance(Risk $risk, User $user, string $reason): void
    {
        $this->logCustom(
            action: 'risk_acceptance',
            entityType: 'Risk',
            entityId: $risk->getId(),
            newValues: [
                'risk_title' => $risk->getTitle(),
                'risk_score' => $risk->getResidualRiskLevel(),
                'accepted_by' => $user->getFullName(),
                'reason' => $reason,
            ],
            description: sprintf(
                'Risk "%s" (ID: %d) accepted: %s',
                $risk->getTitle(),
                $risk->getId(),
                $reason
            ),
            userName: $user->getEmail()
        );
    }

    /**
     * Log risk acceptance request
     */
    public function logRiskAcceptanceRequested(Risk $risk, User $requester, User $approver, string $approvalLevel): void
    {
        $this->logCustom(
            action: 'risk_acceptance_requested',
            entityType: 'Risk',
            entityId: $risk->getId(),
            newValues: [
                'risk_title' => $risk->getTitle(),
                'risk_score' => $risk->getResidualRiskLevel(),
                'requester' => $requester->getFullName(),
                'approver' => $approver->getFullName(),
                'approval_level' => $approvalLevel,
            ],
            description: sprintf(
                'Risk "%s" (ID: %d) acceptance requested by %s, requires %s approval from %s',
                $risk->getTitle(),
                $risk->getId(),
                $requester->getFullName(),
                $approvalLevel,
                $approver->getFullName()
            ),
            userName: $requester->getEmail()
        );
    }

    /**
     * Log risk acceptance approval
     */
    public function logRiskAcceptanceApproved(Risk $risk, User $user, string $comments): void
    {
        $this->logCustom(
            action: 'risk_acceptance_approved',
            entityType: 'Risk',
            entityId: $risk->getId(),
            newValues: [
                'risk_title' => $risk->getTitle(),
                'risk_score' => $risk->getResidualRiskLevel(),
                'approved_by' => $user->getFullName(),
                'comments' => $comments,
            ],
            description: sprintf(
                'Risk "%s" (ID: %d) acceptance approved by %s',
                $risk->getTitle(),
                $risk->getId(),
                $user->getFullName()
            ),
            userName: $user->getEmail()
        );
    }

    /**
     * Log risk acceptance rejection
     */
    public function logRiskAcceptanceRejected(Risk $risk, User $user, string $reason): void
    {
        $this->logCustom(
            action: 'risk_acceptance_rejected',
            entityType: 'Risk',
            entityId: $risk->getId(),
            newValues: [
                'risk_title' => $risk->getTitle(),
                'risk_score' => $risk->getResidualRiskLevel(),
                'rejected_by' => $user->getFullName(),
                'reason' => $reason,
            ],
            description: sprintf(
                'Risk "%s" (ID: %d) acceptance rejected by %s: %s',
                $risk->getTitle(),
                $risk->getId(),
                $user->getFullName(),
                $reason
            ),
            userName: $user->getEmail()
        );
    }
}

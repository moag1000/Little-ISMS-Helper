<?php

declare(strict_types=1);

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
    private const string ACTION_BULK = 'bulk';

    // Notification audit events (Sprint 6a — F3)
    public const string ACTION_NOTIFICATION_RULE_CREATED    = 'notification.rule.created';
    public const string ACTION_NOTIFICATION_RULE_UPDATED    = 'notification.rule.updated';
    public const string ACTION_NOTIFICATION_RULE_DELETED    = 'notification.rule.deleted';
    public const string ACTION_NOTIFICATION_RULE_ENABLED    = 'notification.rule.enabled';
    public const string ACTION_NOTIFICATION_RULE_DISABLED   = 'notification.rule.disabled';
    public const string ACTION_NOTIFICATION_CHANNEL_CREATED  = 'notification.channel.created';
    public const string ACTION_NOTIFICATION_CHANNEL_UPDATED  = 'notification.channel.updated';
    public const string ACTION_NOTIFICATION_CHANNEL_VERIFIED = 'notification.channel.verified';
    public const string ACTION_NOTIFICATION_DELIVERY_SUCCEEDED = 'notification.delivery.succeeded';
    public const string ACTION_NOTIFICATION_DELIVERY_FAILED    = 'notification.delivery.failed';
    public const string ACTION_NOTIFICATION_DELIVERY_RETRIED   = 'notification.delivery.retried';

    // SLA Deadline audit events (Sprint 7A — F3 Wave 2)
    public const string ACTION_SLA_DEADLINE_APPROACHING = 'notification.sla.deadline_approaching';
    public const string ACTION_SLA_DEADLINE_MISSED      = 'notification.sla.deadline_missed';

    // NIS-2 BSI-Portal registration audit events (Sprint 7B — F29)
    public const string ACTION_NIS2_REGISTRATION_EXPORTED = 'nis2.registration.exported';
    public const string ACTION_NIS2_REGISTRATION_UPDATED  = 'nis2.registration.updated';

    // DORA Register of Information audit events (Sprint 8 — F30)
    public const string ACTION_DORA_ROI_EXPORTED  = 'dora.roi.exported';
    public const string ACTION_DORA_ROI_SUBMITTED = 'dora.roi.submitted';

    // F11 FTE-Tracking audit events (Sprint 9A)
    public const string ACTION_FTE_METRIC_RECORDED     = 'fte.metric.recorded';     // low-priority telemetry
    public const string ACTION_FTE_CALIBRATION_CHANGED = 'fte.calibration.changed'; // high-priority admin action

    // SSO-specific audit events (Wave 2) — used by SsoEventLogger
    public const string ACTION_SSO_LOGIN_SUCCESS       = 'sso.login.success';
    public const string ACTION_SSO_LOGIN_FAILURE       = 'sso.login.failure';
    public const string ACTION_SSO_JIT_PROVISIONED     = 'sso.jit.provisioned';
    public const string ACTION_SSO_ROLE_CHANGED        = 'sso.role.changed';
    public const string ACTION_SSO_CONFIG_CHANGED      = 'sso.config.changed';
    public const string ACTION_SSO_ENFORCEMENT_CHANGED = 'sso.enforcement.changed';

    // Risk-Incident cross-link audit events (Sprint 9B — F16)
    public const string ACTION_RISK_INCIDENT_LINKED                  = 'risk_incident.linked';
    public const string ACTION_RISK_INCIDENT_UNLINKED                = 'risk_incident.unlinked';
    public const string ACTION_RISK_REVIEW_SUGGESTED_FROM_INCIDENT   = 'risk_incident.review_suggested';

    // BSI-200-4 Übungs-Logbuch events (Sprint 10B — F27)
    public const string ACTION_BSI_2004_LOG_CREATED                  = 'bsi_2004_log.created';
    public const string ACTION_BSI_2004_LOG_SUBMITTED                = 'bsi_2004_log.submitted';
    public const string ACTION_BSI_2004_LOG_CONFIRMED                = 'bsi_2004_log.confirmed';
    public const string ACTION_BSI_2004_IMPROVEMENT_ACTION_OVERDUE   = 'bsi_2004_log.improvement_action_overdue';

    // Library Import/Export events (Sprint 10A — F5b)
    public const string ACTION_LIBRARY_FRAMEWORK_IMPORTED       = 'library.framework.imported';
    public const string ACTION_LIBRARY_FRAMEWORK_EXPORTED_YAML  = 'library.framework.exported_yaml';
    public const string ACTION_LIBRARY_FRAMEWORK_EXPORTED_CSV   = 'library.framework.exported_csv';
    public const string ACTION_LIBRARY_MAPPING_IMPORTED         = 'library.mapping.imported';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly ?AuditLogIntegrityService $integrityService = null,
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
     * Log a bulk operation as a hybrid pattern: 1 batch-entry + N per-entity-entries.
     *
     * Required for ISO 27001 Clause 7.5.3 (Documented Information) — auditor must
     * be able to ask "show me history of Asset X" AND "show me the import event
     * with source-file-hash". Per-entity entries reference the batch_id so both
     * questions are answerable without external files.
     *
     * @param string $eventType   Specific event type (e.g. "bulk_import", "sso.jit.batch")
     * @param string $entityType  Entity-class short name (e.g. "Asset")
     * @param array  $batchData   Batch-level metadata: source_file_hash, file_name,
     *                            row_count_total, row_count_success, row_count_skipped,
     *                            row_count_error, dry_run_result_hash, mode (initial/delta/dry_run)
     * @param array  $perEntityData Array of per-entity rows: each row is
     *                              ['entity_id' => int|null, 'action' => 'create'|'update'|'delete',
     *                               'old_values' => array|null, 'new_values' => array|null]
     * @param string|null $description Optional human-readable description
     *
     * @return string The generated batch_id (UUIDv4) — referenced from per-entity entries
     */
    public function logBulk(
        string $eventType,
        string $entityType,
        array $batchData,
        array $perEntityData,
        ?string $description = null,
    ): string {
        $batchId = $this->generateBatchId();
        $batchEnvelope = array_merge($batchData, [
            'batch_id' => $batchId,
            'event_type' => $eventType,
            'per_entity_count' => count($perEntityData),
        ]);

        // 1 batch-entry: source-file provenance + aggregate counts
        $this->log(
            self::ACTION_BULK,
            $entityType,
            null,
            null,
            $batchEnvelope,
            $description ?? sprintf('Bulk %s: %d rows', $eventType, count($perEntityData)),
        );

        // N per-entity-entries: each references batch_id for traceability
        foreach ($perEntityData as $row) {
            $action = $row['action'] ?? self::ACTION_CREATE;
            $entityId = $row['entity_id'] ?? null;
            $oldValues = $row['old_values'] ?? null;
            $newValues = isset($row['new_values'])
                ? array_merge($row['new_values'], ['_batch_id' => $batchId])
                : ['_batch_id' => $batchId];

            $this->log(
                $action,
                $entityType,
                $entityId,
                $oldValues,
                $newValues,
                sprintf('Bulk-row of batch %s', $batchId),
            );
        }

        return $batchId;
    }

    /**
     * Generate a UUIDv4 for batch-id correlation.
     */
    private function generateBatchId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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

        // ISB Sprint-2 gate: capture actor's highest role at time of action.
        $auditLog->setActorRole($this->getCurrentActorRole());

        // Set request information if available.
        //
        // ISB MINOR-1 note: when $action originates from a console command
        // (ReSignAuditLogCommand, ComplianceLoaderFixer CLI, LoaderFixer CLI,
        // ScheduledTasks etc.) the RequestStack is empty and ip_address /
        // user_agent stay NULL. This is intentional — a CLI run has no
        // network context — and is accepted by the ISB review. The actor
        // is still identifiable via user_name ("system" for CLI) plus
        // actor_role. For HTTP-triggered actions IP and UA are captured.
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
        // AUD-02: sign before persist (no-op if hmac secret not configured)
        $this->integrityService?->sign($auditLog);

        // EM may be closed after a prior persistence error (e.g. constraint
        // violation in an isolated mark-all loop iteration). Persisting on a
        // closed EM throws EntityManagerClosed. Skip silently if so — the
        // audit log is best-effort in this recovery context.
        if (!$this->entityManager->isOpen()) {
            return;
        }

        // After a DDL migration that implicitly commits in MySQL, the
        // connection's SAVEPOINT context is gone. flush() then throws
        // "SAVEPOINT DOCTRINE_N does not exist". Audit log is best-effort
        // here — swallow rather than crash the operator UI.
        try {
            $this->entityManager->persist($auditLog);
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Silent skip — audit log will be missing this row but the
            // calling operation (mark-all-phantom-diff, etc.) must not abort.
        }
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
     * Highest-priority RBAC role held by the current user.
     * Used as the actor_role column in AuditLog (ISB Sprint-2 gate).
     * Returns null for system / CLI / unauthenticated contexts.
     */
    public function getCurrentActorRole(): ?string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $roles = $user->getRoles();
        foreach (['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_MANAGER', 'ROLE_AUDITOR', 'ROLE_USER'] as $candidate) {
            if (in_array($candidate, $roles, true)) {
                return $candidate;
            }
        }
        return $roles[0] ?? null;
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

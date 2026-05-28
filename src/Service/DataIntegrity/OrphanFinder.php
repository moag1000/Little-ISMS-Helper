<?php

declare(strict_types=1);

namespace App\Service\DataIntegrity;

use App\Entity\Notification\NotificationTemplate;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Detects orphaned entities (tenant_id IS NULL when one is expected).
 *
 * Uses the Doctrine MetadataFactory to discover every entity that has a
 * `tenant` association, then queries each for NULL-tenant rows. Globally-scoped
 * catalogue entities (e.g. NotificationTemplate) are excluded so repair logic
 * never tries to assign a tenant_id to them.
 *
 * Extracted from DataIntegrityService to isolate orphan-detection concerns.
 *
 * @see \App\Service\DataIntegrityService::findAllOrphanedEntities()
 * @see \App\Service\DataIntegrityService::findCascadeOrphans()
 */
final class OrphanFinder
{
    /**
     * Entity classes that are INTENTIONALLY globally scoped (tenant_id = NULL by design).
     *
     * These entities are shared across all tenants as catalogue data. The orphan-repair
     * logic MUST NOT reassign a tenant_id to them — doing so triggers a
     * UniqueConstraintViolationException when multiple seeded rows share the same
     * unique key (e.g. NotificationTemplate.uniq_template_key_tenant).
     *
     * @var list<class-string>
     */
    public const GLOBAL_CATALOGUE_ENTITIES = [
        NotificationTemplate::class, // Sprint-6a: global notification templates, tenant_id=NULL by design
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Find all entities without tenant assignment.
     *
     * WICHTIG: TenantFilter muss hier deaktiviert sein, sonst kombiniert
     * Doctrine das "tenant IS NULL" mit dem automatischen
     * "tenant_id = :current" zu einer widersprüchlichen Bedingung
     * und liefert 0 Resultate zurück. Orphans bleiben unsichtbar.
     */
    public function findAllOrphanedEntities(): array
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        try {
            return $this->queryOrphanedEntities();
        } finally {
            if ($wasEnabled) {
                $filters->enable('tenant_filter');
            }
        }
    }

    /**
     * Generischer Scan: alle Doctrine-gemappten Entities mit tenant-Assoziation
     * auf NULL-Tenant prüfen. Entdeckt automatisch neue Entity-Typen — kein
     * Ctor-Argument pro Entity-Klasse mehr nötig.
     */
    private function queryOrphanedEntities(): array
    {
        $orphaned = [];
        $metadataFactory = $this->entityManager->getMetadataFactory();

        // User wird ausgeschlossen — Super-Admins dürfen legitim tenant-los sein.
        // GLOBAL_CATALOGUE_ENTITIES are excluded: their tenant_id=NULL is intentional.
        $excludedClasses = array_merge(
            [Tenant::class, \App\Entity\User::class],
            self::GLOBAL_CATALOGUE_ENTITIES,
        );

        foreach ($metadataFactory->getAllMetadata() as $metadata) {
            $className = $metadata->getName();

            if (in_array($className, $excludedClasses, true) || !$metadata->hasAssociation('tenant')) {
                continue;
            }

            // Abstract/Mapped-Superclass können nicht direkt abgefragt werden
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }

            $orphans = $this->entityManager->createQueryBuilder()
                ->select('e')
                ->from($className, 'e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult();

            if (count($orphans) > 0) {
                // Key ist kurzer Entity-Name in snake_case-Plural (z.B. DataBreach → data_breaches)
                $shortName = substr($className, strrpos($className, '\\') + 1);
                $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $shortName));
                $key = $snake . (str_ends_with($snake, 's') ? '' : 's');
                $orphaned[$key] = $orphans;
            }
        }

        ksort($orphaned);
        return $orphaned;
    }

    /**
     * Cross-entity cascade cleanup detection: entities whose ManyToOne target
     * was deleted but the cascade didn't fire. The five buckets each have
     * a distinct repair-path:
     *   - workflow_instances : target entity-class+id no longer resolves
     *   - mfa_tokens         : `expires_at` < NOW() and the token never logged a usage
     *   - sso_user_approvals : `reviewed_by` user no longer exists
     *   - evidence_tasks     : referenced DocumentVersion + Control are both NULL
     *   - notification_deliveries : NotificationRule that owned the delivery is gone
     *
     * The detection is read-only. Repair runs through
     * {@see DataRepairController::cleanupDanglingRefs()} under a single
     * AuditLogger::logBulk() batch (one batch_id covers all five categories).
     *
     * @return array<string, list<array{id: int, label: string, hint?: string}>>
     */
    public function findCascadeOrphans(): array
    {
        $result = [
            'workflow_instances' => [],
            'mfa_tokens' => [],
            'sso_user_approvals' => [],
            'evidence_tasks' => [],
            'notification_deliveries' => [],
        ];

        // 1. WorkflowInstance — entity_type + entity_id pointing nowhere.
        try {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('wi.id, wi.entityType, wi.entityId')
                ->from(\App\Entity\WorkflowInstance::class, 'wi')
                ->where('wi.entityType IS NOT NULL AND wi.entityId IS NOT NULL')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $type = (string) ($row['entityType'] ?? '');
                $id = (int) ($row['entityId'] ?? 0);
                if ($type === '' || $id === 0 || !class_exists($type)) {
                    continue;
                }
                $target = $this->entityManager->find($type, $id);
                if ($target === null) {
                    $result['workflow_instances'][] = [
                        'id' => (int) $row['id'],
                        'label' => sprintf('WorkflowInstance#%d → %s#%d (missing target)', (int) $row['id'], $type, $id),
                    ];
                }
            }
        } catch (\Throwable) {
            // Pre-flush state or missing table — skip silently.
        }

        // 2. MfaToken — past expiry, never re-used.
        try {
            $now = new \DateTimeImmutable();
            $rows = $this->entityManager->createQueryBuilder()
                ->select('m.id, m.tokenType, m.expiresAt, m.lastUsedAt')
                ->from(\App\Entity\MfaToken::class, 'm')
                ->where('m.expiresAt IS NOT NULL AND m.expiresAt < :now')
                ->setParameter('now', $now)
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                // Only flag tokens that were never used after expiry — used tokens
                // are kept for forensic audit-trail.
                $lastUsed = $row['lastUsedAt'] ?? null;
                if ($lastUsed instanceof \DateTimeInterface && $lastUsed > ($row['expiresAt'] ?? $now)) {
                    continue;
                }
                $result['mfa_tokens'][] = [
                    'id' => (int) $row['id'],
                    'label' => sprintf('MfaToken#%d (%s) expired %s', (int) $row['id'], (string) ($row['tokenType'] ?? 'unknown'), $row['expiresAt'] instanceof \DateTimeInterface ? $row['expiresAt']->format('Y-m-d') : 'unknown'),
                ];
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 3. SsoUserApproval where reviewer User was deleted.
        try {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('s.id, s.email, IDENTITY(s.reviewedBy) AS reviewerId')
                ->from(\App\Entity\SsoUserApproval::class, 's')
                ->where('s.reviewedBy IS NOT NULL')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $reviewerId = (int) ($row['reviewerId'] ?? 0);
                if ($reviewerId === 0) {
                    continue;
                }
                $reviewer = $this->entityManager->find(\App\Entity\User::class, $reviewerId);
                if ($reviewer === null) {
                    $result['sso_user_approvals'][] = [
                        'id' => (int) $row['id'],
                        'label' => sprintf('SsoUserApproval#%d (%s) → User#%d (deleted)', (int) $row['id'], (string) ($row['email'] ?? ''), $reviewerId),
                    ];
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 4. EvidenceReverificationTask where BOTH targets (DocumentVersion + Control)
        //    were deleted — the task has no anchor.
        try {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('t.id, IDENTITY(t.documentVersion) AS dvId, IDENTITY(t.control) AS ctrlId, IDENTITY(t.complianceFulfillment) AS cfId')
                ->from(\App\Entity\EvidenceReverificationTask::class, 't')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $dvId = (int) ($row['dvId'] ?? 0);
                $ctrlId = (int) ($row['ctrlId'] ?? 0);
                $cfId = (int) ($row['cfId'] ?? 0);
                if ($dvId === 0 && $ctrlId === 0 && $cfId === 0) {
                    $result['evidence_tasks'][] = [
                        'id' => (int) $row['id'],
                        'label' => sprintf('EvidenceReverificationTask#%d (no anchor)', (int) $row['id']),
                    ];
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 5. NotificationDelivery whose owning NotificationRule was deleted.
        try {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('d.id, IDENTITY(d.rule) AS ruleId')
                ->from(\App\Entity\Notification\NotificationDelivery::class, 'd')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $ruleId = (int) ($row['ruleId'] ?? 0);
                if ($ruleId === 0) {
                    $result['notification_deliveries'][] = [
                        'id' => (int) $row['id'],
                        'label' => sprintf('NotificationDelivery#%d (rule missing)', (int) $row['id']),
                    ];
                    continue;
                }
                $rule = $this->entityManager->find(\App\Entity\Notification\NotificationRule::class, $ruleId);
                if ($rule === null) {
                    $result['notification_deliveries'][] = [
                        'id' => (int) $row['id'],
                        'label' => sprintf('NotificationDelivery#%d → Rule#%d (deleted)', (int) $row['id'], $ruleId),
                    ];
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        return $result;
    }
}

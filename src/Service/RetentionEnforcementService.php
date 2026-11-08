<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use App\Entity\AuditFinding;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\ProcessingActivity;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\WorkflowInstance;
use App\Repository\TenantRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Enforces the per-tenant data-retention policies configured in the
 * /admin/settings/data-retention UI (Tenant.dataRetentionPolicies).
 *
 * Until now that UI was a pure stub ("auto-delete cron is NOT implemented"): retention periods were documented but never enforced, so data
 * stayed forever (GDPR Art. 5(1)(e) storage-limitation gap).
 *
 * This service only ever touches entity types the admin has explicitly opted
 * into (auto_delete = true); the GDPR maximum caps are already applied at save
 * time in the controller. Deletion goes through EntityManager::remove() so
 * Doctrine cascade + lifecycle rules apply, and every run is audit-logged.
 *
 * Retention deletes only records in an END STATE (see END_STATES). An expired
 * record that is still active (an active asset, an open incident, a published
 * VVT entry, ...) is never removed: that would bypass the lifecycle (e.g. the
 * four-eyes asset disposal) and destroy live data. Such records are counted and
 * reported as "overdue for retention, still active" so a person can run the
 * lifecycle transition; no automatic transition is made because every path to
 * an end state needs a human decision or a four-eyes approval.
 *
 * Every query carries the tenant explicitly (the Doctrine tenant filter is not
 * armed in CLI), and the audit trail is written per tenant and type through
 * AuditLogger::logBulk() with that tenant pinned.
 */
final class RetentionEnforcementService
{
    /** The scheduler runs the sweep on Sundays at this hour (see App\Schedule). */
    public const int SWEEP_WEEKDAY = 0;
    public const int SWEEP_HOUR = 4;

    /**
     * entity-type key (as stored in the policy JSON) => [entity class, age field].
     * Only these types can be auto-enforced; any other configured type is
     * reported as "no enforcer" instead of being silently skipped.
     *
     * DELIBERATELY EXCLUDED — DataSubjectRequest, Consent and Person.
     * These are accountability / proof records, not free-floating operational
     * data: a Consent IS the Art. 7(1) proof of what the subject agreed to, a
     * DataSubjectRequest IS the Art. 5(2) evidence that a right was handled, and
     * Person carries BDSG § 26 employee records under their own statutory holds.
     * Blanket time-based auto-deletion would destroy exactly the evidence the
     * GDPR requires us to keep. Their erasure is governed by the dedicated
     * Art. 17 data-subject-request workflow and consent-withdrawal lifecycle
     * (case-by-case, legal-hold aware), NOT by this storage-limitation sweep.
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    public const array REGISTRY = [
        'asset'               => [Asset::class, 'createdAt'],
        'risk'                => [Risk::class, 'createdAt'],
        'incident'            => [Incident::class, 'createdAt'],
        'document'            => [Document::class, 'uploadedAt'],
        'processing_activity' => [ProcessingActivity::class, 'createdAt'],
        'audit_finding'       => [AuditFinding::class, 'createdAt'],
        'workflow_instance'   => [WorkflowInstance::class, 'startedAt'],
    ];

    /**
     * entity-type key => status values after which a record may be hard-deleted.
     * Taken from the lifecycle definitions in config/workflows/*.yaml:
     * - asset: `disposed` only (`retired` still needs the four-eyes `dispose` step)
     * - risk: `closed` (`accepted` is a live decision under periodic review)
     * - incident / audit_finding: `closed`
     * - document: `archived`, `deleted` (soft-deleted)
     * - processing_activity: `archived`
     * - workflow_instance: `approved`, `rejected`, `cancelled` (all terminal)
     *
     * @var array<string, list<string>>
     */
    public const array END_STATES = [
        'asset'               => ['disposed'],
        'risk'                => ['closed'],
        'incident'            => ['closed'],
        'document'            => ['archived', 'deleted'],
        'processing_activity' => ['archived'],
        'audit_finding'       => ['closed'],
        'workflow_instance'   => ['approved', 'rejected', 'cancelled'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{tenant: int|null, entity_type: string, expired: int, deleted: int, note: ?string}>
     */
    public function enforce(bool $apply): array
    {
        $report = [];

        foreach ($this->tenantRepository->findAll() as $tenant) {
            $policies = $tenant->getDataRetentionPolicies() ?? [];

            foreach ($policies as $type => $policy) {
                if (empty($policy['auto_delete'])) {
                    continue; // not opted in
                }
                $days = $policy['retention_days'];
                if ($days <= 0) {
                    continue; // 0 = keep forever
                }

                if (!isset(self::REGISTRY[$type])) {
                    $report[] = $this->row($tenant, $type, 0, 0, 'no enforcer (manual cleanup required)');
                    continue;
                }

                [$class, $dateField] = self::REGISTRY[$type];
                $cutoff = new DateTimeImmutable(sprintf('-%d days', $days));
                [$expired, $stillActive] = $this->partitionByEndState(
                    $type,
                    $this->findExpired($class, $dateField, $tenant, $cutoff),
                );
                $activeNote = $stillActive === 0 ? null : sprintf(
                    '%d overdue for retention, still active (not deleted)',
                    $stillActive,
                );
                if ($stillActive > 0) {
                    $this->logger->notice('Retention: expired records are not in an end state and were kept', [
                        'tenant' => $tenant->getId(),
                        'entity_type' => $type,
                        'count' => $stillActive,
                    ]);
                }

                if ($expired === []) {
                    $report[] = $this->row($tenant, $type, 0, 0, $activeNote);
                    continue;
                }

                if (!$apply) {
                    $report[] = $this->row($tenant, $type, count($expired), 0, $activeNote);
                    continue;
                }

                $report[] = $this->deleteExpired($tenant, $type, $expired, $cutoff, $days, $activeNote);

                if (!$this->entityManager->isOpen()) {
                    // A flush failure closed the EM — stop gracefully, the cron
                    // re-runs next cycle. Better than throwing fatals downstream.
                    $this->logger->error('Retention enforcement halted: EntityManager closed after a flush failure.');
                    break 2;
                }
            }
        }

        return $report;
    }

    /**
     * Split expired records into those in an end state (deletable) and the
     * count of those still active.
     *
     * @param list<object> $records
     * @return array{0: list<object>, 1: int}
     */
    private function partitionByEndState(string $type, array $records): array
    {
        $endStates = self::END_STATES[$type] ?? [];
        $deletable = [];
        $active = 0;
        foreach ($records as $record) {
            if (in_array($this->statusOf($record), $endStates, true)) {
                $deletable[] = $record;
            } else {
                ++$active;
            }
        }

        return [$deletable, $active];
    }

    private function statusOf(object $record): ?string
    {
        if ($record instanceof Risk || $record instanceof Incident) {
            return $record->getStatusValue();
        }
        if ($record instanceof Asset || $record instanceof Document || $record instanceof ProcessingActivity
            || $record instanceof AuditFinding || $record instanceof WorkflowInstance) {
            return $record->getStatus();
        }

        return null;
    }

    /**
     * @param list<Asset|Risk|Incident|Document|ProcessingActivity|AuditFinding|WorkflowInstance> $expired
     * @return array{tenant: int|null, entity_type: string, expired: int, deleted: int, note: ?string}
     */
    private function deleteExpired(
        Tenant $tenant,
        string $type,
        array $expired,
        DateTimeImmutable $cutoff,
        int $days,
        ?string $activeNote,
    ): array {
        // Ids and status are read before remove()/flush() — Doctrine nulls the id afterwards.
        $perEntity = [];
        foreach ($expired as $entity) {
            $perEntity[] = [
                'entity_id' => $entity->getId(),
                'action' => 'delete',
                'old_values' => ['status' => $this->statusOf($entity), 'retention_type' => $type],
            ];
        }

        try {
            foreach ($expired as $entity) {
                $this->entityManager->remove($entity);
            }
            $this->entityManager->flush();
        } catch (\Throwable $throwable) {
            $this->logger->error('Retention deletion failed', [
                'tenant' => $tenant->getId(),
                'entity_type' => $type,
                'error' => $throwable->getMessage(),
            ]);

            return $this->row($tenant, $type, count($expired), 0, 'deletion failed (FK / constraint)');
        }

        $deleted = count($expired);
        $this->auditLogger->withTenant($tenant, fn () => $this->auditLogger->logBulk(
            eventType: 'retention.enforced',
            entityType: self::REGISTRY[$type][0],
            batchData: [
                'tenant' => $tenant->getId(),
                'entity_type' => $type,
                'deleted' => $deleted,
                'retention_days' => $days,
                'cutoff' => $cutoff->format('Y-m-d'),
                'end_states' => self::END_STATES[$type] ?? [],
                'actor' => 'system:enforce-retention',
            ],
            perEntityData: $perEntity,
            description: sprintf('Retention: deleted %d %s record(s) older than %d days (GDPR Art. 5(1)(e))', $deleted, $type, $days),
        ));

        return $this->row($tenant, $type, $deleted, $deleted, $activeNote);
    }

    /**
     * @param class-string $class
     * @return list<object>
     */
    private function findExpired(string $class, string $dateField, Tenant $tenant, DateTimeImmutable $cutoff): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($class, 'e')
            ->where(sprintf('e.%s < :cutoff', $dateField))
            ->andWhere('e.tenant = :tenant')
            ->setParameter('cutoff', $cutoff)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{tenant: int|null, entity_type: string, expired: int, deleted: int, note: ?string}
     */
    private function row(Tenant $tenant, string $type, int $expired, int $deleted, ?string $note): array
    {
        return [
            'tenant' => $tenant->getId(),
            'entity_type' => $type,
            'expired' => $expired,
            'deleted' => $deleted,
            'note' => $note,
        ];
    }
}

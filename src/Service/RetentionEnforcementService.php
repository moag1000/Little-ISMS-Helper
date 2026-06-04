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
 * Until now that UI was a pure stub ("auto-delete cron is NOT implemented —
 * Wave 2"): retention periods were documented but never enforced, so data
 * stayed forever (GDPR Art. 5(1)(e) storage-limitation gap, audit finding M-4).
 *
 * This service only ever touches entity types the admin has explicitly opted
 * into (auto_delete = true); the GDPR maximum caps are already applied at save
 * time in the controller. Deletion goes through EntityManager::remove() so
 * Doctrine cascade + lifecycle rules apply, and every run is audit-logged.
 */
final class RetentionEnforcementService
{
    /**
     * entity-type key (as stored in the policy JSON) => [entity class, age field].
     * Only these types can be auto-enforced; any other configured type is
     * reported as "no enforcer" instead of being silently skipped.
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    private const array REGISTRY = [
        'asset'               => [Asset::class, 'createdAt'],
        'risk'                => [Risk::class, 'createdAt'],
        'incident'            => [Incident::class, 'createdAt'],
        'document'            => [Document::class, 'uploadedAt'],
        'processing_activity' => [ProcessingActivity::class, 'createdAt'],
        'audit_finding'       => [AuditFinding::class, 'createdAt'],
        'workflow_instance'   => [WorkflowInstance::class, 'startedAt'],
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
                $expired = $this->findExpired($class, $dateField, $tenant, $cutoff);

                if ($expired === []) {
                    $report[] = $this->row($tenant, $type, 0, 0, null);
                    continue;
                }

                if (!$apply) {
                    $report[] = $this->row($tenant, $type, count($expired), 0, null);
                    continue;
                }

                $report[] = $this->deleteExpired($tenant, $type, $expired, $cutoff, $days);

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
     * @param list<object> $expired
     * @return array{tenant: int|null, entity_type: string, expired: int, deleted: int, note: ?string}
     */
    private function deleteExpired(Tenant $tenant, string $type, array $expired, DateTimeImmutable $cutoff, int $days): array
    {
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
        $this->auditLogger->logCustom(
            action: 'retention.enforced',
            entityType: self::REGISTRY[$type][0],
            entityId: null,
            newValues: [
                'tenant' => $tenant->getId(),
                'entity_type' => $type,
                'deleted' => $deleted,
                'retention_days' => $days,
                'cutoff' => $cutoff->format('Y-m-d'),
            ],
            description: sprintf('Retention: deleted %d %s record(s) older than %d days (GDPR Art. 5(1)(e))', $deleted, $type, $days),
            userName: 'system:enforce-retention',
        );

        return $this->row($tenant, $type, $deleted, $deleted, null);
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

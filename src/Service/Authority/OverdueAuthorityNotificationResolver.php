<?php

declare(strict_types=1);

namespace App\Service\Authority;

use App\Entity\AuditLog;
use App\Entity\DataBreach;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DataBreachRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single source of truth for "which high/critical DataBreaches are overdue for
 * a supervisory-authority notification" (GDPR Art. 33, 72h deadline).
 *
 * A breach is overdue when it is high/critical, has no
 * supervisoryAuthorityNotifiedAt, was detected more than $hours ago, and has no
 * authority-export AuditLog event logged in the last 72h.
 *
 * Shared by AuthorityTemplateOverdueRule (the Alva hint) and the authority
 * notification index `focus=overdue` filter, so the hint deep-links to EXACTLY
 * the breaches it counts.
 */
class OverdueAuthorityNotificationResolver
{
    public const int DEFAULT_HOURS_THRESHOLD = 24;

    public function __construct(
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return DataBreach[]
     */
    public function findOverdueBreaches(Tenant $tenant, int $hours = self::DEFAULT_HOURS_THRESHOLD): array
    {
        $cutoff = new DateTimeImmutable(sprintf('-%d hours', $hours));
        $overdue = [];

        foreach ($this->dataBreachRepository->findByTenant($tenant) as $breach) {
            if (!in_array($breach->getSeverity(), ['high', 'critical'], true)) {
                continue;
            }
            if ($breach->getSupervisoryAuthorityNotifiedAt() !== null) {
                continue;
            }
            $detectedAt = $breach->getEffectiveDetectedAt() ?? $breach->getDetectedAt();
            if ($detectedAt === null || $detectedAt > $cutoff) {
                continue;
            }
            if ($this->hasExportEvent($tenant, $breach->getId())) {
                continue;
            }
            $overdue[] = $breach;
        }

        return $overdue;
    }

    /**
     * Whether an authority-notification export has been logged for this tenant
     * (and breach) within the last 72 hours.
     */
    private function hasExportEvent(Tenant $tenant, ?int $breachId): bool
    {
        $cutoff = new DateTimeImmutable('-72 hours');

        $result = $this->entityManager->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(AuditLog::class, 'a')
            ->innerJoin(User::class, 'u', 'ON', 'u.email = a.userName')
            ->andWhere('u.tenant = :tenant')
            ->andWhere('a.action = :action')
            ->andWhere('a.entityId = :breachId OR a.entityId IS NULL')
            ->andWhere('a.createdAt >= :cutoff')
            ->setParameter('tenant', $tenant)
            ->setParameter('action', 'export')
            ->setParameter('breachId', $breachId)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result > 0;
    }
}

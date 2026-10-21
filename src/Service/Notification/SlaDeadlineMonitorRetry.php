<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\AuditFinding;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Notification\SlaDeadlineMonitor;
use App\Enum\SlaDeadlineType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Retry for {@see \App\EventListener\SlaDeadlineMonitorSpawner}: the spawner
 * starts SLA clocks in postFlush, best effort — a factory error or a failed
 * second flush only gets logged. `app:process-timed-workflows` runs this
 * first, so a failed spawn is retried on the next cron run.
 *
 * Idempotent: only relevant entities ({@see SlaDeadlineFactory::isRelevant()})
 * WITHOUT a monitor are considered, so a second run creates nothing. Only
 * clocks whose deadline still lies ahead are started — the retry closes
 * spawn gaps, it never back-fills historic records with already-missed
 * deadlines (which would fire a burst of "missed" notifications).
 *
 * Runs across all organisations on purpose (cron, no tenant context); every
 * monitor takes the tenant of its own entity.
 */
final readonly class SlaDeadlineMonitorRetry
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SlaDeadlineFactory $factory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int number of SLA monitors started
     */
    public function spawnMissing(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $created = 0;

        foreach ($this->candidates($now) as $entity) {
            try {
                if ($entity->getTenant() === null || $entity->getId() === null || !SlaDeadlineFactory::isRelevant($entity)) {
                    continue;
                }
                $monitor = $this->factory->buildFor($entity);
                if ($monitor === null || $monitor->getDeadlineAt() <= $now) {
                    continue;
                }
                $this->entityManager->persist($monitor);
                ++$created;
            } catch (\Throwable $e) {
                $this->logger->error('SlaDeadlineMonitorRetry: could not start SLA monitor', [
                    'entity' => $entity::class,
                    'id' => $entity->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return $created;
    }

    /**
     * Entities of each monitored type without a monitor, triggered within
     * the longest deadline of that type (older ones are past their deadline).
     *
     * @return iterable<DataBreach|Incident|AuditFinding>
     */
    private function candidates(DateTimeImmutable $now): iterable
    {
        yield from $this->withoutMonitor(
            DataBreach::class,
            'DataBreach',
            'e.createdAt >= :since',
            $now->modify('-' . SlaDeadlineType::GdprNotification72h->durationHours() . ' hours'),
        );
        // The factory starts an incident clock at detectedAt (createdAt when unset).
        yield from $this->withoutMonitor(
            Incident::class,
            'Incident',
            '(e.detectedAt >= :since OR (e.detectedAt IS NULL AND e.createdAt >= :since))',
            $now->modify('-' . SlaDeadlineType::Nis2Notification72h->durationHours() . ' hours'),
        );
        yield from $this->withoutMonitor(
            AuditFinding::class,
            'AuditFinding',
            'e.createdAt >= :since',
            $now->modify('-' . SlaDeadlineType::IsoCorrectiveAction30d->durationHours() . ' hours'),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function withoutMonitor(string $class, string $monitorType, string $window, DateTimeImmutable $since): array
    {
        /** @var list<T> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($class, 'e')
            ->andWhere($window)
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT m.id FROM %s m WHERE m.entityType = :monitorType AND m.entityId = e.id)',
                SlaDeadlineMonitor::class,
            ))
            ->setParameter('since', $since)
            ->setParameter('monitorType', $monitorType)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}

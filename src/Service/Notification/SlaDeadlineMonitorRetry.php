<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\AuditFinding;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Notification\SlaDeadlineMonitor;
use App\Enum\SlaDeadlineType;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Retry for {@see \App\EventListener\SlaDeadlineMonitorSpawner}: the spawner
 * starts SLA clocks in postFlush, best effort — a factory error or a failed
 * second flush only gets logged. `app:process-timed-workflows` runs this
 * first, so a failed spawn is retried on the next cron run.
 *
 * Idempotent: only relevant entities ({@see SlaDeadlineFactory::isRelevant()})
 * WITHOUT a monitor are considered, and the unique key
 * (entity_type, entity_id, deadline_type) rejects a concurrent duplicate —
 * treated as "already started". Candidates are records triggered within
 * their deadline plus {@see self::MISSED_GRACE_HOURS}: a clock missed while
 * the spawn was failing (short DORA / NIS2 clocks, cron down) still gets its
 * monitor, which the watcher then marks missed and alerts on once. Older
 * records are never back-filled (no burst of historic "missed" alerts).
 *
 * Each monitor is flushed on its own, so one bad row cannot take the others
 * down; a flush that closes the EntityManager resets it and ends the run
 * (the rest follows next run). The command runs this in its own try/catch
 * before the watcher, so a failure here never stops the deadline watcher.
 *
 * Runs across all organisations on purpose (cron, no tenant context); every
 * monitor takes the tenant of its own entity.
 *
 * Not final — command tests stub it.
 */
readonly class SlaDeadlineMonitorRetry
{
    /** How long after a missed deadline the retry still starts (and so alerts on) the clock. */
    public const int MISSED_GRACE_HOURS = 48;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SlaDeadlineFactory $factory,
        private LoggerInterface $logger,
        private ?ManagerRegistry $managerRegistry = null,
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
            $monitor = null;
            try {
                if ($entity->getTenant() === null || $entity->getId() === null || !SlaDeadlineFactory::isRelevant($entity)) {
                    continue;
                }
                $monitor = $this->factory->buildFor($entity);
                if ($monitor === null) {
                    continue;
                }
                $this->entityManager->persist($monitor);
                $this->entityManager->flush();
                ++$created;
            } catch (UniqueConstraintViolationException) {
                // A concurrent spawn (web request) started this clock first.
                $this->logger->info('SlaDeadlineMonitorRetry: monitor already started concurrently', [
                    'entity' => $entity::class,
                    'id' => $entity->getId(),
                ]);
                if ($this->recover($monitor)) {
                    break;
                }
            } catch (\Throwable $e) {
                $this->logger->error('SlaDeadlineMonitorRetry: could not start SLA monitor', [
                    'entity' => $entity::class,
                    'id' => $entity->getId(),
                    'error' => $e->getMessage(),
                ]);
                if ($this->recover($monitor)) {
                    break;
                }
            }
        }

        return $created;
    }

    /**
     * Drop the failed monitor from the unit of work; a closed EntityManager
     * is reset instead. Returns true when the run has to stop (the loaded
     * candidates belong to the closed manager).
     */
    private function recover(?SlaDeadlineMonitor $monitor): bool
    {
        if (!$this->entityManager->isOpen()) {
            $this->managerRegistry?->resetManager();

            return true;
        }
        if ($monitor !== null && $this->entityManager->contains($monitor)) {
            $this->entityManager->detach($monitor);
        }

        return false;
    }

    /**
     * Entities of each monitored type without a monitor, triggered within
     * the longest deadline of that type plus the missed-deadline grace.
     *
     * @return iterable<DataBreach|Incident|AuditFinding>
     */
    private function candidates(DateTimeImmutable $now): iterable
    {
        yield from $this->withoutMonitor(
            DataBreach::class,
            'DataBreach',
            'e.createdAt >= :since',
            $now->modify('-' . (SlaDeadlineType::GdprNotification72h->durationHours() + self::MISSED_GRACE_HOURS) . ' hours'),
        );
        // The factory starts an incident clock at detectedAt (createdAt when unset).
        yield from $this->withoutMonitor(
            Incident::class,
            'Incident',
            '(e.detectedAt >= :since OR (e.detectedAt IS NULL AND e.createdAt >= :since))',
            $now->modify('-' . (SlaDeadlineType::Nis2Notification72h->durationHours() + self::MISSED_GRACE_HOURS) . ' hours'),
        );
        yield from $this->withoutMonitor(
            AuditFinding::class,
            'AuditFinding',
            'e.createdAt >= :since',
            $now->modify('-' . (SlaDeadlineType::IsoCorrectiveAction30d->durationHours() + self::MISSED_GRACE_HOURS) . ' hours'),
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

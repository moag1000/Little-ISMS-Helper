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
use App\Doctrine\EntityManagerRecovery;
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
 * down; a flush that closes the EntityManager is recovered
 * (EntityManagerRecovery), the failed record is skipped for the rest of the
 * run and the candidates are queried again. The command runs this in its own try/catch
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
        private ?EntityManagerRecovery $entityManagerRecovery = null,
    ) {
    }

    /** Safety net: maximum number of manager resets (each skips one record) per run. */
    private const int MAX_RECOVERIES = 50;

    /**
     * @return int number of SLA monitors started
     */
    public function spawnMissing(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $created = 0;
        /** @var array<string, true> $skip records whose monitor failed in this run, "<class>#<id>" */
        $skip = [];
        $recoveries = 0;

        // A failed flush closes the EntityManager; the loaded candidates then
        // belong to the dead unit of work. Recover, skip the failed record for
        // the rest of the run and query again — one bad record must not block
        // the ones after it.
        do {
            $restart = false;
            foreach ($this->candidates($now) as $entity) {
                $key = $entity::class . '#' . $entity->getId();
                if (isset($skip[$key])) {
                    continue;
                }
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
                    $skip[$key] = true;
                    if ($this->recover($monitor)) {
                        $restart = ++$recoveries <= self::MAX_RECOVERIES;
                        break;
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('SlaDeadlineMonitorRetry: could not start SLA monitor', [
                        'entity' => $entity::class,
                        'id' => $entity->getId(),
                        'error' => $e->getMessage(),
                    ]);
                    $skip[$key] = true;
                    if ($this->recover($monitor)) {
                        $restart = ++$recoveries <= self::MAX_RECOVERIES;
                        break;
                    }
                }
            }
        } while ($restart);

        return $created;
    }

    /**
     * Drop the failed monitor from the unit of work; a closed EntityManager
     * is recovered instead (EntityManagerRecovery). Returns true when the
     * candidates have to be queried again (they belong to the closed manager).
     */
    private function recover(?SlaDeadlineMonitor $monitor): bool
    {
        if (!$this->entityManager->isOpen()) {
            $this->entityManagerRecovery?->recover();

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

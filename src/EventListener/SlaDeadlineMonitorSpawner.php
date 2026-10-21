<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\AuditFinding;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use App\Service\Notification\SlaDeadlineFactory;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Starts the regulatory SLA clock (SlaDeadlineMonitor) that
 * `app:process-timed-workflows` ticks via SlaDeadlineWatcher:
 *
 *  - DataBreach (created)                         → GDPR Art. 33 72h
 *  - Incident with notificationRequired or a NIS2
 *    category (created or later flagged)          → NIS2 Art. 23 24h (high/critical) / 72h
 *  - AuditFinding of type major/minor NC (created) → ISO 27001 Cl. 10.1 30 days
 *
 * At most one monitor per entity. Monitors are persisted in postFlush with a
 * second flush, because entities persisted during a flush are not written by
 * that same flush. A spawn that fails here (factory error, failed second
 * flush) is logged and retried idempotently by
 * {@see \App\Service\Notification\SlaDeadlineMonitorRetry} on the next run
 * of `app:process-timed-workflows`.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SlaDeadlineMonitorSpawner
{
    /** @var array<string, DataBreach|Incident|AuditFinding> */
    private array $pending = [];

    public function __construct(
        private readonly SlaDeadlineFactory $factory,
        private readonly SlaDeadlineMonitorRepository $monitorRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->queue($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        // Only incidents can become reportable after creation.
        if ($args->getObject() instanceof Incident) {
            $this->queue($args->getObject());
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];
        $created = false;
        $em = $args->getObjectManager();

        foreach ($pending as $entity) {
            try {
                $tenant = $entity->getTenant();
                $id = $entity->getId();
                $type = SlaDeadlineFactory::entityTypeOf($entity);
                if ($tenant === null || $id === null || $type === null) {
                    continue;
                }
                if ($this->monitorRepository->findForEntity($type, $id, $tenant) !== null) {
                    continue;
                }

                $monitor = $this->factory->buildFor($entity);
                if ($monitor !== null) {
                    $em->persist($monitor);
                    $created = true;
                }
            } catch (\Throwable $e) {
                $this->logger->error('SlaDeadlineMonitorSpawner: could not start SLA monitor — the next cron run retries', [
                    'entity' => $entity::class,
                    'id' => $entity->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$created) {
            return;
        }

        try {
            $em->flush();
        } catch (\Throwable $e) {
            // The triggering change is already committed; do not fail the
            // caller's request over the follow-up. SlaDeadlineMonitorRetry
            // (app:process-timed-workflows) starts the missing clock on its
            // next run.
            $this->logger->error('SlaDeadlineMonitorSpawner: could not store SLA monitors — the next cron run retries', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function queue(object $entity): void
    {
        if (SlaDeadlineFactory::isRelevant($entity)) {
            /** @var DataBreach|Incident|AuditFinding $entity */
            $this->pending[$entity::class . '#' . spl_object_id($entity)] = $entity;
        }
    }
}

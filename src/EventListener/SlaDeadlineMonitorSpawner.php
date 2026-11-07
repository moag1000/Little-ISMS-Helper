<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Doctrine\EntityManagerRecovery;
use App\Entity\AuditFinding;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use App\Service\Notification\SlaDeadlineFactory;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Keeps the regulatory SLA clocks (SlaDeadlineMonitor) that
 * `app:process-timed-workflows` ticks via SlaDeadlineWatcher in line with
 * their records, on every save:
 *
 *  - Incident / DataBreach (created or changed) → one clock per statutory
 *    report (ReportingPlanner: NIS-2, DORA, GDPR). A recorded report closes
 *    its clock, a withdrawn assessment dismisses it, a later anchor moves it.
 *  - AuditFinding of type major/minor NC (created) → ISO 27001 Cl. 10.1 30 days
 *
 * Monitors are written in postFlush with a second flush, because entities
 * persisted during a flush are not written by that same flush. A spawn that
 * fails here is logged and retried idempotently by
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
        private readonly ?EntityManagerRecovery $entityManagerRecovery = null,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->queue($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        // Reports, assessments and anchors change on incidents and breaches.
        if ($args->getObject() instanceof Incident || $args->getObject() instanceof DataBreach) {
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
                $result = $this->factory->sync($entity, $this->monitorRepository->findAllForEntity($type, $id, $tenant));
                foreach ($result['new'] as $monitor) {
                    $em->persist($monitor);
                }
                $created = $created || $result['changed'];
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
        } catch (UniqueConstraintViolationException) {
            // The unique key (entity_type, entity_id, deadline_type) caught a
            // concurrent spawn (cron retry): the clock is running already.
            $this->logger->info('SlaDeadlineMonitorSpawner: SLA monitor already started concurrently');
            $this->entityManagerRecovery?->recoverIfClosed();
        } catch (\Throwable $e) {
            // The triggering change is already committed; do not fail the
            // caller's request over the follow-up. SlaDeadlineMonitorRetry
            // (app:process-timed-workflows) starts the missing clock on its
            // next run.
            $this->logger->error('SlaDeadlineMonitorSpawner: could not store SLA monitors — the next cron run retries', [
                'error' => $e->getMessage(),
            ]);
            // The failed flush closed the manager; later writes of the request
            // need it open, tenant-scoped and with a managed tenant.
            $this->entityManagerRecovery?->recoverIfClosed();
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

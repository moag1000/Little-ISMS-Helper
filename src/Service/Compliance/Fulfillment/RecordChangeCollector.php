<?php

declare(strict_types=1);

namespace App\Service\Compliance\Fulfillment;

use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\IsmsRegisterEntry;
use App\Entity\ManagementDecision;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One register entry, one top-management decision, one completed training →
 * the requirements those records answer show it at once (ISO 27001 4.1, 5.1,
 * 7.2, NIS-2 Art. 20 (1), Art. 23, …), not only after the nightly refresh.
 *
 * The record ↔ check-type table below is the only wiring: a new record check
 * reading another entity adds one line. Like {@see ControlChangeCollector}, a
 * failure never undoes the user's save; the nightly task catches up.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class RecordChangeCollector implements ResetInterface
{
    /** Entity → wizard check types that read it. */
    public const array CHECK_TYPES = [
        IsmsRegisterEntry::class => ['register_coverage'],
        ManagementDecision::class => ['management_decision'],
        Training::class => ['training_coverage', 'training_participation'],
        TrainingParticipation::class => ['training_participation'],
        Incident::class => ['reporting_duty', 'incident_process'],
        DataBreach::class => ['reporting_duty'],
    ];

    /** @var array<int, array{tenant: Tenant, types: array<string, true>}> */
    private array $pending = [];

    private bool $running = false;

    public function __construct(
        private readonly FulfillmentDerivationService $derivation,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->running) {
            return;
        }
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            $tenant = self::tenantOf($entity);
            if (!$tenant instanceof Tenant || $tenant->getId() === null) {
                continue;
            }
            foreach (self::CHECK_TYPES as $class => $types) {
                if (!$entity instanceof $class) {
                    continue;
                }
                $key = (int) $tenant->getId();
                $this->pending[$key] ??= ['tenant' => $tenant, 'types' => []];
                foreach ($types as $type) {
                    $this->pending[$key]['types'][$type] = true;
                }
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->running || $this->pending === []) {
            return;
        }
        $em = $args->getObjectManager();
        $pending = $this->pending;
        $this->pending = [];
        $this->running = true;

        try {
            foreach ($pending as $bucket) {
                if (!$em->isOpen()) {
                    break;
                }
                if ($this->derivation->refreshForRecords($bucket['tenant'], array_keys($bucket['types'])) > 0) {
                    $em->flush();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Fulfilment refresh after record change failed; the nightly task catches up', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->running = false;
        }
    }

    private static function tenantOf(object $entity): ?Tenant
    {
        return match (true) {
            $entity instanceof IsmsRegisterEntry, $entity instanceof ManagementDecision, $entity instanceof Training,
            $entity instanceof TrainingParticipation, $entity instanceof Incident, $entity instanceof DataBreach => $entity->getTenant(),
            default => null,
        };
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->running = false;
    }
}

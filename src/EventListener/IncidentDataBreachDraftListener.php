<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Incident;
use App\Entity\User;
use App\Repository\DataBreachRepository;
use App\Service\DataBreachService;
use App\Service\ModuleConfigurationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * An incident flagged "data breach occurred" gets its GDPR data breach draft
 * at once (module privacy): DataBreachService::createFromIncident() — the one
 * path — takes over what happened, the detection time (Art. 33 clock),
 * affected persons and measures. The DPO completes the draft instead of
 * typing the incident again; the 72 h reporting duty starts with it.
 *
 * Runs in postFlush (an entity persisted during a flush is not written by
 * that flush); a failure is logged and never breaks the incident save.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postFlush)]
final class IncidentDataBreachDraftListener
{
    /** @var array<int, Incident> */
    private array $pending = [];

    public function __construct(
        private readonly DataBreachService $dataBreachService,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly ModuleConfigurationService $modules,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->queue($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->queue($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $incident) {
            try {
                $tenant = $incident->getTenant();
                if ($tenant === null || $incident->getId() === null
                    || !$this->modules->isModuleActiveForTenant('privacy', $tenant)
                    || $this->dataBreachRepository->findOneBy(['incident' => $incident]) !== null
                ) {
                    continue;
                }
                $user = $this->security->getUser();
                $this->dataBreachService->createFromIncident($incident, $user instanceof User ? $user : null);
            } catch (\Throwable $e) {
                $this->logger->error('Data breach draft from incident failed', [
                    'incident_id' => $incident->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function queue(object $entity): void
    {
        if ($entity instanceof Incident && $entity->isDataBreachOccurred() === true) {
            $this->pending[spl_object_id($entity)] = $entity;
        }
    }
}

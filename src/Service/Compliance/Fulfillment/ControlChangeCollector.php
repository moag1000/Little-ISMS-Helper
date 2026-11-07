<?php

declare(strict_types=1);

namespace App\Service\Compliance\Fulfillment;

use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One control update → every framework shows it. Collects the controls a flush
 * changed (status, percentage, applicability, justification, new, removed) and
 * the requirements whose control mapping changed; after the flush recomputes
 * the affected fulfilments in all frameworks and flushes once more.
 *
 * A failure here never undoes the user's save: it is logged, and the nightly
 * task app:compliance:refresh-fulfillments catches up. Changes to the global
 * mapping catalogue reach the organisations through that nightly task too.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ControlChangeCollector implements ResetInterface
{
    /** Control fields the rule reads. */
    private const RULE_FIELDS = ['implementationStatus', 'implementationPercentage', 'applicable', 'justification', 'controlId'];

    /** @var array<int, array{tenant: Tenant, controls: array<int, Control>, requirements: array<int, ComplianceRequirement>}> */
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

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Control) {
                $this->addControl($entity);
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Control) {
                $this->addControl($entity);
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Control
                && array_intersect(self::RULE_FIELDS, array_keys($uow->getEntityChangeSet($entity))) !== []) {
                $this->addControl($entity);
            }
        }

        $collections = [...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()];
        foreach ($collections as $collection) {
            if (!$collection->getOwner() instanceof ComplianceRequirement
                || $collection->getMapping()->fieldName !== 'mappedControls') {
                continue;
            }
            /** @var ComplianceRequirement $requirement */
            $requirement = $collection->getOwner();
            $touched = [...$collection->getInsertDiff(), ...$collection->getDeleteDiff()];
            foreach ($touched as $control) {
                if ($control instanceof Control && $control->getTenant() instanceof Tenant) {
                    $bucket = &$this->bucket($control->getTenant());
                    $bucket['requirements'][spl_object_id($requirement)] = $requirement;
                    unset($bucket);
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
                $this->derivation->refreshForControls($bucket['tenant'], array_values($bucket['controls']));
                if ($bucket['requirements'] !== []) {
                    $this->derivation->refreshRequirements($bucket['tenant'], array_values($bucket['requirements']));
                }
                $em->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Fulfilment refresh after control change failed; the nightly task catches up', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->running = false;
        }
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->running = false;
    }

    private function addControl(Control $control): void
    {
        $tenant = $control->getTenant();
        if ($tenant instanceof Tenant) {
            $bucket = &$this->bucket($tenant);
            $bucket['controls'][spl_object_id($control)] = $control;
            unset($bucket);
        }
    }

    /**
     * @return array{tenant: Tenant, controls: array<int, Control>, requirements: array<int, ComplianceRequirement>}
     */
    private function &bucket(Tenant $tenant): array
    {
        $key = spl_object_id($tenant);
        $this->pending[$key] ??= ['tenant' => $tenant, 'controls' => [], 'requirements' => []];

        return $this->pending[$key];
    }
}

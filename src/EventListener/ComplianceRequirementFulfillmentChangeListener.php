<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\User;
use App\Event\ComplianceRequirementFulfillmentUpdatedEvent;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Fires ComplianceRequirementFulfillmentUpdatedEvent whenever fulfillmentPercentage changes.
 * Avoids intrusive refactor of the fulfillment service; keeps Source-updated propagation
 * (WS-1 ENT-2 notification-based, no silent cascade) hooked into any code path.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
final class ComplianceRequirementFulfillmentChangeListener
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Security $security,
    ) {
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ComplianceRequirementFulfillment) {
            return;
        }
        if (!$args->hasChangedField('fulfillmentPercentage')) {
            return;
        }

        $previous = (int) $args->getOldValue('fulfillmentPercentage');
        $current = (int) $args->getNewValue('fulfillmentPercentage');

        $actor = $this->security->getUser();
        $this->dispatcher->dispatch(new ComplianceRequirementFulfillmentUpdatedEvent(
            $entity,
            $previous,
            $current,
            $actor instanceof User ? $actor : null,
        ));
    }
}

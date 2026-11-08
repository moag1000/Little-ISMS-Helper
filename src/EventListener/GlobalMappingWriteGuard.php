<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ComplianceMapping;
use App\Security\Voter\GlobalCatalogueVoter;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Backstop for {@see GlobalCatalogueVoter}: no flush creates, changes or deletes a global
 * compliance mapping for a signed-in user who may not write the global catalogue — whichever
 * screen, import, API resource or assistant tool made the change. The screens check the
 * voter first; this only catches what they miss.
 *
 * Without a signed-in user (console commands, scheduler, setup) nothing is checked: those
 * run as the instance operator.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class GlobalMappingWriteGuard
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->security->getToken() === null) {
            return;
        }
        $uow = $args->getObjectManager()->getUnitOfWork();
        $changed = [
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ];
        foreach ($changed as $entity) {
            if ($entity instanceof ComplianceMapping
                && $entity->getOwnerTenant() === null
                && !$this->security->isGranted(GlobalCatalogueVoter::WRITE, $entity)
            ) {
                throw new AccessDeniedException(
                    'Global compliance mappings are shared by all organisations; only the super admin changes them.',
                );
            }
        }
    }
}

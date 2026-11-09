<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\MappingGapItem;
use App\Security\Voter\GlobalCatalogueVoter;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Backstop for {@see GlobalCatalogueVoter}: no flush changes the catalogue every organisation
 * shares for a signed-in user who may not write it — whichever screen, import, loader,
 * in-process console command, API resource or assistant tool made the change. The screens
 * check the voter first; this only catches what they miss. Shared rows (S3, S13):
 *  - a global compliance mapping (no owner organisation): create, change, delete;
 *  - a gap item of such a mapping (no organisation): create, change, delete;
 *  - a requirement of the shared catalogue (no upload organisation): create, delete;
 *  - a framework: change, delete (its `active` flag and texts are the same for everyone).
 * Requirement texts are guarded by their screens; maturity fields still live on the
 * requirement row (open point of the sixth assessment), so updates of requirements pass.
 *
 * Only inside a request with a signed-in user. Console commands, scheduler, setup and
 * Messenger workers run as the instance operator and are not checked.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class GlobalMappingWriteGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->requestStack->getMainRequest() === null || $this->security->getToken() === null) {
            return;
        }
        $uow = $args->getObjectManager()->getUnitOfWork();
        $changed = [
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ];
        foreach ($changed as $entity) {
            $this->check($entity);
        }
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof ComplianceRequirement && $entity->getUploadTenant() === null) {
                $this->refuseUnlessGranted(null);
            }
        }
        foreach ([...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof ComplianceFramework) {
                $this->refuseUnlessGranted(null);
            }
        }
    }

    private function check(object $entity): void
    {
        if ($entity instanceof ComplianceMapping && $entity->getOwnerTenant() === null) {
            $this->refuseUnlessGranted($entity);
        }
        if ($entity instanceof MappingGapItem && $entity->getTenant() === null) {
            $this->refuseUnlessGranted($entity->getMapping());
        }
    }

    private function refuseUnlessGranted(?ComplianceMapping $subject): void
    {
        if (!$this->security->isGranted(GlobalCatalogueVoter::WRITE, $subject)) {
            throw new AccessDeniedException(
                'The compliance catalogue is shared by all organisations; only the super admin changes it.',
            );
        }
    }
}

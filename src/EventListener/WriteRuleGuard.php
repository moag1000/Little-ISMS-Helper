<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\WriteRule\WriteRuleCheck;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * S22: one write rule for every writer. Rules that used to live in a FormType
 * were bypassed by the bulk import, REST and the assistant (MCP `save_record`);
 * here, at the flush every write passes, they hold for all of them:
 *
 * - `#[CreateOnly]` fields of a stored record change only by a manager
 *   (incident / data breach `detectedAt`, the risk of a treatment plan);
 * - `#[ManagerOrResponsibleField]` fields (incident classification, asset
 *   protection need, supplier criticality) and who is responsible for the
 *   record change only by a manager or the person responsible before the change.
 *
 * Web forms show those fields read-only ({@see \App\Form\Extension\WriteRuleFormExtension});
 * this is the backstop: 403, nothing is written. Checked only while a person
 * is logged in (console, scheduler and workers act for nobody).
 * Gate 96: tests/Security/WritePathParityTest.php.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class WriteRuleGuard
{
    public function __construct(
        private readonly Security $security,
        // Lazy: the check asks every deadline source; some reach services that use this EntityManager.
        #[Autowire(lazy: true)]
        private readonly WriteRuleCheck $check,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /** @var array<int, object> $records */
        $records = [];
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $records[spl_object_id($entity)] = $entity;
        }
        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            if ($owner !== null) {
                $records[spl_object_id($owner)] = $owner;
            }
        }

        $isManager = null;
        foreach ($records as $record) {
            $changes = WriteRuleCheck::ruledChanges($em, $record);
            if ($changes === []) {
                continue;
            }
            $isManager ??= $this->security->isGranted(SelfAssignmentGuard::MANAGER_ROLE);
            $refusal = $this->check->refusal($record, $user, $isManager, $changes);
            if ($refusal !== null) {
                throw new AccessDeniedException($refusal . ' (S22)');
            }
        }
    }
}

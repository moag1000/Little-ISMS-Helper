<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Person;
use App\Entity\User;
use App\Service\Responsibility\SelfAssignmentCheck;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * S20: nobody without manager scope makes themselves responsible for a record.
 *
 * Responsibility is a right (`RECORD_RESPONSIBLE` approves four-eyes requests,
 * decides risk treatment, marks a statutory report as sent, closes a breach).
 * Two ways to take it are closed here, at the one place every write passes —
 * web forms, REST, MCP `save_record`, imports run by the person:
 *
 * 1. Person ↔ account (`Person.linkedUser`): a Person counts through its linked
 *    account, so linking the DPO's Person to one's own account inherits every
 *    duty of the DPO. Only ROLE_MANAGER (persona roles, admins) sets or changes it.
 * 2. Self-assignment on an existing record: a changed User/Person reference
 *    (owner, assignee, approver, deputies) after which the person is responsible
 *    for the record and before which they were not ({@see SelfAssignmentCheck}).
 *    The current holder may still hand the record on; creating a record with
 *    oneself as owner stays allowed (it is one's own record).
 *
 * Web forms show the refusal as a form error first ({@see \App\Form\Extension\SelfAssignmentFormExtension});
 * here it is the backstop for every other path: 403, nothing is written.
 * Checked only when a person is logged in (console, scheduler and workers act for nobody).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class SelfAssignmentGuard
{
    public const string MANAGER_ROLE = 'ROLE_MANAGER';

    public function __construct(
        private readonly Security $security,
        // Lazy: the check asks every deadline source; some of them reach services that use this EntityManager.
        #[Autowire(lazy: true)]
        private readonly SelfAssignmentCheck $check,
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

        $managerScope = null;
        $isManager = function () use (&$managerScope): bool {
            return $managerScope ??= $this->security->isGranted(self::MANAGER_ROLE);
        };

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Person && $entity->getLinkedUser() !== null && !$isManager()) {
                throw self::refused('link a person to a user account');
            }
        }

        /** @var array<int, object> $records */
        $records = [];
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Person && array_key_exists('linkedUser', $uow->getEntityChangeSet($entity)) && !$isManager()) {
                throw self::refused('change the user account of a person');
            }
            $records[spl_object_id($entity)] = $entity;
        }
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            $owner = $collection->getOwner();
            if ($owner !== null) {
                $records[spl_object_id($owner)] = $owner;
            }
        }

        foreach ($records as $record) {
            $originals = SelfAssignmentCheck::selfAssignedReferences($em, $record, $user);
            if ($originals !== [] && !$isManager() && $this->check->gainsResponsibility($user, $record, $originals)) {
                throw self::refused(sprintf('assign yourself to a %s', (new \ReflectionClass($record))->getShortName()));
            }
        }
    }

    private static function refused(string $what): AccessDeniedException
    {
        return new AccessDeniedException(sprintf(
            'Only a manager may %s (S20: responsibility grants the rights to act on a record).',
            $what,
        ));
    }
}

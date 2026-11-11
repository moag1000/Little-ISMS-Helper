<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Person;
use App\Entity\User;
use App\Security\ActingUser;
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
 *    The current holder may still hand the record on.
 * 3. Self-assignment on create (S28, F-04): for classes whose responsibility opens an
 *    action ({@see \App\Attribute\ResponsibilityGrantsAuthority}: incident reports, risk
 *    treatment decision, DSR closing …) the creator does not name themselves either;
 *    the slot stays empty and the duty's roles act. Other records (one's own task)
 *    may still be created with oneself as owner.
 *
 * Web forms show the refusal as a form error first ({@see \App\Form\Extension\SelfAssignmentFormExtension});
 * here it is the backstop for every other path: 403, nothing is written.
 * Checked for the acting person ({@see ActingUser}): the logged-in user, or the one who
 * started an async job (S28); console commands and the scheduler act for nobody.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class SelfAssignmentGuard
{
    public const string MANAGER_ROLE = 'ROLE_MANAGER';

    public function __construct(
        private readonly Security $security,
        private readonly ActingUser $actingUser,
        // Lazy: the check asks every deadline source; some of them reach services that use this EntityManager.
        #[Autowire(lazy: true)]
        private readonly SelfAssignmentCheck $check,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        // S28 (F-07): in a worker the person who started the job, not nobody.
        $user = $this->actingUser->current();
        if (!$user instanceof User) {
            return;
        }
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $managerScope = null;
        $isManager = function () use (&$managerScope, $user): bool {
            return $managerScope ??= $this->security->isGrantedForUser($user, self::MANAGER_ROLE);
        };

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Person && $entity->getLinkedUser() !== null && !$isManager()) {
                throw self::refused('link a person to a user account');
            }
            $named = SelfAssignmentCheck::selfNamedOnCreate($em, $entity, $user);
            if ($named !== [] && !$isManager() && $this->check->gainsResponsibility($user, $entity, $named)) {
                throw self::refused(sprintf('create a %s with yourself as responsible', (new \ReflectionClass($entity))->getShortName()));
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

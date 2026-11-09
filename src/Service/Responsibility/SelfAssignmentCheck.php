<?php

declare(strict_types=1);

namespace App\Service\Responsibility;

use App\Entity\Person;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;

/**
 * S20: does a change to a record's person references make the user who
 * changes them responsible for it — when they were not before?
 *
 * Whoever {@see ResponsibilityResolver} names for a record may act on it
 * (`RECORD_RESPONSIBLE`: four-eyes approval, risk treatment decision, statutory
 * report, closing a breach). A person without manager scope who writes their
 * own account or Person into the owner, assignee, approver or deputy slot of a
 * record they do not hold would grant themselves those rights. The answer is
 * asked of the resolver itself — before and after the change — so every slot
 * any deadline source reads counts, and fields it does not read (createdBy,
 * updatedBy, …) never do.
 */
final class SelfAssignmentCheck
{
    public function __construct(
        private readonly ResponsibilityResolver $resolver,
    ) {
    }

    /**
     * The person references (to-one or to-many User/Person associations) of a
     * stored record that now name the user and did not when it was loaded —
     * field name → value before. Empty for new records: creating a record with
     * oneself as owner is one's own record.
     *
     * @return array<string, mixed>
     */
    public static function selfAssignedReferences(EntityManagerInterface $em, object $record, User $user): array
    {
        $uow = $em->getUnitOfWork();
        if (!$uow->isInIdentityMap($record) || $uow->isScheduledForInsert($record)) {
            return [];
        }
        $meta = $em->getClassMetadata($record::class);
        // During flush the original data already holds the new values; the change set keeps the old ones.
        $original = $uow->getOriginalEntityData($record);
        foreach ($uow->getEntityChangeSet($record) as $field => $change) {
            $original[$field] = $change[0];
        }
        $changed = [];
        foreach ($meta->getAssociationNames() as $field) {
            $target = $meta->getAssociationTargetClass($field);
            if (!is_a($target, User::class, true) && !is_a($target, Person::class, true)) {
                continue;
            }
            $now = $meta->getFieldValue($record, $field);
            if ($meta->isCollectionValuedAssociation($field)) {
                if (!$now instanceof PersistentCollection) {
                    // replaced by a plain collection: everything in it is new
                    $before = [];
                } elseif (!$now->isDirty()) {
                    continue;
                } else {
                    $before = $now->getSnapshot();
                }
            } else {
                $before = $original[$field] ?? null;
            }
            if (self::names($user, $now) && !self::names($user, $before)) {
                $changed[$field] = $before;
            }
        }

        return $changed;
    }

    /** Whether the value (User, Person or a list of them) names the user's account. */
    public static function names(User $user, mixed $value): bool
    {
        if ($value instanceof Collection) {
            $value = $value->toArray();
        }
        if (is_iterable($value)) {
            foreach ($value as $item) {
                if (self::names($user, $item)) {
                    return true;
                }
            }

            return false;
        }
        $account = $value instanceof Person ? $value->getLinkedUser() : $value;
        if (!$account instanceof User) {
            return false;
        }

        return $account === $user || ($account->getId() !== null && $account->getId() === $user->getId());
    }

    /**
     * True when the user is responsible for the record now and would not be
     * with the original values of the changed fields.
     *
     * @param array<string, mixed> $originals field name → value before the change
     *                                        (a list of the former elements for collections)
     */
    public function gainsResponsibility(User $user, object $record, array $originals): bool
    {
        if ($originals === [] || !$this->isResponsible($user, $record)) {
            return false;
        }

        $current = [];
        $properties = [];
        try {
            foreach ($originals as $field => $original) {
                $property = self::property($record, $field);
                if ($property === null) {
                    continue;
                }
                $properties[$field] = $property;
                $current[$field] = $property->isInitialized($record) ? $property->getValue($record) : null;
                $value = $current[$field] instanceof Collection
                    ? new ArrayCollection(is_iterable($original) ? array_values([...$original]) : [])
                    : $original;
                $property->setValue($record, $value);
            }

            return !$this->isResponsible($user, $record);
        } finally {
            foreach ($properties as $field => $property) {
                $property->setValue($record, $current[$field]);
            }
        }
    }

    private function isResponsible(User $user, object $record): bool
    {
        // Not the request-local cache of isResponsibleFor(): it may hold the answer from before the change.
        foreach ($this->resolver->responsibleFor($record) as $candidate) {
            if ($candidate === $user || ($candidate->getId() !== null && $candidate->getId() === $user->getId())) {
                return true;
            }
        }

        return false;
    }

    private static function property(object $record, string $field): ?\ReflectionProperty
    {
        for ($class = new \ReflectionClass($record); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($field)) {
                $property = $class->getProperty($field);
                if (!$property->isStatic()) {
                    return $property;
                }
            }
        }

        return null;
    }
}

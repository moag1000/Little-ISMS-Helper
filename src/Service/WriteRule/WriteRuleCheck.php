<?php

declare(strict_types=1);

namespace App\Service\WriteRule;

use App\Entity\Person;
use App\Entity\User;
use App\Service\Responsibility\ResponsibilityResolver;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;

/**
 * S22: may this person change these fields of a stored record?
 *
 * Michel's line: classification and responsibility are manager data. After a
 * record is created
 * - a create-only field (`#[CreateOnly]`) changes only by a manager (with a
 *   reason, S28 — checked by the flush guard, see {@see createOnlyChanges()});
 * - a classification field (`#[ManagerOrResponsibleField]`) and the question
 *   "who is responsible" (any User/Person reference whose change changes the
 *   answer of {@see ResponsibilityResolver}) change only by a manager or by the
 *   person responsible for the record before the change (the holder hands on).
 *
 * The same answer serves the flush guard (every writer) and the web form.
 */
final class WriteRuleCheck
{
    public function __construct(
        private readonly ResponsibilityResolver $resolver,
    ) {
    }

    /**
     * Fields of a stored record a rule covers whose value differs from the
     * stored one — field → value before (a list of the former elements for
     * collections). Rule fields and User/Person references; empty for new records.
     *
     * @return array<string, mixed>
     */
    public static function ruledChanges(EntityManagerInterface $em, object $record): array
    {
        $uow = $em->getUnitOfWork();
        if (!$uow->isInIdentityMap($record) || $uow->isScheduledForInsert($record)) {
            return [];
        }
        $meta = $em->getClassMetadata($record::class);
        $rules = WriteRules::of($record::class);
        // During flush the original data already holds the new values; the change set keeps the old ones.
        $original = $uow->getOriginalEntityData($record);
        foreach ($uow->getEntityChangeSet($record) as $field => $change) {
            $original[$field] = $change[0];
        }

        $fields = array_keys($rules);
        foreach ($meta->getAssociationNames() as $association) {
            $target = $meta->getAssociationTargetClass($association);
            if (is_a($target, User::class, true) || is_a($target, Person::class, true)) {
                $fields[] = $association;
            }
        }

        $changes = [];
        foreach (array_unique($fields) as $field) {
            if (!$meta->hasField($field) && !$meta->hasAssociation($field)) {
                continue;
            }
            $now = $meta->getFieldValue($record, $field);
            if ($meta->hasAssociation($field) && $meta->isCollectionValuedAssociation($field)) {
                if (!$now instanceof PersistentCollection) {
                    if ($now instanceof Collection && $now->isEmpty()) {
                        continue;
                    }
                    $changes[$field] = []; // replaced by a plain collection
                } elseif ($now->isDirty() && !self::sameList($now->getSnapshot(), $now->toArray())) {
                    $changes[$field] = $now->getSnapshot();
                }
                continue;
            }
            if (!array_key_exists($field, $original)) {
                continue;
            }
            if (!self::same($original[$field], $now)) {
                $changes[$field] = $original[$field];
            }
        }

        return $changes;
    }

    /**
     * The create-only fields among the changes — field → value before (S28: a
     * manager corrects them only with a reason).
     *
     * @param array<string, mixed> $changes from {@see ruledChanges()}
     * @return array<string, mixed>
     */
    public static function createOnlyChanges(object $record, array $changes): array
    {
        $rules = WriteRules::of($record::class);

        return array_filter(
            $changes,
            static fn (string $field): bool => ($rules[$field] ?? null) === WriteRules::CREATE_ONLY,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Why the change is refused, or null when it may pass.
     *
     * @param array<string, mixed> $changes from {@see ruledChanges()}
     */
    public function refusal(object $record, User $user, bool $isManager, array $changes): ?string
    {
        if ($changes === [] || $isManager) {
            return null;
        }
        $rules = WriteRules::of($record::class);
        $short = (new \ReflectionClass($record))->getShortName();
        foreach (array_keys($changes) as $field) {
            if (($rules[$field] ?? null) === WriteRules::CREATE_ONLY) {
                return sprintf('%s.%s is set when the record is created; only a manager corrects it.', $short, $field);
            }
        }

        $classified = array_values(array_filter(
            array_keys($changes),
            static fn (string $field): bool => ($rules[$field] ?? null) === WriteRules::MANAGER_OR_RESPONSIBLE,
        ));
        $references = array_diff(array_keys($changes), array_keys($rules));
        $handsOver = $references !== [] && $this->changesResponsibility($record, $changes);
        if ($classified === [] && !$handsOver) {
            return null;
        }
        if ($this->wasResponsible($user, $record, $changes)) {
            return null;
        }

        return $classified !== []
            ? sprintf('%s.%s is classification data; only a manager or the person responsible for the record changes it.', $short, $classified[0])
            : sprintf('Who is responsible for this %s is decided by a manager or the current holder.', $short);
    }

    /** @param array<string, mixed> $changes */
    private function wasResponsible(User $user, object $record, array $changes): bool
    {
        return $this->withOriginals($record, $changes, function () use ($user, $record): bool {
            foreach ($this->resolver->responsibleFor($record) as $candidate) {
                if ($candidate === $user || ($candidate->getId() !== null && $candidate->getId() === $user->getId())) {
                    return true;
                }
            }

            return false;
        });
    }

    /** @param array<string, mixed> $changes */
    private function changesResponsibility(object $record, array $changes): bool
    {
        $now = self::ids($this->resolver->responsibleFor($record));
        $before = $this->withOriginals($record, $changes, fn (): array => self::ids($this->resolver->responsibleFor($record)));

        return $now !== $before;
    }

    /**
     * @template T
     * @param array<string, mixed> $changes
     * @param callable(): T $fn
     * @return T
     */
    private function withOriginals(object $record, array $changes, callable $fn): mixed
    {
        $current = [];
        $properties = [];
        // Only the references decide who is responsible; ruled scalar fields stay as they are.
        $rules = WriteRules::of($record::class);
        try {
            foreach (array_diff_key($changes, $rules) as $field => $original) {
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

            return $fn();
        } finally {
            foreach ($properties as $field => $property) {
                $property->setValue($record, $current[$field]);
            }
        }
    }

    /**
     * @param list<User> $users
     * @return list<int>
     */
    private static function ids(array $users): array
    {
        $ids = array_map(static fn (User $u): int => $u->getId() ?? spl_object_id($u), $users);
        sort($ids);

        return array_values(array_unique($ids));
    }

    private static function same(mixed $a, mixed $b): bool
    {
        $a = $a instanceof \BackedEnum ? $a->value : $a;
        $b = $b instanceof \BackedEnum ? $b->value : $b;
        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            // a form re-submits a stored time at minute precision: dropping the seconds is no change
            return $a->getTimestamp() === $b->getTimestamp()
                || ($b->format('s') === '00' && intdiv($a->getTimestamp(), 60) === intdiv($b->getTimestamp(), 60));
        }
        if (is_object($a) || is_object($b)) {
            if (is_object($a) && is_object($b) && is_callable([$a, 'getId']) && is_callable([$b, 'getId'])
                && $a::class === $b::class && $a->getId() !== null) {
                return $a->getId() === $b->getId();
            }

            return $a === $b;
        }
        if (is_bool($a) || is_bool($b) || $a === null || $b === null) {
            return $a === $b || ($a !== null && $b !== null && (bool) $a === (bool) $b);
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a == $b;
    }

    /**
     * @param array<int|string, mixed> $a
     * @param array<int|string, mixed> $b
     */
    private static function sameList(array $a, array $b): bool
    {
        $key = static fn (mixed $x): string => is_object($x) ? (is_callable([$x, 'getId']) && $x->getId() !== null ? $x::class . '#' . $x->getId() : (string) spl_object_id($x)) : (string) json_encode($x);
        $ka = array_map($key, array_values($a));
        $kb = array_map($key, array_values($b));
        sort($ka);
        sort($kb);

        return $ka === $kb;
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

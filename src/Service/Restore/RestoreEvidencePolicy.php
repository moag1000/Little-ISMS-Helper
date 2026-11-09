<?php

declare(strict_types=1);

namespace App\Service\Restore;

use App\Attribute\EvidenceRecord;
use App\Service\Import\EvidenceFieldFilter;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Symfony\Component\Workflow\Registry;

/**
 * What an organisation restore must not take from the backup file (S26).
 *
 * The file is untrusted (S19: unsigned, editable by whoever holds it). Evidence is
 * written by the step that produces it (S12), never by a form, an import, the API —
 * and therefore not by a restore of an organisation admin either:
 *
 *  - whole records marked #[EvidenceRecord] (audit trail, management decisions,
 *    audit freezes, SoA snapshots, acknowledgements, approval requests and runs,
 *    published versions) are neither added, rewritten nor cleared;
 *  - on every other record the evidence fields (#[ApiReadOnlyEvidence], except the
 *    `importable` current-state values) and the lifecycle status (the marking of each
 *    Symfony workflow of the class — only LifecycleService moves it) keep their live
 *    value; a record the restore creates starts without evidence, in the workflow's
 *    initial place, like a record created by hand.
 *
 * The lists are derived from the attributes and the workflow registry, so a new
 * evidence field or lifecycle is covered without touching the restore
 * (tests/Security/RestoreEvidenceContractTest). An instance restore (SUPER_ADMIN,
 * no scope) stays complete.
 */
final class RestoreEvidencePolicy
{
    /** @var array<string, bool> */
    private static array $recordCache = [];

    /** @var array<string, array<string, array{status: bool, fresh: mixed}>> */
    private array $fieldCache = [];

    public function __construct(
        private readonly ?Registry $workflows = null,
    ) {
    }

    /** Whether the class is evidence as a whole (#[EvidenceRecord]). */
    public static function isEvidenceRecord(string $entityClass): bool
    {
        if (!isset(self::$recordCache[$entityClass])) {
            self::$recordCache[$entityClass] = class_exists($entityClass)
                && (new \ReflectionClass($entityClass))->getAttributes(EvidenceRecord::class) !== [];
        }

        return self::$recordCache[$entityClass];
    }

    /**
     * Fields of the class an organisation restore keeps at their live value, with the
     * value a record created by the restore gets instead of the file's.
     *
     * @param ClassMetadata<object> $meta
     * @return array<string, array{status: bool, fresh: mixed}> field => spec
     */
    public function protectedFields(ClassMetadata $meta): array
    {
        $class = $meta->getName();
        if (isset($this->fieldCache[$class])) {
            return $this->fieldCache[$class];
        }
        $fields = [];
        $blank = null;
        foreach (EvidenceFieldFilter::importProtectedFields($class) as $field) {
            if ($meta->hasField($field)
                || ($meta->hasAssociation($field) && $meta->isSingleValuedAssociation($field))
            ) {
                $blank ??= self::blankInstance($class);
                $fields[$field] = ['status' => false, 'fresh' => self::read($meta, $blank, $field)];
            }
        }
        foreach ($this->lifecycleStatusFields($meta) as $field => $initial) {
            $enum = $meta->getFieldMapping($field)->enumType ?? null;
            $fields[$field] = [
                'status' => true,
                'fresh' => $enum !== null && is_subclass_of($enum, \BackedEnum::class) ? $enum::from($initial) : $initial,
            ];
        }

        return $this->fieldCache[$class] = $fields;
    }

    /**
     * Doctrine field holding the marking of each Symfony workflow of the class, with
     * the workflow's initial place.
     *
     * @param ClassMetadata<object> $meta
     * @return array<string, string> field => initial place
     */
    public function lifecycleStatusFields(ClassMetadata $meta): array
    {
        if ($this->workflows === null) {
            return [];
        }
        $reflection = $meta->getReflectionClass();
        if (!$reflection->isInstantiable()) {
            return [];
        }
        $fields = [];
        foreach ($this->workflows->all($reflection->newInstanceWithoutConstructor()) as $workflow) {
            $field = self::doctrineField($meta, self::markingProperty($workflow->getMarkingStore()));
            $initial = $workflow->getDefinition()->getInitialPlaces()[0] ?? null;
            if ($field !== null && is_string($initial)) {
                $fields[$field] = $initial;
            }
        }

        return $fields;
    }

    /**
     * Live values of the protected fields of an existing record (before the file is applied).
     *
     * @param ClassMetadata<object> $meta
     * @return array<string, mixed>
     */
    public function capture(object $entity, ClassMetadata $meta): array
    {
        $live = [];
        foreach (array_keys($this->protectedFields($meta)) as $field) {
            $live[$field] = self::read($meta, $entity, $field);
        }

        return $live;
    }

    /**
     * Puts the protected fields back: the live values of an existing record, or the
     * fresh values of a record the restore creates. Returns the fields where the file
     * carried something else.
     *
     * @param ClassMetadata<object>     $meta
     * @param array<string, mixed>|null $live from capture(); null = the restore creates the record
     * @return list<string>
     */
    public function keep(object $entity, ClassMetadata $meta, ?array $live): array
    {
        $discarded = [];
        foreach ($this->protectedFields($meta) as $field => $spec) {
            $target = $live !== null ? ($live[$field] ?? null) : $spec['fresh'];
            if (self::same(self::read($meta, $entity, $field), $target)) {
                continue;
            }
            $meta->setFieldValue($entity, $field, $target);
            $discarded[] = $field;
        }

        return $discarded;
    }

    private static function read(ClassMetadata $meta, ?object $entity, string $field): mixed
    {
        if ($entity === null) {
            return null;
        }
        try {
            return $meta->getFieldValue($entity, $field);
        } catch (\Error) {
            return null; // typed property never initialised
        }
    }

    private static function blankInstance(string $class): ?object
    {
        try {
            return new $class();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return self::normalise($a) === self::normalise($b);
    }

    private static function normalise(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof \DateTimeInterface => 'd:' . $value->format('Y-m-d H:i:s'),
            $value instanceof \BackedEnum => 'e:' . $value->value,
            $value instanceof \UnitEnum => 'e:' . $value->name,
            is_bool($value) => 'b:' . (int) $value,
            is_scalar($value) => 's:' . $value,
            is_array($value) => 'a:' . json_encode($value),
            is_object($value) && is_callable([$value, 'getId']) => 'o:' . get_debug_type($value) . '#' . var_export($value->getId(), true),
            default => 'x:' . spl_object_id($value),
        };
    }

    private static function markingProperty(MarkingStoreInterface $store): ?string
    {
        $reflection = new \ReflectionObject($store);
        while ($reflection !== false) {
            if ($reflection->hasProperty('property')) {
                $value = $reflection->getProperty('property')->getValue($store);

                return is_string($value) ? $value : null;
            }
            $reflection = $reflection->getParentClass();
        }

        return null;
    }

    /**
     * The mapped field behind a marking property: the property itself, or the field a
     * string bridge (`statusValue` over an enum `status`) converts.
     *
     * @param ClassMetadata<object> $meta
     */
    private static function doctrineField(ClassMetadata $meta, ?string $property): ?string
    {
        if ($property === null) {
            return null;
        }
        if ($meta->hasField($property)) {
            return $property;
        }
        if (str_ends_with($property, 'Value') && $meta->hasField(substr($property, 0, -5))) {
            return substr($property, 0, -5);
        }

        return null;
    }
}

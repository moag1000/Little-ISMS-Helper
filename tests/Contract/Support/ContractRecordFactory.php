<?php

declare(strict_types=1);

namespace App\Tests\Contract\Support;

use ApiPlatform\Metadata\ApiResource;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Builds a persistable record of ANY entity from its Doctrine mapping, so the
 * contract tests can iterate every API resource without one hand-written
 * fixture per class (a new resource is covered the day it is added).
 *
 * Required columns get a type-appropriate value, required relations a record
 * of the same organisation (built the same way), and the name-like text
 * columns carry a marker — a response that contains the marker leaked the
 * record. Values are not meant to pass form validation, only the database.
 */
final class ContractRecordFactory
{
    /** Text columns that carry the leak marker when the entity has them. */
    private const array MARKER_FIELDS = ['title', 'name', 'fullName', 'description', 'summary', 'subject', 'reference'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function create(string $class, Tenant $tenant, string $marker, int $depth = 0): object
    {
        $meta = $this->em->getClassMetadata($class);
        $ref = new ReflectionClass($class);
        $constructor = $ref->getConstructor();
        $record = $constructor === null || $constructor->getNumberOfRequiredParameters() === 0
            ? $ref->newInstance()
            : $ref->newInstanceWithoutConstructor();

        $marked = false;
        foreach ($meta->getFieldNames() as $field) {
            if ($meta->isIdentifier($field)) {
                continue;
            }
            $mapping = $meta->getFieldMapping($field);
            $property = $this->property($ref, $field);
            if ($property === null || str_contains($field, '.')) {
                continue; // embedded column — set through its embeddable
            }
            $isText = in_array($mapping->type, ['string', 'text'], true);
            if ($isText && in_array($field, self::MARKER_FIELDS, true)) {
                $length = $mapping->length ?? 255;
                $property->setValue($record, substr($marker . ' ' . $field, 0, max(1, (int) $length)));
                $marked = true;
                continue;
            }
            if (($mapping->nullable ?? false) || ($property->isInitialized($record) && $property->getValue($record) !== null)) {
                continue;
            }
            $value = $this->valueFor($mapping->type, $mapping->enumType ?? null, $mapping->length ?? null);
            if ($value !== null) {
                $property->setValue($record, $value);
            }
        }

        if (!$marked) {
            $this->markFirstSerializedText($meta, $ref, $record, $marker);
        }

        foreach ($meta->getAssociationNames() as $association) {
            $property = $this->property($ref, $association);
            if ($property === null) {
                continue;
            }
            if ($association === 'tenant') {
                $property->setValue($record, $tenant);
                continue;
            }
            if (!$this->isRequiredToOne($meta, $association)) {
                continue;
            }
            if ($property->isInitialized($record) && $property->getValue($record) !== null) {
                continue;
            }
            $target = $meta->getAssociationTargetClass($association);
            $property->setValue($record, $this->related($target, $tenant, $marker, $depth));
        }

        $this->em->persist($record);

        return $record;
    }

    private function related(string $target, Tenant $tenant, string $marker, int $depth): object
    {
        if ($target === Tenant::class) {
            return $tenant;
        }
        $targetMeta = $this->em->getClassMetadata($target);
        $shared = !$targetMeta->hasAssociation('tenant');
        if ($depth >= 3) {
            throw new \LogicException(sprintf('Cannot build a required %s for the contract test (relation chain too deep).', $target));
        }

        // A global row (requirement, framework, …) is visible to every organisation by
        // design, so it must not carry the leak marker.
        return $this->create($target, $tenant, $shared ? 'contract shared' : $marker, $depth + 1);
    }

    /** No name-like column: the marker goes into the first serialized text column. */
    private function markFirstSerializedText(ClassMetadata $meta, ReflectionClass $ref, object $record, string $marker): void
    {
        foreach ($meta->getFieldNames() as $field) {
            $property = $this->property($ref, $field);
            if ($property === null || !in_array($meta->getTypeOfField($field), ['string', 'text'], true)
                || $property->getAttributes(Groups::class) === []) {
                continue;
            }
            $length = $meta->getFieldMapping($field)->length ?? 255;
            if ($length >= strlen($marker)) {
                $property->setValue($record, substr($marker . ' ' . $field, 0, (int) $length));

                return;
            }
        }
    }

    private function isRequiredToOne(ClassMetadata $meta, string $association): bool
    {
        $mapping = $meta->getAssociationMapping($association);
        if (!$mapping->isToOneOwningSide()) {
            return false;
        }
        foreach ($mapping->joinColumns ?? [] as $joinColumn) {
            if (($joinColumn->nullable ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    private function valueFor(string $type, ?string $enumType, ?int $length): mixed
    {
        if ($enumType !== null && enum_exists($enumType)) {
            return $enumType::cases()[0] ?? null;
        }

        return match ($type) {
            // unique per call: some required text columns carry a unique index (codes, keys)
            'string', 'text', 'ascii_string' => substr('c' . bin2hex(random_bytes(8)), 0, max(1, $length ?? 255)),
            'integer', 'smallint', 'bigint' => 1,
            'float' => 1.0,
            'decimal' => '1.00',
            'boolean' => false,
            'json', 'simple_array' => [],
            'datetime' => new \DateTime(),
            'datetime_immutable', 'datetimetz_immutable' => new \DateTimeImmutable(),
            'date' => new \DateTime('today'),
            'date_immutable' => new \DateTimeImmutable('today'),
            'time' => new \DateTime(),
            'time_immutable' => new \DateTimeImmutable(),
            'guid' => '00000000-0000-4000-8000-' . substr(bin2hex(random_bytes(6)), 0, 12),
            default => null,
        };
    }

    private function property(ReflectionClass $ref, string $name): ?ReflectionProperty
    {
        for ($class = $ref; $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($name)) {
                return $class->getProperty($name);
            }
        }

        return null;
    }

    /** @return array<string, array{class-string, bool}> property => [target class, to-many] */
    public function writableTenantRelations(string $class): array
    {
        $meta = $this->em->getClassMetadata($class);
        $relations = [];
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            $name = $property->getName();
            if ($name === 'tenant' || !$meta->hasAssociation($name) || !self::inWriteGroup($property)) {
                continue;
            }
            $target = $meta->getAssociationTargetClass($name);
            $targetRef = new ReflectionClass($target);
            if (!$targetRef->hasProperty('tenant') || $targetRef->getAttributes(ApiResource::class) === []) {
                continue; // only targets the client can name by IRI
            }
            $relations[$name] = [$target, $meta->isCollectionValuedAssociation($name)];
        }

        return $relations;
    }

    /** The property is in a `*:write` serialization group. */
    public static function inWriteGroup(ReflectionProperty $property): bool
    {
        foreach ($property->getAttributes(Groups::class) as $attribute) {
            foreach ($attribute->newInstance()->groups as $group) {
                if (str_ends_with($group, ':write')) {
                    return true;
                }
            }
        }

        return false;
    }
}

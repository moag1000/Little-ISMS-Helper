<?php

declare(strict_types=1);

namespace App\Mcp;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * What an assistant needs to fill a field right the first time (#1207): its type, whether it is required, the
 * allowed values of a choice or enum and the bounds of a scale — read from the entity's own validation and
 * mapping, never a second list. `save_record` with empty `data` returns it, a rejected value names the allowed
 * ones ({@see allowedValues()}).
 */
final readonly class FieldSchema
{
    public function __construct(
        private ValidatorInterface $validator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param class-string                $class
     * @param list<string>                $fields        writable fields (API names = property names)
     * @param array<class-string, string> $resourceNames short resource name by entity class, for relations
     * @return list<array<string, mixed>>
     */
    public function describe(string $class, array $fields, array $resourceNames): array
    {
        $mapping = $this->entityManager->getClassMetadata($class);
        $described = [];
        foreach ($fields as $field) {
            $entry = ['name' => $field];
            if ($mapping->hasAssociation($field)) {
                $target = $mapping->getAssociationTargetClass($field);
                $many = $mapping->isCollectionValuedAssociation($field);
                $entry['type'] = $many ? 'array of IRI' : 'IRI';
                $entry['resource'] = $resourceNames[$target] ?? (string) substr((string) strrchr('\\' . $target, '\\'), 1);
            } else {
                $entry += $this->scalarType($class, $field);
            }
            $constraints = $this->constraintsOf($class, $field);
            $allowed = $this->allowedFrom($class, $constraints) ?? ($entry['allowed'] ?? null);
            unset($entry['allowed']);
            if ($allowed !== null) {
                $entry['allowed'] = $allowed;
            }
            $entry['required'] = array_filter($constraints, static fn (object $c): bool => $c instanceof NotBlank || $c instanceof NotNull) !== [];
            foreach ($constraints as $constraint) {
                if ($constraint instanceof Range) {
                    $entry['min'] = $constraint->min;
                    $entry['max'] = $constraint->max;
                } elseif ($constraint instanceof Length && $constraint->max !== null) {
                    $entry['max_length'] = $constraint->max;
                }
            }
            $described[] = $entry;
        }

        return $described;
    }

    /**
     * Allowed values of a field, when it is a choice or an enum — for the message of a rejected value.
     *
     * @param class-string $class
     * @return list<string>|null
     */
    public function allowedValues(string $class, string $field): ?array
    {
        $field = explode('.', $field)[0];
        $allowed = $this->allowedFrom($class, $this->constraintsOf($class, $field));
        if ($allowed !== null) {
            return $allowed;
        }
        $type = $this->scalarType($class, $field);

        return isset($type['allowed']) && is_array($type['allowed']) ? $type['allowed'] : null;
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>
     */
    private function scalarType(string $class, string $field): array
    {
        if (!property_exists($class, $field)) {
            return [];
        }
        try {
            $type = (new \ReflectionProperty($class, $field))->getType();
        } catch (\ReflectionException) {
            return [];
        }
        if (!$type instanceof \ReflectionNamedType) {
            return [];
        }
        $name = $type->getName();
        if (enum_exists($name) && is_subclass_of($name, \BackedEnum::class)) {
            return ['type' => 'enum', 'allowed' => array_map(static fn (\BackedEnum $case): string => (string) $case->value, $name::cases())];
        }
        if (is_a($name, \DateTimeInterface::class, true) || $name === \DateTimeInterface::class) {
            return ['type' => 'date-time (ISO 8601; any offset, stored in the server time zone)'];
        }

        return ['type' => match ($name) {
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
            'string' => 'string',
            default => $name,
        }];
    }

    /**
     * @param class-string $class
     * @return list<object>
     */
    private function constraintsOf(string $class, string $field): array
    {
        $metadata = $this->validator->getMetadataFor($class);
        if (!$metadata instanceof ClassMetadataInterface || !$metadata->hasPropertyMetadata($field)) {
            return [];
        }
        $constraints = [];
        foreach ($metadata->getPropertyMetadata($field) as $property) {
            foreach ($property->getConstraints() as $constraint) {
                $constraints[] = $constraint;
            }
        }

        return $constraints;
    }

    /**
     * @param class-string $class
     * @param list<object> $constraints
     * @return list<string>|null
     */
    private function allowedFrom(string $class, array $constraints): ?array
    {
        foreach ($constraints as $constraint) {
            if (!$constraint instanceof Choice) {
                continue;
            }
            $choices = $constraint->choices;
            if ($choices === null && $constraint->callback !== null) {
                try {
                    $callback = $constraint->callback;
                    $choices = is_string($callback) && !function_exists($callback) ? $class::$callback() : $callback();
                } catch (\Throwable) {
                    $choices = null;
                }
            }
            if (is_array($choices) && $choices !== []) {
                return array_values(array_map(static fn (mixed $v): string => (string) $v, $choices));
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\WriteRule;

use App\Attribute\CreateOnly;
use App\Attribute\FrozenInEndState;
use App\Attribute\ManagerOrResponsibleField;

/**
 * S22: the write rules an entity declares on its properties — one place every
 * writer reads them from (the flush guard, the form extension, the bulk import,
 * Gate 96). Properties of parent classes and traits count.
 */
final class WriteRules
{
    public const string CREATE_ONLY = 'create_only';
    public const string MANAGER_OR_RESPONSIBLE = 'manager_or_responsible';

    /** @var array<class-string, array<string, string>> */
    private static array $cache = [];

    /**
     * @param class-string|string $class
     * @return array<string, string> property → rule (CREATE_ONLY / MANAGER_OR_RESPONSIBLE)
     */
    public static function of(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }
        $rules = [];
        if (class_exists($class)) {
            for ($reflection = new \ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                foreach ($reflection->getProperties() as $property) {
                    if (isset($rules[$property->getName()]) || $property->isStatic()) {
                        continue;
                    }
                    if ($property->getAttributes(CreateOnly::class) !== []) {
                        $rules[$property->getName()] = self::CREATE_ONLY;
                    } elseif ($property->getAttributes(ManagerOrResponsibleField::class) !== []) {
                        $rules[$property->getName()] = self::MANAGER_OR_RESPONSIBLE;
                    }
                }
            }
        }

        return self::$cache[$class] = $rules;
    }

    /**
     * Fields a bulk-import UPDATE of an existing record leaves alone: the create-only
     * ones (the importer is a manager; the rule still keeps an existing anchor where it is).
     *
     * @return list<string>
     */
    public static function createOnlyFields(string $class): array
    {
        return array_keys(array_filter(self::of($class), static fn (string $rule): bool => $rule === self::CREATE_ONLY));
    }

    /**
     * S34 (R7): the fields that stay put once the record is in an end state of its lifecycle.
     *
     * @return list<string>
     */
    public static function frozenFields(string $class): array
    {
        $fields = [];
        if (class_exists($class)) {
            for ($reflection = new \ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                foreach ($reflection->getProperties() as $property) {
                    if (!$property->isStatic() && $property->getAttributes(FrozenInEndState::class) !== []) {
                        $fields[] = $property->getName();
                    }
                }
            }
        }

        return array_values(array_unique($fields));
    }
}

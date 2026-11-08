<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Attribute\ApiReadOnlyEvidence;

/**
 * Evidence is written by the action that produces it (mark as reported, approve,
 * run the test) — not by a spreadsheet. The bulk import neither sets it on a NEW
 * record (S11: a backdated report time or approval in an import row would fake a
 * deadline kept) nor overwrites it on an existing one. Evidence = properties
 * marked #[ApiReadOnlyEvidence], the same set REST and the assistant may not write;
 * those flagged `importable` (current-state values like a control's implementation
 * status, set by a person in the reviewed import) pass.
 */
final class EvidenceFieldFilter
{
    /** @var array<class-string, list<string>> */
    private static array $cache = [];

    /** @var array<class-string, list<string>> */
    private static array $importableCache = [];

    /** @return list<string> */
    public static function evidenceFields(string $class): array
    {
        if (!isset(self::$cache[$class])) {
            $fields = [];
            if (class_exists($class)) {
                foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                    foreach ($property->getAttributes(ApiReadOnlyEvidence::class) as $attribute) {
                        $fields[] = $property->getName();
                        if ($attribute->newInstance()->importable) {
                            self::$importableCache[$class][] = $property->getName();
                        }
                    }
                }
            }
            self::$cache[$class] = $fields;
        }

        return self::$cache[$class];
    }

    /**
     * Evidence fields an import must not write (all but the `importable` ones).
     *
     * @return list<string>
     */
    public static function importProtectedFields(string $class): array
    {
        $fields = self::evidenceFields($class);

        return array_values(array_diff($fields, self::$importableCache[$class] ?? []));
    }

    /**
     * Entity data for an UPDATE of an existing record: evidence fields removed.
     *
     * @param array<string, mixed> $entityData
     * @return array<string, mixed>
     */
    public static function forUpdate(string $class, array $entityData): array
    {
        return array_diff_key($entityData, array_flip(self::importProtectedFields($class)));
    }

    /**
     * Entity data for a NEW record: evidence fields removed as well — the record is
     * created without them and the action fills them (S11).
     *
     * @param array<string, mixed> $entityData
     * @return array<string, mixed>
     */
    public static function forCreate(string $class, array $entityData): array
    {
        return self::forUpdate($class, $entityData);
    }
}

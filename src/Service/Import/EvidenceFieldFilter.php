<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Attribute\ApiReadOnlyEvidence;

/**
 * The bulk import may bring evidence along with a NEW record (migrating closed
 * incidents with their report times, tested plans, verified changes from an
 * old tool — the batch is audit-logged with its source file), but never
 * overwrite it on an existing record: a delta import must not move a report
 * time, a test date or an approval after the fact. Evidence = properties
 * marked #[ApiReadOnlyEvidence], the same set REST and the assistant may not write.
 */
final class EvidenceFieldFilter
{
    /** @var array<class-string, list<string>> */
    private static array $cache = [];

    /** @return list<string> */
    public static function evidenceFields(string $class): array
    {
        if (!isset(self::$cache[$class])) {
            $fields = [];
            if (class_exists($class)) {
                foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                    if ($property->getAttributes(ApiReadOnlyEvidence::class) !== []) {
                        $fields[] = $property->getName();
                    }
                }
            }
            self::$cache[$class] = $fields;
        }

        return self::$cache[$class];
    }

    /**
     * Entity data for an UPDATE of an existing record: evidence fields removed.
     *
     * @param array<string, mixed> $entityData
     * @return array<string, mixed>
     */
    public static function forUpdate(string $class, array $entityData): array
    {
        return array_diff_key($entityData, array_flip(self::evidenceFields($class)));
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Import\Schema;

/**
 * The complete importable-field schema for one entity type.
 *
 * `module` (when set) gates the WHOLE import for that entity — the type is only
 * importable when the module is active. Individual fields can be gated
 * independently via {@see ImportFieldSpec::$module}.
 */
final class EntityImportSchema
{
    /**
     * @param class-string         $entityClass
     * @param list<ImportFieldSpec> $fields
     */
    public function __construct(
        public readonly string $entityType,
        public readonly string $entityClass,
        public readonly array $fields,
        public readonly ?string $module = null,
    ) {
    }

    /** @return list<ImportFieldSpec> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function fieldByName(string $name): ?ImportFieldSpec
    {
        foreach ($this->fields as $f) {
            if ($f->name === $name) {
                return $f;
            }
        }

        return null;
    }
}

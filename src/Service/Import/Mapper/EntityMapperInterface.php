<?php

declare(strict_types=1);

namespace App\Service\Import\Mapper;

use App\Entity\Tenant;

/**
 * Contract for entity-specific import mappers.
 *
 * Each mapper receives pre-parsed row-arrays (from SpreadsheetParser) and is
 * responsible for validation, type-casting, and resolving the final entity-
 * property array that the BulkImportOrchestrator will persist.
 *
 * Mappers MUST NOT parse raw files — that is SpreadsheetParser's job.
 */
interface EntityMapperInterface
{
    /**
     * Whether this mapper handles the given entity type string.
     * Convention: use the simple class name without namespace (e.g. 'Asset').
     */
    public function supportsEntityType(string $entityType): bool;

    /**
     * Validate a parsed row array against the entity's business rules.
     *
     * @param array<string, mixed> $row
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $row): array;

    /**
     * Map a parsed row to an entity-property array ready for persistence.
     *
     * Keys in the returned array correspond to entity setter-property names
     * (camelCase without "set" prefix, e.g. 'name', 'assetType').
     *
     * @param array<string, mixed>      $row
     * @param array<string, string>|null $columnMapping  optional manual column → field override
     * @return array<string, mixed>
     */
    public function toEntityData(array $row, ?array $columnMapping = null): array;

    /**
     * Attempt to find an existing entity matching the row (for delta / upsert mode).
     * Match strategy is mapper-specific (e.g. name+type for Asset, identifier for Control).
     *
     * @param array<string, mixed> $row
     * @return object|null  the existing entity, or null if not found
     */
    public function findExisting(array $row, Tenant $tenant): ?object;
}

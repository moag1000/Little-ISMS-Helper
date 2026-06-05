<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Exception\Import\ImportFailedException;
use App\Service\Import\Mapper\EntityMapperInterface;
use App\Service\Import\Schema\ImportSchemaRegistry;

/**
 * Central registry for entity import mappers.
 *
 * Mappers are collected via the Symfony tagged-service iterator mechanism.
 * Services tagged with `app.import.mapper` are injected automatically;
 * see config/services.yaml for the tag registration.
 */
final class EntityMapperRegistry
{
    /** @var list<EntityMapperInterface> */
    private array $mappers;

    /**
     * @param iterable<EntityMapperInterface> $mappers
     */
    public function __construct(
        iterable $mappers,
        private readonly ?ImportSchemaRegistry $schemaRegistry = null,
    ) {
        $this->mappers = iterator_to_array($mappers, false);
    }

    /**
     * Return the mapper responsible for $entityType. Hand-written mappers win;
     * otherwise a {@see \App\Service\Import\Schema\SchemaDrivenMapper} is built
     * from the schema registry.
     *
     * @throws ImportFailedException when no mapper supports the requested type
     */
    public function getMapperFor(string $entityType): EntityMapperInterface
    {
        foreach ($this->mappers as $mapper) {
            if ($mapper->supportsEntityType($entityType)) {
                return $mapper;
            }
        }

        $schemaMapper = $this->schemaRegistry?->getMapperFor($entityType);
        if ($schemaMapper !== null) {
            return $schemaMapper;
        }

        throw ImportFailedException::forType(
            $entityType,
            sprintf(
                'No import mapper registered. Supported types: %s.',
                implode(', ', $this->getSupportedEntityTypes()),
            ),
        );
    }

    /**
     * @return list<string>  entity-type strings supported by import (schema-driven).
     *
     * All generic bulk-import types are schema-driven; the only hand-written
     * mapper left (TisaxRequirement) has its own dedicated wizard, not the
     * generic dropdown.
     */
    public function getSupportedEntityTypes(): array
    {
        return array_values(array_unique($this->schemaRegistry?->supportedEntityTypes() ?? []));
    }

    /**
     * Whether a mapper is available for $entityType.
     */
    public function hasMapperFor(string $entityType): bool
    {
        foreach ($this->mappers as $mapper) {
            if ($mapper->supportsEntityType($entityType)) {
                return true;
            }
        }

        return $this->schemaRegistry?->hasSchemaFor($entityType) ?? false;
    }
}

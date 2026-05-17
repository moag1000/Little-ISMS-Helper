<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Exception\Import\ImportFailedException;
use App\Service\Import\Mapper\EntityMapperInterface;

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
    public function __construct(iterable $mappers)
    {
        $this->mappers = iterator_to_array($mappers, false);
    }

    /**
     * Return the mapper responsible for $entityType.
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

        throw ImportFailedException::forType(
            $entityType,
            sprintf(
                'No import mapper registered. Supported types: %s.',
                implode(', ', $this->getSupportedEntityTypes()),
            ),
        );
    }

    /**
     * @return list<string>  entity-type strings supported by registered mappers
     */
    public function getSupportedEntityTypes(): array
    {
        $types = [];
        foreach ($this->mappers as $mapper) {
            // Derive display name from each mapper's support check
            foreach (['Asset', 'Supplier', 'Control', 'Risk', 'BusinessProcess'] as $candidate) {
                if ($mapper->supportsEntityType($candidate)) {
                    $types[] = $candidate;
                }
            }
        }

        return array_unique($types);
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

        return false;
    }
}

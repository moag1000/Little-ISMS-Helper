<?php

declare(strict_types=1);

namespace App\Service\Import\Schema;

use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Collects every {@see ImportSchemaProviderInterface} and builds a
 * {@see SchemaDrivenMapper} for a requested entity type on demand.
 *
 * {@see \App\Service\Import\EntityMapperRegistry} consults this after its
 * hand-written mappers, so schema-defined and bespoke mappers coexist.
 */
final class ImportSchemaRegistry
{
    /** @var list<ImportSchemaProviderInterface> */
    private array $providers;

    /**
     * @param iterable<ImportSchemaProviderInterface> $providers
     */
    public function __construct(
        iterable $providers,
        private readonly EntityManagerInterface $em,
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {
        $this->providers = iterator_to_array($providers, false);
    }

    public function hasSchemaFor(string $entityType): bool
    {
        return $this->findProvider($entityType) !== null;
    }

    public function getSchemaFor(string $entityType): ?EntityImportSchema
    {
        return $this->findProvider($entityType)?->getSchema();
    }

    public function getMapperFor(string $entityType): ?SchemaDrivenMapper
    {
        $schema = $this->getSchemaFor($entityType);
        if ($schema === null) {
            return null;
        }

        return new SchemaDrivenMapper($this->em, $this->moduleConfiguration, $schema);
    }

    /** @return list<string> entity types backed by a schema */
    public function supportedEntityTypes(): array
    {
        return array_map(static fn(ImportSchemaProviderInterface $p): string => $p->getSchema()->entityType, $this->providers);
    }

    private function findProvider(string $entityType): ?ImportSchemaProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($entityType)) {
                return $provider;
            }
        }

        return null;
    }
}

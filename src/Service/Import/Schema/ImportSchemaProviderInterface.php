<?php

declare(strict_types=1);

namespace App\Service\Import\Schema;

/**
 * Provides the {@see EntityImportSchema} for one entity type. Implementations
 * are auto-registered (tagged iterable) and collected by {@see ImportSchemaRegistry}.
 *
 * Adding bulk-import support for a new entity = add one provider class; no
 * changes to the orchestrator, wizard, or generic mapper are needed.
 */
interface ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool;

    public function getSchema(): EntityImportSchema;
}

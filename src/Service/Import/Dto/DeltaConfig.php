<?php

declare(strict_types=1);

namespace App\Service\Import\Dto;

use App\Entity\Tenant;

/**
 * Configuration DTO for a single DeltaCalculator run.
 *
 * Passed into DeltaCalculator::calculate() to control which entity type is
 * being imported, which tenant's data is in scope, and optional behaviour flags.
 */
final readonly class DeltaConfig
{
    /**
     * @param string   $entityType     Simple class name without namespace, e.g. 'Asset', 'Supplier'.
     * @param Tenant   $tenant         Tenant whose persisted entities are compared against the sheet.
     * @param bool     $includeDeletes When true, entities present in DB but absent from the sheet
     *                                 are collected as deletable candidates in DeltaResult::$deletes.
     * @param string[] $ignoredFields  Entity property names excluded from diff comparison.
     *                                 Defaults to audit-timestamp fields 'updatedAt' and 'createdAt'.
     */
    public function __construct(
        public string $entityType,
        public Tenant $tenant,
        public bool $includeDeletes = false,
        public array $ignoredFields = ['updatedAt', 'createdAt'],
    ) {}
}

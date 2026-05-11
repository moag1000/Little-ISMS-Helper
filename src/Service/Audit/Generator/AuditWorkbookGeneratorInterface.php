<?php

declare(strict_types=1);

namespace App\Service\Audit\Generator;

use App\Entity\Tenant;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Contract for all ALVA Audit-Workbook generators.
 *
 * Each implementation produces an audit-ready XLSX spreadsheet for a
 * specific export type (Statement of Applicability, Risk Register, …).
 * The orchestrator AuditWorkbookGenerator selects the correct implementation
 * at runtime via supportsExportType().
 */
interface AuditWorkbookGeneratorInterface
{
    /**
     * Returns true when this generator handles the given export type.
     *
     * Known export types:
     *   'soa'                     — Statement of Applicability (ISO 27001 Annex A)
     *   'control-implementation'  — Per-control implementation detail
     *   'compliance-fulfillment'  — Compliance Requirement Fulfillment by framework
     *   'risk-register'           — Risk Register with treatment & residual-risk
     */
    public function supportsExportType(string $exportType): bool;

    /**
     * Generate a Spreadsheet workbook for the given tenant.
     *
     * @param Tenant               $tenant  The tenant whose data is exported
     * @param array<string, mixed> $options Generator-specific options (e.g. frameworkId)
     */
    public function generate(Tenant $tenant, array $options = []): Spreadsheet;
}

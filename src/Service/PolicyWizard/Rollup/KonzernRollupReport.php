<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Rollup;

use App\Entity\Tenant;

/**
 * Policy-Wizard W7-B — read-only DTO carrying the Konzern roll-up
 * data assembled by {@see KonzernRollupAggregator}.
 *
 * Spec: docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md
 *       lines 298-301 (Compliance-Manager "What's missing" #4 +
 *       CISO Board-Reporting + Auditor Konzern-Tochter compliance).
 *
 * Shape of the public arrays:
 *
 *  tenantTree (hierarchical): list<array{
 *      tenant_id: int,
 *      code: string,
 *      name: string,
 *      depth: int,
 *      children: list<array<string, mixed>>,
 *  }>
 *
 *  policyCoverageMatrix: list<array{
 *      tenant_id: int,
 *      tenant_code: string,
 *      tenant_name: string,
 *      standards: array<string, array{
 *          standard_code: string,
 *          policy_count: int,
 *          last_updated_at: ?string,         // ISO-8601 string, nullable
 *          approval_status_breakdown: array<string, int>,
 *      }>,
 *  }>
 *
 *  outstandingActions: list<array{
 *      tenant_id: int,
 *      tenant_code: string,
 *      tenant_name: string,
 *      action: string,                        // human label, e.g. "ciso_review"
 *      severity: string,                      // info|warning|danger
 *      due_in_seconds: ?int,                  // nullable when no dueDate
 *      workflow_instance_id: int,
 *      entity_type: string,
 *      entity_id: int,
 *  }>
 *
 *  complianceScore: list<array{
 *      tenant_id: int,
 *      tenant_code: string,
 *      tenant_name: string,
 *      framework_code: string,
 *      framework_name: string,
 *      score_percentage: float,                // 0..100
 *      total_requirements: int,
 *      fulfilled_requirements: int,
 *  }>
 *
 *  settingsDriftRows: list<array{
 *      tenant_id: int,
 *      tenant_code: string,
 *      tenant_name: string,
 *      setting_key: string,
 *      konzern_value: mixed,
 *      tochter_value: mixed,
 *      drift_detected_at: ?string,
 *      override_mode: ?string,                 // floor_only|ceiling_only|...
 *  }>
 *
 *  acknowledgmentCoverage: list<array{
 *      tenant_id: int,
 *      tenant_code: string,
 *      tenant_name: string,
 *      published_documents_count: int,
 *      total_users: int,
 *      acknowledgements_count: int,
 *      coverage_percentage: float,             // 0..100
 *  }>
 */
final readonly class KonzernRollupReport
{
    /**
     * @param list<array<string, mixed>> $tenantTree
     * @param list<array<string, mixed>> $policyCoverageMatrix
     * @param list<array<string, mixed>> $outstandingActions
     * @param list<array<string, mixed>> $complianceScore
     * @param list<array<string, mixed>> $settingsDriftRows
     * @param list<array<string, mixed>> $acknowledgmentCoverage
     */
    public function __construct(
        public Tenant $konzernRoot,
        public array $tenantTree,
        public array $policyCoverageMatrix,
        public array $outstandingActions,
        public array $complianceScore,
        public array $settingsDriftRows,
        public array $acknowledgmentCoverage,
        public int $subsidiaryCount,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->subsidiaryCount === 0;
    }
}

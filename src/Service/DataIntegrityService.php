<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Service\DataIntegrity\DuplicateFinder;
use App\Service\DataIntegrity\EntityCountAggregator;
use App\Service\DataIntegrity\HealthIssueAggregator;
use App\Service\DataIntegrity\OrphanFinder;
use App\Service\DataIntegrity\ReferenceIntegrityChecker;
use App\Service\DataIntegrity\SchemaDriftChecker;
use App\Service\DataIntegrity\StatusEnumDriftChecker;
use App\Service\DataIntegrity\UploadOrphanChecker;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\TenantRepository;
use App\Repository\ControlRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\DocumentRepository;
use App\Repository\TrainingRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\DataBreachRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\SupplierRepository;
use App\Repository\LocationRepository;
use App\Repository\PersonRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\KpiSnapshotRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Repository\RiskTreatmentPlanRepository;

/**
 * Comprehensive data integrity checker for tenant isolation and data consistency.
 *
 * This class is a pure facade — all detection logic is delegated to collaborators
 * under the App\Service\DataIntegrity\ namespace:
 *
 *   - {@see OrphanFinder}              — orphaned entities + cascade orphans
 *   - {@see DuplicateFinder}           — duplicate detection + merge
 *   - {@see ReferenceIntegrityChecker} — broken refs, missing relationships, inconsistent data
 *   - {@see HealthIssueAggregator}     — risk, compliance, operational, data-quality checks
 *   - {@see SchemaDriftChecker}        — JSON-schema violations + AuditLog gaps
 *   - {@see UploadOrphanChecker}       — filesystem orphan scan (already extracted pre-split)
 *   - {@see StatusEnumDriftChecker}    — status-enum drift (already extracted pre-split)
 *
 * The facade retains the original public API for backward-compat with all call-sites
 * (admin UI, data-repair commands, Jobs, etc.). The only logic in this class is:
 *   - getEntityCountsByTenant()   — per-tenant aggregate counts (used for dashboard)
 *   - getSummaryStatistics()      — aggregates counts from delegates
 *   - calculateHealthScore()      — health score formula (private)
 *   - getGlobalCatalogueEntityClasses() — exposes constant from OrphanFinder
 *   - runFullIntegrityCheck()     — dispatches all checks and returns unified result
 */
final class DataIntegrityService
{
    /**
     * Entity classes that are INTENTIONALLY globally scoped (tenant_id = NULL by design).
     *
     * These entities are shared across all tenants as catalogue data. The orphan-repair
     * logic MUST NOT reassign a tenant_id to them — doing so triggers a
     * UniqueConstraintViolationException when multiple seeded rows share the same
     * unique key (e.g. NotificationTemplate.uniq_template_key_tenant).
     *
     * Source of truth: {@see OrphanFinder::GLOBAL_CATALOGUE_ENTITIES}.
     * The facade returns the constant from there to avoid duplication.
     */

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly TenantRepository $tenantRepository,
        private readonly ControlRepository $controlRepository,
        private readonly InternalAuditRepository $auditRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly LocationRepository $locationRepository,
        private readonly PersonRepository $personRepository,
        private readonly ?DataSubjectRequestRepository $dataSubjectRequestRepository = null,
        private readonly ?KpiSnapshotRepository $kpiSnapshotRepository = null,
        private readonly ?DataProtectionImpactAssessmentRepository $dpiaRepository = null,
        private readonly ?AuditFindingRepository $auditFindingRepository = null,
        private readonly ?CorrectiveActionRepository $correctiveActionRepository = null,
        private readonly ?ManagementReviewRepository $managementReviewRepository = null,
        private readonly ?WorkflowInstanceRepository $workflowInstanceRepository = null,
        private readonly ?RiskTreatmentPlanRepository $riskTreatmentPlanRepository = null,
        /**
         * %kernel.project_dir% — needed for filesystem-orphan scan (uploads-vs-DB).
         * Optional with default null so existing constructor call-sites + unit-tests
         * keep compiling; downstream methods handle the null case gracefully.
         */
        private readonly ?string $projectDir = null,
        /**
         * Filesystem upload-orphan scanner. Injected by the container when
         * $projectDir is available; null-safe — DataIntegrityService keeps
         * compiling in unit-test contexts that don't provide this dep.
         */
        private readonly ?UploadOrphanChecker $uploadOrphanChecker = null,
        /**
         * Status-enum drift checker. Optional for backward-compat with
         * unit-test setUp() that constructs without the new dep.
         */
        private readonly ?StatusEnumDriftChecker $statusEnumDriftChecker = null,
        /**
         * Orphan + cascade-orphan detector.
         */
        private readonly ?OrphanFinder $orphanFinder = null,
        /**
         * Duplicate entity finder + merger.
         */
        private readonly ?DuplicateFinder $duplicateFinder = null,
        /**
         * Reference integrity checker (broken refs, missing relationships, inconsistent data).
         */
        private readonly ?ReferenceIntegrityChecker $referenceIntegrityChecker = null,
        /**
         * Health issue aggregator (risk, compliance, operational, data-quality checks).
         */
        private readonly ?HealthIssueAggregator $healthIssueAggregator = null,
        /**
         * JSON-schema and AuditLog integrity drift checker.
         */
        private readonly ?SchemaDriftChecker $schemaDriftChecker = null,
        /**
         * Entity count aggregator — per-tenant counts, summary statistics, health score.
         * Optional for backward-compat with existing unit-test setUp() calls.
         */
        private readonly ?EntityCountAggregator $entityCountAggregator = null,
    ) {
    }

    /**
     * Returns the list of entity FQCN that are globally scoped (tenant_id=NULL by design).
     * The repair-orphan logic skips these to prevent UniqueConstraintViolationException.
     *
     * @return list<class-string>
     */
    public function getGlobalCatalogueEntityClasses(): array
    {
        return OrphanFinder::GLOBAL_CATALOGUE_ENTITIES;
    }

    /**
     * Run comprehensive integrity check and return all issues found
     */
    public function runFullIntegrityCheck(): array
    {
        return [
            'orphaned_entities' => $this->findAllOrphanedEntities(),
            'duplicates' => $this->findDuplicateEntities(),
            'broken_references' => $this->findBrokenReferences(),
            'missing_relationships' => $this->findMissingRelationships(),
            'inconsistent_data' => $this->findInconsistentData(),
            'entity_counts' => $this->getEntityCountsByTenant(),
            // Extended coverage (2026-05): file-orphans, cascade orphans,
            // JSON-schema violations, AuditLog integrity gaps, status-enum drift.
            // All detection-only except the first two — repair paths live
            // in DataRepairController.
            'orphaned_uploads' => $this->findOrphanedUploads(),
            'cascade_orphans' => $this->findCascadeOrphans(),
            'json_schema_violations' => $this->findJsonSchemaViolations(),
            'audit_log_integrity' => $this->findAuditLogIntegrityIssues(),
            'status_enum_drift' => $this->findStatusEnumDriftIssues(),
        ];
    }

    /**
     * Find all entities without tenant assignment.
     * Delegates to {@see OrphanFinder}.
     */
    public function findAllOrphanedEntities(): array
    {
        return $this->orphanFinder?->findAllOrphanedEntities() ?? [];
    }

    /**
     * Find duplicate entities within the same tenant.
     * Delegates to {@see DuplicateFinder}.
     */
    public function findDuplicateEntities(): array
    {
        return $this->duplicateFinder?->findDuplicateEntities() ?? [];
    }

    /**
     * Find broken foreign key references.
     * Delegates to {@see ReferenceIntegrityChecker}.
     */
    public function findBrokenReferences(): array
    {
        return $this->referenceIntegrityChecker?->findBrokenReferences() ?? [];
    }

    /**
     * Find entities with missing required relationships.
     * Delegates to {@see ReferenceIntegrityChecker}.
     */
    public function findMissingRelationships(): array
    {
        return $this->referenceIntegrityChecker?->findMissingRelationships() ?? [];
    }

    /**
     * Find inconsistent data (e.g., dates, status).
     * Delegates to {@see ReferenceIntegrityChecker}.
     */
    public function findInconsistentData(): array
    {
        return $this->referenceIntegrityChecker?->findInconsistentData() ?? [];
    }

    /**
     * Risk-specific health checks (ISO 27005 / ISO 27001 Clause 6.1.2).
     * Delegates to {@see HealthIssueAggregator}.
     *
     * @return array{
     *     risks_missing_treatment_strategy?: list<mixed>,
     *     risks_residual_exceeds_inherent?: list<mixed>,
     *     risks_treatment_plan_without_controls?: list<mixed>,
     *     risks_past_review_date?: list<mixed>,
     * }
     */
    public function findRiskHealthIssues(): array
    {
        return $this->healthIssueAggregator?->findRiskHealthIssues() ?? [];
    }

    /**
     * Compliance-specific health checks (GDPR / ISO 27001 privacy extensions).
     * Delegates to {@see HealthIssueAggregator}.
     *
     * @return array{
     *     assets_without_cia?: list<mixed>,
     *     breaches_overdue_72h?: list<mixed>,
     *     dsr_overdue_30d?: list<mixed>,
     *     dpia_without_dpo?: list<mixed>,
     *     vvt_incomplete?: list<mixed>,
     * }
     */
    public function findComplianceHealthIssues(): array
    {
        return $this->healthIssueAggregator?->findComplianceHealthIssues() ?? [];
    }

    /**
     * Operational health checks (ISO 27001 Tier 2 operational gaps).
     * Delegates to {@see HealthIssueAggregator}.
     */
    public function findOperationalHealthIssues(): array
    {
        return $this->healthIssueAggregator?->findOperationalHealthIssues() ?? [];
    }

    /**
     * Tier 3 data quality checks — business-process issues.
     * Delegates to {@see HealthIssueAggregator}.
     */
    public function findDataQualityIssues(): array
    {
        return $this->healthIssueAggregator?->findDataQualityIssues() ?? [];
    }

    /**
     * Merge duplicate entities for a given entity type, keeping the entity
     * with the lowest ID (oldest) and removing the rest.
     *
     * Returns the number of deleted duplicate entities.
     * Delegates to {@see DuplicateFinder}.
     *
     * Supported entity types: audits, assets, risks, incidents, documents
     */
    public function mergeDuplicates(string $entityType): int
    {
        return $this->duplicateFinder?->mergeDuplicates($entityType) ?? 0;
    }

    /**
     * Get entity counts grouped by tenant.
     * Delegates to {@see EntityCountAggregator::countByTenant()}.
     *
     * Falls back to inline implementation when aggregator is not injected
     * (legacy unit-test setUp() without the new dep).
     */
    public function getEntityCountsByTenant(): array
    {
        if ($this->entityCountAggregator !== null) {
            return $this->entityCountAggregator->countByTenant();
        }

        // Inline fallback for backward-compat with unit-test setUp() that
        // constructs DataIntegrityService without the new optional dep.
        $tenants = $this->tenantRepository->findAll();
        $counts = [];

        foreach ($tenants as $tenant) {
            $counts[$tenant->getId()] = [
                'tenant' => $tenant,
                'assets' => (int) $this->assetRepository->createQueryBuilder('a')->select('COUNT(a.id)')->where('a.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'risks' => (int) $this->riskRepository->createQueryBuilder('r')->select('COUNT(r.id)')->where('r.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'incidents' => (int) $this->incidentRepository->createQueryBuilder('i')->select('COUNT(i.id)')->where('i.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'audits' => (int) $this->auditRepository->createQueryBuilder('au')->select('COUNT(au.id)')->where('au.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'documents' => (int) $this->documentRepository->createQueryBuilder('d')->select('COUNT(d.id)')->where('d.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'trainings' => (int) $this->trainingRepository->createQueryBuilder('tr')->select('COUNT(tr.id)')->where('tr.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'business_processes' => (int) $this->businessProcessRepository->createQueryBuilder('bp')->select('COUNT(bp.id)')->where('bp.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'bc_plans' => (int) $this->bcPlanRepository->createQueryBuilder('bc')->select('COUNT(bc.id)')->where('bc.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'data_breaches' => (int) $this->dataBreachRepository->createQueryBuilder('db')->select('COUNT(db.id)')->where('db.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'processing_activities' => (int) $this->processingActivityRepository->createQueryBuilder('pa')->select('COUNT(pa.id)')->where('pa.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'suppliers' => (int) $this->supplierRepository->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'locations' => (int) $this->locationRepository->createQueryBuilder('l')->select('COUNT(l.id)')->where('l.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'people' => (int) $this->personRepository->createQueryBuilder('p')->select('COUNT(p.id)')->where('p.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
            ];

            if ($this->dataSubjectRequestRepository !== null) {
                $counts[$tenant->getId()]['data_subject_requests'] = (int) $this->dataSubjectRequestRepository->createQueryBuilder('dsr')->select('COUNT(dsr.id)')->where('dsr.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult();
            }
            if ($this->kpiSnapshotRepository !== null) {
                $counts[$tenant->getId()]['kpi_snapshots'] = (int) $this->kpiSnapshotRepository->createQueryBuilder('ks')->select('COUNT(ks.id)')->where('ks.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult();
            }
        }

        return $counts;
    }

    /**
     * Get summary statistics for dashboard display.
     * Delegates to {@see EntityCountAggregator::summarize()} when available;
     * falls back to inline implementation for backward-compat.
     */
    public function getSummaryStatistics(): array
    {
        $orphaned = $this->findAllOrphanedEntities();
        $missing = $this->findMissingRelationships();
        $broken = $this->findBrokenReferences();
        $duplicates = $this->findDuplicateEntities();
        $inconsistent = $this->findInconsistentData();

        if ($this->entityCountAggregator !== null) {
            return $this->entityCountAggregator->summarize($orphaned, $missing, $broken, $duplicates, $inconsistent);
        }

        // Inline fallback for backward-compat with unit-test setUp() without the new dep.
        $totalOrphaned = 0;
        foreach ($orphaned as $entities) {
            $totalOrphaned += count($entities);
        }

        $totalMissing = 0;
        foreach ($missing as $entities) {
            $totalMissing += count($entities);
        }

        $totalDuplicates = 0;
        foreach ($duplicates as $groups) {
            $totalDuplicates += count($groups);
        }

        $totalInconsistent = 0;
        foreach ($inconsistent as $entities) {
            $totalInconsistent += count($entities);
        }

        return [
            'total_issues' => $totalOrphaned + $totalMissing + count($broken) + $totalDuplicates + $totalInconsistent,
            'orphaned_count' => $totalOrphaned,
            'missing_relationships_count' => $totalMissing,
            'broken_references_count' => count($broken),
            'duplicates_count' => $totalDuplicates,
            'inconsistent_count' => $totalInconsistent,
            'health_score' => $this->calculateHealthScore($totalOrphaned, $totalMissing, count($broken), $totalDuplicates, $totalInconsistent),
        ];
    }

    /**
     * Calculate overall data health score (0-100).
     *
     * Denominator (total entities) is computed with tenant_filter disabled so
     * it matches the cross-tenant numerator from findAllOrphanedEntities().
     * Otherwise the score is arithmetically inconsistent on multi-tenant
     * installations (filter-on denominator vs filter-off numerator).
     *
     * @internal Used by the inline getSummaryStatistics() fallback only.
     *           When entityCountAggregator is injected, the aggregator owns this formula.
     */
    private function calculateHealthScore(int $orphaned, int $missing, int $broken, int $duplicates, int $inconsistent): int
    {
        $filters = $this->entityManager->getFilters();
        $filterWasEnabled = $filters->isEnabled('tenant_filter');
        if ($filterWasEnabled) {
            $filters->disable('tenant_filter');
        }
        try {
            $totalEntities = count($this->assetRepository->findAll()) +
                            count($this->riskRepository->findAll()) +
                            count($this->incidentRepository->findAll()) +
                            count($this->auditRepository->findAll()) +
                            count($this->documentRepository->findAll());
        } finally {
            if ($filterWasEnabled) {
                $filters->enable('tenant_filter');
            }
        }

        if ($totalEntities === 0) {
            return 100;
        }

        $totalIssues = ($orphaned * 3) + ($broken * 5) + ($missing) + ($duplicates * 2) + ($inconsistent);
        $maxPossibleIssues = $totalEntities * 5; // Max severity weight

        $healthScore = max(0, 100 - (($totalIssues / $maxPossibleIssues) * 100));

        return (int) round($healthScore);
    }

    /**
     * Scans the filesystem under {projectDir}/public/uploads/ and cross-checks
     * every regular file against the set of file-paths actually referenced
     * by Doctrine entities. Reports files present on disk with no DB owner.
     *
     * Repair path: {@see DataRepairController::quarantineOrphanedUploads()}
     * Implementation delegated to {@see UploadOrphanChecker}.
     *
     * @return array{
     *     files: list<array{path: string, relative: string, size: int, mtime: int}>,
     *     scanned: int,
     *     referenced: int,
     *     uploads_dir: string|null,
     * }
     */
    public function findOrphanedUploads(): array
    {
        if ($this->uploadOrphanChecker !== null) {
            return $this->uploadOrphanChecker->findOrphanedUploads();
        }
        // Fallback when helper is not injected (e.g. legacy unit-test setUp
        // that constructs DataIntegrityService without the new optional dep).
        return ['files' => [], 'scanned' => 0, 'referenced' => 0, 'uploads_dir' => null];
    }

    /**
     * Cross-entity cascade cleanup detection.
     * Delegates to {@see OrphanFinder}.
     *
     * @return array<string, list<array{id: int, label: string, hint?: string}>>
     */
    public function findCascadeOrphans(): array
    {
        return $this->orphanFinder?->findCascadeOrphans() ?? [
            'workflow_instances' => [],
            'mfa_tokens' => [],
            'sso_user_approvals' => [],
            'evidence_tasks' => [],
            'notification_deliveries' => [],
        ];
    }

    /**
     * Decodes JSON columns and validates their minimal shape. NO auto-repair.
     * Delegates to {@see SchemaDriftChecker}.
     *
     * @return array<string, list<array{id: int, tenant?: ?string, error: string}>>
     */
    public function findJsonSchemaViolations(): array
    {
        return $this->schemaDriftChecker?->findJsonSchemaViolations() ?? [
            'tenant_settings' => [],
            'tenant_policy_settings' => [],
            'notification_rule_conditions' => [],
            'workflow_step_metadata' => [],
        ];
    }

    /**
     * AuditLog integrity gap detection.
     * Delegates to {@see SchemaDriftChecker}.
     *
     * @return array{
     *     bulk_batch_mismatches: list<array{batch_id: string, expected: int, actual: int}>,
     *     day_gaps: list<array{date: string}>,
     *     null_tenant_entries: list<array{id: int, action: string, entity_type: string}>,
     * }
     */
    public function findAuditLogIntegrityIssues(): array
    {
        return $this->schemaDriftChecker?->findAuditLogIntegrityIssues() ?? [
            'bulk_batch_mismatches' => [],
            'day_gaps' => [],
            'null_tenant_entries' => [],
        ];
    }

    /**
     * Status-Enum drift detection.
     * Implementation delegated to {@see StatusEnumDriftChecker}.
     *
     * @return list<array{
     *     entity: string,
     *     enum: string,
     *     unknown_values: array<string, int>,
     * }>
     */
    public function findStatusEnumDriftIssues(): array
    {
        if ($this->statusEnumDriftChecker !== null) {
            return $this->statusEnumDriftChecker->findDriftIssues();
        }
        // Fallback when helper not injected (legacy unit-test setUp without new dep).
        return [];
    }
}

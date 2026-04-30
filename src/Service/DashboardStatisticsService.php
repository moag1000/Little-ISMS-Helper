<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use App\Enum\RiskStatus;
use App\Repository\AssetRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\KpiSnapshotRepository;
use App\Repository\KpiThresholdConfigRepository;
use App\Service\KpiThresholdConfigResolver;
use App\Repository\ManagementReviewRepository;
use App\Repository\RiskAppetiteRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Service\AssetService;
use App\Service\BsiGrundschutzCheckService;
use App\Service\ComplianceAnalyticsService;
use App\Service\RiskService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Dashboard Statistics Service
 *
 * Centralizes dashboard metrics calculation and business logic.
 * Follows Symfony best practice: keep controllers thin, move logic to services.
 *
 * Responsibilities:
 * - Calculate KPI metrics (module-aware)
 * - Compute statistics for dashboard widgets
 * - Filter and count critical/high-risk items
 * - Calculate compliance percentages
 * - Provide management-relevant KPIs for reports
 *
 * Benefits:
 * - Testable business logic
 * - Reusable across different controllers
 * - Cleaner controller code
 * - Single source of truth for metrics
 * - Module-aware: KPIs only shown when modules are active
 */
class DashboardStatisticsService
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly ControlRepository $controlRepository,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly ?AssetService $assetService = null,
        private readonly ?RiskService $riskService = null,
        private readonly ?TrainingRepository $trainingRepository = null,
        private readonly ?InternalAuditRepository $auditRepository = null,
        private readonly ?BusinessProcessRepository $businessProcessRepository = null,
        private readonly ?BusinessContinuityPlanRepository $bcPlanRepository = null,
        private readonly ?BCExerciseRepository $bcExerciseRepository = null,
        private readonly ?SupplierRepository $supplierRepository = null,
        private readonly ?DocumentRepository $documentRepository = null,
        private readonly ?RiskTreatmentPlanRepository $treatmentPlanRepository = null,
        private readonly ?ManagementReviewRepository $managementReviewRepository = null,
        private readonly ?ComplianceAnalyticsService $complianceAnalyticsService = null,
        private readonly ?RiskAppetiteRepository $riskAppetiteRepository = null,
        private readonly ?KpiThresholdConfigRepository $thresholdConfigRepository = null,
        private readonly ?KpiSnapshotRepository $kpiSnapshotRepository = null,
        private readonly ?KpiThresholdConfigResolver $kpiThresholdConfigResolver = null,
        private readonly ?CacheInterface $cache = null,
        private readonly ?BsiGrundschutzCheckService $bsiGrundschutzCheckService = null,
    ) {
    }

    /**
     * Per-tenant threshold override cache. Loaded on first status() call.
     *
     * @var array<string, array{good:int, warning:int}>|null
     */
    private ?array $thresholdOverrides = null;
    private ?int $thresholdOverrideTenantId = null;

    /**
     * Tenant-aware getStatus — prefers override from kpi_threshold_config.
     *
     * Phase 8M.3: Wenn KpiThresholdConfigResolver injiziert ist, wird dieser
     * bevorzugt (Holding-Fallback-Kaskade). Andernfalls Fallback auf den
     * direkten Repository-Zugriff (Legacy-Pfad für Backward-Compatibility).
     */
    private function getStatusFor(string $kpiKey, int $value, int $goodDefault, int $warningDefault, ?Tenant $tenant): string
    {
        $good = $goodDefault;
        $warning = $warningDefault;

        if ($tenant instanceof Tenant) {
            // Bevorzugter Pfad: KpiThresholdConfigResolver (inkl. Holding-Fallback-Kaskade)
            if ($this->kpiThresholdConfigResolver !== null) {
                $view = $this->kpiThresholdConfigResolver->resolveFor($tenant, $kpiKey, $goodDefault, $warningDefault);
                $good = $view->goodThreshold;
                $warning = $view->warningThreshold;
            } elseif ($this->thresholdConfigRepository !== null) {
                // Legacy-Pfad: direkter Repository-Zugriff (kein Holding-Merge)
                if ($this->thresholdOverrideTenantId !== $tenant->getId() || $this->thresholdOverrides === null) {
                    $this->thresholdOverrides = $this->thresholdConfigRepository->getThresholdMap($tenant);
                    $this->thresholdOverrideTenantId = $tenant->getId();
                }
                if (isset($this->thresholdOverrides[$kpiKey])) {
                    $good = $this->thresholdOverrides[$kpiKey]['good'];
                    $warning = $this->thresholdOverrides[$kpiKey]['warning'];
                }
            }
        }

        if ($value >= $good) {
            return 'good';
        }
        if ($value >= $warning) {
            return 'warning';
        }
        return 'danger';
    }

    /**
     * @return array<string, int> Map of category => maxAcceptableRisk for the tenant (fallback: global).
     */
    /**
     * A10: Boolean readiness checklist. Each key is 1 if fulfilled, 0 otherwise.
     *
     * @return array<string, int>
     */
    private function buildReadinessChecklist(?Tenant $tenant): array
    {
        $checklist = [
            'isms_scope_defined' => 0,
            'isms_policy_approved' => 0,
            'risk_assessment_performed' => 0,
            'soa_generated' => 0,
            'controls_80pct_implemented' => 0,
            'management_review_within_year' => 0,
            'internal_audit_within_year' => 0,
            'audit_findings_resolved' => 0,
        ];
        if (!$tenant instanceof Tenant) {
            return $checklist;
        }

        // ISMS scope/policy — at least one ISMSContext record marks scope defined
        $contextCount = $this->entityManager()->getRepository(\App\Entity\ISMSContext::class)
            ->count(['tenant' => $tenant]);
        if ($contextCount > 0) {
            $checklist['isms_scope_defined'] = 1;
            $checklist['isms_policy_approved'] = 1;
        }

        // Risk assessment — at least one Risk exists
        $riskCount = count($this->getAllAccessibleRisks($tenant));
        if ($riskCount > 0) {
            $checklist['risk_assessment_performed'] = 1;
        }

        // SoA — at least one control is marked as applicable (proxy)
        $applicableControls = $this->controlRepository->findApplicableControls($tenant);
        if (count($applicableControls) > 0) {
            $checklist['soa_generated'] = 1;
            $implemented = count(array_filter(
                $applicableControls,
                fn($c): bool => $c->getImplementationStatus() === 'implemented'
            ));
            $pct = (count($applicableControls) > 0) ? ($implemented / count($applicableControls)) : 0;
            if ($pct >= 0.80) {
                $checklist['controls_80pct_implemented'] = 1;
            }
        }

        // Management review within last 365 days
        if ($this->managementReviewRepository !== null) {
            $latest = $this->managementReviewRepository->findLatest(1);
            if (isset($latest[0])) {
                $days = $latest[0]->getDaysSinceReview();
                if ($days !== null && $days >= 0 && $days <= 365) {
                    $checklist['management_review_within_year'] = 1;
                }
            }
        }

        // Internal audit within last 365 days
        if ($this->auditRepository !== null) {
            $audits = $this->auditRepository->findBy(['tenant' => $tenant], ['actualDate' => 'DESC'], 1);
            if (isset($audits[0])) {
                $days = $audits[0]->getDaysSinceActual();
                if ($days !== null && $days >= 0 && $days <= 365) {
                    $checklist['internal_audit_within_year'] = 1;
                }
            }
        }

        // Audit findings resolved — no open AuditFinding
        $openFindings = $this->entityManager()->getRepository(\App\Entity\AuditFinding::class)
            ->count(['tenant' => $tenant, 'status' => \App\Entity\AuditFinding::STATUS_OPEN]);
        if ($openFindings === 0) {
            $checklist['audit_findings_resolved'] = 1;
        }

        return $checklist;
    }

    private function entityManager(): \Doctrine\ORM\EntityManagerInterface
    {
        return $this->controlRepository->createQueryBuilder('c')->getEntityManager();
    }

    /**
     * Count distinct frameworks that map to a given control via its requirements.
     */
    private function getFrameworkCountForControl(int $controlId): int
    {
        if ($this->complianceAnalyticsService === null) {
            return 0;
        }
        // Use requirement repository through analytics service? No direct access —
        // piggyback on getControlCoverageMatrix() once cached would be overkill.
        // Simpler: query the unit-of-work repository via entityManager.
        $em = $this->controlRepository->createQueryBuilder('c')->getEntityManager();
        $qb = $em->createQueryBuilder();
        $qb->select('COUNT(DISTINCT f.id)')
            ->from(\App\Entity\ComplianceRequirement::class, 'r')
            ->join('r.mappedControls', 'c')
            ->join('r.framework', 'f')
            ->where('c.id = :id')
            ->setParameter('id', $controlId);
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function getRiskAppetiteByCategory(?Tenant $tenant): array
    {
        if ($this->riskAppetiteRepository === null) {
            return [];
        }
        $appetites = $this->riskAppetiteRepository->findAllActiveForTenant($tenant);
        $map = [];
        foreach ($appetites as $appetite) {
            $category = $appetite->getCategory();
            $max = $appetite->getMaxAcceptableRisk();
            if ($category !== null && $max !== null) {
                $map[$category] = $max;
            }
        }
        return $map;
    }

    /**
     * Get all dashboard statistics
     *
     * @return array{
     *     assetCount: int,
     *     riskCount: int,
     *     openIncidentCount: int,
     *     compliancePercentage: int,
     *     assets_total: int,
     *     assets_critical: int,
     *     risks_total: int,
     *     risks_high: int,
     *     controls_total: int,
     *     controls_implemented: int,
     *     incidents_open: int
     * }
     */
    public function getDashboardStatistics(): array
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();
        $cacheKey = 'dashboard_stats_' . ($tenant?->getId() ?? 'global');

        if ($this->cache !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($tenant) {
                $item->expiresAfter(300); // 5 minutes
                return $this->computeDashboardStatistics($tenant);
            });
        }

        return $this->computeDashboardStatistics($tenant);
    }

    /**
     * Compute dashboard statistics (extracted for caching).
     */
    private function computeDashboardStatistics(?Tenant $tenant): array
    {
        // Basic counts - show ALL accessible entities (own + inherited + subsidiaries)
        if ($tenant) {
            // Get all accessible assets (own + inherited from parent + from subsidiaries)
            $allAccessibleAssets = $this->getAllAccessibleAssets($tenant);
            $activeAssets = array_filter($allAccessibleAssets, fn($asset): bool => $asset->getStatus() === 'active');
            $assetCount = count($activeAssets);

            // Get all accessible risks
            $allAccessibleRisks = $this->getAllAccessibleRisks($tenant);
            $riskCount = count($allAccessibleRisks);

            // Get all accessible incidents
            $allAccessibleIncidents = $this->getAllAccessibleIncidents($tenant);
            $openIncidents = array_filter($allAccessibleIncidents, fn($incident): bool => in_array($incident->getStatus(), [
                \App\Enum\IncidentStatus::Reported,
                \App\Enum\IncidentStatus::InInvestigation,
                \App\Enum\IncidentStatus::InResolution,
            ], true));
            $openIncidentCount = count($openIncidents);
        } else {
            // Fallback for users without tenant (admin view)
            // Super admin fallback: no tenant context, return empty/zero
            $activeAssets = [];
            $assetCount = 0;
            $allAccessibleRisks = $this->riskRepository->findAll();
            $riskCount = count($allAccessibleRisks);
            $openIncidentCount = 0;
        }

        // Control statistics (tenant-scoped)
        $applicableControls = $tenant
            ? $this->controlRepository->findApplicableControls($tenant)
            : [];
        $implementedControls = $this->countImplementedControls($applicableControls);
        $compliancePercentage = $this->calculateCompliancePercentage(
            $implementedControls,
            count($applicableControls)
        );

        // Critical/High items - using all accessible data
        $criticalAssetCount = $tenant
            ? $this->countCriticalAssetsAccessible($tenant)
            : $this->countCriticalAssets();
        $highRiskCount = $tenant
            ? $this->countHighRisksAccessible($tenant)
            : $this->countHighRisks();

        return [
            // Basic KPIs
            'assetCount' => $assetCount,
            'riskCount' => $riskCount,
            'openIncidentCount' => $openIncidentCount,
            'compliancePercentage' => $compliancePercentage,

            // Detailed statistics
            'assets_total' => $assetCount,
            'assets_critical' => $criticalAssetCount,
            'risks_total' => $riskCount,
            'risks_high' => $highRiskCount,
            'controls_total' => count($applicableControls),
            'controls_implemented' => $implementedControls,
            'incidents_open' => $openIncidentCount,
        ];
    }

    /**
     * Count critical assets (confidentiality value >= 4)
     *
     * @return int Number of critical assets
     */
    private function countCriticalAssets(): int
    {
        $activeAssets = [];

        return count(array_filter(
            $activeAssets,
            fn(Asset $asset): bool => $asset->getConfidentialityValue() >= 4
        ));
    }

    /**
     * Count high-risk items (inherent risk level >= 12)
     *
     * @return int Number of high risks
     */
    private function countHighRisks(): int
    {
        $allRisks = $this->riskRepository->findAll();

        return count(array_filter(
            $allRisks,
            fn(Risk $risk): bool => $risk->getInherentRiskLevel() >= 12
        ));
    }

    /**
     * Get all accessible assets for a tenant (own + inherited + subsidiaries)
     *
     * @param Tenant $tenant The tenant
     * @return array All accessible assets
     */
    private function getAllAccessibleAssets(Tenant $tenant): array
    {
        $allAssets = [];

        // Own assets
        $ownAssets = $this->assetRepository->findByTenant($tenant);
        foreach ($ownAssets as $asset) {
            $allAssets[$asset->getId()] = $asset;
        }

        // Inherited from parent (if AssetService available)
        if ($this->assetService instanceof AssetService) {
            $inheritedAssets = $this->assetService->getAssetsForTenant($tenant);
            foreach ($inheritedAssets as $inheritedAsset) {
                $allAssets[$inheritedAsset->getId()] = $inheritedAsset;
            }
        }

        // From subsidiaries
        $subsidiaryAssets = $this->assetRepository->findByTenantIncludingSubsidiaries($tenant);
        foreach ($subsidiaryAssets as $subsidiaryAsset) {
            $allAssets[$subsidiaryAsset->getId()] = $subsidiaryAsset;
        }

        return array_values($allAssets);
    }

    /**
     * Get all accessible risks for a tenant (own + inherited + subsidiaries)
     *
     * @param Tenant $tenant The tenant
     * @return array All accessible risks
     */
    private function getAllAccessibleRisks(Tenant $tenant): array
    {
        $allRisks = [];

        // Own risks
        $ownRisks = $this->riskRepository->findByTenant($tenant);
        foreach ($ownRisks as $risk) {
            $allRisks[$risk->getId()] = $risk;
        }

        // Inherited from parent (if RiskService available)
        if ($this->riskService instanceof RiskService) {
            $inheritedRisks = $this->riskService->getRisksForTenant($tenant);
            foreach ($inheritedRisks as $inheritedRisk) {
                $allRisks[$inheritedRisk->getId()] = $inheritedRisk;
            }
        }

        // From subsidiaries
        $subsidiaryRisks = $this->riskRepository->findByTenantIncludingSubsidiaries($tenant);
        foreach ($subsidiaryRisks as $subsidiaryRisk) {
            $allRisks[$subsidiaryRisk->getId()] = $subsidiaryRisk;
        }

        return array_values($allRisks);
    }

    /**
     * Get all accessible incidents for a tenant (own + inherited + subsidiaries)
     *
     * @param Tenant $tenant The tenant
     * @return array All accessible incidents
     */
    private function getAllAccessibleIncidents(Tenant $tenant): array
    {
        $allIncidents = [];

        // Own incidents
        $ownIncidents = $this->incidentRepository->findByTenant($tenant);
        foreach ($ownIncidents as $incident) {
            $allIncidents[$incident->getId()] = $incident;
        }

        // Inherited from parent
        if ($tenant->getParent() instanceof Tenant) {
            $parentIncidents = $this->incidentRepository->findByTenantIncludingParent($tenant, $tenant->getParent());
            foreach ($parentIncidents as $parentIncident) {
                $allIncidents[$parentIncident->getId()] = $parentIncident;
            }
        }

        // From subsidiaries
        $subsidiaryIncidents = $this->incidentRepository->findByTenantIncludingSubsidiaries($tenant);
        foreach ($subsidiaryIncidents as $subsidiaryIncident) {
            $allIncidents[$subsidiaryIncident->getId()] = $subsidiaryIncident;
        }

        return array_values($allIncidents);
    }

    /**
     * Count critical assets from all accessible assets
     *
     * @param Tenant $tenant The tenant
     * @return int Number of critical assets
     */
    private function countCriticalAssetsAccessible(Tenant $tenant): int
    {
        $allAssets = $this->getAllAccessibleAssets($tenant);
        $activeAssets = array_filter($allAssets, fn($asset): bool => $asset->getStatus() === 'active');

        return count(array_filter(
            $activeAssets,
            fn($asset): bool => $asset->getConfidentialityValue() >= 4
        ));
    }

    /**
     * Count high-risk items from all accessible risks
     *
     * @param Tenant $tenant The tenant
     * @return int Number of high risks
     */
    private function countHighRisksAccessible(Tenant $tenant): int
    {
        $allRisks = $this->getAllAccessibleRisks($tenant);

        return count(array_filter(
            $allRisks,
            fn($risk): bool => $risk->getInherentRiskLevel() >= 12
        ));
    }

    /**
     * Count implemented controls from applicable controls
     *
     * @param array $applicableControls List of applicable controls
     * @return int Number of implemented controls
     */
    private function countImplementedControls(array $applicableControls): int
    {
        return count(array_filter(
            $applicableControls,
            fn($control): bool => $control->getImplementationStatus() === 'implemented'
        ));
    }

    /**
     * C4: Weighted compliance score (implemented=1.0, partially=0.5, planned/not=0).
     * More honest KPI than counting only fully-implemented controls.
     */
    private function calculateWeightedComplianceScore(array $applicableControls): float
    {
        $total = count($applicableControls);
        if ($total === 0) {
            return 0.0;
        }
        $weighted = 0.0;
        foreach ($applicableControls as $control) {
            $status = $control->getImplementationStatus();
            if ($status === 'implemented') {
                $weighted += 1.0;
            } elseif ($status === 'partially_implemented' || $status === 'partial') {
                $weighted += 0.5;
            }
        }
        return round(($weighted / $total) * 100, 1);
    }

    /**
     * Calculate compliance percentage
     *
     * @param int $implementedCount Number of implemented controls
     * @param int $totalCount Total number of applicable controls
     * @return int Compliance percentage (0-100)
     */
    private function calculateCompliancePercentage(int $implementedCount, int $totalCount): int
    {
        if ($totalCount === 0) {
            return 0;
        }

        return (int) round(($implementedCount / $totalCount) * 100);
    }

    /**
     * Get module-aware management KPIs for dashboard and reports
     *
     * Returns KPIs only for active modules, suitable for management reports.
     * Each KPI includes value, status (good/warning/danger), and trend data.
     *
     * @return array Module-aware KPIs grouped by category
     */
    public function getManagementKPIs(): array
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();
        $cacheKey = 'management_kpis_' . ($tenant?->getId() ?? 'global');

        if ($this->cache !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($tenant) {
                $item->expiresAfter(300); // 5 minutes
                return $this->computeManagementKPIs($tenant);
            });
        }

        return $this->computeManagementKPIs($tenant);
    }

    /**
     * Compute management KPIs (extracted for caching).
     */
    private function computeManagementKPIs(?Tenant $tenant): array
    {
        $activeModules = $this->moduleConfigurationService->getActiveModules();

        $core = $this->getCoreKPIs($tenant);

        $kpis = [
            'core' => $core,
            'active_modules' => $activeModules,
        ];

        // A1: per-framework compliance (if analytics service available)
        $perFramework = $this->getPerFrameworkKPIs();
        if ($perFramework !== []) {
            $kpis['per_framework'] = $perFramework;
        }

        // A1b: BSI IT-Grundschutz per Absicherungsstufe (basis / standard / kern)
        $bsiStufen = $this->getBsiAbsicherungsstufenKPIs();
        if ($bsiStufen !== []) {
            $kpis['bsi_stufen'] = $bsiStufen;
        }

        // A4: composite ISMS health score (lightweight, no snapshots needed)
        $healthScore = $this->calculateIsmsHealthScore($tenant, $core, $activeModules);
        $kpis['health'] = [
            'isms_health_score' => [
                'label' => 'kpi.isms_health_score',
                'value' => $healthScore['score'],
                'unit' => '/100',
                'status' => $this->getStatus((int) $healthScore['score'], 80, 60),
                'details' => $healthScore['breakdown'],
            ],
        ];

        // A5/A6/A7/A8/A9/A10: management KPIs
        $kpis['management'] = $this->getManagementKpiMetrics($tenant);

        // Add module-specific KPIs only if module is active
        if (in_array('risks', $activeModules, true)) {
            $kpis['risk_management'] = $this->getRiskManagementKPIs($tenant);
        }

        if (in_array('assets', $activeModules, true)) {
            $kpis['asset_management'] = $this->getAssetManagementKPIs($tenant);
        }

        if (in_array('incidents', $activeModules, true)) {
            $kpis['incident_management'] = $this->getIncidentManagementKPIs($tenant);
        }

        if (in_array('bcm', $activeModules, true)) {
            $kpis['business_continuity'] = $this->getBusinessContinuityKPIs($tenant);
        }

        if (in_array('training', $activeModules, true)) {
            $kpis['training'] = $this->getTrainingKPIs($tenant);
        }

        if (in_array('audits', $activeModules, true)) {
            $kpis['audits'] = $this->getAuditKPIs($tenant);
        }

        if (in_array('suppliers', $activeModules, true)) {
            $kpis['supplier_management'] = $this->getSupplierKPIs($tenant);
        }

        if (in_array('documents', $activeModules, true)) {
            $kpis['documentation'] = $this->getDocumentationKPIs($tenant);
        }

        return $kpis;
    }

    /**
     * Add trend data to management KPIs by comparing current values with historical snapshots.
     *
     * For each KPI that has a numeric value, compares against the snapshot from ~30 days ago.
     * Adds 'trend' (up/down/stable), 'trend_delta' (numeric change), and 'trend_sentiment'
     * (good/bad/neutral — context-aware, e.g., fewer incidents = good, lower compliance = bad).
     *
     * @param array $kpis The structured management KPIs array
     * @param Tenant|null $tenant The current tenant
     * @return array The enriched KPIs array with trend data
     */
    public function addTrendData(array $kpis, ?Tenant $tenant): array
    {
        if ($tenant === null || $this->kpiSnapshotRepository === null) {
            return $kpis;
        }

        // Find snapshot from ~30 days ago
        $compareDate = new \DateTimeImmutable('-30 days');
        $previousSnapshot = $this->kpiSnapshotRepository->findClosestBefore($tenant, $compareDate);

        if ($previousSnapshot === null) {
            return $kpis;
        }

        $previousData = $previousSnapshot->getKpiData();
        if ($previousData === []) {
            return $kpis;
        }

        // Map of snapshot keys to KPI section.key paths and their sentiment direction
        // 'higher_is_better' = true means going up is 'good', going down is 'bad'
        $kpiMapping = [
            'control_compliance' => ['section' => 'core', 'key' => 'control_compliance', 'higher_is_better' => true],
            'risk_treatment_rate' => ['section' => 'risk_management', 'key' => 'risk_treatment_rate', 'higher_is_better' => true],
            'total_risks' => ['section' => 'risk_management', 'key' => 'total_risks', 'higher_is_better' => false],
            'high_risks' => ['section' => 'risk_management', 'key' => 'high_risks', 'higher_is_better' => false],
            'critical_risks' => ['section' => 'risk_management', 'key' => 'critical_risks', 'higher_is_better' => false],
            'open_incidents' => ['section' => 'incident_management', 'key' => 'open_incidents', 'higher_is_better' => false],
            'training_completion' => ['section' => 'training', 'key' => 'training_completion_rate', 'higher_is_better' => true],
            'supplier_assessment' => ['section' => 'supplier_management', 'key' => 'supplier_assessment_rate', 'higher_is_better' => true],
            'isms_health_score' => ['section' => 'health', 'key' => 'isms_health_score', 'higher_is_better' => true],
        ];

        foreach ($kpiMapping as $snapshotKey => $mapping) {
            if (!isset($previousData[$snapshotKey])) {
                continue;
            }
            $section = $mapping['section'];
            $key = $mapping['key'];

            if (!isset($kpis[$section][$key]['value'])) {
                continue;
            }

            $currentValue = $kpis[$section][$key]['value'];
            $previousValue = $previousData[$snapshotKey];

            if (!is_numeric($currentValue) || !is_numeric($previousValue)) {
                continue;
            }

            $delta = $currentValue - $previousValue;

            if (abs($delta) < 0.01) {
                $trend = 'stable';
                $sentiment = 'neutral';
            } else {
                $trend = $delta > 0 ? 'up' : 'down';
                if ($mapping['higher_is_better']) {
                    $sentiment = $delta > 0 ? 'good' : 'bad';
                } else {
                    $sentiment = $delta < 0 ? 'good' : 'bad';
                }
            }

            $kpis[$section][$key]['trend'] = $trend;
            $kpis[$section][$key]['trend_delta'] = round($delta, 1);
            $kpis[$section][$key]['trend_sentiment'] = $sentiment;
        }

        return $kpis;
    }

    /**
     * Get core KPIs (always shown)
     */
    /**
     * A6/A7/A8: Management-level KPIs.
     *  A6 days_since_management_review
     *  A7 oldest_overdue_item_age (days)
     *  A8 gap_count_critical / gap_count_high (unfulfilled critical/high requirements)
     *
     * @return array<string, array>
     */
    private function getManagementKpiMetrics(?Tenant $tenant): array
    {
        $out = [];

        // A5: Control Reuse Ratio — avg frameworks covered per implemented control
        if ($tenant instanceof Tenant) {
            $applicableControls = $this->controlRepository->findApplicableControls($tenant);
            $implementedControls = array_filter(
                $applicableControls,
                fn($c): bool => $c->getImplementationStatus() === 'implemented'
            );
            $totalFrameworkCoverage = 0;
            $controlCount = count($implementedControls);
            foreach ($implementedControls as $control) {
                $totalFrameworkCoverage += $this->getFrameworkCountForControl($control->getId());
            }
            $avgReuse = $controlCount > 0 ? round($totalFrameworkCoverage / $controlCount, 2) : 0.0;
            $out['control_reuse_ratio'] = [
                'label' => 'kpi.control_reuse_ratio',
                'value' => $avgReuse,
                'unit' => '',
                'status' => $avgReuse >= 2.0 ? 'good' : ($avgReuse >= 1.0 ? 'info' : 'warning'),
                'details' => [
                    'implemented_controls' => $controlCount,
                    'total_framework_coverage' => $totalFrameworkCoverage,
                ],
            ];
        }

        // A6: days since last management review
        if ($this->managementReviewRepository !== null) {
            $latest = $this->managementReviewRepository->findLatest(1);
            $daysSince = isset($latest[0]) ? $latest[0]->getDaysSinceReview() : null;
            $out['days_since_management_review'] = [
                'label' => 'kpi.days_since_management_review',
                'value' => $daysSince,
                'unit' => $daysSince === null ? '' : 'd',
                // ISO 27001 requires yearly review → >365d = danger, >270d = warning
                'status' => $daysSince === null
                    ? 'info'
                    : ($daysSince > 365 ? 'danger' : ($daysSince > 270 ? 'warning' : 'good')),
                'na' => $daysSince === null,
            ];
        }

        // A7: oldest overdue item across risk reviews and treatment plans
        $oldestOverdueDays = 0;
        if ($tenant !== null && $this->treatmentPlanRepository !== null) {
            foreach ($this->treatmentPlanRepository->findAll() as $plan) {
                if ($plan->getStatus() === 'completed') {
                    continue;
                }
                $days = $plan->getDaysOverdue();
                if ($days !== null && $days > $oldestOverdueDays) {
                    $oldestOverdueDays = $days;
                }
            }
        }
        $out['oldest_overdue_item_age'] = [
            'label' => 'kpi.oldest_overdue_item_age',
            'value' => $oldestOverdueDays,
            'unit' => 'd',
            'status' => $oldestOverdueDays > 90 ? 'danger' : ($oldestOverdueDays > 30 ? 'warning' : 'good'),
        ];

        // A9: regulatory deadlines upcoming within 30 days (control reviews, BC plan reviews,
        // risk treatment plans, BC tests)
        $horizon = new \DateTime('+30 days');
        $now = new \DateTime();
        $upcoming = 0;
        $overdue = 0;
        if ($tenant !== null) {
            $allControls = $this->controlRepository->findApplicableControls($tenant);
            foreach ($allControls as $control) {
                $due = $control->getNextReviewDate();
                if ($due === null) {
                    continue;
                }
                if ($due < $now) {
                    $overdue++;
                } elseif ($due <= $horizon) {
                    $upcoming++;
                }
            }
            if ($this->bcPlanRepository !== null) {
                foreach ($this->bcPlanRepository->findAll() as $plan) {
                    $due = $plan->getNextReviewDate();
                    if ($due === null) { continue; }
                    if ($due < $now) { $overdue++; } elseif ($due <= $horizon) { $upcoming++; }
                    $dueTest = $plan->getNextTestDate();
                    if ($dueTest === null) { continue; }
                    if ($dueTest < $now) { $overdue++; } elseif ($dueTest <= $horizon) { $upcoming++; }
                }
            }
            if ($this->treatmentPlanRepository !== null) {
                foreach ($this->treatmentPlanRepository->findAll() as $plan) {
                    $due = $plan->getTargetCompletionDate();
                    if ($due === null || $plan->getStatus() === 'completed') { continue; }
                    if ($due < $now) { $overdue++; } elseif ($due <= $horizon) { $upcoming++; }
                }
            }
        }
        $out['regulatory_deadlines_upcoming'] = [
            'label' => 'kpi.regulatory_deadlines_upcoming',
            'value' => $upcoming,
            'unit' => '',
            'status' => $upcoming > 10 ? 'warning' : 'info',
            'details' => ['overdue' => $overdue, 'horizon_days' => 30],
        ];

        // A10: implementation readiness — composite boolean checklist
        $checklist = $this->buildReadinessChecklist($tenant);
        $passed = array_sum($checklist);
        $total = count($checklist);
        $readinessPct = $total > 0 ? (int) round(($passed / $total) * 100) : 0;
        $out['implementation_readiness'] = [
            'label' => 'kpi.implementation_readiness',
            'value' => $readinessPct,
            'unit' => '%',
            'status' => $this->getStatus($readinessPct, 80, 60),
            'details' => $checklist,
        ];

        // A8: gap count by priority (unfulfilled critical/high compliance requirements)
        if ($this->complianceAnalyticsService !== null) {
            try {
                $gap = $this->complianceAnalyticsService->getGapAnalysis();
                $byPriority = $gap['by_priority'] ?? [];
                $critical = (int) ($byPriority['critical'] ?? 0);
                $high = (int) ($byPriority['high'] ?? 0);
                $out['gap_count_critical'] = [
                    'label' => 'kpi.gap_count_critical',
                    'value' => $critical,
                    'unit' => '',
                    'status' => $critical === 0 ? 'good' : ($critical > 5 ? 'danger' : 'warning'),
                ];
                $out['gap_count_high'] = [
                    'label' => 'kpi.gap_count_high',
                    'value' => $high,
                    'unit' => '',
                    'status' => $high === 0 ? 'good' : ($high > 10 ? 'warning' : 'info'),
                ];
            } catch (\Throwable) {
                // gap analysis optional
            }
        }

        return $out;
    }

    /**
     * A4: Composite ISMS Health Score (0-100).
     *
     * Mixes four dimensions, weighted:
     *  - Control compliance (weighted)   : 40%
     *  - Risk exposure inverse            : 25% (fewer critical/high risks → higher score)
     *  - Incident backlog inverse         : 20% (fewer open incidents → higher score)
     *  - Asset classification rate        : 15%
     *
     * @return array{score: int, breakdown: array<string, float>}
     */
    private function calculateIsmsHealthScore(?Tenant $tenant, array $coreKpis, array $activeModules): array
    {
        $weightedCompliance = (float) ($coreKpis['control_compliance_weighted']['value'] ?? 0);

        // Risk exposure component (invert; more critical = lower score)
        $riskScore = 100.0;
        if (in_array('risks', $activeModules, true)) {
            $risks = $tenant ? $this->getAllAccessibleRisks($tenant) : $this->riskRepository->findAll();
            $critical = count(array_filter($risks, fn($r): bool => $r->getResidualRiskLevel() >= 16));
            $high = count(array_filter($risks, fn($r): bool => $r->getResidualRiskLevel() >= 12 && $r->getResidualRiskLevel() < 16));
            $riskScore = max(0.0, 100.0 - ($critical * 15.0) - ($high * 5.0));
        }

        // Incident backlog component (open/total)
        $incidentScore = 100.0;
        if (in_array('incidents', $activeModules, true)) {
            $incidents = $tenant ? $this->getAllAccessibleIncidents($tenant) : $this->incidentRepository->findAll();
            $total = count($incidents);
            $open = count(array_filter($incidents, fn($i): bool => in_array($i->getStatus(), [
                \App\Enum\IncidentStatus::Reported,
                \App\Enum\IncidentStatus::InInvestigation,
                \App\Enum\IncidentStatus::InResolution,
            ], true)));
            $incidentScore = $total > 0 ? max(0.0, 100.0 - (($open / $total) * 100.0)) : 100.0;
        }

        // Asset classification (recompute to avoid coupling to asset_management KPI shape)
        $assetScore = 100.0;
        if (in_array('assets', $activeModules, true)) {
            $assets = $tenant ? $this->getAllAccessibleAssets($tenant) : [];
            $active = array_filter($assets, fn($a): bool => $a->getStatus() === 'active');
            $total = count($active);
            if ($total > 0) {
                $classified = count(array_filter($active, fn($a): bool => $a->getConfidentialityValue() > 0 && $a->getIntegrityValue() > 0 && $a->getAvailabilityValue() > 0));
                $assetScore = round(($classified / $total) * 100, 1);
            }
        }

        $score = ($weightedCompliance * 0.40)
            + ($riskScore * 0.25)
            + ($incidentScore * 0.20)
            + ($assetScore * 0.15);

        return [
            'score' => (int) round($score),
            'breakdown' => [
                'compliance_weighted' => round($weightedCompliance, 1),
                'risk_exposure' => round($riskScore, 1),
                'incident_backlog' => round($incidentScore, 1),
                'asset_classification' => round($assetScore, 1),
            ],
        ];
    }

    /**
     * A1: Per-framework compliance percentage (surfaced from ComplianceAnalyticsService).
     *
     * @return array<string, array{label: string, value: int|float, unit: string, status: string, details: array}>
     */
    private function getPerFrameworkKPIs(): array
    {
        if ($this->complianceAnalyticsService === null) {
            return [];
        }
        try {
            $comparison = $this->complianceAnalyticsService->getFrameworkComparison();
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        foreach ($comparison['frameworks'] ?? [] as $fw) {
            if (($fw['applicable'] ?? 0) === 0) {
                continue;
            }
            $pct = (float) ($fw['compliance_percentage'] ?? 0);
            $result['framework_' . strtolower(str_replace(['-', ' '], '_', (string) $fw['code']))] = [
                'label' => (string) $fw['name'],
                'value' => $pct,
                'unit' => '%',
                'status' => $this->getStatus((int) round($pct), 80, 60),
                'details' => [
                    'fulfilled' => $fw['fulfilled'] ?? 0,
                    'applicable' => $fw['applicable'] ?? 0,
                    'mandatory' => $fw['mandatory'] ?? false,
                ],
            ];
        }
        return $result;
    }

    /**
     * A1b: BSI IT-Grundschutz Absicherungsstufen filter — surface weighted
     * compliance per Stufe (basis / standard / kern) as individual KPIs so
     * the portfolio dashboard can show BSI readiness per Stufe instead of
     * only the framework-level aggregate.
     *
     * Silently returns an empty array when the BSI framework is not loaded
     * or the check service is not wired — no error, no log noise.
     *
     * @return array<string, array{label: string, value: int|float, unit: string, status: string, details: array}>
     */
    private function getBsiAbsicherungsstufenKPIs(): array
    {
        if ($this->bsiGrundschutzCheckService === null) {
            return [];
        }

        $result = [];
        foreach (['basis', 'standard', 'kern'] as $stufe) {
            try {
                $report = $this->bsiGrundschutzCheckService->getCheckReport($stufe);
            } catch (\Throwable) {
                continue;
            }
            $overall = $report['overall'] ?? null;
            if (!is_array($overall) || ($overall['total'] ?? 0) === 0) {
                continue;
            }
            $weighted = $overall['weighted_pct'];
            $pct = $weighted === null ? 0 : (int) $weighted;
            $result['bsi_stufe_' . $stufe] = [
                'label' => 'kpi.bsi_stufe.' . $stufe,
                'value' => $weighted ?? 0,
                'unit' => '%',
                'status' => $this->getStatus($pct, 80, 60),
                'details' => [
                    'fulfilled' => $overall['fulfilled'] ?? 0,
                    'total' => $overall['total'] ?? 0,
                    'muss' => $overall['breakdown']['muss'] ?? [],
                    'sollte' => $overall['breakdown']['sollte'] ?? [],
                    'kann' => $overall['breakdown']['kann'] ?? [],
                ],
            ];
        }
        return $result;
    }

    private function getCoreKPIs(?Tenant $tenant): array
    {
        $applicableControls = $tenant
            ? $this->controlRepository->findApplicableControls($tenant)
            : [];
        $implementedControls = $this->countImplementedControls($applicableControls);
        $totalControls = count($applicableControls);
        $compliancePercentage = $this->calculateCompliancePercentage($implementedControls, $totalControls);

        $weighted = $this->calculateWeightedComplianceScore($applicableControls);

        return [
            'control_compliance' => [
                'label' => 'kpi.control_compliance',
                'value' => $compliancePercentage,
                'unit' => '%',
                'status' => $this->getStatus($compliancePercentage, 80, 60),
                'details' => [
                    'implemented' => $implementedControls,
                    'total' => $totalControls,
                ],
            ],
            'control_compliance_weighted' => [
                'label' => 'kpi.control_compliance_weighted',
                'value' => $weighted,
                'unit' => '%',
                'status' => $this->getStatus((int) round($weighted), 80, 60),
                'details' => ['total' => $totalControls, 'note' => 'kpi.hint.weighted_compliance'],
            ],
            'controls_implemented' => [
                'label' => 'kpi.controls_implemented',
                'value' => $implementedControls,
                'unit' => '',
                'status' => 'info',
                'details' => ['total' => $totalControls],
            ],
        ];
    }

    /**
     * Get risk management KPIs
     */
    private function getRiskManagementKPIs(?Tenant $tenant): array
    {
        $allRisks = $tenant
            ? $this->getAllAccessibleRisks($tenant)
            : $this->riskRepository->findAll();

        $totalRisks = count($allRisks);
        $highRisks = count(array_filter($allRisks, fn($r): bool => $r->getInherentRiskLevel() >= 12));
        $criticalRisks = count(array_filter($allRisks, fn($r): bool => $r->getInherentRiskLevel() >= 16));
        $treatedRisks = count(array_filter($allRisks, fn($r): bool => $r->getTreatmentStrategy() !== null));
        $treatmentRate = $totalRisks > 0 ? (int) round(($treatedRisks / $totalRisks) * 100) : 0;

        // A3: Residual Risk Exposure — sum of all residual risk levels, plus counts by severity
        // A2: Risk Appetite Compliance — count risks exceeding their category's appetite
        $residualSum = 0;
        $residualCritical = 0;
        $residualHigh = 0;
        $appetiteByCategory = $this->getRiskAppetiteByCategory($tenant);
        $appetiteApplicable = 0;
        $appetiteExceeded = 0;
        foreach ($allRisks as $risk) {
            $residual = $risk->getResidualRiskLevel();
            $residualSum += $residual;
            if ($residual >= 16) {
                $residualCritical++;
            } elseif ($residual >= 12) {
                $residualHigh++;
            }
            $category = $risk->getCategory();
            if ($category !== null && isset($appetiteByCategory[$category])) {
                $appetiteApplicable++;
                if ($residual > $appetiteByCategory[$category]) {
                    $appetiteExceeded++;
                }
            }
        }
        $appetitePct = $appetiteApplicable > 0
            ? round((($appetiteApplicable - $appetiteExceeded) / $appetiteApplicable) * 100, 1)
            : null;

        // Check for overdue treatment plans
        $overdueTreatments = 0;
        if ($this->treatmentPlanRepository !== null) {
            $allPlans = $this->treatmentPlanRepository->findAll();
            $overdueTreatments = count(array_filter($allPlans, fn($p): bool => $p->getTargetCompletionDate() !== null && $p->getTargetCompletionDate() < new \DateTime() && $p->getStatus() !== 'completed'
            ));
        }

        return [
            'total_risks' => [
                'label' => 'kpi.total_risks',
                'value' => $totalRisks,
                'unit' => '',
                'status' => 'info',
                'tier' => 'details',
            ],
            'high_risks' => [
                'label' => 'kpi.high_risks',
                'value' => $highRisks,
                'unit' => '',
                'status' => $highRisks > 5 ? 'danger' : ($highRisks > 0 ? 'warning' : 'good'),
            ],
            'critical_risks' => [
                'label' => 'kpi.critical_risks',
                'value' => $criticalRisks,
                'unit' => '',
                'status' => $criticalRisks > 0 ? 'danger' : 'good',
            ],
            'risk_treatment_rate' => [
                'label' => 'kpi.risk_treatment_rate',
                'value' => $treatmentRate,
                'unit' => '%',
                'status' => $this->getStatus($treatmentRate, 90, 70),
                'details' => ['treated' => $treatedRisks, 'total' => $totalRisks],
            ],
            'residual_risk_exposure' => [
                'label' => 'kpi.residual_risk_exposure',
                'value' => $residualSum,
                'unit' => '',
                'status' => $residualCritical > 0 ? 'danger' : ($residualHigh > 0 ? 'warning' : 'good'),
                'details' => ['critical' => $residualCritical, 'high' => $residualHigh, 'total' => $totalRisks],
            ],
            'risk_appetite_compliance' => [
                'label' => 'kpi.risk_appetite_compliance',
                'value' => $appetitePct,
                'unit' => $appetitePct === null ? '' : '%',
                'status' => $appetitePct === null
                    ? 'info'
                    : ($appetitePct >= 90 ? 'good' : ($appetitePct >= 70 ? 'warning' : 'danger')),
                'na' => $appetitePct === null,
                'details' => ['applicable' => $appetiteApplicable, 'exceeded' => $appetiteExceeded],
            ],
            'overdue_treatments' => [
                'label' => 'kpi.overdue_treatments',
                'value' => $overdueTreatments,
                'unit' => '',
                'status' => $overdueTreatments > 0 ? 'danger' : 'good',
            ],
        ];
    }

    /**
     * Get asset management KPIs
     */
    private function getAssetManagementKPIs(?Tenant $tenant): array
    {
        $allAssets = $tenant
            ? $this->getAllAccessibleAssets($tenant)
            : [];

        $activeAssets = array_filter($allAssets, fn($a): bool => $a->getStatus() === 'active');
        $totalAssets = count($activeAssets);
        $criticalAssets = count(array_filter($activeAssets, fn($a): bool => $a->getConfidentialityValue() >= 4 || $a->getIntegrityValue() >= 4 || $a->getAvailabilityValue() >= 4
        ));
        $classifiedAssets = count(array_filter($activeAssets, fn($a): bool => $a->getConfidentialityValue() > 0 && $a->getIntegrityValue() > 0 && $a->getAvailabilityValue() > 0
        ));
        $classificationRate = $totalAssets > 0 ? (int) round(($classifiedAssets / $totalAssets) * 100) : 0;

        return [
            'total_assets' => [
                'label' => 'kpi.total_assets',
                'value' => $totalAssets,
                'unit' => '',
                'status' => 'info',
                'tier' => 'details',
            ],
            'critical_assets' => [
                'label' => 'kpi.critical_assets',
                'value' => $criticalAssets,
                'unit' => '',
                'status' => 'info',
            ],
            'asset_classification_rate' => [
                'label' => 'kpi.asset_classification_rate',
                'value' => $classificationRate,
                'unit' => '%',
                'status' => $this->getStatus($classificationRate, 90, 70),
                'details' => ['classified' => $classifiedAssets, 'total' => $totalAssets],
            ],
        ];
    }

    /**
     * Get incident management KPIs
     */
    private function getIncidentManagementKPIs(?Tenant $tenant): array
    {
        $allIncidents = $tenant
            ? $this->getAllAccessibleIncidents($tenant)
            : $this->incidentRepository->findAll();

        $openIncidents = array_filter($allIncidents, fn($i): bool => in_array($i->getStatus(), [IncidentStatus::Reported, IncidentStatus::InInvestigation, IncidentStatus::InResolution], true));
        $resolvedIncidents = array_filter($allIncidents, fn($i): bool => $i->getStatus() === IncidentStatus::Resolved || $i->getStatus() === IncidentStatus::Closed);

        // Calculate MTTR (Mean Time To Resolve) for resolved incidents this year
        $thisYear = (new \DateTime())->format('Y');
        $resolvedThisYear = array_filter(
            $resolvedIncidents,
            fn($i): bool => $i->getResolvedAt() !== null && $i->getResolvedAt()->format('Y') === $thisYear
        );

        $mttrHours = 0;
        // C2: MTTR segmented by severity (critical/high/medium/low)
        $mttrBySeverity = ['critical' => [], 'high' => [], 'medium' => [], 'low' => []];
        if (count($resolvedThisYear) > 0) {
            $totalHours = 0;
            $validCount = 0;
            foreach ($resolvedThisYear as $incident) {
                if ($incident->getDetectedAt() !== null && $incident->getResolvedAt() !== null) {
                    $diff = $incident->getDetectedAt()->diff($incident->getResolvedAt());
                    $hours = ($diff->days * 24) + $diff->h;
                    $totalHours += $hours;
                    $validCount++;
                    $sev = $incident->getSeverity()?->value;
                    if ($sev !== null && isset($mttrBySeverity[$sev])) {
                        $mttrBySeverity[$sev][] = $hours;
                    }
                }
            }
            if ($validCount > 0) {
                $mttrHours = round($totalHours / $validCount);
            }
        }
        $mttrSeverityAvg = [];
        foreach ($mttrBySeverity as $sev => $vals) {
            $mttrSeverityAvg[$sev] = $vals === [] ? null : (int) round(array_sum($vals) / count($vals));
        }

        // Count overdue incidents (open > 7 days)
        $overdueIncidents = count(array_filter(
            $openIncidents,
            fn($i): bool => $i->getDetectedAt() !== null && $i->getDetectedAt()->diff(new \DateTime())->days > 7
        ));

        return [
            'open_incidents' => [
                'label' => 'kpi.open_incidents',
                'value' => count($openIncidents),
                'unit' => '',
                'status' => count($openIncidents) > 10 ? 'danger' : (count($openIncidents) > 5 ? 'warning' : 'good'),
            ],
            'resolved_incidents_ytd' => [
                'label' => 'kpi.resolved_incidents_ytd',
                'value' => count($resolvedThisYear),
                'unit' => '',
                'status' => 'info',
            ],
            'mttr' => [
                'label' => 'kpi.mttr',
                'value' => $mttrHours,
                'unit' => 'h',
                'status' => $mttrHours > 72 ? 'warning' : 'good',
                'details' => $mttrSeverityAvg,
            ],
            'mttr_critical' => [
                'label' => 'kpi.mttr_critical',
                'value' => $mttrSeverityAvg['critical'],
                'unit' => $mttrSeverityAvg['critical'] === null ? '' : 'h',
                'status' => $mttrSeverityAvg['critical'] === null
                    ? 'info'
                    : ($mttrSeverityAvg['critical'] > 24 ? 'danger' : 'good'),
                'na' => $mttrSeverityAvg['critical'] === null,
            ],
            'mttr_high' => [
                'label' => 'kpi.mttr_high',
                'value' => $mttrSeverityAvg['high'],
                'unit' => $mttrSeverityAvg['high'] === null ? '' : 'h',
                'status' => $mttrSeverityAvg['high'] === null
                    ? 'info'
                    : ($mttrSeverityAvg['high'] > 72 ? 'warning' : 'good'),
                'na' => $mttrSeverityAvg['high'] === null,
            ],
            'overdue_incidents' => [
                'label' => 'kpi.overdue_incidents',
                'value' => $overdueIncidents,
                'unit' => '',
                'status' => $overdueIncidents > 0 ? 'danger' : 'good',
            ],
        ];
    }

    /**
     * Get business continuity KPIs
     */
    private function getBusinessContinuityKPIs(?Tenant $tenant): array
    {
        $kpis = [];

        // Business processes with BIA
        if ($this->businessProcessRepository !== null) {
            $allProcesses = $this->businessProcessRepository->findAll();
            $criticalProcesses = array_filter($allProcesses, fn($p): bool => $p->getCriticality() === 'critical' || $p->getCriticality() === 'high');
            $processesWithBia = array_filter($criticalProcesses, fn($p): bool => $p->getRto() !== null || $p->getRpo() !== null);
            $biaCoverage = count($criticalProcesses) > 0
                ? (int) round((count($processesWithBia) / count($criticalProcesses)) * 100)
                : 0;

            $kpis['critical_processes'] = [
                'label' => 'kpi.critical_processes',
                'value' => count($criticalProcesses),
                'unit' => '',
                'status' => 'info',
            ];
            $kpis['bia_coverage'] = [
                'label' => 'kpi.bia_coverage',
                'value' => $biaCoverage,
                'unit' => '%',
                'status' => $this->getStatus($biaCoverage, 90, 70),
            ];
        }

        // BC Plans
        if ($this->bcPlanRepository !== null) {
            $allPlans = $this->bcPlanRepository->findAll();
            $activePlans = array_filter($allPlans, fn($p): bool => $p->getStatus() === 'approved' || $p->getStatus() === 'active');

            $kpis['active_bc_plans'] = [
                'label' => 'kpi.active_bc_plans',
                'value' => count($activePlans),
                'unit' => '',
                'status' => count($activePlans) > 0 ? 'good' : 'warning',
            ];
        }

        // BC Exercises
        if ($this->bcExerciseRepository !== null) {
            $allExercises = $this->bcExerciseRepository->findAll();
            $thisYear = (new \DateTime())->format('Y');
            $exercisesThisYear = array_filter(
                $allExercises,
                fn($e): bool => $e->getExerciseDate() !== null && $e->getExerciseDate()->format('Y') === $thisYear
            );

            $kpis['bc_exercises_ytd'] = [
                'label' => 'kpi.bc_exercises_ytd',
                'value' => count($exercisesThisYear),
                'unit' => '',
                'status' => count($exercisesThisYear) >= 1 ? 'good' : 'warning',
            ];
        }

        return $kpis;
    }

    /**
     * Get training KPIs
     */
    private function getTrainingKPIs(?Tenant $tenant): array
    {
        if ($this->trainingRepository === null) {
            return [];
        }

        $allTrainings = $this->trainingRepository->findAll();
        $completedTrainings = array_filter($allTrainings, fn($t): bool => $t->getStatus() === 'completed');
        $overdueTrainings = array_filter(
            $allTrainings,
            fn($t): bool => $t->getScheduledDate() !== null && $t->getScheduledDate() < new \DateTime() && $t->getStatus() !== 'completed'
        );

        $completionRate = count($allTrainings) > 0
            ? (int) round((count($completedTrainings) / count($allTrainings)) * 100)
            : 0;

        return [
            'training_completion_rate' => [
                'label' => 'kpi.training_completion_rate',
                'value' => $completionRate,
                'unit' => '%',
                'status' => $this->getStatus($completionRate, 90, 70),
            ],
            'overdue_trainings' => [
                'label' => 'kpi.overdue_trainings',
                'value' => count($overdueTrainings),
                'unit' => '',
                'status' => count($overdueTrainings) > 0 ? 'danger' : 'good',
            ],
        ];
    }

    /**
     * Get audit KPIs
     */
    private function getAuditKPIs(?Tenant $tenant): array
    {
        if ($this->auditRepository === null) {
            return [];
        }

        $allAudits = $this->auditRepository->findAll();
        $thisYear = (new \DateTime())->format('Y');
        $auditsThisYear = array_filter(
            $allAudits,
            fn($a): bool => $a->getPlannedDate() !== null && $a->getPlannedDate()->format('Y') === $thisYear
        );
        $completedAudits = array_filter($auditsThisYear, fn($a): bool => $a->getStatus() === 'completed');

        // Count open findings (assuming audits have a method for findings)
        $openFindings = 0;
        foreach ($auditsThisYear as $audit) {
            if (method_exists($audit, 'getFindings')) {
                $findings = $audit->getFindings();
                $openFindings += count(array_filter(
                    $findings instanceof \Traversable ? iterator_to_array($findings) : (array) $findings,
                    fn($f): bool => method_exists($f, 'getStatus') && $f->getStatus() !== 'closed'
                ));
            }
        }

        return [
            'audits_completed_ytd' => [
                'label' => 'kpi.audits_completed_ytd',
                'value' => count($completedAudits),
                'unit' => '',
                'status' => 'info',
            ],
            'planned_audits' => [
                'label' => 'kpi.planned_audits',
                'value' => count($auditsThisYear),
                'unit' => '',
                'status' => 'info',
            ],
            'open_audit_findings' => [
                'label' => 'kpi.open_audit_findings',
                'value' => $openFindings,
                'unit' => '',
                'status' => $openFindings > 5 ? 'danger' : ($openFindings > 0 ? 'warning' : 'good'),
            ],
        ];
    }

    /**
     * Get supplier management KPIs
     */
    private function getSupplierKPIs(?Tenant $tenant): array
    {
        if ($this->supplierRepository === null) {
            return [];
        }

        $allSuppliers = $this->supplierRepository->findAll();
        $criticalSuppliers = array_filter(
            $allSuppliers,
            fn($s): bool => method_exists($s, 'getCriticality') && ($s->getCriticality() === 'critical' || $s->getCriticality() === 'high')
        );
        $assessedSuppliers = array_filter(
            $criticalSuppliers,
            fn($s): bool => method_exists($s, 'getLastSecurityAssessment') && $s->getLastSecurityAssessment() !== null
        );
        $hasCriticalSuppliers = count($criticalSuppliers) > 0;
        $assessmentRate = $hasCriticalSuppliers
            ? (int) round((count($assessedSuppliers) / count($criticalSuppliers)) * 100)
            : null;

        // Check for overdue assessments (> 12 months)
        $overdueAssessments = count(array_filter(
            $criticalSuppliers,
            fn($s): bool => method_exists($s, 'getLastSecurityAssessment') && (
                $s->getLastSecurityAssessment() === null ||
                $s->getLastSecurityAssessment()->diff(new \DateTime())->days > 365
            )
        ));

        return [
            'total_suppliers' => [
                'label' => 'kpi.total_suppliers',
                'value' => count($allSuppliers),
                'unit' => '',
                'status' => 'info',
                'tier' => 'details',
            ],
            'critical_suppliers' => [
                'label' => 'kpi.critical_suppliers',
                'value' => count($criticalSuppliers),
                'unit' => '',
                'status' => 'info',
            ],
            'supplier_assessment_rate' => [
                'label' => 'kpi.supplier_assessment_rate',
                'value' => $assessmentRate,
                'unit' => $hasCriticalSuppliers ? '%' : '',
                'status' => $hasCriticalSuppliers ? $this->getStatus($assessmentRate, 90, 70) : 'info',
                'na' => !$hasCriticalSuppliers,
            ],
            'overdue_assessments' => [
                'label' => 'kpi.overdue_supplier_assessments',
                'value' => $overdueAssessments,
                'unit' => '',
                'status' => $overdueAssessments > 0 ? 'warning' : 'good',
            ],
        ];
    }

    /**
     * Get documentation KPIs
     */
    private function getDocumentationKPIs(?Tenant $tenant): array
    {
        if ($this->documentRepository === null) {
            return [];
        }

        $allDocuments = $this->documentRepository->findAll();
        $activeDocuments = array_filter(
            $allDocuments,
            fn($d): bool => method_exists($d, 'getStatus') && ($d->getStatus() === 'approved' || $d->getStatus() === 'active' || $d->getStatus() === null)
        );

        // Documents needing review (> 12 months since last review)
        $documentsNeedingReview = count(array_filter(
            $activeDocuments,
            fn($d): bool => method_exists($d, 'getLastReviewDate') && (
                $d->getLastReviewDate() === null ||
                $d->getLastReviewDate()->diff(new \DateTime())->days > 365
            )
        ));

        // Documents without owner
        $documentsWithoutOwner = count(array_filter(
            $activeDocuments,
            fn($d): bool => method_exists($d, 'getOwner') && $d->getOwner() === null
        ));

        return [
            'total_documents' => [
                'label' => 'kpi.total_documents',
                'value' => count($activeDocuments),
                'unit' => '',
                'status' => 'info',
                'tier' => 'details',
            ],
            'documents_needing_review' => [
                'label' => 'kpi.documents_needing_review',
                'value' => $documentsNeedingReview,
                'unit' => '',
                'status' => $documentsNeedingReview > 10 ? 'danger' : ($documentsNeedingReview > 0 ? 'warning' : 'good'),
            ],
            'documents_without_owner' => [
                'label' => 'kpi.documents_without_owner',
                'value' => $documentsWithoutOwner,
                'unit' => '',
                'status' => $documentsWithoutOwner > 0 ? 'warning' : 'good',
            ],
        ];
    }

    /**
     * Determine KPI status based on value and thresholds
     */
    private function getStatus(int $value, int $goodThreshold, int $warningThreshold): string
    {
        if ($value >= $goodThreshold) {
            return 'good';
        }
        if ($value >= $warningThreshold) {
            return 'warning';
        }
        return 'danger';
    }

    /**
     * Get DORA-specific KPIs for financial institutions
     *
     * @return array DORA compliance KPIs
     */
    public function getDoraKPIs(): array
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        return [
            'ict_risk' => $this->getIctRiskKPIs($tenant),
            'incident_reporting' => $this->getDoraIncidentKPIs($tenant),
            'resilience_testing' => $this->getResilienceTestingKPIs($tenant),
            'third_party' => $this->getThirdPartyRiskKPIs($tenant),
        ];
    }

    /**
     * Get ICT Risk Management KPIs for DORA
     */
    private function getIctRiskKPIs(?Tenant $tenant): array
    {
        $allRisks = $tenant
            ? $this->getAllAccessibleRisks($tenant)
            : $this->riskRepository->findAll();

        // Filter for ICT-related risks (assuming category or tag)
        $ictRisks = array_filter(
            $allRisks,
            fn($r): bool => method_exists($r, 'getCategory') && stripos($r->getCategory() ?? '', 'ICT') !== false
        );

        return [
            'ict_risks_total' => [
                'label' => 'kpi.dora.ict_risks',
                'value' => count($ictRisks),
                'unit' => '',
                'status' => 'info',
            ],
            'ict_risks_high' => [
                'label' => 'kpi.dora.ict_risks_high',
                'value' => count(array_filter($ictRisks, fn($r): bool => $r->getInherentRiskLevel() >= 12)),
                'unit' => '',
                'status' => count(array_filter($ictRisks, fn($r): bool => $r->getInherentRiskLevel() >= 12)) > 0 ? 'warning' : 'good',
            ],
        ];
    }

    /**
     * Get DORA Incident Reporting KPIs
     */
    private function getDoraIncidentKPIs(?Tenant $tenant): array
    {
        $allIncidents = $tenant
            ? $this->getAllAccessibleIncidents($tenant)
            : $this->incidentRepository->findAll();

        // Major ICT incidents (high severity)
        $majorIncidents = array_filter(
            $allIncidents,
            fn($i): bool => method_exists($i, 'getSeverity') && in_array($i->getSeverity(), [IncidentSeverity::Critical, IncidentSeverity::High], true)
        );

        // Check for 4h initial reporting compliance
        $reportedWithin4h = count(array_filter(
            $majorIncidents,
            fn($i): bool => $i->getDetectedAt() !== null &&
                method_exists($i, 'getReportedAt') &&
                $i->getReportedAt() !== null &&
                $i->getDetectedAt()->diff($i->getReportedAt())->h <= 4 &&
                $i->getDetectedAt()->diff($i->getReportedAt())->days === 0
        ));

        $reportingCompliance = count($majorIncidents) > 0
            ? (int) round(($reportedWithin4h / count($majorIncidents)) * 100)
            : 100;

        return [
            'major_ict_incidents' => [
                'label' => 'kpi.dora.major_ict_incidents',
                'value' => count($majorIncidents),
                'unit' => '',
                'status' => 'info',
            ],
            'reporting_compliance' => [
                'label' => 'kpi.dora.4h_reporting_compliance',
                'value' => $reportingCompliance,
                'unit' => '%',
                'status' => $this->getStatus($reportingCompliance, 100, 80),
            ],
        ];
    }

    /**
     * Get Resilience Testing KPIs for DORA
     */
    private function getResilienceTestingKPIs(?Tenant $tenant): array
    {
        $kpis = [];

        // BC Exercises as resilience tests
        if ($this->bcExerciseRepository !== null) {
            $allExercises = $this->bcExerciseRepository->findAll();
            $thisYear = (new \DateTime())->format('Y');
            $exercisesThisYear = array_filter(
                $allExercises,
                fn($e): bool => $e->getExerciseDate() !== null && $e->getExerciseDate()->format('Y') === $thisYear
            );

            $kpis['resilience_tests_ytd'] = [
                'label' => 'kpi.dora.resilience_tests_ytd',
                'value' => count($exercisesThisYear),
                'unit' => '',
                'status' => count($exercisesThisYear) >= 1 ? 'good' : 'warning',
            ];
        }

        return $kpis;
    }

    /**
     * Get Third-Party Risk KPIs for DORA
     */
    private function getThirdPartyRiskKPIs(?Tenant $tenant): array
    {
        if ($this->supplierRepository === null) {
            return [];
        }

        $allSuppliers = $this->supplierRepository->findAll();

        // ICT Third-party providers (assuming type or category)
        $ictProviders = array_filter(
            $allSuppliers,
            fn($s): bool => method_exists($s, 'getType') && stripos($s->getType() ?? '', 'ICT') !== false
        );

        $criticalIctProviders = array_filter(
            $ictProviders,
            fn($s): bool => method_exists($s, 'getCriticality') && $s->getCriticality() === 'critical'
        );

        return [
            'ict_providers' => [
                'label' => 'kpi.dora.ict_third_party_providers',
                'value' => count($ictProviders),
                'unit' => '',
                'status' => 'info',
            ],
            'critical_ict_providers' => [
                'label' => 'kpi.dora.critical_ict_providers',
                'value' => count($criticalIctProviders),
                'unit' => '',
                'status' => 'info',
            ],
        ];
    }
}

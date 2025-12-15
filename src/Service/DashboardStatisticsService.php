<?php

namespace App\Service;

use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Service\AssetService;
use App\Service\RiskService;
use Symfony\Bundle\SecurityBundle\Security;

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
        private readonly ?ManagementReviewRepository $managementReviewRepository = null
    ) {
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
        // Get current tenant from authenticated user
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

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
            $openIncidents = array_filter($allAccessibleIncidents, fn($incident): bool => $incident->getStatus() === 'open');
            $openIncidentCount = count($openIncidents);
        } else {
            // Fallback for users without tenant (admin view)
            $activeAssets = $this->assetRepository->findActiveAssets();
            $assetCount = count($activeAssets);
            $allAccessibleRisks = $this->riskRepository->findAll();
            $riskCount = count($allAccessibleRisks);
            $openIncidentCount = count($this->incidentRepository->findOpenIncidents());
        }

        // Control statistics (controls are global, not tenant-specific)
        $applicableControls = $this->controlRepository->findApplicableControls();
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
        $activeAssets = $this->assetRepository->findActiveAssets();

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
        $activeModules = $this->moduleConfigurationService->getActiveModules();

        $kpis = [
            'core' => $this->getCoreKPIs($tenant),
            'active_modules' => $activeModules,
        ];

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
     * Get core KPIs (always shown)
     */
    private function getCoreKPIs(?Tenant $tenant): array
    {
        $applicableControls = $this->controlRepository->findApplicableControls();
        $implementedControls = $this->countImplementedControls($applicableControls);
        $totalControls = count($applicableControls);
        $compliancePercentage = $this->calculateCompliancePercentage($implementedControls, $totalControls);

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
        $treatedRisks = count(array_filter($allRisks, fn($r): bool => $r->getTreatmentStrategy() !== null && $r->getTreatmentStrategy() !== ''));
        $treatmentRate = $totalRisks > 0 ? round(($treatedRisks / $totalRisks) * 100) : 0;

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
            : $this->assetRepository->findActiveAssets();

        $activeAssets = array_filter($allAssets, fn($a): bool => $a->getStatus() === 'active');
        $totalAssets = count($activeAssets);
        $criticalAssets = count(array_filter($activeAssets, fn($a): bool => $a->getConfidentialityValue() >= 4 || $a->getIntegrityValue() >= 4 || $a->getAvailabilityValue() >= 4
        ));
        $classifiedAssets = count(array_filter($activeAssets, fn($a): bool => $a->getConfidentialityValue() > 0 || $a->getIntegrityValue() > 0 || $a->getAvailabilityValue() > 0
        ));
        $classificationRate = $totalAssets > 0 ? round(($classifiedAssets / $totalAssets) * 100) : 0;

        return [
            'total_assets' => [
                'label' => 'kpi.total_assets',
                'value' => $totalAssets,
                'unit' => '',
                'status' => 'info',
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

        $openIncidents = array_filter($allIncidents, fn($i): bool => $i->getStatus() === 'open');
        $resolvedIncidents = array_filter($allIncidents, fn($i): bool => $i->getStatus() === 'resolved' || $i->getStatus() === 'closed');

        // Calculate MTTR (Mean Time To Resolve) for resolved incidents this year
        $thisYear = (new \DateTime())->format('Y');
        $resolvedThisYear = array_filter(
            $resolvedIncidents,
            fn($i): bool => $i->getResolvedAt() !== null && $i->getResolvedAt()->format('Y') === $thisYear
        );

        $mttrHours = 0;
        if (count($resolvedThisYear) > 0) {
            $totalHours = 0;
            foreach ($resolvedThisYear as $incident) {
                if ($incident->getDetectedAt() !== null && $incident->getResolvedAt() !== null) {
                    $diff = $incident->getDetectedAt()->diff($incident->getResolvedAt());
                    $totalHours += ($diff->days * 24) + $diff->h;
                }
            }
            $mttrHours = round($totalHours / count($resolvedThisYear));
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
                ? round((count($processesWithBia) / count($criticalProcesses)) * 100)
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
            ? round((count($completedTrainings) / count($allTrainings)) * 100)
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
        $assessmentRate = count($criticalSuppliers) > 0
            ? round((count($assessedSuppliers) / count($criticalSuppliers)) * 100)
            : 100;

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
                'unit' => '%',
                'status' => $this->getStatus($assessmentRate, 90, 70),
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
            fn($i): bool => method_exists($i, 'getSeverity') && ($i->getSeverity() === 'critical' || $i->getSeverity() === 'high')
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
            ? round(($reportedWithin4h / count($majorIncidents)) * 100)
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

<?php

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Compliance Wizard Service
 *
 * Provides guided compliance assessment through existing ISMS modules.
 * Uses Data Reuse principle: analyzes existing entities to calculate coverage.
 *
 * Features:
 * - Module-aware: Only checks data from active modules
 * - Framework-specific: Different wizards for ISO 27001, NIS2, DORA, etc.
 * - Gap identification: Shows what's missing with links to relevant modules
 * - Progress tracking: Calculates overall compliance percentage
 *
 * Supported Frameworks:
 * - ISO 27001:2022 (controls, risks, assets)
 * - NIS2 (incidents, controls, authentication)
 * - DORA (bcm, incidents, controls, assets, suppliers)
 * - TISAX (controls, assets)
 * - BSI IT-Grundschutz (controls, assets, risks)
 * - GDPR/DSGVO (processing activities, DPIA, data breaches)
 */
class ComplianceWizardService
{
    public function __construct(
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly ControlRepository $controlRepository,
        private readonly RiskRepository $riskRepository,
        private readonly AssetRepository $assetRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly InternalAuditRepository $auditRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly RiskTreatmentPlanRepository $treatmentPlanRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator
    ) {
    }

    /**
     * Get available wizards based on active modules
     *
     * @return array List of available wizard configurations
     */
    public function getAvailableWizards(): array
    {
        $activeModules = $this->moduleConfigurationService->getActiveModules();

        $wizards = [
            'iso27001' => [
                'code' => 'ISO27001',
                'name' => 'ISO 27001:2022 Readiness',
                'description' => 'wizard.iso27001.description',
                'icon' => 'bi-shield-check',
                'color' => 'primary',
                'required_modules' => ['controls'],
                'recommended_modules' => ['risks', 'assets', 'audits'],
                'categories' => $this->getIso27001Categories(),
            ],
            'nis2' => [
                'code' => 'NIS2',
                'name' => 'NIS2 Compliance',
                'description' => 'wizard.nis2.description',
                'icon' => 'bi-shield-exclamation',
                'color' => 'warning',
                'required_modules' => ['incidents', 'controls'],
                'recommended_modules' => ['authentication', 'assets'],
                'categories' => $this->getNis2Categories(),
            ],
            'dora' => [
                'code' => 'DORA',
                'name' => 'DORA Readiness',
                'description' => 'wizard.dora.description',
                'icon' => 'bi-bank',
                'color' => 'info',
                'required_modules' => ['bcm', 'incidents', 'controls'],
                'recommended_modules' => ['assets', 'risks'],
                'categories' => $this->getDoraCategories(),
            ],
            'tisax' => [
                'code' => 'TISAX',
                'name' => 'TISAX Assessment',
                'description' => 'wizard.tisax.description',
                'icon' => 'bi-car-front',
                'color' => 'secondary',
                'required_modules' => ['controls', 'assets'],
                'recommended_modules' => ['risks'],
                'categories' => $this->getTisaxCategories(),
            ],
            'gdpr' => [
                'code' => 'GDPR',
                'name' => 'GDPR/DSGVO Compliance',
                'description' => 'wizard.gdpr.description',
                'icon' => 'bi-person-lock',
                'color' => 'success',
                'required_modules' => ['controls'],
                'recommended_modules' => ['incidents', 'training'],
                'categories' => $this->getGdprCategories(),
            ],
        ];

        // Filter wizards by required modules
        return array_filter($wizards, function ($wizard) use ($activeModules) {
            foreach ($wizard['required_modules'] as $requiredModule) {
                if (!in_array($requiredModule, $activeModules)) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Check if a specific wizard is available
     */
    public function isWizardAvailable(string $wizardKey): bool
    {
        $available = $this->getAvailableWizards();
        return isset($available[$wizardKey]);
    }

    /**
     * Get wizard configuration
     */
    public function getWizardConfig(string $wizardKey): ?array
    {
        $wizards = $this->getAvailableWizards();
        return $wizards[$wizardKey] ?? null;
    }

    /**
     * Run compliance assessment for a specific wizard
     *
     * @param string $wizardKey Wizard identifier (iso27001, nis2, dora, etc.)
     * @param Tenant|null $tenant Optional tenant context
     * @return array Assessment results with categories, scores, and gaps
     */
    public function runAssessment(string $wizardKey, ?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenantContext->getCurrentTenant();
        $config = $this->getWizardConfig($wizardKey);

        if (!$config) {
            return [
                'success' => false,
                'error' => 'Wizard not available or required modules not active',
            ];
        }

        $activeModules = $this->moduleConfigurationService->getActiveModules();
        $categories = [];
        $totalScore = 0;
        $totalWeight = 0;
        $criticalGaps = [];

        foreach ($config['categories'] as $categoryKey => $category) {
            $categoryResult = $this->assessCategory($categoryKey, $category, $tenant, $activeModules);
            $categories[$categoryKey] = $categoryResult;

            $weight = $category['weight'] ?? 1;
            $totalScore += $categoryResult['score'] * $weight;
            $totalWeight += $weight;

            // Collect critical gaps
            foreach ($categoryResult['gaps'] as $gap) {
                if (($gap['priority'] ?? 'medium') === 'critical') {
                    $criticalGaps[] = $gap;
                }
            }
        }

        $overallScore = $totalWeight > 0 ? round($totalScore / $totalWeight, 1) : 0;

        // Determine compliance status
        $status = match (true) {
            $overallScore >= 95 => 'compliant',
            $overallScore >= 75 => 'partial',
            $overallScore >= 50 => 'in_progress',
            default => 'non_compliant',
        };

        return [
            'success' => true,
            'wizard' => $wizardKey,
            'framework' => $config['code'],
            'framework_name' => $config['name'],
            'overall_score' => $overallScore,
            'status' => $status,
            'categories' => $categories,
            'critical_gaps' => $criticalGaps,
            'critical_gap_count' => count($criticalGaps),
            'active_modules' => $activeModules,
            'missing_modules' => array_diff(
                $config['recommended_modules'],
                $activeModules
            ),
            'assessed_at' => new \DateTimeImmutable(),
            'tenant_id' => $tenant?->getId(),
        ];
    }

    /**
     * Assess a single category
     */
    private function assessCategory(
        string $categoryKey,
        array $category,
        ?Tenant $tenant,
        array $activeModules
    ): array {
        $checks = $category['checks'] ?? [];
        $totalScore = 0;
        $totalChecks = 0;
        $gaps = [];
        $items = [];

        foreach ($checks as $checkKey => $check) {
            // Skip check if required module is not active
            if (isset($check['module']) && !in_array($check['module'], $activeModules)) {
                continue;
            }

            $result = $this->runCheck($check, $tenant);
            $items[$checkKey] = $result;

            $totalScore += $result['score'];
            $totalChecks++;

            if ($result['score'] < 100 && !empty($result['gap'])) {
                $gaps[] = array_merge($result['gap'], [
                    'check_key' => $checkKey,
                    'category' => $categoryKey,
                ]);
            }
        }

        $categoryScore = $totalChecks > 0 ? round($totalScore / $totalChecks, 1) : 0;

        return [
            'name' => $category['name'],
            'description' => $category['description'] ?? '',
            'icon' => $category['icon'] ?? 'bi-check-circle',
            'score' => $categoryScore,
            'items' => $items,
            'gaps' => $gaps,
            'gap_count' => count($gaps),
            'status' => $this->getStatusFromScore($categoryScore),
        ];
    }

    /**
     * Run a single compliance check
     */
    private function runCheck(array $check, ?Tenant $tenant): array
    {
        $type = $check['type'] ?? 'manual';
        $score = 0;
        $details = [];
        $gap = null;

        switch ($type) {
            case 'control_coverage':
                $result = $this->checkControlCoverage($check, $tenant);
                break;

            case 'risk_coverage':
                $result = $this->checkRiskCoverage($check, $tenant);
                break;

            case 'asset_coverage':
                $result = $this->checkAssetCoverage($check, $tenant);
                break;

            case 'incident_process':
                $result = $this->checkIncidentProcess($check, $tenant);
                break;

            case 'bcm_coverage':
                $result = $this->checkBcmCoverage($check, $tenant);
                break;

            case 'training_coverage':
                $result = $this->checkTrainingCoverage($check, $tenant);
                break;

            case 'audit_status':
                $result = $this->checkAuditStatus($check, $tenant);
                break;

            case 'supplier_assessment':
                $result = $this->checkSupplierAssessment($check, $tenant);
                break;

            case 'document_review':
                $result = $this->checkDocumentReview($check, $tenant);
                break;

            case 'treatment_plan':
                $result = $this->checkTreatmentPlanStatus($check, $tenant);
                break;

            default:
                // Manual check - requires user confirmation
                $result = [
                    'score' => 0,
                    'details' => ['type' => 'manual', 'requires_confirmation' => true],
                    'gap' => [
                        'title' => $check['name'] ?? 'Manual Check Required',
                        'description' => $check['description'] ?? '',
                        'priority' => $check['priority'] ?? 'medium',
                        'action' => $check['action'] ?? null,
                        'route' => $check['route'] ?? null,
                    ],
                ];
        }

        return [
            'name' => $check['name'] ?? $type,
            'description' => $check['description'] ?? '',
            'score' => $result['score'],
            'status' => $this->getStatusFromScore($result['score']),
            'details' => $result['details'],
            'gap' => $result['gap'] ?? null,
            'route' => $check['route'] ?? null,
            'module' => $check['module'] ?? null,
        ];
    }

    /**
     * Check control implementation coverage
     */
    private function checkControlCoverage(array $check, ?Tenant $tenant): array
    {
        $controlIds = $check['control_ids'] ?? [];
        $minImplementation = $check['min_implementation'] ?? 100;

        if (empty($controlIds)) {
            // Check all applicable controls
            $controls = $this->controlRepository->findApplicableControls();
        } else {
            $controls = $this->controlRepository->findByControlIds($controlIds);
        }

        $totalControls = count($controls);
        $implementedControls = 0;
        $partialControls = 0;
        $notImplemented = [];

        foreach ($controls as $control) {
            $status = $control->getImplementationStatus();
            $percentage = $control->getImplementationPercentage() ?? 0;

            if ($status === 'implemented' || $percentage >= $minImplementation) {
                $implementedControls++;
            } elseif ($status === 'in_progress' || $percentage > 0) {
                $partialControls++;
            } else {
                $notImplemented[] = [
                    'id' => $control->getControlId(),
                    'name' => $control->getName(),
                ];
            }
        }

        $score = $totalControls > 0
            ? round((($implementedControls + ($partialControls * 0.5)) / $totalControls) * 100, 1)
            : 0;

        $gap = null;
        if ($score < 100) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.controls_not_implemented', [], 'wizard'),
                'description' => $this->translator->trans(
                    'wizard.gap.controls_description',
                    ['%count%' => count($notImplemented)],
                    'wizard'
                ),
                'priority' => count($notImplemented) > 5 ? 'critical' : 'high',
                'action' => $this->translator->trans('wizard.action.implement_controls', [], 'wizard'),
                'route' => 'app_soa_index',
                'items' => array_slice($notImplemented, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalControls,
                'implemented' => $implementedControls,
                'partial' => $partialControls,
                'not_implemented' => count($notImplemented),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check risk management coverage
     */
    private function checkRiskCoverage(array $check, ?Tenant $tenant): array
    {
        $risks = $tenant
            ? $this->riskRepository->findByTenant($tenant)
            : $this->riskRepository->findAll();

        $totalRisks = count($risks);
        $treatedRisks = 0;
        $highUntreated = [];

        foreach ($risks as $risk) {
            $treatment = $risk->getTreatmentStrategy();
            if ($treatment && $treatment !== 'none') {
                $treatedRisks++;
            } else {
                $riskLevel = $risk->getInherentRiskLevel();
                if ($riskLevel >= 12) { // High risk threshold
                    $highUntreated[] = [
                        'id' => $risk->getId(),
                        'title' => $risk->getTitle(),
                        'level' => $riskLevel,
                    ];
                }
            }
        }

        $score = $totalRisks > 0
            ? round(($treatedRisks / $totalRisks) * 100, 1)
            : 100; // No risks = 100% (not applicable)

        $gap = null;
        if (!empty($highUntreated)) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.high_risks_untreated', [], 'wizard'),
                'description' => $this->translator->trans(
                    'wizard.gap.high_risks_description',
                    ['%count%' => count($highUntreated)],
                    'wizard'
                ),
                'priority' => 'critical',
                'action' => $this->translator->trans('wizard.action.treat_risks', [], 'wizard'),
                'route' => 'app_risk_index',
                'items' => array_slice($highUntreated, 0, 5),
            ];
        } elseif ($score < 100) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.risks_need_treatment', [], 'wizard'),
                'description' => $this->translator->trans(
                    'wizard.gap.risks_treatment_description',
                    ['%treated%' => $treatedRisks, '%total%' => $totalRisks],
                    'wizard'
                ),
                'priority' => 'medium',
                'action' => $this->translator->trans('wizard.action.review_risks', [], 'wizard'),
                'route' => 'app_risk_index',
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalRisks,
                'treated' => $treatedRisks,
                'high_untreated' => count($highUntreated),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check asset inventory coverage
     */
    private function checkAssetCoverage(array $check, ?Tenant $tenant): array
    {
        $assets = $tenant
            ? $this->assetRepository->findByTenant($tenant)
            : $this->assetRepository->findAll();

        $totalAssets = count($assets);
        $classifiedAssets = 0;
        $criticalAssets = 0;
        $unclassified = [];

        foreach ($assets as $asset) {
            $cia = $asset->getConfidentialityValue() + $asset->getIntegrityValue() + $asset->getAvailabilityValue();

            if ($cia > 0) {
                $classifiedAssets++;
                if ($asset->getConfidentialityValue() >= 4 || $asset->getAvailabilityValue() >= 4) {
                    $criticalAssets++;
                }
            } else {
                $unclassified[] = [
                    'id' => $asset->getId(),
                    'name' => $asset->getName(),
                ];
            }
        }

        $score = $totalAssets > 0
            ? round(($classifiedAssets / $totalAssets) * 100, 1)
            : 0;

        $gap = null;
        if (!empty($unclassified)) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.assets_not_classified', [], 'wizard'),
                'description' => $this->translator->trans(
                    'wizard.gap.assets_classification_description',
                    ['%count%' => count($unclassified)],
                    'wizard'
                ),
                'priority' => count($unclassified) > 10 ? 'high' : 'medium',
                'action' => $this->translator->trans('wizard.action.classify_assets', [], 'wizard'),
                'route' => 'app_asset_index',
                'items' => array_slice($unclassified, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalAssets,
                'classified' => $classifiedAssets,
                'critical' => $criticalAssets,
                'unclassified' => count($unclassified),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check incident management process
     */
    private function checkIncidentProcess(array $check, ?Tenant $tenant): array
    {
        $incidents = $tenant
            ? $this->incidentRepository->findByTenant($tenant)
            : $this->incidentRepository->findAll();

        $totalIncidents = count($incidents);
        $resolvedInTime = 0;
        $overdue = [];

        // Check SLA compliance for incident handling
        $slaHours = $check['sla_hours'] ?? 72;

        foreach ($incidents as $incident) {
            $detectedAt = $incident->getDetectedAt();
            $resolvedAt = $incident->getResolvedAt();

            if ($resolvedAt && $detectedAt) {
                $diff = $detectedAt->diff($resolvedAt);
                $hours = ($diff->days * 24) + $diff->h;

                if ($hours <= $slaHours) {
                    $resolvedInTime++;
                }
            } elseif (!$resolvedAt && $detectedAt) {
                // Check if currently overdue
                $now = new \DateTime();
                $diff = $detectedAt->diff($now);
                $hours = ($diff->days * 24) + $diff->h;

                if ($hours > $slaHours) {
                    $overdue[] = [
                        'id' => $incident->getId(),
                        'title' => $incident->getTitle(),
                        'hours_overdue' => $hours - $slaHours,
                    ];
                }
            }
        }

        // Score based on SLA compliance and no overdue incidents
        $score = $totalIncidents > 0
            ? round((($resolvedInTime / max($totalIncidents, 1)) * 100) - (count($overdue) * 5), 1)
            : 100;

        $score = max(0, min(100, $score));

        $gap = null;
        if (!empty($overdue)) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.incidents_overdue', [], 'wizard'),
                'description' => $this->translator->trans(
                    'wizard.gap.incidents_overdue_description',
                    ['%count%' => count($overdue), '%sla%' => $slaHours],
                    'wizard'
                ),
                'priority' => 'critical',
                'action' => $this->translator->trans('wizard.action.resolve_incidents', [], 'wizard'),
                'route' => 'app_incident_index',
                'items' => array_slice($overdue, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalIncidents,
                'resolved_in_time' => $resolvedInTime,
                'overdue' => count($overdue),
                'sla_hours' => $slaHours,
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check BCM coverage (Business Continuity Management)
     */
    private function checkBcmCoverage(array $check, ?Tenant $tenant): array
    {
        $processes = $tenant
            ? $this->businessProcessRepository->findByTenant($tenant)
            : $this->businessProcessRepository->findAll();

        $totalProcesses = count($processes);
        $withBia = 0;
        $withBcPlan = 0;
        $testedPlans = 0;
        $criticalWithoutPlan = [];

        // Get all BC plans to check which processes have plans
        $allBcPlans = $this->bcPlanRepository->findAll();
        $processPlansMap = [];
        foreach ($allBcPlans as $plan) {
            $bp = $plan->getBusinessProcess();
            if ($bp !== null) {
                $processPlansMap[$bp->getId()][] = $plan;
            }
        }

        foreach ($processes as $process) {
            // Check if BIA is completed
            if ($process->getRto() !== null && $process->getRpo() !== null) {
                $withBia++;
            }

            // Check if BC plan exists (via our map)
            $bcPlans = $processPlansMap[$process->getId()] ?? [];
            if (count($bcPlans) > 0) {
                $withBcPlan++;

                // Check if plan was tested
                foreach ($bcPlans as $plan) {
                    if ($plan->getLastTested() !== null) {
                        $testedPlans++;
                        break;
                    }
                }
            } elseif ($process->getCriticality() === 'critical' || $process->getCriticality() === 'high') {
                $criticalWithoutPlan[] = [
                    'id' => $process->getId(),
                    'name' => $process->getName(),
                    'criticality' => $process->getCriticality(),
                ];
            }
        }

        $biaCoverage = $totalProcesses > 0 ? ($withBia / $totalProcesses) * 100 : 0;
        $planCoverage = $totalProcesses > 0 ? ($withBcPlan / $totalProcesses) * 100 : 0;
        $testCoverage = $withBcPlan > 0 ? ($testedPlans / $withBcPlan) * 100 : 0;

        // Weighted score: BIA 30%, Plans 40%, Testing 30%
        $score = round(($biaCoverage * 0.3) + ($planCoverage * 0.4) + ($testCoverage * 0.3), 1);

        $gap = null;
        if (!empty($criticalWithoutPlan)) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.critical_no_bcplan', [], 'wizard'),
                'description' => $this->translator->trans(
                    'wizard.gap.critical_bcplan_description',
                    ['%count%' => count($criticalWithoutPlan)],
                    'wizard'
                ),
                'priority' => 'critical',
                'action' => $this->translator->trans('wizard.action.create_bcplan', [], 'wizard'),
                'route' => 'app_bcm_index',
                'items' => array_slice($criticalWithoutPlan, 0, 5),
            ];
        } elseif ($score < 80) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.bcm_incomplete', [], 'wizard'),
                'description' => $this->translator->trans('wizard.gap.bcm_incomplete_description', [], 'wizard'),
                'priority' => 'high',
                'action' => $this->translator->trans('wizard.action.complete_bcm', [], 'wizard'),
                'route' => 'app_bcm_index',
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total_processes' => $totalProcesses,
                'with_bia' => $withBia,
                'with_bc_plan' => $withBcPlan,
                'tested_plans' => $testedPlans,
                'critical_without_plan' => count($criticalWithoutPlan),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check training coverage
     */
    private function checkTrainingCoverage(array $check, ?Tenant $tenant): array
    {
        $trainings = $tenant
            ? $this->trainingRepository->findByTenant($tenant)
            : $this->trainingRepository->findAll();

        $totalTrainings = count($trainings);
        $completed = 0;
        $overdue = [];

        foreach ($trainings as $training) {
            $status = $training->getStatus();
            if ($status === 'completed') {
                $completed++;
            } elseif ($status === 'overdue' || ($training->getScheduledDate() && $training->getScheduledDate() < new \DateTime())) {
                $overdue[] = [
                    'id' => $training->getId(),
                    'name' => $training->getTitle(),
                ];
            }
        }

        $score = $totalTrainings > 0
            ? round(($completed / $totalTrainings) * 100, 1)
            : 100;

        $gap = null;
        if (!empty($overdue)) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.trainings_overdue', [], 'wizard'),
                'description' => $this->translator->trans(
                    'wizard.gap.trainings_overdue_description',
                    ['%count%' => count($overdue)],
                    'wizard'
                ),
                'priority' => 'high',
                'action' => $this->translator->trans('wizard.action.complete_trainings', [], 'wizard'),
                'route' => 'app_training_index',
                'items' => array_slice($overdue, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalTrainings,
                'completed' => $completed,
                'overdue' => count($overdue),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check audit status
     */
    private function checkAuditStatus(array $check, ?Tenant $tenant): array
    {
        $audits = $tenant
            ? $this->auditRepository->findByTenant($tenant)
            : $this->auditRepository->findAll();

        $totalAudits = count($audits);
        $completed = 0;
        $findingsClosed = 0;
        $totalFindings = 0;
        $openCriticalFindings = [];

        foreach ($audits as $audit) {
            if ($audit->getStatus() === 'completed') {
                $completed++;
            }

            $findings = $audit->getFindings() ?? [];
            $totalFindings += count($findings);

            foreach ($findings as $finding) {
                if ($finding['status'] ?? '' === 'closed') {
                    $findingsClosed++;
                } elseif (($finding['severity'] ?? '') === 'critical') {
                    $openCriticalFindings[] = [
                        'audit_id' => $audit->getId(),
                        'finding' => $finding['title'] ?? 'Finding',
                    ];
                }
            }
        }

        $completionRate = $totalAudits > 0 ? ($completed / $totalAudits) * 100 : 100;
        $findingsClosureRate = $totalFindings > 0 ? ($findingsClosed / $totalFindings) * 100 : 100;

        $score = round(($completionRate * 0.5) + ($findingsClosureRate * 0.5), 1);

        $gap = null;
        if (!empty($openCriticalFindings)) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.critical_findings_open', [], 'wizard'),
                'description' => $this->translator->trans(
                    'wizard.gap.critical_findings_description',
                    ['%count%' => count($openCriticalFindings)],
                    'wizard'
                ),
                'priority' => 'critical',
                'action' => $this->translator->trans('wizard.action.close_findings', [], 'wizard'),
                'route' => 'app_audit_index',
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total_audits' => $totalAudits,
                'completed' => $completed,
                'total_findings' => $totalFindings,
                'findings_closed' => $findingsClosed,
                'critical_open' => count($openCriticalFindings),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check supplier/third-party assessment (DORA requirement)
     */
    private function checkSupplierAssessment(array $check, ?Tenant $tenant): array
    {
        $suppliers = $tenant
            ? $this->supplierRepository->findByTenant($tenant)
            : $this->supplierRepository->findAll();

        $totalSuppliers = count($suppliers);
        $assessed = 0;
        $criticalUnassessed = [];

        foreach ($suppliers as $supplier) {
            // Check if supplier has been assessed (has security assessment date or score)
            $hasAssessment = $supplier->getLastSecurityAssessment() !== null || $supplier->getSecurityScore() !== null;
            $criticality = $supplier->getCriticality() ?? 'low';

            if ($hasAssessment) {
                $assessed++;
            } elseif ($criticality === 'critical' || $criticality === 'high') {
                $criticalUnassessed[] = [
                    'id' => $supplier->getId(),
                    'name' => $supplier->getName(),
                    'criticality' => $criticality,
                ];
            }
        }

        $score = $totalSuppliers > 0
            ? round(($assessed / $totalSuppliers) * 100, 1)
            : 100;

        $gap = null;
        if (!empty($criticalUnassessed)) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.suppliers_not_assessed', [], 'wizard'),
                'description' => $this->translator->trans(
                    'wizard.gap.suppliers_assessment_description',
                    ['%count%' => count($criticalUnassessed)],
                    'wizard'
                ),
                'priority' => 'critical',
                'action' => $this->translator->trans('wizard.action.assess_suppliers', [], 'wizard'),
                'route' => 'app_supplier_index',
                'items' => array_slice($criticalUnassessed, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalSuppliers,
                'assessed' => $assessed,
                'critical_unassessed' => count($criticalUnassessed),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check document review status
     */
    private function checkDocumentReview(array $check, ?Tenant $tenant): array
    {
        // Placeholder for document review - implement based on Document entity
        return [
            'score' => 100,
            'details' => ['type' => 'not_implemented'],
            'gap' => null,
        ];
    }

    /**
     * Check treatment plan status
     */
    private function checkTreatmentPlanStatus(array $check, ?Tenant $tenant): array
    {
        $plans = $tenant
            ? $this->treatmentPlanRepository->findByTenant($tenant)
            : $this->treatmentPlanRepository->findAll();

        $totalPlans = count($plans);
        $completed = 0;
        $overdue = [];

        foreach ($plans as $plan) {
            if ($plan->getStatus() === 'completed') {
                $completed++;
            } elseif ($plan->getTargetCompletionDate() && $plan->getTargetCompletionDate() < new \DateTime()) {
                $overdue[] = [
                    'id' => $plan->getId(),
                    'title' => $plan->getTitle(),
                    'due_date' => $plan->getTargetCompletionDate()->format('Y-m-d'),
                ];
            }
        }

        $score = $totalPlans > 0
            ? round(($completed / $totalPlans) * 100, 1)
            : 100;

        $gap = null;
        if (!empty($overdue)) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.treatment_plans_overdue', [], 'wizard'),
                'description' => $this->translator->trans(
                    'wizard.gap.treatment_plans_description',
                    ['%count%' => count($overdue)],
                    'wizard'
                ),
                'priority' => 'high',
                'action' => $this->translator->trans('wizard.action.complete_treatment', [], 'wizard'),
                'route' => 'app_risk_treatment_plan_index',
                'items' => array_slice($overdue, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalPlans,
                'completed' => $completed,
                'overdue' => count($overdue),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Get status label from score
     */
    private function getStatusFromScore(float $score): string
    {
        return match (true) {
            $score >= 95 => 'compliant',
            $score >= 75 => 'partial',
            $score >= 50 => 'in_progress',
            $score > 0 => 'needs_work',
            default => 'not_started',
        };
    }

    // ========================================
    // Framework-specific category definitions
    // ========================================

    /**
     * ISO 27001:2022 Categories
     *
     * Based on ISO/IEC 27001:2022 clauses:
     * - Clause 4: Context of the organization
     * - Clause 5: Leadership
     * - Clause 6: Planning
     * - Clause 7: Support
     * - Clause 8: Operation
     * - Clause 9: Performance evaluation
     * - Clause 10: Improvement
     * - Annex A: Information security controls (93 controls in 4 themes)
     */
    private function getIso27001Categories(): array
    {
        return [
            // Clause 4: Context of the organization
            'context' => [
                'name' => 'wizard.iso27001.context',
                'description' => 'wizard.iso27001.context_desc',
                'icon' => 'bi-diagram-3',
                'weight' => 1.5,
                'clause' => '4',
                'checks' => [
                    'understanding_organization' => [
                        'name' => 'wizard.check.iso_4_1_organization',
                        'description' => 'wizard.check.iso_4_1_organization_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'clause' => '4.1',
                        'route' => 'app_context_index',
                        'action' => 'wizard.action.define_context',
                    ],
                    'interested_parties' => [
                        'name' => 'wizard.check.iso_4_2_interested_parties',
                        'description' => 'wizard.check.iso_4_2_interested_parties_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'clause' => '4.2',
                        'route' => 'app_interested_party_index',
                        'action' => 'wizard.action.identify_parties',
                    ],
                    'isms_scope' => [
                        'name' => 'wizard.check.iso_4_3_scope',
                        'description' => 'wizard.check.iso_4_3_scope_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'clause' => '4.3',
                        'route' => 'app_context_index',
                        'action' => 'wizard.action.define_scope',
                    ],
                    'isms_established' => [
                        'name' => 'wizard.check.iso_4_4_isms',
                        'description' => 'wizard.check.iso_4_4_isms_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'clause' => '4.4',
                    ],
                ],
            ],

            // Clause 5: Leadership
            'leadership' => [
                'name' => 'wizard.iso27001.leadership',
                'description' => 'wizard.iso27001.leadership_desc',
                'icon' => 'bi-people',
                'weight' => 1.5,
                'clause' => '5',
                'checks' => [
                    'leadership_commitment' => [
                        'name' => 'wizard.check.iso_5_1_commitment',
                        'description' => 'wizard.check.iso_5_1_commitment_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'clause' => '5.1',
                    ],
                    'information_security_policy' => [
                        'name' => 'wizard.check.iso_5_2_policy',
                        'description' => 'wizard.check.iso_5_2_policy_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.1'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'clause' => '5.2',
                        'route' => 'app_soa_index',
                    ],
                    'roles_responsibilities' => [
                        'name' => 'wizard.check.iso_5_3_roles',
                        'description' => 'wizard.check.iso_5_3_roles_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.2', '5.3', '5.4'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'clause' => '5.3',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Clause 6: Planning
            'planning' => [
                'name' => 'wizard.iso27001.planning',
                'description' => 'wizard.iso27001.planning_desc',
                'icon' => 'bi-calendar-check',
                'weight' => 2,
                'clause' => '6',
                'checks' => [
                    'risk_assessment' => [
                        'name' => 'wizard.check.iso_6_1_1_risk_assessment',
                        'description' => 'wizard.check.iso_6_1_1_risk_assessment_desc',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'clause' => '6.1.1/6.1.2',
                        'route' => 'app_risk_index',
                    ],
                    'risk_treatment' => [
                        'name' => 'wizard.check.iso_6_1_3_treatment',
                        'description' => 'wizard.check.iso_6_1_3_treatment_desc',
                        'type' => 'treatment_plan',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'clause' => '6.1.3',
                        'route' => 'app_risk_treatment_plan_index',
                    ],
                    'statement_of_applicability' => [
                        'name' => 'wizard.check.iso_6_1_3_soa',
                        'description' => 'wizard.check.iso_6_1_3_soa_desc',
                        'type' => 'control_coverage',
                        'module' => 'controls',
                        'priority' => 'critical',
                        'clause' => '6.1.3 d)',
                        'route' => 'app_soa_index',
                    ],
                    'security_objectives' => [
                        'name' => 'wizard.check.iso_6_2_objectives',
                        'description' => 'wizard.check.iso_6_2_objectives_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'clause' => '6.2',
                        'route' => 'app_objective_index',
                        'action' => 'wizard.action.define_objectives',
                    ],
                    'planning_changes' => [
                        'name' => 'wizard.check.iso_6_3_changes',
                        'description' => 'wizard.check.iso_6_3_changes_desc',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'clause' => '6.3',
                    ],
                ],
            ],

            // Clause 7: Support
            'support' => [
                'name' => 'wizard.iso27001.support',
                'description' => 'wizard.iso27001.support_desc',
                'icon' => 'bi-tools',
                'weight' => 1.5,
                'clause' => '7',
                'checks' => [
                    'resources' => [
                        'name' => 'wizard.check.iso_7_1_resources',
                        'description' => 'wizard.check.iso_7_1_resources_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'clause' => '7.1',
                    ],
                    'competence' => [
                        'name' => 'wizard.check.iso_7_2_competence',
                        'description' => 'wizard.check.iso_7_2_competence_desc',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'priority' => 'high',
                        'clause' => '7.2',
                        'route' => 'app_training_index',
                    ],
                    'awareness' => [
                        'name' => 'wizard.check.iso_7_3_awareness',
                        'description' => 'wizard.check.iso_7_3_awareness_desc',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'priority' => 'high',
                        'clause' => '7.3',
                        'route' => 'app_training_index',
                    ],
                    'communication' => [
                        'name' => 'wizard.check.iso_7_4_communication',
                        'description' => 'wizard.check.iso_7_4_communication_desc',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'clause' => '7.4',
                    ],
                    'documented_information' => [
                        'name' => 'wizard.check.iso_7_5_documentation',
                        'description' => 'wizard.check.iso_7_5_documentation_desc',
                        'type' => 'document_review',
                        'priority' => 'high',
                        'clause' => '7.5',
                        'route' => 'app_document_index',
                    ],
                ],
            ],

            // Clause 8: Operation
            'operation' => [
                'name' => 'wizard.iso27001.operation',
                'description' => 'wizard.iso27001.operation_desc',
                'icon' => 'bi-gear',
                'weight' => 2,
                'clause' => '8',
                'checks' => [
                    'operational_planning' => [
                        'name' => 'wizard.check.iso_8_1_planning',
                        'description' => 'wizard.check.iso_8_1_planning_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'clause' => '8.1',
                    ],
                    'risk_assessment_execution' => [
                        'name' => 'wizard.check.iso_8_2_risk_exec',
                        'description' => 'wizard.check.iso_8_2_risk_exec_desc',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'clause' => '8.2',
                        'route' => 'app_risk_index',
                    ],
                    'risk_treatment_execution' => [
                        'name' => 'wizard.check.iso_8_3_treatment_exec',
                        'description' => 'wizard.check.iso_8_3_treatment_exec_desc',
                        'type' => 'treatment_plan',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'clause' => '8.3',
                        'route' => 'app_risk_treatment_plan_index',
                    ],
                ],
            ],

            // Clause 9: Performance evaluation
            'performance' => [
                'name' => 'wizard.iso27001.performance',
                'description' => 'wizard.iso27001.performance_desc',
                'icon' => 'bi-graph-up',
                'weight' => 1.5,
                'clause' => '9',
                'checks' => [
                    'monitoring_measurement' => [
                        'name' => 'wizard.check.iso_9_1_monitoring',
                        'description' => 'wizard.check.iso_9_1_monitoring_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'clause' => '9.1',
                    ],
                    'internal_audit' => [
                        'name' => 'wizard.check.iso_9_2_audit',
                        'description' => 'wizard.check.iso_9_2_audit_desc',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'priority' => 'critical',
                        'clause' => '9.2',
                        'route' => 'app_audit_index',
                    ],
                    'management_review' => [
                        'name' => 'wizard.check.iso_9_3_review',
                        'description' => 'wizard.check.iso_9_3_review_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'clause' => '9.3',
                        'route' => 'app_management_review_index',
                        'action' => 'wizard.action.management_review',
                    ],
                ],
            ],

            // Clause 10: Improvement
            'improvement' => [
                'name' => 'wizard.iso27001.improvement',
                'description' => 'wizard.iso27001.improvement_desc',
                'icon' => 'bi-arrow-up-circle',
                'weight' => 1,
                'clause' => '10',
                'checks' => [
                    'nonconformity' => [
                        'name' => 'wizard.check.iso_10_1_nonconformity',
                        'description' => 'wizard.check.iso_10_1_nonconformity_desc',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'priority' => 'high',
                        'clause' => '10.1',
                        'route' => 'app_audit_index',
                    ],
                    'continual_improvement' => [
                        'name' => 'wizard.check.iso_10_2_improvement',
                        'description' => 'wizard.check.iso_10_2_improvement_desc',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'clause' => '10.2',
                    ],
                ],
            ],

            // Annex A: Controls (4 Themes)
            'annex_a_organizational' => [
                'name' => 'wizard.iso27001.annex_a_organizational',
                'description' => 'wizard.iso27001.annex_a_organizational_desc',
                'icon' => 'bi-building',
                'weight' => 2,
                'clause' => 'A.5',
                'checks' => [
                    'organizational_controls' => [
                        'name' => 'wizard.check.iso_annex_a5',
                        'description' => 'wizard.check.iso_annex_a5_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.1', '5.2', '5.3', '5.4', '5.5', '5.6', '5.7', '5.8', '5.9', '5.10',
                            '5.11', '5.12', '5.13', '5.14', '5.15', '5.16', '5.17', '5.18', '5.19', '5.20',
                            '5.21', '5.22', '5.23', '5.24', '5.25', '5.26', '5.27', '5.28', '5.29', '5.30',
                            '5.31', '5.32', '5.33', '5.34', '5.35', '5.36', '5.37'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'annex_a_people' => [
                'name' => 'wizard.iso27001.annex_a_people',
                'description' => 'wizard.iso27001.annex_a_people_desc',
                'icon' => 'bi-person-badge',
                'weight' => 1.5,
                'clause' => 'A.6',
                'checks' => [
                    'people_controls' => [
                        'name' => 'wizard.check.iso_annex_a6',
                        'description' => 'wizard.check.iso_annex_a6_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['6.1', '6.2', '6.3', '6.4', '6.5', '6.6', '6.7', '6.8'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'annex_a_physical' => [
                'name' => 'wizard.iso27001.annex_a_physical',
                'description' => 'wizard.iso27001.annex_a_physical_desc',
                'icon' => 'bi-building-lock',
                'weight' => 1.5,
                'clause' => 'A.7',
                'checks' => [
                    'physical_controls' => [
                        'name' => 'wizard.check.iso_annex_a7',
                        'description' => 'wizard.check.iso_annex_a7_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['7.1', '7.2', '7.3', '7.4', '7.5', '7.6', '7.7', '7.8', '7.9', '7.10',
                            '7.11', '7.12', '7.13', '7.14'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'annex_a_technological' => [
                'name' => 'wizard.iso27001.annex_a_technological',
                'description' => 'wizard.iso27001.annex_a_technological_desc',
                'icon' => 'bi-cpu',
                'weight' => 2,
                'clause' => 'A.8',
                'checks' => [
                    'technological_controls' => [
                        'name' => 'wizard.check.iso_annex_a8',
                        'description' => 'wizard.check.iso_annex_a8_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.1', '8.2', '8.3', '8.4', '8.5', '8.6', '8.7', '8.8', '8.9', '8.10',
                            '8.11', '8.12', '8.13', '8.14', '8.15', '8.16', '8.17', '8.18', '8.19', '8.20',
                            '8.21', '8.22', '8.23', '8.24', '8.25', '8.26', '8.27', '8.28', '8.29', '8.30',
                            '8.31', '8.32', '8.33', '8.34'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Asset Management (supports Annex A)
            'asset_management' => [
                'name' => 'wizard.iso27001.asset_management',
                'description' => 'wizard.iso27001.asset_management_desc',
                'icon' => 'bi-hdd-stack',
                'weight' => 1.5,
                'checks' => [
                    'asset_inventory' => [
                        'name' => 'wizard.check.asset_inventory',
                        'description' => 'wizard.check.asset_inventory_desc',
                        'type' => 'asset_coverage',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],

            // Incident Management (supports A.5.24-A.5.28)
            'incident_management' => [
                'name' => 'wizard.iso27001.incident_management',
                'description' => 'wizard.iso27001.incident_management_desc',
                'icon' => 'bi-exclamation-circle',
                'weight' => 1.5,
                'checks' => [
                    'incident_process' => [
                        'name' => 'wizard.check.incident_management',
                        'description' => 'wizard.check.incident_management_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 72,
                        'module' => 'incidents',
                        'priority' => 'high',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],

            // Business Continuity (supports A.5.29-A.5.30)
            'business_continuity' => [
                'name' => 'wizard.iso27001.business_continuity',
                'description' => 'wizard.iso27001.business_continuity_desc',
                'icon' => 'bi-arrow-repeat',
                'weight' => 1.5,
                'checks' => [
                    'bcm_coverage' => [
                        'name' => 'wizard.check.bcm_coverage',
                        'description' => 'wizard.check.bcm_coverage_desc',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'priority' => 'high',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * NIS2 Directive (EU 2022/2555) Categories
     *
     * Based on NIS2 Articles:
     * - Article 20: Governance
     * - Article 21: Cybersecurity risk-management measures (10 areas)
     * - Article 23: Reporting obligations (24h/72h/1 month)
     * - Article 24: Use of European cybersecurity certification schemes
     */
    private function getNis2Categories(): array
    {
        return [
            // Article 20: Governance
            'governance' => [
                'name' => 'wizard.nis2.governance',
                'description' => 'wizard.nis2.governance_desc',
                'icon' => 'bi-building',
                'weight' => 1.5,
                'article' => '20',
                'checks' => [
                    'management_approval' => [
                        'name' => 'wizard.check.nis2_20_1_approval',
                        'description' => 'wizard.check.nis2_20_1_approval_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '20(1)',
                    ],
                    'management_training' => [
                        'name' => 'wizard.check.nis2_20_2_training',
                        'description' => 'wizard.check.nis2_20_2_training_desc',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'priority' => 'critical',
                        'article' => '20(2)',
                        'route' => 'app_training_index',
                    ],
                ],
            ],

            // Article 21(2)(a): Risk analysis and information system security policies
            'risk_policies' => [
                'name' => 'wizard.nis2.risk_policies',
                'description' => 'wizard.nis2.risk_policies_desc',
                'icon' => 'bi-file-earmark-text',
                'weight' => 2,
                'article' => '21(2)(a)',
                'checks' => [
                    'risk_analysis' => [
                        'name' => 'wizard.check.nis2_21_2a_risk',
                        'description' => 'wizard.check.nis2_21_2a_risk_desc',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'route' => 'app_risk_index',
                    ],
                    'security_policies' => [
                        'name' => 'wizard.check.nis2_21_2a_policies',
                        'description' => 'wizard.check.nis2_21_2a_policies_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.1', '5.2', '5.3'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(b): Incident handling
            'incident_handling' => [
                'name' => 'wizard.nis2.incident_handling',
                'description' => 'wizard.nis2.incident_handling_desc',
                'icon' => 'bi-exclamation-triangle',
                'weight' => 2,
                'article' => '21(2)(b)',
                'checks' => [
                    'incident_process' => [
                        'name' => 'wizard.check.nis2_21_2b_incident',
                        'description' => 'wizard.check.nis2_21_2b_incident_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 24,
                        'module' => 'incidents',
                        'priority' => 'critical',
                        'route' => 'app_incident_index',
                    ],
                    'incident_controls' => [
                        'name' => 'wizard.check.nis2_21_2b_controls',
                        'description' => 'wizard.check.nis2_21_2b_controls_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.24', '5.25', '5.26', '5.27', '5.28'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(c): Business continuity and crisis management
            'business_continuity' => [
                'name' => 'wizard.nis2.business_continuity',
                'description' => 'wizard.nis2.business_continuity_desc',
                'icon' => 'bi-arrow-repeat',
                'weight' => 2,
                'article' => '21(2)(c)',
                'checks' => [
                    'bcm_coverage' => [
                        'name' => 'wizard.check.nis2_21_2c_bcm',
                        'description' => 'wizard.check.nis2_21_2c_bcm_desc',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'priority' => 'critical',
                        'route' => 'app_bcm_index',
                    ],
                    'backup_recovery' => [
                        'name' => 'wizard.check.nis2_21_2c_backup',
                        'description' => 'wizard.check.nis2_21_2c_backup_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.13', '8.14'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                    'crisis_management' => [
                        'name' => 'wizard.check.nis2_21_2c_crisis',
                        'description' => 'wizard.check.nis2_21_2c_crisis_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.29', '5.30'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(d): Supply chain security
            'supply_chain' => [
                'name' => 'wizard.nis2.supply_chain',
                'description' => 'wizard.nis2.supply_chain_desc',
                'icon' => 'bi-truck',
                'weight' => 1.5,
                'article' => '21(2)(d)',
                'checks' => [
                    'supplier_security' => [
                        'name' => 'wizard.check.nis2_21_2d_supplier',
                        'description' => 'wizard.check.nis2_21_2d_supplier_desc',
                        'type' => 'supplier_assessment',
                        'module' => 'assets',
                        'priority' => 'high',
                        'route' => 'app_supplier_index',
                    ],
                    'supplier_controls' => [
                        'name' => 'wizard.check.nis2_21_2d_controls',
                        'description' => 'wizard.check.nis2_21_2d_controls_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.19', '5.20', '5.21', '5.22', '5.23'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(e): Security in network and information systems acquisition, development and maintenance
            'secure_development' => [
                'name' => 'wizard.nis2.secure_development',
                'description' => 'wizard.nis2.secure_development_desc',
                'icon' => 'bi-code-slash',
                'weight' => 1.5,
                'article' => '21(2)(e)',
                'checks' => [
                    'secure_development' => [
                        'name' => 'wizard.check.nis2_21_2e_development',
                        'description' => 'wizard.check.nis2_21_2e_development_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.25', '8.26', '8.27', '8.28', '8.29', '8.30', '8.31', '8.32', '8.33'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                    'vulnerability_handling' => [
                        'name' => 'wizard.check.nis2_21_2e_vulnerability',
                        'description' => 'wizard.check.nis2_21_2e_vulnerability_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.8'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_vulnerability_index',
                    ],
                ],
            ],

            // Article 21(2)(f): Policies and procedures for effectiveness assessment
            'effectiveness_assessment' => [
                'name' => 'wizard.nis2.effectiveness_assessment',
                'description' => 'wizard.nis2.effectiveness_assessment_desc',
                'icon' => 'bi-clipboard-check',
                'weight' => 1.5,
                'article' => '21(2)(f)',
                'checks' => [
                    'security_testing' => [
                        'name' => 'wizard.check.nis2_21_2f_testing',
                        'description' => 'wizard.check.nis2_21_2f_testing_desc',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'priority' => 'high',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],

            // Article 21(2)(g): Basic cyber hygiene practices and cybersecurity training
            'cyber_hygiene' => [
                'name' => 'wizard.nis2.cyber_hygiene',
                'description' => 'wizard.nis2.cyber_hygiene_desc',
                'icon' => 'bi-mortarboard',
                'weight' => 1.5,
                'article' => '21(2)(g)',
                'checks' => [
                    'awareness_training' => [
                        'name' => 'wizard.check.nis2_21_2g_training',
                        'description' => 'wizard.check.nis2_21_2g_training_desc',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'priority' => 'critical',
                        'route' => 'app_training_index',
                    ],
                    'hygiene_controls' => [
                        'name' => 'wizard.check.nis2_21_2g_hygiene',
                        'description' => 'wizard.check.nis2_21_2g_hygiene_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['6.3', '8.1', '8.5', '8.7'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(h): Policies and procedures regarding use of cryptography and encryption
            'cryptography' => [
                'name' => 'wizard.nis2.cryptography',
                'description' => 'wizard.nis2.cryptography_desc',
                'icon' => 'bi-lock',
                'weight' => 1.5,
                'article' => '21(2)(h)',
                'checks' => [
                    'crypto_controls' => [
                        'name' => 'wizard.check.nis2_21_2h_crypto',
                        'description' => 'wizard.check.nis2_21_2h_crypto_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.24'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(i): Human resources security, access control policies and asset management
            'access_and_assets' => [
                'name' => 'wizard.nis2.access_and_assets',
                'description' => 'wizard.nis2.access_and_assets_desc',
                'icon' => 'bi-key',
                'weight' => 2,
                'article' => '21(2)(i)',
                'checks' => [
                    'hr_security' => [
                        'name' => 'wizard.check.nis2_21_2i_hr',
                        'description' => 'wizard.check.nis2_21_2i_hr_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['6.1', '6.2', '6.4', '6.5', '6.6'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                    'access_control' => [
                        'name' => 'wizard.check.nis2_21_2i_access',
                        'description' => 'wizard.check.nis2_21_2i_access_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.15', '5.16', '5.17', '5.18', '8.2', '8.3', '8.4', '8.5'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                    'asset_management' => [
                        'name' => 'wizard.check.nis2_21_2i_assets',
                        'description' => 'wizard.check.nis2_21_2i_assets_desc',
                        'type' => 'asset_coverage',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],

            // Article 21(2)(j): Use of multi-factor authentication or continuous authentication solutions
            'mfa' => [
                'name' => 'wizard.nis2.mfa',
                'description' => 'wizard.nis2.mfa_desc',
                'icon' => 'bi-shield-lock',
                'weight' => 1.5,
                'article' => '21(2)(j)',
                'checks' => [
                    'mfa_implementation' => [
                        'name' => 'wizard.check.nis2_21_2j_mfa',
                        'description' => 'wizard.check.nis2_21_2j_mfa_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.5'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                    'secure_communications' => [
                        'name' => 'wizard.check.nis2_21_2j_comms',
                        'description' => 'wizard.check.nis2_21_2j_comms_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.20', '8.21', '8.22'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 23: Reporting obligations
            'reporting' => [
                'name' => 'wizard.nis2.reporting',
                'description' => 'wizard.nis2.reporting_desc',
                'icon' => 'bi-megaphone',
                'weight' => 2,
                'article' => '23',
                'checks' => [
                    'early_warning' => [
                        'name' => 'wizard.check.nis2_23_early_warning',
                        'description' => 'wizard.check.nis2_23_early_warning_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 24,
                        'module' => 'incidents',
                        'priority' => 'critical',
                        'article' => '23(4)(a)',
                        'route' => 'app_incident_index',
                    ],
                    'incident_notification' => [
                        'name' => 'wizard.check.nis2_23_notification',
                        'description' => 'wizard.check.nis2_23_notification_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '23(4)(b)',
                    ],
                    'final_report' => [
                        'name' => 'wizard.check.nis2_23_final_report',
                        'description' => 'wizard.check.nis2_23_final_report_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'article' => '23(4)(d)',
                    ],
                ],
            ],
        ];
    }

    /**
     * DORA (EU 2022/2554) Categories - Digital Operational Resilience Act
     *
     * Based on DORA's 5 pillars:
     * - Chapter II (Art. 5-16): ICT Risk Management
     * - Chapter III (Art. 17-23): ICT-related incident management and reporting
     * - Chapter IV (Art. 24-27): Digital operational resilience testing
     * - Chapter V (Art. 28-44): Managing of ICT third-party risk
     * - Chapter VI (Art. 45): Information-sharing arrangements
     */
    private function getDoraCategories(): array
    {
        return [
            // Chapter II, Section I: ICT Risk Management Framework (Art. 5-6)
            'governance' => [
                'name' => 'wizard.dora.governance',
                'description' => 'wizard.dora.governance_desc',
                'icon' => 'bi-building',
                'weight' => 2,
                'article' => '5-6',
                'checks' => [
                    'management_body' => [
                        'name' => 'wizard.check.dora_5_management',
                        'description' => 'wizard.check.dora_5_management_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '5(2)',
                    ],
                    'ict_risk_framework' => [
                        'name' => 'wizard.check.dora_6_framework',
                        'description' => 'wizard.check.dora_6_framework_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '6(1)',
                    ],
                    'ict_risk_strategy' => [
                        'name' => 'wizard.check.dora_6_strategy',
                        'description' => 'wizard.check.dora_6_strategy_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '6(8)',
                    ],
                ],
            ],

            // Chapter II, Section I: ICT Systems, Protocols and Tools (Art. 7)
            'ict_systems' => [
                'name' => 'wizard.dora.ict_systems',
                'description' => 'wizard.dora.ict_systems_desc',
                'icon' => 'bi-hdd-network',
                'weight' => 2,
                'article' => '7',
                'checks' => [
                    'ict_inventory' => [
                        'name' => 'wizard.check.dora_7_inventory',
                        'description' => 'wizard.check.dora_7_inventory_desc',
                        'type' => 'asset_coverage',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'article' => '7(1)',
                        'route' => 'app_asset_index',
                    ],
                    'network_security' => [
                        'name' => 'wizard.check.dora_7_network',
                        'description' => 'wizard.check.dora_7_network_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.20', '8.21', '8.22', '8.23'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '7(2)',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Identification (Art. 8)
            'identification' => [
                'name' => 'wizard.dora.identification',
                'description' => 'wizard.dora.identification_desc',
                'icon' => 'bi-search',
                'weight' => 2,
                'article' => '8',
                'checks' => [
                    'business_functions' => [
                        'name' => 'wizard.check.dora_8_functions',
                        'description' => 'wizard.check.dora_8_functions_desc',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'priority' => 'critical',
                        'article' => '8(1)',
                        'route' => 'app_bcm_index',
                    ],
                    'ict_assets' => [
                        'name' => 'wizard.check.dora_8_assets',
                        'description' => 'wizard.check.dora_8_assets_desc',
                        'type' => 'asset_coverage',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'article' => '8(2)',
                        'route' => 'app_asset_index',
                    ],
                    'risk_sources' => [
                        'name' => 'wizard.check.dora_8_risks',
                        'description' => 'wizard.check.dora_8_risks_desc',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'article' => '8(3)',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Protection and Prevention (Art. 9)
            'protection' => [
                'name' => 'wizard.dora.protection',
                'description' => 'wizard.dora.protection_desc',
                'icon' => 'bi-shield-check',
                'weight' => 2,
                'article' => '9',
                'checks' => [
                    'ict_security_policies' => [
                        'name' => 'wizard.check.dora_9_policies',
                        'description' => 'wizard.check.dora_9_policies_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.1', '5.2', '5.3'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '9(1)',
                        'route' => 'app_soa_index',
                    ],
                    'access_management' => [
                        'name' => 'wizard.check.dora_9_access',
                        'description' => 'wizard.check.dora_9_access_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.15', '5.16', '5.17', '5.18', '8.2', '8.3', '8.4', '8.5'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '9(4)',
                        'route' => 'app_soa_index',
                    ],
                    'cryptography' => [
                        'name' => 'wizard.check.dora_9_crypto',
                        'description' => 'wizard.check.dora_9_crypto_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.24'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'article' => '9(4)(d)',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Detection (Art. 10)
            'detection' => [
                'name' => 'wizard.dora.detection',
                'description' => 'wizard.dora.detection_desc',
                'icon' => 'bi-eye',
                'weight' => 1.5,
                'article' => '10',
                'checks' => [
                    'monitoring' => [
                        'name' => 'wizard.check.dora_10_monitoring',
                        'description' => 'wizard.check.dora_10_monitoring_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.15', '8.16'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '10(1)',
                        'route' => 'app_soa_index',
                    ],
                    'anomaly_detection' => [
                        'name' => 'wizard.check.dora_10_anomaly',
                        'description' => 'wizard.check.dora_10_anomaly_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.16'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'article' => '10(2)',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Response and Recovery (Art. 11)
            'response_recovery' => [
                'name' => 'wizard.dora.response_recovery',
                'description' => 'wizard.dora.response_recovery_desc',
                'icon' => 'bi-arrow-repeat',
                'weight' => 2,
                'article' => '11',
                'checks' => [
                    'ict_bcm_policy' => [
                        'name' => 'wizard.check.dora_11_bcm_policy',
                        'description' => 'wizard.check.dora_11_bcm_policy_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.29', '5.30'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '11(1)',
                        'route' => 'app_soa_index',
                    ],
                    'bcm_plans' => [
                        'name' => 'wizard.check.dora_11_plans',
                        'description' => 'wizard.check.dora_11_plans_desc',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'priority' => 'critical',
                        'article' => '11(3)',
                        'route' => 'app_bc_plan_index',
                    ],
                    'rto_rpo' => [
                        'name' => 'wizard.check.dora_11_rto_rpo',
                        'description' => 'wizard.check.dora_11_rto_rpo_desc',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'priority' => 'critical',
                        'article' => '11(4)',
                        'route' => 'app_bcm_index',
                    ],
                    'backup' => [
                        'name' => 'wizard.check.dora_11_backup',
                        'description' => 'wizard.check.dora_11_backup_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.13', '8.14'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '11(5)',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Learning and Evolving (Art. 13)
            'learning' => [
                'name' => 'wizard.dora.learning',
                'description' => 'wizard.dora.learning_desc',
                'icon' => 'bi-lightbulb',
                'weight' => 1.5,
                'article' => '13',
                'checks' => [
                    'post_incident_review' => [
                        'name' => 'wizard.check.dora_13_review',
                        'description' => 'wizard.check.dora_13_review_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 720, // Post-incident review within 30 days
                        'module' => 'incidents',
                        'priority' => 'high',
                        'article' => '13(1)',
                        'route' => 'app_incident_index',
                    ],
                    'training' => [
                        'name' => 'wizard.check.dora_13_training',
                        'description' => 'wizard.check.dora_13_training_desc',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'priority' => 'high',
                        'article' => '13(6)',
                        'route' => 'app_training_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Communication (Art. 14)
            'communication' => [
                'name' => 'wizard.dora.communication',
                'description' => 'wizard.dora.communication_desc',
                'icon' => 'bi-megaphone',
                'weight' => 1,
                'article' => '14',
                'checks' => [
                    'communication_plans' => [
                        'name' => 'wizard.check.dora_14_plans',
                        'description' => 'wizard.check.dora_14_plans_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'article' => '14(1)',
                    ],
                ],
            ],

            // Chapter III: ICT-related Incident Management (Art. 17)
            'incident_management' => [
                'name' => 'wizard.dora.incident_management',
                'description' => 'wizard.dora.incident_management_desc',
                'icon' => 'bi-exclamation-triangle',
                'weight' => 2,
                'article' => '17',
                'checks' => [
                    'incident_process' => [
                        'name' => 'wizard.check.dora_17_process',
                        'description' => 'wizard.check.dora_17_process_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 4,
                        'module' => 'incidents',
                        'priority' => 'critical',
                        'article' => '17(1)',
                        'route' => 'app_incident_index',
                    ],
                    'incident_classification' => [
                        'name' => 'wizard.check.dora_17_classification',
                        'description' => 'wizard.check.dora_17_classification_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '17(2)',
                    ],
                ],
            ],

            // Chapter III: Incident Classification (Art. 18)
            'incident_classification' => [
                'name' => 'wizard.dora.incident_classification',
                'description' => 'wizard.dora.incident_classification_desc',
                'icon' => 'bi-tags',
                'weight' => 1.5,
                'article' => '18',
                'checks' => [
                    'classification_criteria' => [
                        'name' => 'wizard.check.dora_18_criteria',
                        'description' => 'wizard.check.dora_18_criteria_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '18(1)',
                    ],
                ],
            ],

            // Chapter III: Incident Reporting (Art. 19)
            'incident_reporting' => [
                'name' => 'wizard.dora.incident_reporting',
                'description' => 'wizard.dora.incident_reporting_desc',
                'icon' => 'bi-clipboard-data',
                'weight' => 2,
                'article' => '19',
                'checks' => [
                    'initial_notification' => [
                        'name' => 'wizard.check.dora_19_initial',
                        'description' => 'wizard.check.dora_19_initial_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 4,
                        'module' => 'incidents',
                        'priority' => 'critical',
                        'article' => '19(4)(a)',
                        'route' => 'app_incident_index',
                    ],
                    'intermediate_report' => [
                        'name' => 'wizard.check.dora_19_intermediate',
                        'description' => 'wizard.check.dora_19_intermediate_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '19(4)(b)',
                    ],
                    'final_report' => [
                        'name' => 'wizard.check.dora_19_final',
                        'description' => 'wizard.check.dora_19_final_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '19(4)(c)',
                    ],
                ],
            ],

            // Chapter IV: Digital Operational Resilience Testing (Art. 24-25)
            'resilience_testing' => [
                'name' => 'wizard.dora.resilience_testing',
                'description' => 'wizard.dora.resilience_testing_desc',
                'icon' => 'bi-bug',
                'weight' => 2,
                'article' => '24-25',
                'checks' => [
                    'testing_program' => [
                        'name' => 'wizard.check.dora_24_program',
                        'description' => 'wizard.check.dora_24_program_desc',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'priority' => 'critical',
                        'article' => '24(1)',
                        'route' => 'app_audit_index',
                    ],
                    'vulnerability_assessment' => [
                        'name' => 'wizard.check.dora_25_vulnerability',
                        'description' => 'wizard.check.dora_25_vulnerability_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.8'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '25(1)',
                        'route' => 'app_vulnerability_index',
                    ],
                    'penetration_testing' => [
                        'name' => 'wizard.check.dora_25_pentest',
                        'description' => 'wizard.check.dora_25_pentest_desc',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'priority' => 'high',
                        'article' => '25(1)',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],

            // Chapter IV: TLPT (Art. 26-27) - Threat-Led Penetration Testing
            'tlpt' => [
                'name' => 'wizard.dora.tlpt',
                'description' => 'wizard.dora.tlpt_desc',
                'icon' => 'bi-shield-exclamation',
                'weight' => 1.5,
                'article' => '26-27',
                'checks' => [
                    'tlpt_program' => [
                        'name' => 'wizard.check.dora_26_tlpt',
                        'description' => 'wizard.check.dora_26_tlpt_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'article' => '26(1)',
                    ],
                ],
            ],

            // Chapter V: Third-Party Risk Management (Art. 28-30)
            'third_party_risk' => [
                'name' => 'wizard.dora.third_party_risk',
                'description' => 'wizard.dora.third_party_risk_desc',
                'icon' => 'bi-people',
                'weight' => 2,
                'article' => '28-30',
                'checks' => [
                    'third_party_strategy' => [
                        'name' => 'wizard.check.dora_28_strategy',
                        'description' => 'wizard.check.dora_28_strategy_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '28(2)',
                    ],
                    'ict_concentration_risk' => [
                        'name' => 'wizard.check.dora_29_concentration',
                        'description' => 'wizard.check.dora_29_concentration_desc',
                        'type' => 'supplier_assessment',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'article' => '29(1)',
                        'route' => 'app_supplier_index',
                    ],
                    'register_of_information' => [
                        'name' => 'wizard.check.dora_28_register',
                        'description' => 'wizard.check.dora_28_register_desc',
                        'type' => 'supplier_assessment',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'article' => '28(3)',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],

            // Chapter V: Contractual Arrangements (Art. 30)
            'contractual' => [
                'name' => 'wizard.dora.contractual',
                'description' => 'wizard.dora.contractual_desc',
                'icon' => 'bi-file-earmark-text',
                'weight' => 1.5,
                'article' => '30',
                'checks' => [
                    'contract_requirements' => [
                        'name' => 'wizard.check.dora_30_contracts',
                        'description' => 'wizard.check.dora_30_contracts_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'article' => '30(2)',
                    ],
                    'exit_strategies' => [
                        'name' => 'wizard.check.dora_30_exit',
                        'description' => 'wizard.check.dora_30_exit_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'article' => '30(3)(f)',
                    ],
                ],
            ],
        ];
    }

    private function getTisaxCategories(): array
    {
        return [
            'information_security' => [
                'name' => 'wizard.tisax.information_security',
                'description' => 'wizard.tisax.information_security_desc',
                'icon' => 'bi-shield-lock',
                'weight' => 2,
                'checks' => [
                    'isms_controls' => [
                        'name' => 'wizard.check.tisax_controls',
                        'type' => 'control_coverage',
                        'module' => 'controls',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'prototype_protection' => [
                'name' => 'wizard.tisax.prototype_protection',
                'description' => 'wizard.tisax.prototype_protection_desc',
                'icon' => 'bi-car-front',
                'weight' => 1.5,
                'checks' => [
                    'asset_protection' => [
                        'name' => 'wizard.check.tisax_assets',
                        'type' => 'asset_coverage',
                        'module' => 'assets',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'data_protection' => [
                'name' => 'wizard.tisax.data_protection',
                'description' => 'wizard.tisax.data_protection_desc',
                'icon' => 'bi-person-lock',
                'weight' => 1.5,
                'checks' => [
                    'privacy_controls' => [
                        'name' => 'wizard.check.tisax_privacy',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.34', '5.35', '5.36'],
                        'module' => 'controls',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
        ];
    }

    private function getGdprCategories(): array
    {
        return [
            'lawfulness' => [
                'name' => 'wizard.gdpr.lawfulness',
                'description' => 'wizard.gdpr.lawfulness_desc',
                'icon' => 'bi-file-earmark-text',
                'weight' => 2,
                'checks' => [
                    'processing_records' => [
                        'name' => 'wizard.check.gdpr_processing',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'description' => 'wizard.check.gdpr_processing_desc',
                    ],
                ],
            ],
            'data_subject_rights' => [
                'name' => 'wizard.gdpr.data_subject_rights',
                'description' => 'wizard.gdpr.data_subject_rights_desc',
                'icon' => 'bi-person-check',
                'weight' => 1.5,
                'checks' => [
                    'rights_process' => [
                        'name' => 'wizard.check.gdpr_rights',
                        'type' => 'manual',
                        'priority' => 'high',
                    ],
                ],
            ],
            'security_measures' => [
                'name' => 'wizard.gdpr.security_measures',
                'description' => 'wizard.gdpr.security_measures_desc',
                'icon' => 'bi-shield-lock',
                'weight' => 2,
                'checks' => [
                    'tom' => [
                        'name' => 'wizard.check.gdpr_tom',
                        'type' => 'control_coverage',
                        'module' => 'controls',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'breach_notification' => [
                'name' => 'wizard.gdpr.breach_notification',
                'description' => 'wizard.gdpr.breach_notification_desc',
                'icon' => 'bi-exclamation-triangle',
                'weight' => 1.5,
                'checks' => [
                    'breach_process' => [
                        'name' => 'wizard.check.gdpr_breach',
                        'type' => 'incident_process',
                        'sla_hours' => 72, // GDPR: 72h
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'awareness' => [
                'name' => 'wizard.gdpr.awareness',
                'description' => 'wizard.gdpr.awareness_desc',
                'icon' => 'bi-mortarboard',
                'weight' => 1,
                'checks' => [
                    'training' => [
                        'name' => 'wizard.check.gdpr_training',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'route' => 'app_training_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get quick status overview for all available wizards
     *
     * Useful for dashboard widgets to show compliance status at a glance.
     * Performs a lightweight assessment without full details.
     *
     * @param Tenant|null $tenant Optional tenant context
     * @return array Quick status for each available wizard
     */
    public function getQuickStatus(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenantContext->getCurrentTenant();
        $wizards = $this->getAvailableWizards();
        $results = [];

        foreach ($wizards as $key => $config) {
            // Perform quick assessment (cached/lightweight)
            $assessment = $this->runAssessment($key, $tenant);

            if ($assessment['success']) {
                $results[$key] = [
                    'key' => $key,
                    'code' => $config['code'],
                    'name' => $config['name'],
                    'icon' => $config['icon'],
                    'color' => $config['color'],
                    'score' => $assessment['overall_score'],
                    'status' => $assessment['status'],
                    'critical_gaps' => $assessment['critical_gap_count'],
                    'route' => 'app_compliance_wizard_assess',
                    'route_params' => ['wizard' => $key],
                ];
            }
        }

        return $results;
    }

    /**
     * Get overall compliance summary across all frameworks
     *
     * @param Tenant|null $tenant Optional tenant context
     * @return array Overall compliance metrics
     */
    public function getOverallComplianceSummary(?Tenant $tenant = null): array
    {
        $quickStatus = $this->getQuickStatus($tenant);

        if (empty($quickStatus)) {
            return [
                'has_frameworks' => false,
                'average_score' => 0,
                'status' => 'not_available',
                'frameworks_count' => 0,
                'critical_gaps_total' => 0,
            ];
        }

        $totalScore = 0;
        $criticalGaps = 0;
        $statusCounts = [
            'compliant' => 0,
            'partial' => 0,
            'in_progress' => 0,
            'non_compliant' => 0,
        ];

        foreach ($quickStatus as $wizard) {
            $totalScore += $wizard['score'];
            $criticalGaps += $wizard['critical_gaps'];
            $statusCounts[$wizard['status']] = ($statusCounts[$wizard['status']] ?? 0) + 1;
        }

        $count = count($quickStatus);
        $averageScore = round($totalScore / $count, 1);

        // Determine overall status based on average
        $overallStatus = match (true) {
            $averageScore >= 95 => 'compliant',
            $averageScore >= 75 => 'partial',
            $averageScore >= 50 => 'in_progress',
            default => 'non_compliant',
        };

        return [
            'has_frameworks' => true,
            'average_score' => $averageScore,
            'status' => $overallStatus,
            'frameworks_count' => $count,
            'critical_gaps_total' => $criticalGaps,
            'frameworks' => $quickStatus,
            'status_breakdown' => $statusCounts,
        ];
    }
}

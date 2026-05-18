<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Enum\InternalAuditStatus;
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
use App\Repository\ConsentRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmBiaMethodologyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmCrisisManagementPlanPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmExerciseProgrammeActiveCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmManagementReviewBcmCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmRecoveryPlansPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmsScopeStatementPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmTopLevelPolicyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiBaselineCoverageCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiIsmsConceptPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiKritisFlagDocumentedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiSchutzbedarfMethodPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiTierConsistencyCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiTopLevelLeitliniePresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraExitStrategyDocumentedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraExtensionCoverageCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraIctRiskFrameworkPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraIncidentReportingDeadlinesCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraThirdPartyRegisterMaintainedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraTlptCadenceCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraValidityFromCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701ClauseTagsAppliedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701SchremsIIClauseInTransfersCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701VersionConfiguredCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckRegistry;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardTopicCatalogue;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\A534ThinHostPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DataBreachNotification72hCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DpiaMethodologyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DpoCharterAppointedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DsrProcedurePresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\GdprSectionCoverageCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\PrivacyPolicyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\RopaMethodologyPresentCheck;
use App\Service\TenantSettingResolver\PolicySettingProvider;
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
final class ComplianceWizardService
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
        private readonly ConsentRepository $consentRepository,
        private readonly DataSubjectRequestRepository $dataSubjectRequestRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
        private readonly PolicyWizardCheckRegistry $policyWizardCheckRegistry,
        private readonly ?\App\Repository\ComplianceRequirementRepository $requirementRepository = null,
        private readonly ?\App\Repository\TenantPolicySettingRepository $tenantPolicySettingRepository = null,
        private readonly ?PolicySettingProvider $policySettingProvider = null,
    ) {
    }

    /**
     * Catalogue coverage: how many ComplianceRequirements of the framework
     * are fulfilled by the tenant (via ComplianceRequirementFulfillment).
     *
     * @return array{total: int, covered: int, percent: float}
     */
    private function getCatalogueCoverage(string $frameworkCode, ?Tenant $tenant): array
    {
        if ($this->requirementRepository === null) {
            return ['total' => 0, 'covered' => 0, 'percent' => 0.0];
        }
        $framework = $this->frameworkRepository->findOneBy(['code' => $frameworkCode]);
        if ($framework === null) {
            return ['total' => 0, 'covered' => 0, 'percent' => 0.0];
        }
        $total = count($this->requirementRepository->findBy(['framework' => $framework]));
        if ($total === 0 || $tenant === null) {
            return ['total' => $total, 'covered' => 0, 'percent' => 0.0];
        }
        $covered = $this->fulfillmentRepository->count([
            'tenant' => $tenant,
            'status' => ['implemented', 'verified'],
        ]);
        // Constrain covered count to never exceed total (defensive).
        $covered = min($covered, $total);
        $percent = round(($covered / $total) * 100, 1);
        return ['total' => $total, 'covered' => $covered, 'percent' => $percent];
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
                'icon' => 'shield-check',
                'color' => 'primary',
                'required_modules' => ['controls'],
                'recommended_modules' => ['risks', 'assets', 'audits'],
                'categories' => $this->getIso27001Categories(),
            ],
            'nis2' => [
                'code' => 'NIS2',
                'name' => 'NIS2 Compliance',
                'description' => 'wizard.nis2.description',
                'icon' => 'nav-shield-alert',
                'color' => 'warning',
                'required_modules' => ['incidents', 'controls'],
                'recommended_modules' => ['authentication', 'assets'],
                'categories' => $this->getNis2Categories(),
            ],
            'dora' => [
                'code' => 'DORA',
                'name' => 'DORA Readiness',
                'description' => 'wizard.dora.description',
                'icon' => 'nav-building',
                'color' => 'info',
                'required_modules' => ['bcm', 'incidents', 'controls'],
                'recommended_modules' => ['assets', 'risks'],
                'categories' => $this->getDoraCategories(),
            ],
            'tisax' => [
                'code' => 'TISAX',
                'name' => 'TISAX Assessment',
                'description' => 'wizard.tisax.description',
                'icon' => 'nav-truck',
                'color' => 'secondary',
                'required_modules' => ['controls', 'assets'],
                'recommended_modules' => ['risks'],
                'categories' => $this->getTisaxCategories(),
            ],
            'gdpr' => [
                'code' => 'GDPR',
                'name' => 'GDPR/DSGVO Compliance',
                'description' => 'wizard.gdpr.description',
                'icon' => 'nav-people',
                'color' => 'success',
                'required_modules' => ['controls'],
                'recommended_modules' => ['incidents', 'training'],
                'categories' => $this->getGdprCategories(),
            ],
            'iso22301' => [
                'code' => 'ISO22301',
                'name' => 'ISO 22301:2019 BCM Readiness',
                'description' => 'wizard.iso22301.description',
                'icon' => 'recovery',
                'color' => 'danger',
                'required_modules' => ['bcm'],
                'recommended_modules' => ['risks', 'audits', 'documents'],
                'categories' => $this->getIso22301Categories(),
            ],
            'iso27701' => [
                'code' => 'ISO27701',
                'name' => 'ISO 27701:2019 PIMS Readiness',
                'description' => 'wizard.iso27701.description',
                'icon' => 'shield-check',
                'color' => 'primary',
                'required_modules' => ['controls'],
                'recommended_modules' => ['privacy', 'incidents', 'suppliers'],
                'categories' => $this->getIso27701Categories(),
            ],
            'iso27017' => [
                'code' => 'ISO27017',
                'name' => 'ISO 27017:2015 Cloud Security',
                'description' => 'wizard.iso27017.description',
                'icon' => 'asset-cloud',
                'color' => 'info',
                'required_modules' => ['controls'],
                'recommended_modules' => ['assets', 'suppliers'],
                'categories' => $this->getIso27017Categories(),
            ],
            'iso27018' => [
                'code' => 'ISO27018',
                'name' => 'ISO 27018:2019 Cloud Privacy',
                'description' => 'wizard.iso27018.description',
                'icon' => 'asset-cloud',
                'color' => 'success',
                'required_modules' => ['controls'],
                'recommended_modules' => ['privacy', 'suppliers'],
                'categories' => $this->getIso27018Categories(),
            ],
            'iso42001' => [
                'code' => 'ISO42001',
                'name' => 'ISO 42001:2023 AI Management',
                'description' => 'wizard.iso42001.description',
                'icon' => 'nav-robot',
                'color' => 'warning',
                'required_modules' => ['controls'],
                'recommended_modules' => ['risks', 'assets'],
                'categories' => $this->getIso42001Categories(),
            ],
            'bsi_grundschutz' => [
                'code' => 'BSI-GRUNDSCHUTZ',
                'name' => 'BSI IT-Grundschutz (Basis-Absicherung)',
                'description' => 'wizard.bsi_grundschutz.description',
                'icon' => 'ui-flag',
                'color' => 'danger',
                'required_modules' => ['controls'],
                'recommended_modules' => ['assets', 'risks', 'audits', 'bcm'],
                'categories' => $this->getBsiGrundschutzCategories(),
            ],
            'bsi_c5' => [
                'code' => 'BSI-C5',
                'name' => 'BSI C5:2020 Cloud Compliance',
                'description' => 'wizard.bsi_c5.description',
                'icon' => 'asset-cloud',
                'color' => 'info',
                'required_modules' => ['controls'],
                'recommended_modules' => ['suppliers', 'assets', 'incidents'],
                'categories' => $this->getBsiC5Categories(),
            ],
            'bsi_c5_2026' => [
                'code' => 'BSI-C5-2026',
                'name' => 'BSI C5:2026 Cloud Compliance Criteria Catalogue',
                'description' => 'wizard.bsi_c5_2026.description',
                'icon' => 'asset-cloud',
                'color' => 'primary',
                'required_modules' => ['controls'],
                'recommended_modules' => ['suppliers', 'assets', 'incidents', 'crypto', 'training'],
                'categories' => $this->getBsiC52026Categories(),
            ],
            'bsi_grundschutz_standard' => [
                'code' => 'BSI-GRUNDSCHUTZ-STANDARD',
                'name' => 'BSI IT-Grundschutz (Standard-Absicherung)',
                'description' => 'wizard.bsi_grundschutz_standard.description',
                'icon' => 'ui-flag',
                'color' => 'dark',
                'required_modules' => ['controls'],
                'recommended_modules' => ['assets', 'risks', 'audits', 'bcm', 'documents'],
                'categories' => $this->getBsiGrundschutzStandardCategories(),
            ],
            'bsi_grundschutz_kern' => [
                'code' => 'BSI-GRUNDSCHUTZ-KERN',
                'name' => 'BSI IT-Grundschutz (Kern-Absicherung)',
                'description' => 'wizard.bsi_grundschutz_kern.description',
                'icon' => 'ui-flag',
                'color' => 'dark',
                'required_modules' => ['controls'],
                'recommended_modules' => ['assets', 'risks'],
                'categories' => $this->getBsiGrundschutzKernCategories(),
            ],
            'nist_csf' => [
                'code' => 'NIST-CSF-2.0',
                'name' => 'NIST Cybersecurity Framework 2.0',
                'description' => 'wizard.nist_csf.description',
                'icon' => 'shield-check',
                'color' => 'primary',
                'required_modules' => ['controls'],
                'recommended_modules' => ['risks', 'assets', 'incidents', 'bcm'],
                'categories' => $this->getNistCsfCategories(),
            ],
            'kritis' => [
                'code' => 'KRITIS-DE',
                'name' => 'KRITIS / NIS2-DE-Umsetzung',
                'description' => 'wizard.kritis.description',
                'icon' => 'nav-building',
                'color' => 'warning',
                'required_modules' => ['controls'],
                'recommended_modules' => ['incidents', 'bcm', 'risks', 'audits'],
                'categories' => $this->getKritisCategories(),
            ],
            'pci_dss' => [
                'code' => 'PCI-DSS-4.0.1',
                'name' => 'PCI-DSS v4.0.1 (Payment Card Industry)',
                'description' => 'wizard.pci_dss.description',
                'icon' => 'data-personal',
                'color' => 'info',
                'required_modules' => ['controls'],
                'recommended_modules' => ['assets', 'risks', 'incidents', 'audits'],
                'categories' => $this->getPciDssCategories(),
            ],
            'soc2' => [
                'code' => 'SOC2-TYPE-II',
                'name' => 'SOC 2 Type II (AICPA Trust Services)',
                'description' => 'wizard.soc2.description',
                'icon' => 'nav-patch-check',
                'color' => 'success',
                'required_modules' => ['controls'],
                'recommended_modules' => ['incidents', 'bcm', 'risks', 'audits', 'suppliers'],
                'categories' => $this->getSoc2Categories(),
            ],
            'eu_ai_act' => [
                'code' => 'EU-AI-ACT',
                'name' => 'EU AI Act (Verordnung 2024/1689)',
                'description' => 'wizard.eu_ai_act.description',
                'icon' => 'cpu',
                'color' => 'warning',
                'required_modules' => ['controls'],
                'recommended_modules' => ['risks', 'assets', 'incidents', 'documents'],
                'categories' => $this->getEuAiActCategories(),
            ],
            'eucs' => [
                'code' => 'ENISA-EUCS',
                'name' => 'ENISA EUCS (European Cybersecurity Certification Scheme for Cloud Services)',
                'description' => 'wizard.eucs.description',
                'icon' => 'nav-shield',
                'color' => 'info',
                'required_modules' => ['controls'],
                'recommended_modules' => ['suppliers', 'assets', 'incidents', 'crypto', 'bcm'],
                'categories' => $this->getEucsCategories(),
            ],
            'cra' => [
                'code' => 'EU-CRA',
                'name' => 'EU Cyber Resilience Act (Verordnung 2024/2847)',
                'description' => 'wizard.cra.description',
                'icon' => 'bug',
                'color' => 'danger',
                'required_modules' => ['controls'],
                'recommended_modules' => ['vulnerability_intel', 'incidents', 'assets', 'documents'],
                'categories' => $this->getCraCategories(),
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
            'catalogue_coverage' => $this->getCatalogueCoverage($config['code'], $tenant),
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
            'icon' => $category['icon'] ?? 'status-ok',
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

            case 'consent_coverage':
                $result = $this->checkConsentCoverage($check, $tenant);
                break;

            case 'dsr_coverage':
                $result = $this->checkDsrCoverage($check, $tenant);
                break;

            case 'dpia_coverage':
                $result = $this->checkDpiaCoverage($check, $tenant);
                break;

            case 'policy_wizard':
                $result = $this->dispatchPolicyWizardCheck($check, $tenant);
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
            $controls = $tenant
                ? $this->controlRepository->findApplicableControls($tenant)
                : [];
        } else {
            $controls = $tenant
                ? $this->controlRepository->findByControlIds($tenant, $controlIds)
                : [];
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
            if ($treatment !== null) {
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
            if ($audit->getStatus() === InternalAuditStatus::Completed->value) {
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
     * Counts active vs. revoked consents (GDPR Art. 6/7 + ISO 27701 7.2.4).
     * Score = % of consents that are not revoked, rounded to 1 decimal.
     *
     * Semantic divergence from sibling checks like checkRiskCoverage which
     * return score=100 when no entities exist: for consent-tracking we
     * treat "zero consents" as a gap because GDPR Art. 6/7 requires
     * demonstrable consent records for any tenant processing personal data
     * on consent legal basis. Tenants on other legal bases (contract,
     * legitimate interest) can ignore this wizard category — the gap is
     * informational, not a hard fail.
     */
    private function checkConsentCoverage(array $check, ?Tenant $tenant): array
    {
        $qbTotal = $this->consentRepository->createQueryBuilder('c')->select('COUNT(c.id)');
        $qbActive = $this->consentRepository->createQueryBuilder('c')->select('COUNT(c.id)')
            ->where('c.isRevoked = :revoked')->setParameter('revoked', false);
        if ($tenant !== null) {
            $qbTotal->andWhere('c.tenant = :t')->setParameter('t', $tenant);
            $qbActive->andWhere('c.tenant = :t')->setParameter('t', $tenant);
        }

        $total = (int) $qbTotal->getQuery()->getSingleScalarResult();
        $active = (int) $qbActive->getQuery()->getSingleScalarResult();

        if ($total === 0) {
            return [
                'score' => 0,
                'details' => ['total' => 0, 'active' => 0],
                'gap' => [
                    'title' => $this->translator->trans('wizard.gap.no_consents', [], 'wizard'),
                    'description' => $this->translator->trans('wizard.gap.no_consents_desc', [], 'wizard'),
                    'priority' => 'high',
                    'route' => $check['route'] ?? 'app_consent_index',
                ],
            ];
        }

        $score = round(($active / $total) * 100, 1);

        $gap = null;
        if ($score < 90) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.consent_revoked_high', [], 'wizard'),
                'description' => $this->translator->trans('wizard.gap.consent_revoked_high_desc', [], 'wizard'),
                'priority' => 'medium',
                'route' => $check['route'] ?? 'app_consent_index',
            ];
        }

        return [
            'score' => $score,
            'details' => ['total' => $total, 'active' => $active],
            'gap' => $gap,
        ];
    }

    /**
     * Counts open vs. completed Data-Subject Requests (GDPR Art. 12-22).
     * Score = % of requests in terminal status (completed or rejected),
     * rounded to 1 decimal. When zero requests exist, score=100 + an
     * advisory gap is emitted because GDPR doesn't require requests but
     * the absence of any DSR records suggests the process is untested.
     */
    private function checkDsrCoverage(array $check, ?Tenant $tenant): array
    {
        $qbTotal = $this->dataSubjectRequestRepository->createQueryBuilder('d')->select('COUNT(d.id)');
        $qbDone = $this->dataSubjectRequestRepository->createQueryBuilder('d')->select('COUNT(d.id)')
            ->where('d.status IN (:done)')->setParameter('done', ['completed', 'rejected']);
        if ($tenant !== null) {
            $qbTotal->andWhere('d.tenant = :t')->setParameter('t', $tenant);
            $qbDone->andWhere('d.tenant = :t')->setParameter('t', $tenant);
        }

        $total = (int) $qbTotal->getQuery()->getSingleScalarResult();
        $done = (int) $qbDone->getQuery()->getSingleScalarResult();

        if ($total === 0) {
            return [
                'score' => 100,
                'details' => ['total' => 0, 'completed' => 0, 'message' => 'no_requests_yet'],
                'gap' => [
                    'title' => $this->translator->trans('wizard.gap.no_dsr', [], 'wizard'),
                    'description' => $this->translator->trans('wizard.gap.no_dsr_desc', [], 'wizard'),
                    'priority' => 'medium',
                    'route' => $check['route'] ?? 'app_data_subject_request_index',
                ],
            ];
        }

        $score = round(($done / $total) * 100, 1);

        $gap = null;
        if ($score < 90) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.dsr_open_high', [], 'wizard'),
                'description' => $this->translator->trans('wizard.gap.dsr_open_high_desc', [], 'wizard'),
                'priority' => 'high',
                'route' => $check['route'] ?? 'app_data_subject_request_index',
            ];
        }

        return [
            'score' => $score,
            'details' => ['total' => $total, 'completed' => $done],
            'gap' => $gap,
        ];
    }

    /**
     * Of all ProcessingActivity rows flagged isHighRisk=true (= DPIA required
     * per Art. 35 DSGVO), how many have at least one linked Risk row whose
     * requiresDPIA flag is also true? Lower bound: 0 (no DPIA performed).
     * Upper bound: 100 (every high-risk activity has a documented DPIA risk).
     * When no high-risk activities exist, score=100 (vacuously true).
     */
    private function checkDpiaCoverage(array $check, ?Tenant $tenant): array
    {
        $qbHighRisk = $this->processingActivityRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.isHighRisk = :hr')->setParameter('hr', true);
        if ($tenant !== null) {
            $qbHighRisk->andWhere('p.tenant = :t')->setParameter('t', $tenant);
        }
        $highRisk = (int) $qbHighRisk->getQuery()->getSingleScalarResult();

        if ($highRisk === 0) {
            return [
                'score' => 100,
                'details' => ['high_risk_activities' => 0, 'documented_dpias' => 0],
                'gap' => null,
            ];
        }

        $qbDpia = $this->riskRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.requiresDPIA = :rq')->setParameter('rq', true);
        if ($tenant !== null) {
            $qbDpia->andWhere('r.tenant = :t')->setParameter('t', $tenant);
        }
        $documented = (int) $qbDpia->getQuery()->getSingleScalarResult();

        // Cap at 100 — a tenant might over-document (more DPIA-risks than
        // high-risk activities); we don't want a score >100.
        $score = round(min(100, ($documented / $highRisk) * 100), 1);

        $gap = null;
        if ($score < 100) {
            $gap = [
                'title' => $this->translator->trans('wizard.gap.dpia_missing', [], 'wizard'),
                'description' => $this->translator->trans('wizard.gap.dpia_missing_desc', [], 'wizard'),
                'priority' => 'critical',
                'route' => $check['route'] ?? 'app_processing_activity_index',
            ];
        }

        return [
            'score' => $score,
            'details' => ['high_risk_activities' => $highRisk, 'documented_dpias' => $documented],
            'gap' => $gap,
        ];
    }

    /**
     * Dispatch a `policy_wizard` check-type entry through the
     * {@see PolicyWizardCheckRegistry}.
     *
     * Reads the `check_id` (e.g. `policy_top_level_present`,
     * `policy_topic_access_control_present`) from the category-row, resolves
     * the matching {@see PolicyWizardCheckInterface} implementation and
     * adapts the {@see PolicyWizardCheckResult} into the legacy
     * `runCheck()` array shape (`score`, `details`, `gap`).
     *
     * Unknown / missing check ids fail closed (score=0, gap surfaced) so
     * mis-wired category rows degrade gracefully rather than raising.
     *
     * @param array<string, mixed> $check
     * @return array{score: float|int, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    private function dispatchPolicyWizardCheck(array $check, ?Tenant $tenant): array
    {
        $checkId = (string) ($check['check_id'] ?? '');
        if ($checkId === '') {
            return [
                'score' => 0,
                'details' => ['error' => 'missing_check_id'],
                'gap' => [
                    'title' => $check['name'] ?? 'Policy-Wizard check misconfigured',
                    'description' => 'check_id missing in category row',
                    'priority' => 'high',
                ],
            ];
        }

        $impl = $this->policyWizardCheckRegistry->get($checkId);
        if ($impl === null) {
            return [
                'score' => 0,
                'details' => ['error' => 'unknown_check_id', 'check_id' => $checkId],
                'gap' => [
                    'title' => $check['name'] ?? sprintf('Unknown Policy-Wizard check: %s', $checkId),
                    'description' => sprintf('No PolicyWizardCheckInterface implementation registered for "%s".', $checkId),
                    'priority' => $check['priority'] ?? 'high',
                    'route' => $check['route'] ?? null,
                ],
            ];
        }

        $result = $impl->run($tenant);

        return [
            'score' => $result->score,
            'details' => $result->details,
            'gap' => $result->gap,
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
                'maturity_baseline' => 'wizard.iso27001.context_baseline',
                'maturity_enhanced' => 'wizard.iso27001.context_enhanced',
                'icon' => 'nav-process',
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
                'maturity_baseline' => 'wizard.iso27001.leadership_baseline',
                'maturity_enhanced' => 'wizard.iso27001.leadership_enhanced',
                'icon' => 'nav-people',
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
                'maturity_baseline' => 'wizard.iso27001.planning_baseline',
                'maturity_enhanced' => 'wizard.iso27001.planning_enhanced',
                'icon' => 'nav-calendar-check',
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
                'maturity_baseline' => 'wizard.iso27001.support_baseline',
                'maturity_enhanced' => 'wizard.iso27001.support_enhanced',
                'icon' => 'nav-tools',
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
                'maturity_baseline' => 'wizard.iso27001.operation_baseline',
                'maturity_enhanced' => 'wizard.iso27001.operation_enhanced',
                'icon' => 'nav-gear',
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
                'maturity_baseline' => 'wizard.iso27001.performance_baseline',
                'maturity_enhanced' => 'wizard.iso27001.performance_enhanced',
                'icon' => 'nav-bar-chart',
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
                'maturity_baseline' => 'wizard.iso27001.improvement_baseline',
                'maturity_enhanced' => 'wizard.iso27001.improvement_enhanced',
                'icon' => 'util-arrow-up',
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
                'maturity_baseline' => 'wizard.iso27001.annex_a_organizational_baseline',
                'maturity_enhanced' => 'wizard.iso27001.annex_a_organizational_enhanced',
                'icon' => 'nav-building',
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
                'maturity_baseline' => 'wizard.iso27001.annex_a_people_baseline',
                'maturity_enhanced' => 'wizard.iso27001.annex_a_people_enhanced',
                'icon' => 'nav-people',
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
                'maturity_baseline' => 'wizard.iso27001.annex_a_physical_baseline',
                'maturity_enhanced' => 'wizard.iso27001.annex_a_physical_enhanced',
                'icon' => 'nav-building',
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
                'maturity_baseline' => 'wizard.iso27001.annex_a_technological_baseline',
                'maturity_enhanced' => 'wizard.iso27001.annex_a_technological_enhanced',
                'icon' => 'cpu',
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
                'maturity_baseline' => 'wizard.iso27001.asset_management_baseline',
                'maturity_enhanced' => 'wizard.iso27001.asset_management_enhanced',
                'icon' => 'asset-database',
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
                'maturity_baseline' => 'wizard.iso27001.incident_management_baseline',
                'maturity_enhanced' => 'wizard.iso27001.incident_management_enhanced',
                'icon' => 'status-critical',
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
                'maturity_baseline' => 'wizard.iso27001.business_continuity_baseline',
                'maturity_enhanced' => 'wizard.iso27001.business_continuity_enhanced',
                'icon' => 'util-refresh',
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

            // Policy-Wizard outputs — Top-Level (ISO 27001 Cl. 5.2)
            'policies_top_level' => [
                'name' => 'wizard.iso27001.policies_top_level',
                'description' => 'wizard.iso27001.policies_top_level_desc',
                'icon' => 'nav-file-earmark-text',
                'weight' => 1.5,
                'clause' => '5.2',
                'checks' => [
                    'policy_top_level_present' => [
                        'name' => 'compliance_check.policy_top_level_present.title',
                        'description' => 'compliance_check.policy_top_level_present.description',
                        'translation_domain' => 'policy_wizard',
                        'type' => 'policy_wizard',
                        'check_id' => 'policy_top_level_present',
                        'priority' => 'critical',
                        'clause' => '5.2',
                        'route' => 'app_policy_wizard_index',
                    ],
                ],
            ],

            // Policy-Wizard outputs — 24 ISO 27002 topic policies + 4 cross-cutting
            'policies_topic_coverage' => $this->buildPolicyWizardTopicCategory(),
        ];
    }

    /**
     * Build the "Policies / Topic Coverage" category for the ISO 27001 wizard.
     *
     * Aggregates the 24 ISO 27002:2022 topic policies (one row per topic from
     * {@see PolicyWizardTopicCatalogue::ISO27001_TOPICS}) plus the 4
     * cross-cutting Policy-Wizard checks (approval-chain, acknowledgement,
     * review-cadence, tailoring-fields). Each row is a `policy_wizard`
     * type and resolves through {@see dispatchPolicyWizardCheck()} into the
     * registry.
     *
     * @return array<string, mixed>
     */
    private function buildPolicyWizardTopicCategory(): array
    {
        $checks = [];

        foreach (PolicyWizardTopicCatalogue::iso27001Topics() as $topic) {
            $checkId = sprintf('policy_topic_%s_present', $topic);
            $checks[$checkId] = [
                'name' => sprintf('compliance_check.%s.title', $checkId),
                'description' => sprintf('compliance_check.%s.description', $checkId),
                'translation_domain' => 'policy_wizard',
                'type' => 'policy_wizard',
                'check_id' => $checkId,
                'priority' => 'high',
                'route' => 'app_policy_wizard_index',
            ];
        }

        // Cross-cutting Policy-Wizard checks (approval-chain, acknowledgement,
        // review-cadence, tailoring-fields) — apply across all generated
        // policies, not per-topic.
        foreach ([
            'policy_approval_chain_completed' => 'critical',
            'policy_acknowledgement_coverage' => 'high',
            'policy_review_cadence' => 'high',
            'policy_tailoring_fields' => 'medium',
        ] as $checkId => $priority) {
            $checks[$checkId] = [
                'name' => sprintf('compliance_check.%s.title', $checkId),
                'description' => sprintf('compliance_check.%s.description', $checkId),
                'translation_domain' => 'policy_wizard',
                'type' => 'policy_wizard',
                'check_id' => $checkId,
                'priority' => $priority,
                'route' => 'app_policy_wizard_index',
            ];
        }

        return [
            'name' => 'wizard.iso27001.policies_topic_coverage',
            'description' => 'wizard.iso27001.policies_topic_coverage_desc',
            'icon' => 'documents',
            'weight' => 2,
            'clause' => 'A.5/A.6/A.7/A.8',
            'checks' => $checks,
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
                'maturity_baseline' => 'wizard.nis2.governance_baseline',
                'maturity_enhanced' => 'wizard.nis2.governance_enhanced',
                'icon' => 'nav-building',
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
                'maturity_baseline' => 'wizard.nis2.risk_policies_baseline',
                'maturity_enhanced' => 'wizard.nis2.risk_policies_enhanced',
                'icon' => 'nav-file-earmark-text',
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
                'maturity_baseline' => 'wizard.nis2.incident_handling_baseline',
                'maturity_enhanced' => 'wizard.nis2.incident_handling_enhanced',
                'icon' => 'status-warning',
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
                'maturity_baseline' => 'wizard.nis2.business_continuity_baseline',
                'maturity_enhanced' => 'wizard.nis2.business_continuity_enhanced',
                'icon' => 'util-refresh',
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
                'maturity_baseline' => 'wizard.nis2.supply_chain_baseline',
                'maturity_enhanced' => 'wizard.nis2.supply_chain_enhanced',
                'icon' => 'nav-truck',
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
                'maturity_baseline' => 'wizard.nis2.secure_development_baseline',
                'maturity_enhanced' => 'wizard.nis2.secure_development_enhanced',
                'icon' => 'asset-application',
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
                'maturity_baseline' => 'wizard.nis2.effectiveness_assessment_baseline',
                'maturity_enhanced' => 'wizard.nis2.effectiveness_assessment_enhanced',
                'icon' => 'nav-clipboard-check',
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
                'maturity_baseline' => 'wizard.nis2.cyber_hygiene_baseline',
                'maturity_enhanced' => 'wizard.nis2.cyber_hygiene_enhanced',
                'icon' => 'nav-mortarboard',
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
                'maturity_baseline' => 'wizard.nis2.cryptography_baseline',
                'maturity_enhanced' => 'wizard.nis2.cryptography_enhanced',
                'icon' => 'ui-lock',
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
                'maturity_baseline' => 'wizard.nis2.access_and_assets_baseline',
                'maturity_enhanced' => 'wizard.nis2.access_and_assets_enhanced',
                'icon' => 'ui-key',
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
                'maturity_baseline' => 'wizard.nis2.mfa_baseline',
                'maturity_enhanced' => 'wizard.nis2.mfa_enhanced',
                'icon' => 'nav-shield-lock',
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
                'maturity_baseline' => 'wizard.nis2.reporting_baseline',
                'maturity_enhanced' => 'wizard.nis2.reporting_enhanced',
                'icon' => 'bell',
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
        $categories = [
            // Chapter II, Section I: ICT Risk Management Framework (Art. 5-6)
            'governance' => [
                'name' => 'wizard.dora.governance',
                'description' => 'wizard.dora.governance_desc',
                'maturity_baseline' => 'wizard.dora.governance_baseline',
                'maturity_enhanced' => 'wizard.dora.governance_enhanced',
                'icon' => 'nav-building',
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
                'maturity_baseline' => 'wizard.dora.ict_systems_baseline',
                'maturity_enhanced' => 'wizard.dora.ict_systems_enhanced',
                'icon' => 'asset-network',
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
                'maturity_baseline' => 'wizard.dora.identification_baseline',
                'maturity_enhanced' => 'wizard.dora.identification_enhanced',
                'icon' => 'ui-search',
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
                'maturity_baseline' => 'wizard.dora.protection_baseline',
                'maturity_enhanced' => 'wizard.dora.protection_enhanced',
                'icon' => 'shield-check',
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
                'maturity_baseline' => 'wizard.dora.detection_baseline',
                'maturity_enhanced' => 'wizard.dora.detection_enhanced',
                'icon' => 'ui-eye',
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
                'maturity_baseline' => 'wizard.dora.response_recovery_baseline',
                'maturity_enhanced' => 'wizard.dora.response_recovery_enhanced',
                'icon' => 'util-refresh',
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
                'maturity_baseline' => 'wizard.dora.learning_baseline',
                'maturity_enhanced' => 'wizard.dora.learning_enhanced',
                'icon' => 'ui-lightbulb',
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
                'maturity_baseline' => 'wizard.dora.communication_baseline',
                'maturity_enhanced' => 'wizard.dora.communication_enhanced',
                'icon' => 'bell',
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
                'maturity_baseline' => 'wizard.dora.incident_management_baseline',
                'maturity_enhanced' => 'wizard.dora.incident_management_enhanced',
                'icon' => 'status-warning',
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
                'maturity_baseline' => 'wizard.dora.incident_classification_baseline',
                'maturity_enhanced' => 'wizard.dora.incident_classification_enhanced',
                'icon' => 'nav-tags',
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
                'maturity_baseline' => 'wizard.dora.incident_reporting_baseline',
                'maturity_enhanced' => 'wizard.dora.incident_reporting_enhanced',
                'icon' => 'nav-clipboard-data',
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
                'maturity_baseline' => 'wizard.dora.resilience_testing_baseline',
                'maturity_enhanced' => 'wizard.dora.resilience_testing_enhanced',
                'icon' => 'bug',
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
                'maturity_baseline' => 'wizard.dora.tlpt_baseline',
                'maturity_enhanced' => 'wizard.dora.tlpt_enhanced',
                'icon' => 'nav-shield-alert',
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
                'maturity_baseline' => 'wizard.dora.third_party_risk_baseline',
                'maturity_enhanced' => 'wizard.dora.third_party_risk_enhanced',
                'icon' => 'nav-people',
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
                'maturity_baseline' => 'wizard.dora.contractual_baseline',
                'maturity_enhanced' => 'wizard.dora.contractual_enhanced',
                'icon' => 'nav-file-earmark-text',
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

            // Policy-Wizard outputs — DORA-specific policies, deadlines + tags
            // Gated on DORA scope being active (ComplianceFramework with code
            // 'DORA' present + active OR tenant policy setting 'dora.in_scope').
            // Returns null when out-of-scope; array_filter() drops the row.
            'dora_policies' => $this->buildDoraPolicyWizardCategory(),
        ];

        // Drop categories that opted out (returned null) when DORA is not in
        // scope for the current tenant — keeps the assessment surface clean.
        return array_filter($categories, static fn ($cat): bool => $cat !== null);
    }

    /**
     * Build the "DORA Policy-Wizard outputs" category. Returns null when DORA
     * is not in scope for the tenant — see scope detection below.
     *
     * @return array<string, mixed>|null
     */
    private function buildDoraPolicyWizardCategory(): ?array
    {
        if (!$this->isDoraInScope($this->tenantContext->getCurrentTenant())) {
            return null;
        }

        $checks = [];
        foreach ([
            DoraIctRiskFrameworkPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            DoraIncidentReportingDeadlinesCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_admin_incident_sla_index'],
            DoraThirdPartyRegisterMaintainedCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_supplier_index'],
            DoraTlptCadenceCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_bc_exercise_index'],
            DoraExitStrategyDocumentedCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_supplier_index'],
            DoraValidityFromCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_document_index'],
            DoraExtensionCoverageCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
        ] as $checkId => $meta) {
            $checks[$checkId] = [
                'name' => sprintf('compliance_check.%s.title', $checkId),
                'description' => sprintf('compliance_check.%s.description', $checkId),
                'translation_domain' => 'policy_wizard',
                'type' => 'policy_wizard',
                'check_id' => $checkId,
                'priority' => $meta['priority'],
                'route' => $meta['route'],
            ];
        }

        return [
            'name' => 'wizard.dora.dora_policies',
            'description' => 'wizard.dora.dora_policies_desc',
            'icon' => 'nav-building',
            'weight' => 2,
            'article' => '6/19/26-27/28',
            'checks' => $checks,
        ];
    }

    /**
     * Detects whether DORA is in scope for the tenant. The signal is the
     * presence of an active {@see ComplianceFramework} with `code='DORA'`.
     *
     * Returns false for null tenants when the framework is not active
     * either — keeping the DORA category out of generic admin previews.
     * The tenant-policy-setting alternative (`dora.in_scope`) is intentionally
     * not consulted here to avoid forcing a DB lookup on every category-map
     * build; the DORA wizard is the authoritative entry-point.
     */
    private function isDoraInScope(?Tenant $tenant): bool
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'DORA']);
        if ($framework instanceof ComplianceFramework && $framework->isActive() === true) {
            return true;
        }

        // No active framework → DORA is not in scope. Tenant-specific
        // settings remain a future extension hook.
        unset($tenant); // intentional — see method PHPDoc
        return false;
    }

    private function getTisaxCategories(): array
    {
        return [
            'information_security' => [
                'name' => 'wizard.tisax.information_security',
                'description' => 'wizard.tisax.information_security_desc',
                'maturity_baseline' => 'wizard.tisax.information_security_baseline',
                'maturity_enhanced' => 'wizard.tisax.information_security_enhanced',
                'icon' => 'nav-shield-lock',
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
                'maturity_baseline' => 'wizard.tisax.prototype_protection_baseline',
                'maturity_enhanced' => 'wizard.tisax.prototype_protection_enhanced',
                'icon' => 'nav-truck',
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
                'maturity_baseline' => 'wizard.tisax.data_protection_baseline',
                'maturity_enhanced' => 'wizard.tisax.data_protection_enhanced',
                'icon' => 'nav-people',
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
        $categories = [
            'lawfulness' => [
                'name' => 'wizard.gdpr.lawfulness',
                'description' => 'wizard.gdpr.lawfulness_desc',
                'maturity_baseline' => 'wizard.gdpr.lawfulness_baseline',
                'maturity_enhanced' => 'wizard.gdpr.lawfulness_enhanced',
                'icon' => 'nav-file-earmark-text',
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
                'maturity_baseline' => 'wizard.gdpr.data_subject_rights_baseline',
                'maturity_enhanced' => 'wizard.gdpr.data_subject_rights_enhanced',
                'icon' => 'nav-people',
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
                'maturity_baseline' => 'wizard.gdpr.security_measures_baseline',
                'maturity_enhanced' => 'wizard.gdpr.security_measures_enhanced',
                'icon' => 'nav-shield-lock',
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
                'maturity_baseline' => 'wizard.gdpr.breach_notification_baseline',
                'maturity_enhanced' => 'wizard.gdpr.breach_notification_enhanced',
                'icon' => 'status-warning',
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
                'maturity_baseline' => 'wizard.gdpr.awareness_baseline',
                'maturity_enhanced' => 'wizard.gdpr.awareness_enhanced',
                'icon' => 'nav-mortarboard',
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

            // Policy-Wizard outputs — GDPR-specific policies, sections, DPO
            // appointment + breach SLA. Gated on GDPR scope being active
            // (ComplianceFramework code 'GDPR' active OR tenant policy setting
            // 'org.is_gdpr_subject' true). Returns null when out-of-scope;
            // array_filter() drops the row.
            'gdpr_policies' => $this->buildGdprPolicyWizardCategory(),
        ];

        return array_filter($categories, static fn ($cat): bool => $cat !== null);
    }

    /**
     * Build the "GDPR Policy-Wizard outputs" category. Returns null when GDPR
     * scope is not declared for the tenant.
     *
     * @return array<string, mixed>|null
     */
    private function buildGdprPolicyWizardCategory(): ?array
    {
        if (!$this->isGdprInScope($this->tenantContext->getCurrentTenant())) {
            return null;
        }

        $checks = [];
        foreach ([
            PrivacyPolicyPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            RopaMethodologyPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            DpiaMethodologyPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            DsrProcedurePresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            DataBreachNotification72hCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_admin_incident_sla_index'],
            DpoCharterAppointedCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            GdprSectionCoverageCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
            A534ThinHostPresentCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
        ] as $checkId => $meta) {
            $checks[$checkId] = [
                'name' => sprintf('compliance_check.%s.title', $checkId),
                'description' => sprintf('compliance_check.%s.description', $checkId),
                'translation_domain' => 'policy_wizard',
                'type' => 'policy_wizard',
                'check_id' => $checkId,
                'priority' => $meta['priority'],
                'route' => $meta['route'],
            ];
        }

        return [
            'name' => 'wizard.gdpr.gdpr_policies',
            'description' => 'wizard.gdpr.gdpr_policies_desc',
            'icon' => 'nav-people',
            'weight' => 2,
            'article' => 'GDPR Art. 5/24/30/33-34/35/37-39 + ISO 27001 A.5.34',
            'checks' => $checks,
        ];
    }

    /**
     * Detects whether GDPR is in scope for the tenant. Signals:
     * - ComplianceFramework with `code='GDPR'` and `isActive=true`, OR
     * - Tenant policy setting `org.is_gdpr_subject` set to true.
     *
     * Returns false when neither signal is present (keeps the GDPR category
     * out of generic admin previews).
     */
    private function isGdprInScope(?Tenant $tenant): bool
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'GDPR']);
        if ($framework instanceof ComplianceFramework && $framework->isActive() === true) {
            return true;
        }

        if ($tenant === null || $this->tenantPolicySettingRepository === null) {
            return false;
        }
        $setting = $this->tenantPolicySettingRepository->findOneByTenantAndKey(
            $tenant,
            'org.is_gdpr_subject',
        );
        if ($setting === null) {
            return false;
        }
        $value = $setting->getValue();
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * Categories for ISO 22301:2019 readiness — Business Continuity Management.
     *
     * Maps to the 7 management-system clauses (4-10) and reuses the existing
     * check-types (bcm_coverage, document_review, audit_status, etc.) so this
     * wizard can ship without new dependencies.
     */
    private function getIso22301Categories(): array
    {
        $categories = [
            'context' => [
                'name' => 'wizard.iso22301.context',
                'description' => 'wizard.iso22301.context_desc',
                'maturity_baseline' => 'wizard.iso22301.context_baseline',
                'maturity_enhanced' => 'wizard.iso22301.context_enhanced',
                'icon' => 'ui-globe',
                'weight' => 1.5,
                'checks' => [
                    'scope_definition' => [
                        'name' => 'wizard.check.iso22301_scope',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.iso22301_scope_desc',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'leadership' => [
                'name' => 'wizard.iso22301.leadership',
                'description' => 'wizard.iso22301.leadership_desc',
                'maturity_baseline' => 'wizard.iso22301.leadership_baseline',
                'maturity_enhanced' => 'wizard.iso22301.leadership_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'checks' => [
                    'bcm_policy' => [
                        'name' => 'wizard.check.iso22301_policy',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'planning' => [
                'name' => 'wizard.iso22301.planning',
                'description' => 'wizard.iso22301.planning_desc',
                'maturity_baseline' => 'wizard.iso22301.planning_baseline',
                'maturity_enhanced' => 'wizard.iso22301.planning_enhanced',
                'icon' => 'nav-clipboard-data',
                'weight' => 2,
                'checks' => [
                    'bia_risks' => [
                        'name' => 'wizard.check.iso22301_bia',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],
            'support' => [
                'name' => 'wizard.iso22301.support',
                'description' => 'wizard.iso22301.support_desc',
                'maturity_baseline' => 'wizard.iso22301.support_baseline',
                'maturity_enhanced' => 'wizard.iso22301.support_enhanced',
                'icon' => 'nav-people',
                'weight' => 1,
                'checks' => [
                    'training' => [
                        'name' => 'wizard.check.iso22301_training',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'route' => 'app_training_index',
                    ],
                ],
            ],
            'operation' => [
                'name' => 'wizard.iso22301.operation',
                'description' => 'wizard.iso22301.operation_desc',
                'maturity_baseline' => 'wizard.iso22301.operation_baseline',
                'maturity_enhanced' => 'wizard.iso22301.operation_enhanced',
                'icon' => 'nav-gear',
                'weight' => 3,
                'checks' => [
                    'bc_plans' => [
                        'name' => 'wizard.check.iso22301_bcplans',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
            'evaluation' => [
                'name' => 'wizard.iso22301.evaluation',
                'description' => 'wizard.iso22301.evaluation_desc',
                'maturity_baseline' => 'wizard.iso22301.evaluation_baseline',
                'maturity_enhanced' => 'wizard.iso22301.evaluation_enhanced',
                'icon' => 'nav-bar-chart',
                'weight' => 2,
                'checks' => [
                    'bcm_audit' => [
                        'name' => 'wizard.check.iso22301_audit',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'improvement' => [
                'name' => 'wizard.iso22301.improvement',
                'description' => 'wizard.iso22301.improvement_desc',
                'maturity_baseline' => 'wizard.iso22301.improvement_baseline',
                'maturity_enhanced' => 'wizard.iso22301.improvement_enhanced',
                'icon' => 'util-arrow-up',
                'weight' => 1,
                'checks' => [
                    'treatment' => [
                        'name' => 'wizard.check.iso22301_treatment',
                        'type' => 'treatment_plan',
                        'module' => 'risks',
                        'route' => 'app_risk_treatment_plan_index',
                    ],
                ],
            ],
            // Policy-Wizard outputs — BCM-specific policies (top-level, scope,
            // BIA methodology, exercise programme, crisis plan, recovery plans,
            // management review). Gated on BCM scope being active
            // (`bcm.enabled` tenant setting OR ComplianceFramework code
            // 'ISO_22301' active). Returns null when out-of-scope.
            'bcm_policies' => $this->buildBcmPolicyWizardCategory(),
        ];

        return array_filter($categories, static fn ($cat): bool => $cat !== null);
    }

    /**
     * Build the "BCM Policy-Wizard outputs" category. Returns null when BCM
     * scope is not declared for the tenant.
     *
     * @return array<string, mixed>|null
     */
    private function buildBcmPolicyWizardCategory(): ?array
    {
        if (!$this->isBcmInScope($this->tenantContext->getCurrentTenant())) {
            return null;
        }

        $checks = [];
        foreach ([
            BcmTopLevelPolicyPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BcmsScopeStatementPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BcmBiaMethodologyPresentCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
            BcmExerciseProgrammeActiveCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_bc_exercise_index'],
            BcmCrisisManagementPlanPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BcmRecoveryPlansPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BcmManagementReviewBcmCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
        ] as $checkId => $meta) {
            $checks[$checkId] = [
                'name' => sprintf('compliance_check.%s.title', $checkId),
                'description' => sprintf('compliance_check.%s.description', $checkId),
                'translation_domain' => 'policy_wizard',
                'type' => 'policy_wizard',
                'check_id' => $checkId,
                'priority' => $meta['priority'],
                'route' => $meta['route'],
            ];
        }

        return [
            'name' => 'wizard.bcm.bcm_policies',
            'description' => 'wizard.bcm.bcm_policies_desc',
            'icon' => 'recovery',
            'weight' => 2,
            'article' => 'ISO 22301 Cl. 5.2/4.3/8.2.2/8.4.4/8.4.5/8.6/9.3',
            'checks' => $checks,
        ];
    }

    /**
     * Detects whether BCM is in scope for the tenant. Signals:
     * - ComplianceFramework with `code='ISO_22301'` and `isActive=true`, OR
     * - Tenant policy setting `bcm.enabled` set to true.
     */
    private function isBcmInScope(?Tenant $tenant): bool
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'ISO_22301']);
        if ($framework instanceof ComplianceFramework && $framework->isActive() === true) {
            return true;
        }

        if ($tenant === null || $this->tenantPolicySettingRepository === null) {
            return false;
        }
        $setting = $this->tenantPolicySettingRepository->findOneByTenantAndKey(
            $tenant,
            'bcm.enabled',
        );
        if ($setting === null) {
            return false;
        }
        $value = $setting->getValue();
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * Categories for ISO 27701:2019 readiness — Privacy Information Management
     * System. Maps to the PIMS-specific extensions on top of ISO 27001 — focuses
     * on the eight blocks the standard's Annex A/B require organisations to
     * demonstrate.
     */
    private function getIso27701Categories(): array
    {
        $categories = [
            'pims_context' => [
                'name' => 'wizard.iso27701.pims_context',
                'description' => 'wizard.iso27701.pims_context_desc',
                'maturity_baseline' => 'wizard.iso27701.pims_context_baseline',
                'maturity_enhanced' => 'wizard.iso27701.pims_context_enhanced',
                'icon' => 'nav-process',
                'weight' => 1.5,
                'checks' => [
                    'controller_processor_role' => [
                        'name' => 'wizard.check.iso27701_role',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.iso27701_role_desc',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'privacy_policy' => [
                'name' => 'wizard.iso27701.privacy_policy',
                'description' => 'wizard.iso27701.privacy_policy_desc',
                'maturity_baseline' => 'wizard.iso27701.privacy_policy_baseline',
                'maturity_enhanced' => 'wizard.iso27701.privacy_policy_enhanced',
                'icon' => 'nav-file-check',
                'weight' => 1.5,
                'checks' => [
                    'policy_doc' => [
                        'name' => 'wizard.check.iso27701_policy',
                        'type' => 'document_review',
                        'document_categories' => ['policy', 'privacy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'data_subject_rights' => [
                'name' => 'wizard.iso27701.data_subject_rights',
                'description' => 'wizard.iso27701.data_subject_rights_desc',
                'maturity_baseline' => 'wizard.iso27701.data_subject_rights_baseline',
                'maturity_enhanced' => 'wizard.iso27701.data_subject_rights_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'dsr_pipeline' => [
                        'name' => 'wizard.check.iso27701_dsr',
                        'type' => 'dsr_coverage',
                        'module' => 'privacy',
                        'route' => 'app_data_subject_request_index',
                    ],
                ],
            ],
            'privacy_risk' => [
                'name' => 'wizard.iso27701.privacy_risk',
                'description' => 'wizard.iso27701.privacy_risk_desc',
                'maturity_baseline' => 'wizard.iso27701.privacy_risk_baseline',
                'maturity_enhanced' => 'wizard.iso27701.privacy_risk_enhanced',
                'icon' => 'status-critical',
                'weight' => 3,
                'checks' => [
                    'dpia' => [
                        'name' => 'wizard.check.iso27701_dpia',
                        'type' => 'dpia_coverage',
                        'module' => 'privacy',
                        'route' => 'app_processing_activity_index',
                    ],
                ],
            ],
            'records_of_processing' => [
                'name' => 'wizard.iso27701.records_of_processing',
                'description' => 'wizard.iso27701.records_of_processing_desc',
                'maturity_baseline' => 'wizard.iso27701.records_of_processing_baseline',
                'maturity_enhanced' => 'wizard.iso27701.records_of_processing_enhanced',
                'icon' => 'nav-list-check',
                'weight' => 2,
                'checks' => [
                    'processing_records' => [
                        'name' => 'wizard.check.iso27701_records',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'route' => 'app_processing_activity_index',
                    ],
                ],
            ],
            'breach_notification' => [
                'name' => 'wizard.iso27701.breach_notification',
                'description' => 'wizard.iso27701.breach_notification_desc',
                'maturity_baseline' => 'wizard.iso27701.breach_notification_baseline',
                'maturity_enhanced' => 'wizard.iso27701.breach_notification_enhanced',
                'icon' => 'status-warning',
                'weight' => 1.5,
                'checks' => [
                    'breach_process' => [
                        'name' => 'wizard.check.iso27701_breach',
                        'type' => 'incident_process',
                        'sla_hours' => 72,
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'privacy_by_design' => [
                'name' => 'wizard.iso27701.privacy_by_design',
                'description' => 'wizard.iso27701.privacy_by_design_desc',
                'maturity_baseline' => 'wizard.iso27701.privacy_by_design_baseline',
                'maturity_enhanced' => 'wizard.iso27701.privacy_by_design_enhanced',
                'icon' => 'nav-tools',
                'weight' => 1.5,
                'checks' => [
                    'consent_pipeline' => [
                        'name' => 'wizard.check.iso27701_consent',
                        'type' => 'consent_coverage',
                        'module' => 'privacy',
                        'route' => 'app_consent_index',
                    ],
                ],
            ],
            'third_party_processors' => [
                'name' => 'wizard.iso27701.third_party_processors',
                'description' => 'wizard.iso27701.third_party_processors_desc',
                'maturity_baseline' => 'wizard.iso27701.third_party_processors_baseline',
                'maturity_enhanced' => 'wizard.iso27701.third_party_processors_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'supplier_assessment' => [
                        'name' => 'wizard.check.iso27701_suppliers',
                        'type' => 'supplier_assessment',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],

            // Policy-Wizard outputs — ISO 27701 PIMS-specific checks (version
            // declaration, clause-level mapping tags, Schrems II + supplementary
            // measures wording on transfers). Gated on `iso27701.enabled` tenant
            // policy setting being true. Returns null when out-of-scope.
            'iso27701_pims' => $this->buildIso27701PolicyWizardCategory(),
        ];

        return array_filter($categories, static fn ($cat): bool => $cat !== null);
    }

    /**
     * Build the "ISO 27701 PIMS Policy-Wizard outputs" category. Returns null
     * when the PIMS addon is not opted in for the tenant.
     *
     * @return array<string, mixed>|null
     */
    private function buildIso27701PolicyWizardCategory(): ?array
    {
        if (!$this->isIso27701InScope($this->tenantContext->getCurrentTenant())) {
            return null;
        }

        $checks = [];
        foreach ([
            Iso27701VersionConfiguredCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
            Iso27701ClauseTagsAppliedCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_document_index'],
            Iso27701SchremsIIClauseInTransfersCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
        ] as $checkId => $meta) {
            $checks[$checkId] = [
                'name' => sprintf('compliance_check.%s.title', $checkId),
                'description' => sprintf('compliance_check.%s.description', $checkId),
                'translation_domain' => 'policy_wizard',
                'type' => 'policy_wizard',
                'check_id' => $checkId,
                'priority' => $meta['priority'],
                'route' => $meta['route'],
            ];
        }

        return [
            'name' => 'wizard.iso27701.iso27701_pims',
            'description' => 'wizard.iso27701.iso27701_pims_desc',
            'icon' => 'shield-check',
            'weight' => 1.5,
            'article' => 'ISO 27701:2025 Cl. 5.1/7.2.8/7.5 + GDPR Art. 44-49',
            'checks' => $checks,
        ];
    }

    /**
     * Detects whether the ISO 27701 PIMS addon is opted in for the tenant.
     * Signal: `iso27701.enabled` tenant policy setting set to true (resolved
     * via {@see PolicySettingProvider}). When the provider is not wired in
     * (legacy DI compositions in tests) the category stays hidden.
     */
    private function isIso27701InScope(?Tenant $tenant): bool
    {
        if ($this->policySettingProvider === null) {
            return false;
        }
        return $this->policySettingProvider->isIso27701Enabled($tenant);
    }

    /**
     * Categories for ISO/IEC 27017:2015 readiness — Cloud Security.
     * Covers the cloud-specific control extensions to ISO 27001 Annex A
     * for both cloud service customers and cloud service providers.
     */
    private function getIso27017Categories(): array
    {
        return [
            'shared_responsibility' => [
                'name' => 'wizard.iso27017.shared_responsibility',
                'description' => 'wizard.iso27017.shared_responsibility_desc',
                'maturity_baseline' => 'wizard.iso27017.shared_responsibility_baseline',
                'maturity_enhanced' => 'wizard.iso27017.shared_responsibility_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'iso27017_shared_responsibility' => [
                        'name' => 'wizard.check.iso27017_shared_responsibility',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'cloud_asset_inventory' => [
                'name' => 'wizard.iso27017.cloud_asset_inventory',
                'description' => 'wizard.iso27017.cloud_asset_inventory_desc',
                'maturity_baseline' => 'wizard.iso27017.cloud_asset_inventory_baseline',
                'maturity_enhanced' => 'wizard.iso27017.cloud_asset_inventory_enhanced',
                'icon' => 'nav-download',
                'weight' => 2,
                'checks' => [
                    'iso27017_cloud_assets' => [
                        'name' => 'wizard.check.iso27017_cloud_assets',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'customer_separation' => [
                'name' => 'wizard.iso27017.customer_separation',
                'description' => 'wizard.iso27017.customer_separation_desc',
                'maturity_baseline' => 'wizard.iso27017.customer_separation_baseline',
                'maturity_enhanced' => 'wizard.iso27017.customer_separation_enhanced',
                'icon' => 'nav-process',
                'weight' => 1.5,
                'checks' => [
                    'iso27017_separation' => [
                        'name' => 'wizard.check.iso27017_separation',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'virtual_machine_hardening' => [
                'name' => 'wizard.iso27017.vm_hardening',
                'description' => 'wizard.iso27017.vm_hardening_desc',
                'icon' => 'asset-endpoint',
                'weight' => 1.5,
                'checks' => [
                    'iso27017_vm_hardening' => [
                        'name' => 'wizard.check.iso27017_vm_hardening',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'admin_access' => [
                'name' => 'wizard.iso27017.admin_access',
                'description' => 'wizard.iso27017.admin_access_desc',
                'maturity_baseline' => 'wizard.iso27017.admin_access_baseline',
                'maturity_enhanced' => 'wizard.iso27017.admin_access_enhanced',
                'icon' => 'ui-key',
                'weight' => 2,
                'checks' => [
                    'iso27017_admin_access' => [
                        'name' => 'wizard.check.iso27017_admin_access',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'cloud_supplier_governance' => [
                'name' => 'wizard.iso27017.cloud_supplier_governance',
                'description' => 'wizard.iso27017.cloud_supplier_governance_desc',
                'maturity_baseline' => 'wizard.iso27017.cloud_supplier_governance_baseline',
                'maturity_enhanced' => 'wizard.iso27017.cloud_supplier_governance_enhanced',
                'icon' => 'nav-building-check',
                'weight' => 2,
                'checks' => [
                    'iso27017_cloud_suppliers' => [
                        'name' => 'wizard.check.iso27017_cloud_suppliers',
                        'type' => 'supplier_assessment',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'monitoring_and_logging' => [
                'name' => 'wizard.iso27017.monitoring_logging',
                'description' => 'wizard.iso27017.monitoring_logging_desc',
                'icon' => 'nav-activity',
                'weight' => 1.5,
                'checks' => [
                    'iso27017_monitoring' => [
                        'name' => 'wizard.check.iso27017_monitoring',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * Categories for ISO/IEC 27018:2019 readiness — PII in Cloud.
     * Covers PII processor obligations on top of ISO 27001 + 27002 for
     * organisations acting as public cloud PII processors.
     */
    private function getIso27018Categories(): array
    {
        return [
            'pii_consent_processor' => [
                'name' => 'wizard.iso27018.pii_consent_processor',
                'description' => 'wizard.iso27018.pii_consent_processor_desc',
                'maturity_baseline' => 'wizard.iso27018.pii_consent_processor_baseline',
                'maturity_enhanced' => 'wizard.iso27018.pii_consent_processor_enhanced',
                'icon' => 'nav-file-check',
                'weight' => 2,
                'checks' => [
                    'iso27018_consent' => [
                        'name' => 'wizard.check.iso27018_consent',
                        'type' => 'consent_coverage',
                        'module' => 'privacy',
                        'route' => 'app_consent_index',
                    ],
                ],
            ],
            'pii_purpose_limitation' => [
                'name' => 'wizard.iso27018.purpose_limitation',
                'description' => 'wizard.iso27018.purpose_limitation_desc',
                'icon' => 'nav-bullseye',
                'weight' => 2,
                'checks' => [
                    'iso27018_purpose' => [
                        'name' => 'wizard.check.iso27018_purpose',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_processing_activity_index',
                    ],
                ],
            ],
            'pii_retention_disposal' => [
                'name' => 'wizard.iso27018.retention_disposal',
                'description' => 'wizard.iso27018.retention_disposal_desc',
                'icon' => 'ui-delete',
                'weight' => 2,
                'checks' => [
                    'iso27018_retention' => [
                        'name' => 'wizard.check.iso27018_retention',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_processing_activity_index',
                    ],
                ],
            ],
            'pii_access_transparency' => [
                'name' => 'wizard.iso27018.access_transparency',
                'description' => 'wizard.iso27018.access_transparency_desc',
                'icon' => 'ui-eye',
                'weight' => 1.5,
                'checks' => [
                    'iso27018_dsr' => [
                        'name' => 'wizard.check.iso27018_dsr',
                        'type' => 'dsr_coverage',
                        'module' => 'privacy',
                        'route' => 'app_data_subject_request_index',
                    ],
                ],
            ],
            'pii_breach_notification' => [
                'name' => 'wizard.iso27018.breach_notification',
                'description' => 'wizard.iso27018.breach_notification_desc',
                'icon' => 'bell',
                'weight' => 2,
                'checks' => [
                    'iso27018_breach' => [
                        'name' => 'wizard.check.iso27018_breach',
                        'type' => 'incident_process',
                        'sla_hours' => 72,
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'pii_subprocessor_governance' => [
                'name' => 'wizard.iso27018.subprocessor_governance',
                'description' => 'wizard.iso27018.subprocessor_governance_desc',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'iso27018_subprocessors' => [
                        'name' => 'wizard.check.iso27018_subprocessors',
                        'type' => 'supplier_assessment',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * Categories for ISO/IEC 42001:2023 readiness — AI Management System.
     * Covers management-system clauses 4-10 plus Annex A AI-specific controls
     * for organisations developing, providing or using AI systems.
     */
    private function getIso42001Categories(): array
    {
        return [
            'ai_context' => [
                'name' => 'wizard.iso42001.ai_context',
                'description' => 'wizard.iso42001.ai_context_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_context_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_context_enhanced',
                'icon' => 'ui-globe',
                'weight' => 1.5,
                'checks' => [
                    'iso42001_context' => [
                        'name' => 'wizard.check.iso42001_context',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'ai_leadership_policy' => [
                'name' => 'wizard.iso42001.ai_leadership_policy',
                'description' => 'wizard.iso42001.ai_leadership_policy_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_leadership_policy_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_leadership_policy_enhanced',
                'icon' => 'bell',
                'weight' => 1.5,
                'checks' => [
                    'iso42001_policy' => [
                        'name' => 'wizard.check.iso42001_policy',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'ai_inventory' => [
                'name' => 'wizard.iso42001.ai_inventory',
                'description' => 'wizard.iso42001.ai_inventory_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_inventory_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_inventory_enhanced',
                'icon' => 'nav-list-check',
                'weight' => 3,
                'checks' => [
                    // filters assetType = 'ai_agent'
                    'iso42001_ai_inventory' => [
                        'name' => 'wizard.check.iso42001_ai_inventory',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'ai_risk' => [
                'name' => 'wizard.iso42001.ai_risk',
                'description' => 'wizard.iso42001.ai_risk_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_risk_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_risk_enhanced',
                'icon' => 'status-critical',
                'weight' => 3,
                'checks' => [
                    'iso42001_ai_risk' => [
                        'name' => 'wizard.check.iso42001_ai_risk',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],
            'ai_data_governance' => [
                'name' => 'wizard.iso42001.ai_data_governance',
                'description' => 'wizard.iso42001.ai_data_governance_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_data_governance_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_data_governance_enhanced',
                'icon' => 'nav-database',
                'weight' => 2,
                'checks' => [
                    'iso42001_data_governance' => [
                        'name' => 'wizard.check.iso42001_data_governance',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'ai_human_oversight' => [
                'name' => 'wizard.iso42001.ai_human_oversight',
                'description' => 'wizard.iso42001.ai_human_oversight_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_human_oversight_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_human_oversight_enhanced',
                'icon' => 'ui-eye',
                'weight' => 2,
                'checks' => [
                    'iso42001_oversight' => [
                        'name' => 'wizard.check.iso42001_oversight',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'ai_transparency' => [
                'name' => 'wizard.iso42001.ai_transparency',
                'description' => 'wizard.iso42001.ai_transparency_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_transparency_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_transparency_enhanced',
                'icon' => 'status-info',
                'weight' => 1.5,
                'checks' => [
                    'iso42001_transparency' => [
                        'name' => 'wizard.check.iso42001_transparency',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'ai_incident_response' => [
                'name' => 'wizard.iso42001.ai_incident_response',
                'description' => 'wizard.iso42001.ai_incident_response_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_incident_response_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_incident_response_enhanced',
                'icon' => 'nav-shield-alert',
                'weight' => 1.5,
                'checks' => [
                    'iso42001_incidents' => [
                        'name' => 'wizard.check.iso42001_incidents',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * BSI IT-Grundschutz (Basis-Absicherung) Categories
     *
     * Based on the 10 layers of the BSI IT-Grundschutz-Kompendium:
     * ISMS, ORP, CON, OPS, DET, APP, SYS, IND, NET, INF
     */
    private function getBsiGrundschutzCategories(): array
    {
        $categories = [
            'isms' => [
                'name' => 'wizard.bsi_grundschutz.isms',
                'description' => 'wizard.bsi_grundschutz.isms_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.isms_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.isms_enhanced',
                'icon' => 'shield-check',
                'weight' => 2,
                'checks' => [
                    'bsi_grundschutz_isms' => [
                        'name' => 'wizard.check.bsi_grundschutz_isms',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_grundschutz_isms_desc',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'orp' => [
                'name' => 'wizard.bsi_grundschutz.orp',
                'description' => 'wizard.bsi_grundschutz.orp_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.orp_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.orp_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'bsi_grundschutz_orp' => [
                        'name' => 'wizard.check.bsi_grundschutz_orp',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_grundschutz_orp_desc',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'con' => [
                'name' => 'wizard.bsi_grundschutz.con',
                'description' => 'wizard.bsi_grundschutz.con_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.con_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.con_enhanced',
                'icon' => 'nav-file-earmark-text',
                'weight' => 1.5,
                'checks' => [
                    'bsi_grundschutz_con' => [
                        'name' => 'wizard.check.bsi_grundschutz_con',
                        'type' => 'document_review',
                        'document_categories' => ['policy', 'concept'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'ops' => [
                'name' => 'wizard.bsi_grundschutz.ops',
                'description' => 'wizard.bsi_grundschutz.ops_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.ops_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.ops_enhanced',
                'icon' => 'nav-gear',
                'weight' => 2,
                'checks' => [
                    'bsi_grundschutz_ops' => [
                        'name' => 'wizard.check.bsi_grundschutz_ops',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'det' => [
                'name' => 'wizard.bsi_grundschutz.det',
                'description' => 'wizard.bsi_grundschutz.det_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.det_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.det_enhanced',
                'icon' => 'ui-search',
                'weight' => 1.5,
                'checks' => [
                    'bsi_grundschutz_det' => [
                        'name' => 'wizard.check.bsi_grundschutz_det',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'app' => [
                'name' => 'wizard.bsi_grundschutz.app',
                'description' => 'wizard.bsi_grundschutz.app_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.app_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.app_enhanced',
                'icon' => 'asset-application',
                'weight' => 2,
                'checks' => [
                    'bsi_grundschutz_app' => [
                        'name' => 'wizard.check.bsi_grundschutz_app',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'sys' => [
                'name' => 'wizard.bsi_grundschutz.sys',
                'description' => 'wizard.bsi_grundschutz.sys_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.sys_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.sys_enhanced',
                'icon' => 'asset-endpoint',
                'weight' => 2,
                'checks' => [
                    'bsi_grundschutz_sys' => [
                        'name' => 'wizard.check.bsi_grundschutz_sys',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'ind' => [
                'name' => 'wizard.bsi_grundschutz.ind',
                'description' => 'wizard.bsi_grundschutz.ind_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.ind_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.ind_enhanced',
                'icon' => 'cpu',
                'weight' => 1,
                'checks' => [
                    'bsi_grundschutz_ind' => [
                        'name' => 'wizard.check.bsi_grundschutz_ind',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_grundschutz_ind_desc',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'net' => [
                'name' => 'wizard.bsi_grundschutz.net',
                'description' => 'wizard.bsi_grundschutz.net_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.net_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.net_enhanced',
                'icon' => 'asset-network',
                'weight' => 1.5,
                'checks' => [
                    'bsi_grundschutz_net' => [
                        'name' => 'wizard.check.bsi_grundschutz_net',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'inf' => [
                'name' => 'wizard.bsi_grundschutz.inf',
                'description' => 'wizard.bsi_grundschutz.inf_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.inf_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.inf_enhanced',
                'icon' => 'nav-building',
                'weight' => 1.5,
                'checks' => [
                    'bsi_grundschutz_inf' => [
                        'name' => 'wizard.check.bsi_grundschutz_inf',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_grundschutz_inf_desc',
                        'route' => 'app_location_index',
                    ],
                ],
            ],
            // Policy-Wizard outputs — BSI 200-1/2/3/4 policies, baseline coverage,
            // tier-consistency, KRITIS-applicability. Gated on BSI scope being
            // active (ComplianceFramework code 'BSI_GRUNDSCHUTZ' active OR
            // tenant policy setting 'org.targets_bsi_certification' true).
            // Returns null when out-of-scope; array_filter() drops the row.
            'bsi_policies' => $this->buildBsiPolicyWizardCategory(),
        ];

        // Drop categories that opted out (returned null) when BSI scope is
        // not declared for the current tenant — keeps the surface clean.
        return array_filter($categories, static fn ($cat): bool => $cat !== null);
    }

    /**
     * Build the "BSI Policy-Wizard outputs" category. Returns null when BSI
     * scope is not declared for the tenant.
     *
     * @return array<string, mixed>|null
     */
    private function buildBsiPolicyWizardCategory(): ?array
    {
        if (!$this->isBsiInScope($this->tenantContext->getCurrentTenant())) {
            return null;
        }

        $checks = [];
        foreach ([
            BsiTopLevelLeitliniePresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BsiIsmsConceptPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BsiBaselineCoverageCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BsiSchutzbedarfMethodPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BsiTierConsistencyCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
            BsiKritisFlagDocumentedCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
        ] as $checkId => $meta) {
            $checks[$checkId] = [
                'name' => sprintf('compliance_check.%s.title', $checkId),
                'description' => sprintf('compliance_check.%s.description', $checkId),
                'translation_domain' => 'policy_wizard',
                'type' => 'policy_wizard',
                'check_id' => $checkId,
                'priority' => $meta['priority'],
                'route' => $meta['route'],
            ];
        }

        return [
            'name' => 'wizard.bsi.bsi_policies',
            'description' => 'wizard.bsi.bsi_policies_desc',
            'icon' => 'ui-flag',
            'weight' => 2,
            'article' => '200-1/200-2/200-3/200-4',
            'checks' => $checks,
        ];
    }

    /**
     * Detects whether BSI Grundschutz is in scope for the tenant. Signals:
     * - ComplianceFramework with `code='BSI_GRUNDSCHUTZ'` and `isActive=true`,
     *   OR
     * - Tenant policy setting `org.targets_bsi_certification` set to true.
     *
     * Returns false when neither signal is present (keeps the BSI category
     * out of generic admin previews).
     */
    private function isBsiInScope(?Tenant $tenant): bool
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'BSI_GRUNDSCHUTZ']);
        if ($framework instanceof ComplianceFramework && $framework->isActive() === true) {
            return true;
        }

        if ($tenant === null || $this->tenantPolicySettingRepository === null) {
            return false;
        }
        $setting = $this->tenantPolicySettingRepository->findOneByTenantAndKey(
            $tenant,
            'org.targets_bsi_certification',
        );
        if ($setting === null) {
            return false;
        }
        $value = $setting->getValue();
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * BSI C5:2020 Cloud Compliance Categories
     *
     * Based on 17 chapters of the BSI Cloud Computing Compliance Controls Catalogue (C5:2020).
     */
    private function getBsiC5Categories(): array
    {
        return [
            'ois' => [
                'name' => 'wizard.bsi_c5.ois',
                'description' => 'wizard.bsi_c5.ois_desc',
                'maturity_baseline' => 'wizard.bsi_c5.ois_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.ois_enhanced',
                'icon' => 'nav-patch-check',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_ois' => [
                        'name' => 'wizard.check.bsi_c5_ois',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'sp' => [
                'name' => 'wizard.bsi_c5.sp',
                'description' => 'wizard.bsi_c5.sp_desc',
                'maturity_baseline' => 'wizard.bsi_c5.sp_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.sp_enhanced',
                'icon' => 'nav-shield',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_sp' => [
                        'name' => 'wizard.check.bsi_c5_sp',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'hr' => [
                'name' => 'wizard.bsi_c5.hr',
                'description' => 'wizard.bsi_c5.hr_desc',
                'maturity_baseline' => 'wizard.bsi_c5.hr_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.hr_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_hr' => [
                        'name' => 'wizard.check.bsi_c5_hr',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'route' => 'app_training_index',
                    ],
                ],
            ],
            'am' => [
                'name' => 'wizard.bsi_c5.am',
                'description' => 'wizard.bsi_c5.am_desc',
                'maturity_baseline' => 'wizard.bsi_c5.am_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.am_enhanced',
                'icon' => 'asset-server',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_am' => [
                        'name' => 'wizard.check.bsi_c5_am',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'ps' => [
                'name' => 'wizard.bsi_c5.ps',
                'description' => 'wizard.bsi_c5.ps_desc',
                'maturity_baseline' => 'wizard.bsi_c5.ps_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.ps_enhanced',
                'icon' => 'nav-building',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_ps' => [
                        'name' => 'wizard.check.bsi_c5_ps',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_c5_ps_desc',
                        'route' => 'app_location_index',
                    ],
                ],
            ],
            'rb' => [
                'name' => 'wizard.bsi_c5.rb',
                'description' => 'wizard.bsi_c5.rb_desc',
                'maturity_baseline' => 'wizard.bsi_c5.rb_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.rb_enhanced',
                'icon' => 'util-refresh',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_rb' => [
                        'name' => 'wizard.check.bsi_c5_rb',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
            'idm' => [
                'name' => 'wizard.bsi_c5.idm',
                'description' => 'wizard.bsi_c5.idm_desc',
                'maturity_baseline' => 'wizard.bsi_c5.idm_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.idm_enhanced',
                'icon' => 'ui-key',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_idm' => [
                        'name' => 'wizard.check.bsi_c5_idm',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'co' => [
                'name' => 'wizard.bsi_c5.co',
                'description' => 'wizard.bsi_c5.co_desc',
                'maturity_baseline' => 'wizard.bsi_c5.co_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.co_enhanced',
                'icon' => 'ui-lock',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_co' => [
                        'name' => 'wizard.check.bsi_c5_co',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'kos' => [
                'name' => 'wizard.bsi_c5.kos',
                'description' => 'wizard.bsi_c5.kos_desc',
                'maturity_baseline' => 'wizard.bsi_c5.kos_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.kos_enhanced',
                'icon' => 'nav-process',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_kos' => [
                        'name' => 'wizard.check.bsi_c5_kos',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'bcm' => [
                'name' => 'wizard.bsi_c5.bcm',
                'description' => 'wizard.bsi_c5.bcm_desc',
                'maturity_baseline' => 'wizard.bsi_c5.bcm_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.bcm_enhanced',
                'icon' => 'recovery',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_bcm' => [
                        'name' => 'wizard.check.bsi_c5_bcm',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
            'im' => [
                'name' => 'wizard.bsi_c5.im',
                'description' => 'wizard.bsi_c5.im_desc',
                'maturity_baseline' => 'wizard.bsi_c5.im_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.im_enhanced',
                'icon' => 'status-critical',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_im' => [
                        'name' => 'wizard.check.bsi_c5_im',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'com' => [
                'name' => 'wizard.bsi_c5.com',
                'description' => 'wizard.bsi_c5.com_desc',
                'maturity_baseline' => 'wizard.bsi_c5.com_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.com_enhanced',
                'icon' => 'nav-clipboard-check',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_com' => [
                        'name' => 'wizard.check.bsi_c5_com',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'inq' => [
                'name' => 'wizard.bsi_c5.inq',
                'description' => 'wizard.bsi_c5.inq_desc',
                'maturity_baseline' => 'wizard.bsi_c5.inq_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.inq_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_inq' => [
                        'name' => 'wizard.check.bsi_c5_inq',
                        'type' => 'supplier_assessment',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'pi' => [
                'name' => 'wizard.bsi_c5.pi',
                'description' => 'wizard.bsi_c5.pi_desc',
                'maturity_baseline' => 'wizard.bsi_c5.pi_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.pi_enhanced',
                'icon' => 'nav-tools',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_pi' => [
                        'name' => 'wizard.check.bsi_c5_pi',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'bei' => [
                'name' => 'wizard.bsi_c5.bei',
                'description' => 'wizard.bsi_c5.bei_desc',
                'maturity_baseline' => 'wizard.bsi_c5.bei_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.bei_enhanced',
                'icon' => 'nav-bar-chart',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_bei' => [
                        'name' => 'wizard.check.bsi_c5_bei',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_c5_bei_desc',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'pss' => [
                'name' => 'wizard.bsi_c5.pss',
                'description' => 'wizard.bsi_c5.pss_desc',
                'maturity_baseline' => 'wizard.bsi_c5.pss_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.pss_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_pss' => [
                        'name' => 'wizard.check.bsi_c5_pss',
                        'type' => 'supplier_assessment',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'bc' => [
                'name' => 'wizard.bsi_c5.bc',
                'description' => 'wizard.bsi_c5.bc_desc',
                'maturity_baseline' => 'wizard.bsi_c5.bc_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.bc_enhanced',
                'icon' => 'util-refresh',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_bc' => [
                        'name' => 'wizard.check.bsi_c5_bc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_c5_bc_desc',
                        'route' => 'app_management_review_index',
                    ],
                ],
            ],
        ];
    }

    private function getBsiGrundschutzStandardCategories(): array
    {
        return [
            'initiation' => [
                'name' => 'wizard.bsi_grundschutz_standard.initiation',
                'description' => 'wizard.bsi_grundschutz_standard.initiation_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.initiation_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.initiation_enhanced',
                'icon' => 'play',
                'weight' => 1.5,
                'checks' => [
                    'bsi_gs_std_initiation' => [
                        'name' => 'wizard.check.bsi_gs_std_initiation',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'security_concept' => [
                'name' => 'wizard.bsi_grundschutz_standard.security_concept',
                'description' => 'wizard.bsi_grundschutz_standard.security_concept_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.security_concept_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.security_concept_enhanced',
                'icon' => 'nav-file-earmark-text',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_concept' => [
                        'name' => 'wizard.check.bsi_gs_std_concept',
                        'type' => 'document_review',
                        'document_categories' => ['policy', 'concept'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'structure_analysis' => [
                'name' => 'wizard.bsi_grundschutz_standard.structure_analysis',
                'description' => 'wizard.bsi_grundschutz_standard.structure_analysis_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.structure_analysis_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.structure_analysis_enhanced',
                'icon' => 'nav-process',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_structure' => [
                        'name' => 'wizard.check.bsi_gs_std_structure',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'protection_needs' => [
                'name' => 'wizard.bsi_grundschutz_standard.protection_needs',
                'description' => 'wizard.bsi_grundschutz_standard.protection_needs_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.protection_needs_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.protection_needs_enhanced',
                'icon' => 'nav-shield-alert',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_protection_needs' => [
                        'name' => 'wizard.check.bsi_gs_std_protection_needs',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'modeling' => [
                'name' => 'wizard.bsi_grundschutz_standard.modeling',
                'description' => 'wizard.bsi_grundschutz_standard.modeling_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.modeling_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.modeling_enhanced',
                'icon' => 'nav-puzzle',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_modeling' => [
                        'name' => 'wizard.check.bsi_gs_std_modeling',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'grundschutz_check' => [
                'name' => 'wizard.bsi_grundschutz_standard.grundschutz_check',
                'description' => 'wizard.bsi_grundschutz_standard.grundschutz_check_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.grundschutz_check_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.grundschutz_check_enhanced',
                'icon' => 'status-ok',
                'weight' => 3,
                'checks' => [
                    'bsi_gs_std_check' => [
                        'name' => 'wizard.check.bsi_gs_std_check',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'risk_analysis' => [
                'name' => 'wizard.bsi_grundschutz_standard.risk_analysis',
                'description' => 'wizard.bsi_grundschutz_standard.risk_analysis_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.risk_analysis_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.risk_analysis_enhanced',
                'icon' => 'status-warning',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_risk_analysis' => [
                        'name' => 'wizard.check.bsi_gs_std_risk_analysis',
                        'type' => 'risk_coverage',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],
            'realization' => [
                'name' => 'wizard.bsi_grundschutz_standard.realization',
                'description' => 'wizard.bsi_grundschutz_standard.realization_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.realization_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.realization_enhanced',
                'icon' => 'nav-list-check',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_realization' => [
                        'name' => 'wizard.check.bsi_gs_std_realization',
                        'type' => 'treatment_plan',
                        'route' => 'app_risk_treatment_plan_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * KRITIS / NIS2-DE-Umsetzung categories (BSI-Kritisverordnung + NIS2-Umsetzungsgesetz)
     *
     * Covers obligations for critical infrastructure operators in Germany:
     * KRITIS threshold check, state of the art, 24h incident reporting,
     * BCM, biannual audit proof, top-management training, supply chain security.
     */
    private function getKritisCategories(): array
    {
        return [
            'scope_determination' => [
                'name' => 'wizard.kritis.scope_determination',
                'description' => 'wizard.kritis.scope_determination_desc',
                'maturity_baseline' => 'wizard.kritis.scope_determination_baseline',
                'maturity_enhanced' => 'wizard.kritis.scope_determination_enhanced',
                'icon' => 'nav-bullseye',
                'weight' => 2,
                'checks' => [
                    'kritis_scope' => [
                        'name' => 'wizard.check.kritis_scope',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'state_of_the_art' => [
                'name' => 'wizard.kritis.state_of_the_art',
                'description' => 'wizard.kritis.state_of_the_art_desc',
                'maturity_baseline' => 'wizard.kritis.state_of_the_art_baseline',
                'maturity_enhanced' => 'wizard.kritis.state_of_the_art_enhanced',
                'icon' => 'ui-stars',
                'weight' => 3,
                'checks' => [
                    'kritis_state_of_art' => [
                        'name' => 'wizard.check.kritis_state_of_art',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'incident_reporting' => [
                'name' => 'wizard.kritis.incident_reporting',
                'description' => 'wizard.kritis.incident_reporting_desc',
                'maturity_baseline' => 'wizard.kritis.incident_reporting_baseline',
                'maturity_enhanced' => 'wizard.kritis.incident_reporting_enhanced',
                'icon' => 'bell',
                'weight' => 3,
                'checks' => [
                    'kritis_incident_reporting' => [
                        'name' => 'wizard.check.kritis_incident_reporting',
                        'type' => 'incident_process',
                        'sla_hours' => 24,
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'bcm_kritis' => [
                'name' => 'wizard.kritis.bcm_kritis',
                'description' => 'wizard.kritis.bcm_kritis_desc',
                'maturity_baseline' => 'wizard.kritis.bcm_kritis_baseline',
                'maturity_enhanced' => 'wizard.kritis.bcm_kritis_enhanced',
                'icon' => 'recovery',
                'weight' => 2,
                'checks' => [
                    'kritis_bcm' => [
                        'name' => 'wizard.check.kritis_bcm',
                        'type' => 'bcm_coverage',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
            'audit_proof' => [
                'name' => 'wizard.kritis.audit_proof',
                'description' => 'wizard.kritis.audit_proof_desc',
                'maturity_baseline' => 'wizard.kritis.audit_proof_baseline',
                'maturity_enhanced' => 'wizard.kritis.audit_proof_enhanced',
                'icon' => 'nav-clipboard-data',
                'weight' => 2,
                'checks' => [
                    'kritis_audit_proof' => [
                        'name' => 'wizard.check.kritis_audit_proof',
                        'type' => 'audit_status',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'top_management' => [
                'name' => 'wizard.kritis.top_management',
                'description' => 'wizard.kritis.top_management_desc',
                'maturity_baseline' => 'wizard.kritis.top_management_baseline',
                'maturity_enhanced' => 'wizard.kritis.top_management_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'checks' => [
                    'kritis_top_mgmt' => [
                        'name' => 'wizard.check.kritis_top_mgmt',
                        'type' => 'training_coverage',
                        'route' => 'app_training_index',
                    ],
                ],
            ],
            'supplier_due_diligence' => [
                'name' => 'wizard.kritis.supplier_due_diligence',
                'description' => 'wizard.kritis.supplier_due_diligence_desc',
                'maturity_baseline' => 'wizard.kritis.supplier_due_diligence_baseline',
                'maturity_enhanced' => 'wizard.kritis.supplier_due_diligence_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'kritis_suppliers' => [
                        'name' => 'wizard.check.kritis_suppliers',
                        'type' => 'supplier_assessment',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * PCI-DSS v4.0.1 categories — 12 Requirements mapped to ISMS check types.
     *
     * Covers all 12 PCI-DSS Requirements of the Payment Card Industry Data Security Standard v4.0.1.
     */
    private function getPciDssCategories(): array
    {
        return [
            'req_1_network' => [
                'name' => 'wizard.pci_dss.req_1_network',
                'description' => 'wizard.pci_dss.req_1_network_desc',
                'icon' => 'asset-network',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_1' => [
                        'name' => 'wizard.check.pci_dss_req_1',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_2_secure_config' => [
                'name' => 'wizard.pci_dss.req_2_secure_config',
                'description' => 'wizard.pci_dss.req_2_secure_config_desc',
                'icon' => 'nav-gear',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_2' => [
                        'name' => 'wizard.check.pci_dss_req_2',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_3_protect_data' => [
                'name' => 'wizard.pci_dss.req_3_protect_data',
                'description' => 'wizard.pci_dss.req_3_protect_data_desc',
                'icon' => 'nav-shield-lock',
                'weight' => 3,
                'checks' => [
                    'pci_dss_req_3' => [
                        'name' => 'wizard.check.pci_dss_req_3',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_4_transit' => [
                'name' => 'wizard.pci_dss.req_4_transit',
                'description' => 'wizard.pci_dss.req_4_transit_desc',
                'icon' => 'nav-shield',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_4' => [
                        'name' => 'wizard.check.pci_dss_req_4',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_5_malware' => [
                'name' => 'wizard.pci_dss.req_5_malware',
                'description' => 'wizard.pci_dss.req_5_malware_desc',
                'icon' => 'bug',
                'weight' => 1.5,
                'checks' => [
                    'pci_dss_req_5' => [
                        'name' => 'wizard.check.pci_dss_req_5',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_6_secure_dev' => [
                'name' => 'wizard.pci_dss.req_6_secure_dev',
                'description' => 'wizard.pci_dss.req_6_secure_dev_desc',
                'icon' => 'asset-application',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_6' => [
                        'name' => 'wizard.check.pci_dss_req_6',
                        'type' => 'document_review',
                        'document_categories' => ['policy', 'concept'],
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'req_7_access' => [
                'name' => 'wizard.pci_dss.req_7_access',
                'description' => 'wizard.pci_dss.req_7_access_desc',
                'icon' => 'ui-key',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_7' => [
                        'name' => 'wizard.check.pci_dss_req_7',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_8_idauth' => [
                'name' => 'wizard.pci_dss.req_8_idauth',
                'description' => 'wizard.pci_dss.req_8_idauth_desc',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_8' => [
                        'name' => 'wizard.check.pci_dss_req_8',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_9_physical' => [
                'name' => 'wizard.pci_dss.req_9_physical',
                'description' => 'wizard.pci_dss.req_9_physical_desc',
                'icon' => 'nav-building',
                'weight' => 1.5,
                'checks' => [
                    'pci_dss_req_9' => [
                        'name' => 'wizard.check.pci_dss_req_9',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_location_index',
                    ],
                ],
            ],
            'req_10_logging' => [
                'name' => 'wizard.pci_dss.req_10_logging',
                'description' => 'wizard.pci_dss.req_10_logging_desc',
                'icon' => 'nav-journal-text',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_10' => [
                        'name' => 'wizard.check.pci_dss_req_10',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_11_test' => [
                'name' => 'wizard.pci_dss.req_11_test',
                'description' => 'wizard.pci_dss.req_11_test_desc',
                'icon' => 'nav-clipboard-check',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_11' => [
                        'name' => 'wizard.check.pci_dss_req_11',
                        'type' => 'audit_status',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'req_12_policy' => [
                'name' => 'wizard.pci_dss.req_12_policy',
                'description' => 'wizard.pci_dss.req_12_policy_desc',
                'icon' => 'nav-file-earmark-text',
                'weight' => 1.5,
                'checks' => [
                    'pci_dss_req_12' => [
                        'name' => 'wizard.check.pci_dss_req_12',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'route' => 'app_document_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * SOC 2 Type II categories — 5 AICPA Trust Services Criteria.
     *
     * Covers Security (mandatory), Availability, Processing Integrity, Confidentiality, Privacy.
     */
    private function getSoc2Categories(): array
    {
        return [
            'security' => [
                'name' => 'wizard.soc2.security',
                'description' => 'wizard.soc2.security_desc',
                'maturity_baseline' => 'wizard.soc2.security_baseline',
                'maturity_enhanced' => 'wizard.soc2.security_enhanced',
                'icon' => 'shield-check',
                'weight' => 3,
                'checks' => [
                    'soc2_security' => [
                        'name' => 'wizard.check.soc2_security',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'availability' => [
                'name' => 'wizard.soc2.availability',
                'description' => 'wizard.soc2.availability_desc',
                'maturity_baseline' => 'wizard.soc2.availability_baseline',
                'maturity_enhanced' => 'wizard.soc2.availability_enhanced',
                'icon' => 'recovery',
                'weight' => 2,
                'checks' => [
                    'soc2_availability' => [
                        'name' => 'wizard.check.soc2_availability',
                        'type' => 'bcm_coverage',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
            'processing_integrity' => [
                'name' => 'wizard.soc2.processing_integrity',
                'description' => 'wizard.soc2.processing_integrity_desc',
                'maturity_baseline' => 'wizard.soc2.processing_integrity_baseline',
                'maturity_enhanced' => 'wizard.soc2.processing_integrity_enhanced',
                'icon' => 'ui-check',
                'weight' => 2,
                'checks' => [
                    'soc2_processing_integrity' => [
                        'name' => 'wizard.check.soc2_processing_integrity',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'confidentiality' => [
                'name' => 'wizard.soc2.confidentiality',
                'description' => 'wizard.soc2.confidentiality_desc',
                'maturity_baseline' => 'wizard.soc2.confidentiality_baseline',
                'maturity_enhanced' => 'wizard.soc2.confidentiality_enhanced',
                'icon' => 'data-personal',
                'weight' => 2,
                'checks' => [
                    'soc2_confidentiality' => [
                        'name' => 'wizard.check.soc2_confidentiality',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'privacy' => [
                'name' => 'wizard.soc2.privacy',
                'description' => 'wizard.soc2.privacy_desc',
                'maturity_baseline' => 'wizard.soc2.privacy_baseline',
                'maturity_enhanced' => 'wizard.soc2.privacy_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'soc2_privacy' => [
                        'name' => 'wizard.check.soc2_privacy',
                        'type' => 'dsr_coverage',
                        'route' => 'app_data_subject_request_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * BSI IT-Grundschutz Kern-Absicherung categories (BSI 200-2 Kern-Absicherung)
     *
     * Focused on crown-jewel assets and accelerated protection assessment.
     * Smaller scope than Standard-Absicherung — only critical business processes.
     */
    private function getBsiGrundschutzKernCategories(): array
    {
        return [
            'crown_jewels' => [
                'name' => 'wizard.bsi_grundschutz_kern.crown_jewels',
                'description' => 'wizard.bsi_grundschutz_kern.crown_jewels_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_kern.crown_jewels_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_kern.crown_jewels_enhanced',
                'icon' => 'ui-star',
                'weight' => 3,
                'checks' => [
                    'bsi_gs_kern_crown_jewels' => [
                        'name' => 'wizard.check.bsi_gs_kern_crown_jewels',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'accelerated_protection_needs' => [
                'name' => 'wizard.bsi_grundschutz_kern.accelerated_protection_needs',
                'description' => 'wizard.bsi_grundschutz_kern.accelerated_protection_needs_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_kern.accelerated_protection_needs_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_kern.accelerated_protection_needs_enhanced',
                'icon' => 'status-warning',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_kern_protection_needs' => [
                        'name' => 'wizard.check.bsi_gs_kern_protection_needs',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'kern_modeling' => [
                'name' => 'wizard.bsi_grundschutz_kern.kern_modeling',
                'description' => 'wizard.bsi_grundschutz_kern.kern_modeling_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_kern.kern_modeling_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_kern.kern_modeling_enhanced',
                'icon' => 'nav-puzzle',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_kern_modeling' => [
                        'name' => 'wizard.check.bsi_gs_kern_modeling',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'kern_check' => [
                'name' => 'wizard.bsi_grundschutz_kern.kern_check',
                'description' => 'wizard.bsi_grundschutz_kern.kern_check_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_kern.kern_check_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_kern.kern_check_enhanced',
                'icon' => 'status-ok',
                'weight' => 3,
                'checks' => [
                    'bsi_gs_kern_check' => [
                        'name' => 'wizard.check.bsi_gs_kern_check',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'kern_realization' => [
                'name' => 'wizard.bsi_grundschutz_kern.kern_realization',
                'description' => 'wizard.bsi_grundschutz_kern.kern_realization_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_kern.kern_realization_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_kern.kern_realization_enhanced',
                'icon' => 'nav-list-check',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_kern_realization' => [
                        'name' => 'wizard.check.bsi_gs_kern_realization',
                        'type' => 'treatment_plan',
                        'route' => 'app_risk_treatment_plan_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * NIST Cybersecurity Framework 2.0 categories (6 Functions: GV/ID/PR/DE/RS/RC)
     *
     * Covers the six core functions of NIST CSF 2.0 including the new Govern function.
     */
    private function getNistCsfCategories(): array
    {
        return [
            'govern' => [
                'name' => 'wizard.nist_csf.govern',
                'description' => 'wizard.nist_csf.govern_desc',
                'maturity_baseline' => 'wizard.nist_csf.govern_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.govern_enhanced',
                'icon' => 'nav-patch-check',
                'weight' => 2,
                'checks' => [
                    'nist_csf_govern' => [
                        'name' => 'wizard.check.nist_csf_govern',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'identify' => [
                'name' => 'wizard.nist_csf.identify',
                'description' => 'wizard.nist_csf.identify_desc',
                'maturity_baseline' => 'wizard.nist_csf.identify_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.identify_enhanced',
                'icon' => 'ui-search',
                'weight' => 2,
                'checks' => [
                    'nist_csf_identify' => [
                        'name' => 'wizard.check.nist_csf_identify',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'protect' => [
                'name' => 'wizard.nist_csf.protect',
                'description' => 'wizard.nist_csf.protect_desc',
                'maturity_baseline' => 'wizard.nist_csf.protect_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.protect_enhanced',
                'icon' => 'nav-shield-lock',
                'weight' => 3,
                'checks' => [
                    'nist_csf_protect' => [
                        'name' => 'wizard.check.nist_csf_protect',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'detect' => [
                'name' => 'wizard.nist_csf.detect',
                'description' => 'wizard.nist_csf.detect_desc',
                'maturity_baseline' => 'wizard.nist_csf.detect_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.detect_enhanced',
                'icon' => 'ui-eye',
                'weight' => 2,
                'checks' => [
                    'nist_csf_detect' => [
                        'name' => 'wizard.check.nist_csf_detect',
                        'type' => 'incident_process',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'respond' => [
                'name' => 'wizard.nist_csf.respond',
                'description' => 'wizard.nist_csf.respond_desc',
                'maturity_baseline' => 'wizard.nist_csf.respond_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.respond_enhanced',
                'icon' => 'status-warning',
                'weight' => 2,
                'checks' => [
                    'nist_csf_respond' => [
                        'name' => 'wizard.check.nist_csf_respond',
                        'type' => 'incident_process',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'recover' => [
                'name' => 'wizard.nist_csf.recover',
                'description' => 'wizard.nist_csf.recover_desc',
                'maturity_baseline' => 'wizard.nist_csf.recover_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.recover_enhanced',
                'icon' => 'util-refresh',
                'weight' => 2,
                'checks' => [
                    'nist_csf_recover' => [
                        'name' => 'wizard.check.nist_csf_recover',
                        'type' => 'bcm_coverage',
                        'route' => 'app_bcm_index',
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

    /**
     * BSI C5:2026 Cloud Computing Compliance Criteria Catalogue Categories
     *
     * Reflects the 11 thematic clusters of the C5:2026 final release:
     * organisational, container, supply chain, post-quantum readiness,
     * confidential computing, AI/ML security, EUCS Substantial alignment,
     * enhanced client separation, NIS2 alignment, ISO 27001:2022 integration,
     * CSA CCM v4 alignment.
     */
    private function getBsiC52026Categories(): array
    {
        return [
            'organisation_personnel' => [
                'name' => 'wizard.bsi_c5_2026.organisation_personnel',
                'description' => 'wizard.bsi_c5_2026.organisation_personnel_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.organisation_personnel_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.organisation_personnel_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_2026_organisation_personnel' => [
                        'name' => 'wizard.check.bsi_c5_2026_organisation_personnel',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'route' => 'app_training_index',
                    ],
                ],
            ],
            'container_management' => [
                'name' => 'wizard.bsi_c5_2026.container_management',
                'description' => 'wizard.bsi_c5_2026.container_management_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.container_management_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.container_management_enhanced',
                'icon' => 'nav-boxes',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_2026_container_management' => [
                        'name' => 'wizard.check.bsi_c5_2026_container_management',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'supply_chain_security' => [
                'name' => 'wizard.bsi_c5_2026.supply_chain_security',
                'description' => 'wizard.bsi_c5_2026.supply_chain_security_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.supply_chain_security_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.supply_chain_security_enhanced',
                'icon' => 'link',
                'weight' => 2.5,
                'checks' => [
                    'bsi_c5_2026_supply_chain_security' => [
                        'name' => 'wizard.check.bsi_c5_2026_supply_chain_security',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_c5_2026_supply_chain_security_desc',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'post_quantum_cryptography' => [
                'name' => 'wizard.bsi_c5_2026.post_quantum_cryptography',
                'description' => 'wizard.bsi_c5_2026.post_quantum_cryptography_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.post_quantum_cryptography_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.post_quantum_cryptography_enhanced',
                'icon' => 'ui-key',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_2026_post_quantum_cryptography' => [
                        'name' => 'wizard.check.bsi_c5_2026_post_quantum_cryptography',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'description' => 'wizard.check.bsi_c5_2026_post_quantum_cryptography_desc',
                        'route' => 'app_crypto_index',
                    ],
                ],
            ],
            'confidential_computing' => [
                'name' => 'wizard.bsi_c5_2026.confidential_computing',
                'description' => 'wizard.bsi_c5_2026.confidential_computing_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.confidential_computing_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.confidential_computing_enhanced',
                'icon' => 'nav-shield-lock',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_2026_confidential_computing' => [
                        'name' => 'wizard.check.bsi_c5_2026_confidential_computing',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'ai_ml_security' => [
                'name' => 'wizard.bsi_c5_2026.ai_ml_security',
                'description' => 'wizard.bsi_c5_2026.ai_ml_security_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.ai_ml_security_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.ai_ml_security_enhanced',
                'icon' => 'cpu',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_2026_ai_ml_security' => [
                        'name' => 'wizard.check.bsi_c5_2026_ai_ml_security',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'eucs_alignment' => [
                'name' => 'wizard.bsi_c5_2026.eucs_alignment',
                'description' => 'wizard.bsi_c5_2026.eucs_alignment_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.eucs_alignment_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.eucs_alignment_enhanced',
                'icon' => 'ui-flag',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_2026_eucs_alignment' => [
                        'name' => 'wizard.check.bsi_c5_2026_eucs_alignment',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'enhanced_client_separation' => [
                'name' => 'wizard.bsi_c5_2026.enhanced_client_separation',
                'description' => 'wizard.bsi_c5_2026.enhanced_client_separation_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.enhanced_client_separation_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.enhanced_client_separation_enhanced',
                'icon' => 'nav-process',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_2026_enhanced_client_separation' => [
                        'name' => 'wizard.check.bsi_c5_2026_enhanced_client_separation',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'nis2_alignment' => [
                'name' => 'wizard.bsi_c5_2026.nis2_alignment',
                'description' => 'wizard.bsi_c5_2026.nis2_alignment_desc',
                'icon' => 'shield-check',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_2026_nis2_alignment' => [
                        'name' => 'wizard.check.bsi_c5_2026_nis2_alignment',
                        'type' => 'incident_coverage',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'iso_27001_integration' => [
                'name' => 'wizard.bsi_c5_2026.iso_27001_integration',
                'description' => 'wizard.bsi_c5_2026.iso_27001_integration_desc',
                'icon' => 'ui-stars',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_2026_iso_27001_integration' => [
                        'name' => 'wizard.check.bsi_c5_2026_iso_27001_integration',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'csa_ccm_alignment' => [
                'name' => 'wizard.bsi_c5_2026.csa_ccm_alignment',
                'description' => 'wizard.bsi_c5_2026.csa_ccm_alignment_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.csa_ccm_alignment_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.csa_ccm_alignment_enhanced',
                'icon' => 'nav-upload',
                'weight' => 1,
                'checks' => [
                    'bsi_c5_2026_csa_ccm_alignment' => [
                        'name' => 'wizard.check.bsi_c5_2026_csa_ccm_alignment',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * EU AI Act (Verordnung 2024/1689) Categories.
     * Articles 5-50 + 51-55 (GPAI) + 72 (Post-Market Monitoring).
     * Risk-based: prohibited (Art 5) + high-risk (Art 6, Annex III) + transparency (Art 50).
     */
    private function getEuAiActCategories(): array
    {
        return [
            'prohibited_practices' => [
                'name' => 'wizard.eu_ai_act.prohibited_practices',
                'description' => 'wizard.eu_ai_act.prohibited_practices_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.prohibited_practices_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.prohibited_practices_enhanced',
                'icon' => 'status-critical',
                'weight' => 3,
                'checks' => [
                    'eu_ai_act_prohibited' => [
                        'name' => 'wizard.check.eu_ai_act_prohibited',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'high_risk_classification' => [
                'name' => 'wizard.eu_ai_act.high_risk_classification',
                'description' => 'wizard.eu_ai_act.high_risk_classification_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.high_risk_classification_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.high_risk_classification_enhanced',
                'icon' => 'status-warning',
                'weight' => 3,
                'checks' => [
                    'eu_ai_act_classification' => [
                        'name' => 'wizard.check.eu_ai_act_classification',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'risk_management_system' => [
                'name' => 'wizard.eu_ai_act.risk_management_system',
                'description' => 'wizard.eu_ai_act.risk_management_system_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.risk_management_system_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.risk_management_system_enhanced',
                'icon' => 'nav-bar-chart',
                'weight' => 2.5,
                'checks' => [
                    'eu_ai_act_rms' => [
                        'name' => 'wizard.check.eu_ai_act_rms',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],
            'data_governance' => [
                'name' => 'wizard.eu_ai_act.data_governance',
                'description' => 'wizard.eu_ai_act.data_governance_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.data_governance_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.data_governance_enhanced',
                'icon' => 'nav-database',
                'weight' => 2,
                'checks' => [
                    'eu_ai_act_data_governance' => [
                        'name' => 'wizard.check.eu_ai_act_data_governance',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'technical_documentation' => [
                'name' => 'wizard.eu_ai_act.technical_documentation',
                'description' => 'wizard.eu_ai_act.technical_documentation_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.technical_documentation_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.technical_documentation_enhanced',
                'icon' => 'nav-file-earmark-text',
                'weight' => 2,
                'checks' => [
                    'eu_ai_act_tech_doc' => [
                        'name' => 'wizard.check.eu_ai_act_tech_doc',
                        'type' => 'document_review',
                        'document_categories' => ['technical', 'manual'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'human_oversight' => [
                'name' => 'wizard.eu_ai_act.human_oversight',
                'description' => 'wizard.eu_ai_act.human_oversight_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.human_oversight_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.human_oversight_enhanced',
                'icon' => 'ui-eye',
                'weight' => 2,
                'checks' => [
                    'eu_ai_act_oversight' => [
                        'name' => 'wizard.check.eu_ai_act_oversight',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'accuracy_robustness' => [
                'name' => 'wizard.eu_ai_act.accuracy_robustness',
                'description' => 'wizard.eu_ai_act.accuracy_robustness_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.accuracy_robustness_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.accuracy_robustness_enhanced',
                'icon' => 'shield-check',
                'weight' => 2,
                'checks' => [
                    'eu_ai_act_accuracy' => [
                        'name' => 'wizard.check.eu_ai_act_accuracy',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'transparency_obligations' => [
                'name' => 'wizard.eu_ai_act.transparency_obligations',
                'description' => 'wizard.eu_ai_act.transparency_obligations_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.transparency_obligations_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.transparency_obligations_enhanced',
                'icon' => 'status-info',
                'weight' => 1.5,
                'checks' => [
                    'eu_ai_act_transparency' => [
                        'name' => 'wizard.check.eu_ai_act_transparency',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'gpai_obligations' => [
                'name' => 'wizard.eu_ai_act.gpai_obligations',
                'description' => 'wizard.eu_ai_act.gpai_obligations_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.gpai_obligations_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.gpai_obligations_enhanced',
                'icon' => 'nav-process',
                'weight' => 1.5,
                'checks' => [
                    'eu_ai_act_gpai' => [
                        'name' => 'wizard.check.eu_ai_act_gpai',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'conformity_assessment' => [
                'name' => 'wizard.eu_ai_act.conformity_assessment',
                'description' => 'wizard.eu_ai_act.conformity_assessment_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.conformity_assessment_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.conformity_assessment_enhanced',
                'icon' => 'nav-clipboard-check',
                'weight' => 1.5,
                'checks' => [
                    'eu_ai_act_conformity' => [
                        'name' => 'wizard.check.eu_ai_act_conformity',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'post_market_monitoring' => [
                'name' => 'wizard.eu_ai_act.post_market_monitoring',
                'description' => 'wizard.eu_ai_act.post_market_monitoring_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.post_market_monitoring_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.post_market_monitoring_enhanced',
                'icon' => 'nav-activity',
                'weight' => 1.5,
                'checks' => [
                    'eu_ai_act_pmm' => [
                        'name' => 'wizard.check.eu_ai_act_pmm',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * ENISA EUCS Categories (European Cybersecurity Certification Scheme for Cloud Services).
     * Aligned to Substantial assurance level. ENISA Draft published 2020, ongoing work.
     */
    private function getEucsCategories(): array
    {
        return [
            'organization_security' => [
                'name' => 'wizard.eucs.organization_security',
                'description' => 'wizard.eucs.organization_security_desc',
                'maturity_baseline' => 'wizard.eucs.organization_security_baseline',
                'maturity_enhanced' => 'wizard.eucs.organization_security_enhanced',
                'icon' => 'nav-process',
                'weight' => 2,
                'checks' => [
                    'eucs_organization' => [
                        'name' => 'wizard.check.eucs_organization',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'risk_management' => [
                'name' => 'wizard.eucs.risk_management',
                'description' => 'wizard.eucs.risk_management_desc',
                'maturity_baseline' => 'wizard.eucs.risk_management_baseline',
                'maturity_enhanced' => 'wizard.eucs.risk_management_enhanced',
                'icon' => 'nav-bar-chart',
                'weight' => 2.5,
                'checks' => [
                    'eucs_risk' => [
                        'name' => 'wizard.check.eucs_risk',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],
            'asset_management' => [
                'name' => 'wizard.eucs.asset_management',
                'description' => 'wizard.eucs.asset_management_desc',
                'maturity_baseline' => 'wizard.eucs.asset_management_baseline',
                'maturity_enhanced' => 'wizard.eucs.asset_management_enhanced',
                'icon' => 'archive',
                'weight' => 2,
                'checks' => [
                    'eucs_assets' => [
                        'name' => 'wizard.check.eucs_assets',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'identity_access' => [
                'name' => 'wizard.eucs.identity_access',
                'description' => 'wizard.eucs.identity_access_desc',
                'maturity_baseline' => 'wizard.eucs.identity_access_baseline',
                'maturity_enhanced' => 'wizard.eucs.identity_access_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'eucs_iam' => [
                        'name' => 'wizard.check.eucs_iam',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'cryptography' => [
                'name' => 'wizard.eucs.cryptography',
                'description' => 'wizard.eucs.cryptography_desc',
                'maturity_baseline' => 'wizard.eucs.cryptography_baseline',
                'maturity_enhanced' => 'wizard.eucs.cryptography_enhanced',
                'icon' => 'ui-key',
                'weight' => 1.5,
                'checks' => [
                    'eucs_crypto' => [
                        'name' => 'wizard.check.eucs_crypto',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'operations_security' => [
                'name' => 'wizard.eucs.operations_security',
                'description' => 'wizard.eucs.operations_security_desc',
                'maturity_baseline' => 'wizard.eucs.operations_security_baseline',
                'maturity_enhanced' => 'wizard.eucs.operations_security_enhanced',
                'icon' => 'nav-gear',
                'weight' => 2,
                'checks' => [
                    'eucs_operations' => [
                        'name' => 'wizard.check.eucs_operations',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'communication_security' => [
                'name' => 'wizard.eucs.communication_security',
                'description' => 'wizard.eucs.communication_security_desc',
                'maturity_baseline' => 'wizard.eucs.communication_security_baseline',
                'maturity_enhanced' => 'wizard.eucs.communication_security_enhanced',
                'icon' => 'bell',
                'weight' => 1.5,
                'checks' => [
                    'eucs_communication' => [
                        'name' => 'wizard.check.eucs_communication',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'portability_interoperability' => [
                'name' => 'wizard.eucs.portability_interoperability',
                'description' => 'wizard.eucs.portability_interoperability_desc',
                'maturity_baseline' => 'wizard.eucs.portability_interoperability_baseline',
                'maturity_enhanced' => 'wizard.eucs.portability_interoperability_enhanced',
                'icon' => 'util-refresh',
                'weight' => 1,
                'checks' => [
                    'eucs_portability' => [
                        'name' => 'wizard.check.eucs_portability',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'incident_management' => [
                'name' => 'wizard.eucs.incident_management',
                'description' => 'wizard.eucs.incident_management_desc',
                'maturity_baseline' => 'wizard.eucs.incident_management_baseline',
                'maturity_enhanced' => 'wizard.eucs.incident_management_enhanced',
                'icon' => 'nav-shield-alert',
                'weight' => 2,
                'checks' => [
                    'eucs_incidents' => [
                        'name' => 'wizard.check.eucs_incidents',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'business_continuity' => [
                'name' => 'wizard.eucs.business_continuity',
                'description' => 'wizard.eucs.business_continuity_desc',
                'maturity_baseline' => 'wizard.eucs.business_continuity_baseline',
                'maturity_enhanced' => 'wizard.eucs.business_continuity_enhanced',
                'icon' => 'util-refresh',
                'weight' => 1.5,
                'checks' => [
                    'eucs_bcm' => [
                        'name' => 'wizard.check.eucs_bcm',
                        'type' => 'manual',
                        'priority' => 'high',
                        'module' => 'bcm',
                        'route' => 'app_business_continuity_plan_index',
                    ],
                ],
            ],
            'supplier_management' => [
                'name' => 'wizard.eucs.supplier_management',
                'description' => 'wizard.eucs.supplier_management_desc',
                'maturity_baseline' => 'wizard.eucs.supplier_management_baseline',
                'maturity_enhanced' => 'wizard.eucs.supplier_management_enhanced',
                'icon' => 'nav-building',
                'weight' => 1.5,
                'checks' => [
                    'eucs_suppliers' => [
                        'name' => 'wizard.check.eucs_suppliers',
                        'type' => 'manual',
                        'priority' => 'high',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'compliance_audit' => [
                'name' => 'wizard.eucs.compliance_audit',
                'description' => 'wizard.eucs.compliance_audit_desc',
                'maturity_baseline' => 'wizard.eucs.compliance_audit_baseline',
                'maturity_enhanced' => 'wizard.eucs.compliance_audit_enhanced',
                'icon' => 'nav-clipboard-check',
                'weight' => 1.5,
                'checks' => [
                    'eucs_compliance' => [
                        'name' => 'wizard.check.eucs_compliance',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * EU Cyber Resilience Act Categories (Verordnung 2024/2847).
     * Annex I Part I (Security) + Part II (Vulnerability handling) + Art. 11 (Disclosure) +
     * Art. 13 (CE marking) + Art. 14 (24h vulnerability reporting to ENISA).
     * Verbindlich ab 11.12.2027 fuer Produkte mit digitalen Elementen.
     */
    private function getCraCategories(): array
    {
        return [
            'security_by_design' => [
                'name' => 'wizard.cra.security_by_design',
                'description' => 'wizard.cra.security_by_design_desc',
                'maturity_baseline' => 'wizard.cra.security_by_design_baseline',
                'maturity_enhanced' => 'wizard.cra.security_by_design_enhanced',
                'icon' => 'nav-shield-check',
                'weight' => 3,
                'checks' => [
                    'cra_security_by_design' => [
                        'name' => 'wizard.check.cra_security_by_design',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'vulnerability_handling' => [
                'name' => 'wizard.cra.vulnerability_handling',
                'description' => 'wizard.cra.vulnerability_handling_desc',
                'maturity_baseline' => 'wizard.cra.vulnerability_handling_baseline',
                'maturity_enhanced' => 'wizard.cra.vulnerability_handling_enhanced',
                'icon' => 'bug',
                'weight' => 3,
                'checks' => [
                    'cra_vuln_handling' => [
                        'name' => 'wizard.check.cra_vuln_handling',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'module' => 'vulnerability_intel',
                        'route' => 'app_vulnerability_index',
                    ],
                ],
            ],
            'sbom_supply_chain' => [
                'name' => 'wizard.cra.sbom_supply_chain',
                'description' => 'wizard.cra.sbom_supply_chain_desc',
                'maturity_baseline' => 'wizard.cra.sbom_supply_chain_baseline',
                'maturity_enhanced' => 'wizard.cra.sbom_supply_chain_enhanced',
                'icon' => 'nav-list-check',
                'weight' => 2.5,
                'checks' => [
                    'cra_sbom' => [
                        'name' => 'wizard.check.cra_sbom',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'vulnerability_disclosure' => [
                'name' => 'wizard.cra.vulnerability_disclosure',
                'description' => 'wizard.cra.vulnerability_disclosure_desc',
                'maturity_baseline' => 'wizard.cra.vulnerability_disclosure_baseline',
                'maturity_enhanced' => 'wizard.cra.vulnerability_disclosure_enhanced',
                'icon' => 'bell',
                'weight' => 2,
                'checks' => [
                    'cra_disclosure' => [
                        'name' => 'wizard.check.cra_disclosure',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'incident_reporting' => [
                'name' => 'wizard.cra.incident_reporting',
                'description' => 'wizard.cra.incident_reporting_desc',
                'maturity_baseline' => 'wizard.cra.incident_reporting_baseline',
                'maturity_enhanced' => 'wizard.cra.incident_reporting_enhanced',
                'icon' => 'bell',
                'weight' => 2.5,
                'checks' => [
                    'cra_incident_reporting' => [
                        'name' => 'wizard.check.cra_incident_reporting',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'ce_marking_conformity' => [
                'name' => 'wizard.cra.ce_marking_conformity',
                'description' => 'wizard.cra.ce_marking_conformity_desc',
                'maturity_baseline' => 'wizard.cra.ce_marking_conformity_baseline',
                'maturity_enhanced' => 'wizard.cra.ce_marking_conformity_enhanced',
                'icon' => 'nav-patch-check',
                'weight' => 1.5,
                'checks' => [
                    'cra_ce_marking' => [
                        'name' => 'wizard.check.cra_ce_marking',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'technical_documentation' => [
                'name' => 'wizard.cra.technical_documentation',
                'description' => 'wizard.cra.technical_documentation_desc',
                'maturity_baseline' => 'wizard.cra.technical_documentation_baseline',
                'maturity_enhanced' => 'wizard.cra.technical_documentation_enhanced',
                'icon' => 'nav-file-earmark-text',
                'weight' => 1.5,
                'checks' => [
                    'cra_tech_doc' => [
                        'name' => 'wizard.check.cra_tech_doc',
                        'type' => 'document_review',
                        'document_categories' => ['technical', 'manual'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'support_period' => [
                'name' => 'wizard.cra.support_period',
                'description' => 'wizard.cra.support_period_desc',
                'maturity_baseline' => 'wizard.cra.support_period_baseline',
                'maturity_enhanced' => 'wizard.cra.support_period_enhanced',
                'icon' => 'nav-clock-history',
                'weight' => 1,
                'checks' => [
                    'cra_support_period' => [
                        'name' => 'wizard.check.cra_support_period',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
        ];
    }
}

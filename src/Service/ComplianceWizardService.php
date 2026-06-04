<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\ComplianceWizard\CategoryProvider\BsiFrameworkCategoryProvider;
use App\Service\ComplianceWizard\CategoryProvider\EuRegulatoryFrameworkCategoryProvider;
use App\Service\ComplianceWizard\CategoryProvider\IsoFrameworkCategoryProvider;
use App\Service\ComplianceWizard\CategoryProvider\OtherFrameworkCategoryProvider;
use App\Service\ComplianceWizard\CoverageCheckService;

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
        private readonly TenantContext $tenantContext,
        private readonly CoverageCheckService $coverageCheckService,
        private readonly IsoFrameworkCategoryProvider $isoProvider,
        private readonly EuRegulatoryFrameworkCategoryProvider $euProvider,
        private readonly BsiFrameworkCategoryProvider $bsiProvider,
        private readonly OtherFrameworkCategoryProvider $otherProvider,
        private readonly \App\Service\Tisax\TisaxMaturityAssessmentService $tisaxMaturityAssessmentService,
        private readonly ?\App\Repository\WizardManualConfirmationRepository $manualConfirmationRepository = null,
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
        return $this->coverageCheckService->getCatalogueCoverage($frameworkCode, $tenant);
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
                'categories' => $this->isoProvider->getIso27001Categories(),
            ],
            'nis2' => [
                'code' => 'NIS2',
                'name' => 'NIS2 Compliance',
                'description' => 'wizard.nis2.description',
                'icon' => 'nav-shield-alert',
                'color' => 'warning',
                'required_modules' => ['incidents', 'controls'],
                'recommended_modules' => ['authentication', 'assets'],
                'categories' => $this->euProvider->getNis2Categories(),
            ],
            'dora' => [
                'code' => 'DORA',
                'name' => 'DORA Readiness',
                'description' => 'wizard.dora.description',
                'icon' => 'nav-building',
                'color' => 'info',
                'required_modules' => ['bcm', 'incidents', 'controls'],
                'recommended_modules' => ['assets', 'risks'],
                'categories' => $this->euProvider->getDoraCategories(),
            ],
            'tisax' => [
                'code' => 'TISAX',
                'name' => 'TISAX Assessment',
                'description' => 'wizard.tisax.description',
                'icon' => 'nav-truck',
                'color' => 'secondary',
                'required_modules' => ['controls', 'assets'],
                'recommended_modules' => ['risks'],
                'categories' => $this->otherProvider->getTisaxCategories(),
            ],
            'gdpr' => [
                'code' => 'GDPR',
                'name' => 'GDPR/DSGVO Compliance',
                'description' => 'wizard.gdpr.description',
                'icon' => 'nav-people',
                'color' => 'success',
                'required_modules' => ['controls'],
                'recommended_modules' => ['incidents', 'training'],
                'categories' => $this->euProvider->getGdprCategories(),
            ],
            'iso22301' => [
                'code' => 'ISO22301',
                'name' => 'ISO 22301:2019 BCM Readiness',
                'description' => 'wizard.iso22301.description',
                'icon' => 'recovery',
                'color' => 'danger',
                'required_modules' => ['bcm'],
                'recommended_modules' => ['risks', 'audits', 'documents'],
                'categories' => $this->isoProvider->getIso22301Categories(),
            ],
            'iso27701' => [
                'code' => 'ISO27701',
                'name' => 'ISO 27701:2019 PIMS Readiness',
                'description' => 'wizard.iso27701.description',
                'icon' => 'shield-check',
                'color' => 'primary',
                'required_modules' => ['controls'],
                'recommended_modules' => ['privacy', 'incidents', 'suppliers'],
                'categories' => $this->isoProvider->getIso27701Categories(),
            ],
            'iso27017' => [
                'code' => 'ISO27017',
                'name' => 'ISO 27017:2015 Cloud Security',
                'description' => 'wizard.iso27017.description',
                'icon' => 'asset-cloud',
                'color' => 'info',
                'required_modules' => ['controls'],
                'recommended_modules' => ['assets', 'suppliers'],
                'categories' => $this->isoProvider->getIso27017Categories(),
            ],
            'iso27018' => [
                'code' => 'ISO27018',
                'name' => 'ISO 27018:2019 Cloud Privacy',
                'description' => 'wizard.iso27018.description',
                'icon' => 'asset-cloud',
                'color' => 'success',
                'required_modules' => ['controls'],
                'recommended_modules' => ['privacy', 'suppliers'],
                'categories' => $this->isoProvider->getIso27018Categories(),
            ],
            'iso42001' => [
                'code' => 'ISO42001',
                'name' => 'ISO 42001:2023 AI Management',
                'description' => 'wizard.iso42001.description',
                'icon' => 'nav-robot',
                'color' => 'warning',
                'required_modules' => ['controls'],
                'recommended_modules' => ['risks', 'assets'],
                'categories' => $this->isoProvider->getIso42001Categories(),
            ],
            'bsi_grundschutz' => [
                'code' => 'BSI-GRUNDSCHUTZ',
                'name' => 'BSI IT-Grundschutz (Basis-Absicherung)',
                'description' => 'wizard.bsi_grundschutz.description',
                'icon' => 'ui-flag',
                'color' => 'danger',
                'required_modules' => ['controls'],
                'recommended_modules' => ['assets', 'risks', 'audits', 'bcm'],
                'categories' => $this->bsiProvider->getBsiGrundschutzCategories(),
            ],
            'bsi_c5' => [
                'code' => 'BSI-C5',
                'name' => 'BSI C5:2020 Cloud Compliance',
                'description' => 'wizard.bsi_c5.description',
                'icon' => 'asset-cloud',
                'color' => 'info',
                'required_modules' => ['controls'],
                'recommended_modules' => ['suppliers', 'assets', 'incidents'],
                'categories' => $this->bsiProvider->getBsiC5Categories(),
            ],
            'bsi_c5_2026' => [
                'code' => 'BSI-C5-2026',
                'name' => 'BSI C5:2026 Cloud Compliance Criteria Catalogue',
                'description' => 'wizard.bsi_c5_2026.description',
                'icon' => 'asset-cloud',
                'color' => 'primary',
                'required_modules' => ['controls'],
                'recommended_modules' => ['suppliers', 'assets', 'incidents', 'crypto', 'training'],
                'categories' => $this->bsiProvider->getBsiC52026Categories(),
            ],
            'bsi_grundschutz_standard' => [
                'code' => 'BSI-GRUNDSCHUTZ-STANDARD',
                'name' => 'BSI IT-Grundschutz (Standard-Absicherung)',
                'description' => 'wizard.bsi_grundschutz_standard.description',
                'icon' => 'ui-flag',
                'color' => 'dark',
                'required_modules' => ['controls'],
                'recommended_modules' => ['assets', 'risks', 'audits', 'bcm', 'documents'],
                'categories' => $this->bsiProvider->getBsiGrundschutzStandardCategories(),
            ],
            'bsi_grundschutz_kern' => [
                'code' => 'BSI-GRUNDSCHUTZ-KERN',
                'name' => 'BSI IT-Grundschutz (Kern-Absicherung)',
                'description' => 'wizard.bsi_grundschutz_kern.description',
                'icon' => 'ui-flag',
                'color' => 'dark',
                'required_modules' => ['controls'],
                'recommended_modules' => ['assets', 'risks'],
                'categories' => $this->bsiProvider->getBsiGrundschutzKernCategories(),
            ],
            'nist_csf' => [
                'code' => 'NIST-CSF-2.0',
                'name' => 'NIST Cybersecurity Framework 2.0',
                'description' => 'wizard.nist_csf.description',
                'icon' => 'shield-check',
                'color' => 'primary',
                'required_modules' => ['controls'],
                'recommended_modules' => ['risks', 'assets', 'incidents', 'bcm'],
                'categories' => $this->otherProvider->getNistCsfCategories(),
            ],
            'kritis' => [
                'code' => 'KRITIS-DE',
                'name' => 'KRITIS / NIS2-DE-Umsetzung',
                'description' => 'wizard.kritis.description',
                'icon' => 'nav-building',
                'color' => 'warning',
                'required_modules' => ['controls'],
                'recommended_modules' => ['incidents', 'bcm', 'risks', 'audits'],
                'categories' => $this->euProvider->getKritisCategories(),
            ],
            'pci_dss' => [
                'code' => 'PCI-DSS-4.0.1',
                'name' => 'PCI-DSS v4.0.1 (Payment Card Industry)',
                'description' => 'wizard.pci_dss.description',
                'icon' => 'data-personal',
                'color' => 'info',
                'required_modules' => ['controls'],
                'recommended_modules' => ['assets', 'risks', 'incidents', 'audits'],
                'categories' => $this->otherProvider->getPciDssCategories(),
            ],
            'soc2' => [
                'code' => 'SOC2-TYPE-II',
                'name' => 'SOC 2 Type II (AICPA Trust Services)',
                'description' => 'wizard.soc2.description',
                'icon' => 'nav-patch-check',
                'color' => 'success',
                'required_modules' => ['controls'],
                'recommended_modules' => ['incidents', 'bcm', 'risks', 'audits', 'suppliers'],
                'categories' => $this->otherProvider->getSoc2Categories(),
            ],
            'eu_ai_act' => [
                'code' => 'EU-AI-ACT',
                'name' => 'EU AI Act (Verordnung 2024/1689)',
                'description' => 'wizard.eu_ai_act.description',
                'icon' => 'cpu',
                'color' => 'warning',
                'required_modules' => ['controls'],
                'recommended_modules' => ['risks', 'assets', 'incidents', 'documents'],
                'categories' => $this->euProvider->getEuAiActCategories(),
            ],
            'eucs' => [
                'code' => 'ENISA-EUCS',
                'name' => 'ENISA EUCS (European Cybersecurity Certification Scheme for Cloud Services)',
                'description' => 'wizard.eucs.description',
                'icon' => 'nav-shield',
                'color' => 'info',
                'required_modules' => ['controls'],
                'recommended_modules' => ['suppliers', 'assets', 'incidents', 'crypto', 'bcm'],
                'categories' => $this->euProvider->getEucsCategories(),
            ],
            'cra' => [
                'code' => 'EU-CRA',
                'name' => 'EU Cyber Resilience Act (Verordnung 2024/2847)',
                'description' => 'wizard.cra.description',
                'icon' => 'bug',
                'color' => 'danger',
                'required_modules' => ['controls'],
                'recommended_modules' => ['vulnerability_intel', 'incidents', 'assets', 'documents'],
                'categories' => $this->euProvider->getCraCategories(),
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
     * Get quick compliance status for all available wizards.
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
     * Get overall compliance summary across all frameworks.
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
            $categoryResult = $this->assessCategory($categoryKey, $category, $tenant, $activeModules, $wizardKey);
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
        array $activeModules,
        string $wizardKey = ''
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

            $result = $this->runCheck($check, $tenant, $wizardKey, (string) $checkKey);
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
            'status' => $this->coverageCheckService->getStatusFromScore($categoryScore),
        ];
    }

    /**
     * Run a single compliance check
     */
    private function runCheck(array $check, ?Tenant $tenant, string $wizardKey = '', string $checkKey = ''): array
    {
        $type = $check['type'] ?? 'manual';

        $result = match ($type) {
            'control_coverage' => $this->coverageCheckService->checkControlCoverage($check, $tenant),
            'maturity_coverage' => $this->checkMaturityCoverage($check, $tenant),
            'risk_coverage' => $this->coverageCheckService->checkRiskCoverage($check, $tenant),
            'asset_coverage' => $this->coverageCheckService->checkAssetCoverage($check, $tenant),
            'incident_process' => $this->coverageCheckService->checkIncidentProcess($check, $tenant),
            'bcm_coverage' => $this->coverageCheckService->checkBcmCoverage($check, $tenant),
            'training_coverage' => $this->coverageCheckService->checkTrainingCoverage($check, $tenant),
            'audit_status' => $this->coverageCheckService->checkAuditStatus($check, $tenant),
            'supplier_assessment' => $this->coverageCheckService->checkSupplierAssessment($check, $tenant),
            'document_review' => $this->coverageCheckService->checkDocumentReview($check, $tenant),
            'treatment_plan' => $this->coverageCheckService->checkTreatmentPlanStatus($check, $tenant),
            'consent_coverage' => $this->coverageCheckService->checkConsentCoverage($check, $tenant),
            'dsr_coverage' => $this->coverageCheckService->checkDsrCoverage($check, $tenant),
            'dpia_coverage' => $this->coverageCheckService->checkDpiaCoverage($check, $tenant),
            'entity_presence' => $this->coverageCheckService->checkEntityPresence($check, $tenant),
            'policy_wizard' => $this->coverageCheckService->dispatchPolicyWizardCheck($check, $tenant),
            default => $this->runManualCheck($check, $tenant, $wizardKey, $checkKey),
        };

        return [
            'name' => $check['name'] ?? $type,
            'description' => $check['description'] ?? '',
            'score' => $result['score'],
            'status' => $this->coverageCheckService->getStatusFromScore($result['score']),
            'details' => $result['details'],
            'gap' => $result['gap'] ?? null,
            'route' => $check['route'] ?? null,
            'module' => $check['module'] ?? null,
            'manual_confirmable' => ($check['type'] ?? 'manual') === 'manual',
            'check_key' => $checkKey,
        ];
    }

    /**
     * Genuinely-manual check (no backing entity). Scores 100 once a
     * WizardManualConfirmation row exists for (tenant, wizard, check) — i.e.
     * a user ticked "addressed" — otherwise stays an open gap as before.
     *
     * @param array<string, mixed> $check
     * @return array{score: float, details: array<string, mixed>, gap: ?array<string, mixed>}
     */
    private function runManualCheck(array $check, ?Tenant $tenant, string $wizardKey, string $checkKey): array
    {
        $confirmed = false;
        if ($tenant !== null && $this->manualConfirmationRepository !== null && $wizardKey !== '' && $checkKey !== '') {
            $confirmation = $this->manualConfirmationRepository->findOneForCheck($tenant, $wizardKey, $checkKey);
            $confirmed = $confirmation !== null && $confirmation->isConfirmed();
        }

        if ($confirmed) {
            return [
                'score' => 100,
                'details' => ['type' => 'manual', 'confirmed' => true],
                'gap' => null,
            ];
        }

        return [
            'score' => 0,
            'details' => ['type' => 'manual', 'requires_confirmation' => true, 'confirmed' => false],
            'gap' => [
                'title' => $check['name'] ?? 'Manual Check Required',
                'description' => $check['description'] ?? '',
                'priority' => $check['priority'] ?? 'medium',
                'action' => $check['action'] ?? null,
                'route' => $check['route'] ?? null,
            ],
        ];
    }

    /**
     * Maturity-based coverage for TISAX/VDA-ISA modules.
     *
     * Derives fulfilment from the imported Reifegrad
     * (ComplianceRequirement.maturityCurrent, AL3 target = level 3 → "established"
     * counts as 100 %) instead of generic ISO-control implementation, so a
     * VDA-ISA workbook the tenant uploaded is actually reflected. Requires
     * $check['framework'] (the framework code whose tenant-uploaded rows hold the
     * Reifegrad). Returns the standard {score, details, gap} shape.
     *
     * @param array<string, mixed> $check
     * @return array{score: float, details: array<string, mixed>, gap: ?array<string, mixed>}
     */
    private function checkMaturityCoverage(array $check, ?Tenant $tenant): array
    {
        $frameworkCode = $check['framework'] ?? null;
        $framework = is_string($frameworkCode)
            ? $this->frameworkRepository->findOneBy(['code' => $frameworkCode])
            : null;

        $notAssessedGap = [
            'title' => 'wizard.gap.maturity_not_assessed',
            'description' => 'wizard.gap.maturity_not_assessed_description',
            'priority' => 'high',
            'action' => 'wizard.action.import_maturity',
            'route' => $check['route'] ?? null,
        ];

        if ($framework === null || $tenant === null) {
            return [
                'score' => 0.0,
                'details' => ['type' => 'maturity', 'total' => 0, 'fulfilled' => 0],
                'gap' => $notAssessedGap,
            ];
        }

        $tier = is_string($check['tier'] ?? null) ? $check['tier'] : null;
        $coverage = $this->tisaxMaturityAssessmentService->computeCoverage($framework, $tenant, $tier);

        $gap = null;
        if ($coverage['score'] < 100) {
            $gap = $coverage['total'] === 0
                ? $notAssessedGap
                : [
                    'title' => 'wizard.gap.maturity_below_target',
                    'description' => 'wizard.gap.maturity_below_target_description',
                    'description_params' => ['%count%' => count($coverage['not_fulfilled'])],
                    'priority' => count($coverage['not_fulfilled']) > 5 ? 'critical' : 'high',
                    'action' => 'wizard.action.improve_maturity',
                    'route' => $check['route'] ?? null,
                    'items' => array_slice($coverage['not_fulfilled'], 0, 5),
                ];
        }

        return [
            'score' => $coverage['score'],
            'details' => [
                'type' => 'maturity',
                'total' => $coverage['total'],
                'fulfilled' => $coverage['fulfilled'],
                'not_fulfilled' => count($coverage['not_fulfilled']),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Backward-compat facade for test-reflection — delegates to CoverageCheckService.
     */
    private function checkConsentCoverage(array $check, ?Tenant $tenant): array
    {
        return $this->coverageCheckService->checkConsentCoverage($check, $tenant);
    }

    /**
     * Backward-compat facade for test-reflection — delegates to CoverageCheckService.
     */
    private function checkDsrCoverage(array $check, ?Tenant $tenant): array
    {
        return $this->coverageCheckService->checkDsrCoverage($check, $tenant);
    }

    /**
     * Backward-compat facade for test-reflection — delegates to CoverageCheckService.
     */
    private function checkDpiaCoverage(array $check, ?Tenant $tenant): array
    {
        return $this->coverageCheckService->checkDpiaCoverage($check, $tenant);
    }
}

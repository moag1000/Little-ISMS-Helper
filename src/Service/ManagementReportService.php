<?php

namespace App\Service;

use DateTime;
use DateTimeImmutable;
use App\Entity\Risk;
use App\Entity\Control;
use App\Entity\Incident;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\TrainingRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\DataBreachRepository;
use App\Repository\RiskTreatmentPlanRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Management Report Service
 *
 * Phase 7A: Provides comprehensive management reporting data for executive dashboards,
 * risk management reports, BCM reports, compliance status, and more.
 *
 * Supports:
 * - Executive Dashboard summaries
 * - Risk Management Reports (Risk Register, Trends, Treatment Plans)
 * - BCM Reports (BC Plans, Exercises, BIA)
 * - Audit Management Reports
 * - Compliance Status Reports
 * - Asset Management Reports
 */
class ManagementReportService
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly ControlRepository $controlRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly InternalAuditRepository $auditRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly ManagementReviewRepository $managementReviewRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly BCExerciseRepository $bcExerciseRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly RiskTreatmentPlanRepository $riskTreatmentPlanRepository,
        private readonly DashboardStatisticsService $dashboardStatisticsService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // ===================== EXECUTIVE DASHBOARD =====================

    /**
     * Get executive summary data for management dashboard
     *
     * @return array Executive summary with key metrics
     */
    public function getExecutiveSummary(): array
    {
        $risks = $this->riskRepository->findAll();
        $controls = $this->controlRepository->findAll();

        // Risk analysis
        $criticalRisks = array_filter($risks, fn(Risk $r): bool => $r->getRiskScore() >= 20);
        $highRisks = array_filter($risks, fn(Risk $r): bool => $r->getRiskScore() >= 12 && $r->getRiskScore() < 20);
        $mediumRisks = array_filter($risks, fn(Risk $r): bool => $r->getRiskScore() >= 6 && $r->getRiskScore() < 12);
        $lowRisks = array_filter($risks, fn(Risk $r): bool => $r->getRiskScore() < 6);

        // Control analysis
        $implementedControls = array_filter($controls, fn(Control $c): bool => $c->getImplementationStatus() === 'implemented');
        $inProgressControls = array_filter($controls, fn(Control $c): bool => $c->getImplementationStatus() === 'in_progress');

        // Incident analysis (last 12 months)
        $yearAgo = new DateTimeImmutable('-12 months');
        $recentIncidents = array_filter(
            $this->incidentRepository->findAll(),
            fn(Incident $i): bool => $i->getDetectedAt() >= $yearAgo
        );
        $openIncidents = array_filter($recentIncidents, fn(Incident $i): bool => in_array($i->getStatus(), ['new', 'investigating', 'in_progress']));

        // Compliance calculation
        $compliancePercentage = count($controls) > 0
            ? round((count($implementedControls) / count($controls)) * 100, 1)
            : 0;

        return [
            'generated_at' => new DateTime(),
            'summary' => [
                'total_assets' => $this->assetRepository->count([]),
                'total_risks' => count($risks),
                'total_controls' => count($controls),
                'compliance_percentage' => $compliancePercentage,
            ],
            'risk_distribution' => [
                'critical' => count($criticalRisks),
                'high' => count($highRisks),
                'medium' => count($mediumRisks),
                'low' => count($lowRisks),
            ],
            'control_status' => [
                'implemented' => count($implementedControls),
                'in_progress' => count($inProgressControls),
                'not_started' => count($controls) - count($implementedControls) - count($inProgressControls),
            ],
            'incident_summary' => [
                'total_12_months' => count($recentIncidents),
                'open' => count($openIncidents),
                'resolved' => count($recentIncidents) - count($openIncidents),
            ],
            'audit_summary' => [
                'total_audits' => $this->auditRepository->count([]),
                'completed_this_year' => count($this->auditRepository->findBy(['status' => 'completed'])),
            ],
            'training_summary' => [
                'total_trainings' => $this->trainingRepository->count([]),
            ],
        ];
    }

    // ===================== RISK MANAGEMENT REPORTS =====================

    /**
     * Get comprehensive risk management report data
     *
     * @param array $filters Optional filters (status, category, owner)
     * @return array Risk management report data
     */
    public function getRiskManagementReport(array $filters = []): array
    {
        $risks = $this->riskRepository->findAll();

        // Apply filters
        if (!empty($filters['status'])) {
            $risks = array_filter($risks, fn(Risk $r): bool => $r->getStatus() === $filters['status']);
        }
        if (!empty($filters['category'])) {
            $risks = array_filter($risks, fn(Risk $r): bool => $r->getCategory() === $filters['category']);
        }

        // Group by category
        $byCategory = [];
        foreach ($risks as $risk) {
            $category = $risk->getCategory() ?? 'Uncategorized';
            if (!isset($byCategory[$category])) {
                $byCategory[$category] = [];
            }
            $byCategory[$category][] = $risk;
        }

        // Group by risk level
        $byLevel = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];
        foreach ($risks as $risk) {
            $score = $risk->getRiskScore();
            if ($score >= 20) {
                $byLevel['critical'][] = $risk;
            } elseif ($score >= 12) {
                $byLevel['high'][] = $risk;
            } elseif ($score >= 6) {
                $byLevel['medium'][] = $risk;
            } else {
                $byLevel['low'][] = $risk;
            }
        }

        // Treatment plan summary
        $treatmentPlans = $this->riskTreatmentPlanRepository->findAll();
        $activePlans = array_filter($treatmentPlans, fn($p): bool => $p->getStatus() === 'in_progress');
        $overduePlans = array_filter($treatmentPlans, fn($p): bool => $p->getTargetDate() !== null && $p->getTargetDate() < new DateTime() && $p->getStatus() !== 'completed');

        return [
            'generated_at' => new DateTime(),
            'total_risks' => count($risks),
            'risks' => $risks,
            'by_category' => $byCategory,
            'by_level' => $byLevel,
            'level_counts' => [
                'critical' => count($byLevel['critical']),
                'high' => count($byLevel['high']),
                'medium' => count($byLevel['medium']),
                'low' => count($byLevel['low']),
            ],
            'treatment_plans' => [
                'total' => count($treatmentPlans),
                'active' => count($activePlans),
                'overdue' => count($overduePlans),
            ],
            'average_risk_score' => count($risks) > 0
                ? round(array_sum(array_map(fn(Risk $r): int => $r->getRiskScore(), $risks)) / count($risks), 2)
                : 0,
        ];
    }

    /**
     * Get risk trend data for the last N months
     *
     * @param int $months Number of months to analyze
     * @return array Monthly risk trend data
     */
    public function getRiskTrendData(int $months = 12): array
    {
        $trends = [];
        $now = new DateTime();

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = (clone $now)->modify("-{$i} months")->modify('first day of this month')->setTime(0, 0);
            $monthEnd = (clone $monthStart)->modify('last day of this month')->setTime(23, 59, 59);

            $risks = $this->riskRepository->findAll();
            $risksInMonth = array_filter($risks, function (Risk $r) use ($monthStart, $monthEnd): bool {
                $created = $r->getCreatedAt();
                return $created !== null && $created >= $monthStart && $created <= $monthEnd;
            });

            $trends[] = [
                'month' => $monthStart->format('Y-m'),
                'month_name' => $monthStart->format('M Y'),
                'new_risks' => count($risksInMonth),
                'high_critical' => count(array_filter($risksInMonth, fn(Risk $r): bool => $r->getRiskScore() >= 12)),
            ];
        }

        return $trends;
    }

    // ===================== BCM REPORTS =====================

    /**
     * Get Business Continuity Management report data
     *
     * @return array BCM report data
     */
    public function getBCMReport(): array
    {
        $bcPlans = $this->bcPlanRepository->findAll();
        $exercises = $this->bcExerciseRepository->findAll();
        $processes = $this->businessProcessRepository->findAll();

        // Classify plans by status
        $activePlans = array_filter($bcPlans, fn($p): bool => $p->getStatus() === 'active' || $p->getStatus() === 'approved');
        $draftPlans = array_filter($bcPlans, fn($p): bool => $p->getStatus() === 'draft');
        $reviewPlans = array_filter($bcPlans, fn($p): bool => $p->getStatus() === 'review');

        // Exercise analysis (last 12 months)
        $yearAgo = new DateTimeImmutable('-12 months');
        $recentExercises = array_filter($exercises, function ($e) use ($yearAgo): bool {
            $date = $e->getExerciseDate();
            return $date !== null && $date >= $yearAgo;
        });

        // Process criticality analysis
        $criticalProcesses = array_filter($processes, fn($p): bool => $p->getCriticality() === 'critical' || $p->getCriticality() === 'high');

        return [
            'generated_at' => new DateTime(),
            'bc_plans' => [
                'total' => count($bcPlans),
                'active' => count($activePlans),
                'draft' => count($draftPlans),
                'review' => count($reviewPlans),
                'plans' => $bcPlans,
            ],
            'exercises' => [
                'total' => count($exercises),
                'last_12_months' => count($recentExercises),
                'exercises' => $exercises,
            ],
            'business_processes' => [
                'total' => count($processes),
                'critical' => count($criticalProcesses),
                'processes' => $processes,
            ],
        ];
    }

    /**
     * Get Business Impact Analysis (BIA) summary
     *
     * @return array BIA summary data
     */
    public function getBIASummary(): array
    {
        $processes = $this->businessProcessRepository->findAll();

        // Group by criticality
        $byCriticality = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];

        foreach ($processes as $process) {
            $criticality = $process->getCriticality() ?? 'medium';
            if (isset($byCriticality[$criticality])) {
                $byCriticality[$criticality][] = $process;
            }
        }

        // RTO/RPO analysis
        $processesWithRTO = array_filter($processes, fn($p): bool => $p->getRto() !== null);
        $processesWithRPO = array_filter($processes, fn($p): bool => $p->getRpo() !== null);

        return [
            'generated_at' => new DateTime(),
            'total_processes' => count($processes),
            'by_criticality' => [
                'critical' => count($byCriticality['critical']),
                'high' => count($byCriticality['high']),
                'medium' => count($byCriticality['medium']),
                'low' => count($byCriticality['low']),
            ],
            'processes' => $byCriticality,
            'rto_defined' => count($processesWithRTO),
            'rpo_defined' => count($processesWithRPO),
            'coverage' => count($processes) > 0
                ? round((count($processesWithRTO) / count($processes)) * 100, 1)
                : 0,
        ];
    }

    // ===================== COMPLIANCE REPORTS =====================

    /**
     * Get compliance status report across all frameworks
     *
     * @return array Compliance status data
     */
    public function getComplianceStatusReport(): array
    {
        $frameworks = $this->complianceFrameworkRepository->findAll();
        $controls = $this->controlRepository->findAll();

        $implementedControls = array_filter($controls, fn(Control $c): bool => $c->getImplementationStatus() === 'implemented');
        $applicableControls = array_filter($controls, fn(Control $c): bool => $c->isApplicable());

        // Framework compliance summary
        $frameworkStatus = [];
        foreach ($frameworks as $framework) {
            $frameworkStatus[] = [
                'name' => $framework->getName(),
                'code' => $framework->getCode(),
                'active' => $framework->isActive(),
            ];
        }

        return [
            'generated_at' => new DateTime(),
            'overall_compliance' => count($applicableControls) > 0
                ? round((count($implementedControls) / count($applicableControls)) * 100, 1)
                : 0,
            'controls' => [
                'total' => count($controls),
                'applicable' => count($applicableControls),
                'implemented' => count($implementedControls),
                'not_applicable' => count($controls) - count($applicableControls),
            ],
            'frameworks' => $frameworkStatus,
            'active_frameworks' => count(array_filter($frameworks, fn($f): bool => $f->isActive())),
        ];
    }

    // ===================== AUDIT REPORTS =====================

    /**
     * Get audit management report data
     *
     * @return array Audit management data
     */
    public function getAuditManagementReport(): array
    {
        $audits = $this->auditRepository->findAll();

        // Group by status
        $byStatus = [
            'planned' => [],
            'in_progress' => [],
            'completed' => [],
            'cancelled' => [],
        ];

        foreach ($audits as $audit) {
            $status = $audit->getStatus() ?? 'planned';
            if (isset($byStatus[$status])) {
                $byStatus[$status][] = $audit;
            }
        }

        // This year's audits
        $thisYear = (new DateTime())->format('Y');
        $auditsThisYear = array_filter($audits, function ($a) use ($thisYear): bool {
            $date = $a->getAuditDate();
            return $date !== null && $date->format('Y') === $thisYear;
        });

        // Management reviews
        $reviews = $this->managementReviewRepository->findAll();
        $thisYearReviews = array_filter($reviews, function ($r) use ($thisYear): bool {
            $date = $r->getReviewDate();
            return $date !== null && $date->format('Y') === $thisYear;
        });

        return [
            'generated_at' => new DateTime(),
            'audits' => [
                'total' => count($audits),
                'this_year' => count($auditsThisYear),
                'by_status' => [
                    'planned' => count($byStatus['planned']),
                    'in_progress' => count($byStatus['in_progress']),
                    'completed' => count($byStatus['completed']),
                    'cancelled' => count($byStatus['cancelled']),
                ],
            ],
            'management_reviews' => [
                'total' => count($reviews),
                'this_year' => count($thisYearReviews),
            ],
        ];
    }

    // ===================== ASSET REPORTS =====================

    /**
     * Get asset management report data
     *
     * @return array Asset management data
     */
    public function getAssetManagementReport(): array
    {
        $assets = $this->assetRepository->findAll();

        // Group by type
        $byType = [];
        foreach ($assets as $asset) {
            $type = $asset->getAssetType() ?? 'Other';
            if (!isset($byType[$type])) {
                $byType[$type] = [];
            }
            $byType[$type][] = $asset;
        }

        // Group by classification
        $byClassification = [];
        foreach ($assets as $asset) {
            $classification = $asset->getClassification() ?? 'Unclassified';
            if (!isset($byClassification[$classification])) {
                $byClassification[$classification] = [];
            }
            $byClassification[$classification][] = $asset;
        }

        return [
            'generated_at' => new DateTime(),
            'total_assets' => count($assets),
            'by_type' => array_map(fn($arr): int => count($arr), $byType),
            'by_classification' => array_map(fn($arr): int => count($arr), $byClassification),
            'assets' => $assets,
        ];
    }

    // ===================== DATA BREACH / GDPR REPORTS =====================

    /**
     * Get data breach summary report
     *
     * @return array Data breach report data
     */
    public function getDataBreachReport(): array
    {
        $breaches = $this->dataBreachRepository->findAll();

        // Group by severity
        $bySeverity = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];

        foreach ($breaches as $breach) {
            $severity = $breach->getSeverity() ?? 'medium';
            if (isset($bySeverity[$severity])) {
                $bySeverity[$severity][] = $breach;
            }
        }

        // Notification status
        $notified = array_filter($breaches, fn($b): bool => $b->isNotificationRequired() && $b->getNotificationDate() !== null);
        $pendingNotification = array_filter($breaches, fn($b): bool => $b->isNotificationRequired() && $b->getNotificationDate() === null);

        // This year's breaches
        $thisYear = (new DateTime())->format('Y');
        $breachesThisYear = array_filter($breaches, function ($b) use ($thisYear): bool {
            $date = $b->getDiscoveredAt();
            return $date !== null && $date->format('Y') === $thisYear;
        });

        return [
            'generated_at' => new DateTime(),
            'total_breaches' => count($breaches),
            'this_year' => count($breachesThisYear),
            'by_severity' => [
                'critical' => count($bySeverity['critical']),
                'high' => count($bySeverity['high']),
                'medium' => count($bySeverity['medium']),
                'low' => count($bySeverity['low']),
            ],
            'notification_status' => [
                'notified' => count($notified),
                'pending' => count($pendingNotification),
            ],
            'breaches' => $breaches,
        ];
    }

    // ===================== TREND ANALYSIS =====================

    /**
     * Get incident trend data for the last N months
     *
     * @param int $months Number of months to analyze
     * @return array Monthly incident trend data
     */
    public function getIncidentTrendData(int $months = 12): array
    {
        $trends = [];
        $now = new DateTime();
        $incidents = $this->incidentRepository->findAll();

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = (clone $now)->modify("-{$i} months")->modify('first day of this month')->setTime(0, 0);
            $monthEnd = (clone $monthStart)->modify('last day of this month')->setTime(23, 59, 59);

            $incidentsInMonth = array_filter($incidents, function (Incident $inc) use ($monthStart, $monthEnd): bool {
                $detected = $inc->getDetectedAt();
                return $detected !== null && $detected >= $monthStart && $detected <= $monthEnd;
            });

            $highSeverity = array_filter($incidentsInMonth, fn(Incident $inc): bool => in_array($inc->getSeverity(), ['high', 'critical']));

            $trends[] = [
                'month' => $monthStart->format('Y-m'),
                'month_name' => $monthStart->format('M Y'),
                'total' => count($incidentsInMonth),
                'high_critical' => count($highSeverity),
            ];
        }

        return $trends;
    }

    // ===================== REPORT METADATA =====================

    /**
     * Get available report categories
     *
     * @return array Report categories with metadata
     */
    public function getReportCategories(): array
    {
        return [
            'executive' => [
                'key' => 'executive',
                'name' => 'Executive Summary',
                'name_de' => 'Executive Summary',
                'icon' => 'speedometer2',
                'color' => 'primary',
                'reports' => [
                    ['key' => 'executive_summary', 'name' => 'Executive Dashboard', 'name_de' => 'Executive Dashboard'],
                    ['key' => 'kpi_overview', 'name' => 'KPI Overview', 'name_de' => 'KPI-Übersicht'],
                    ['key' => 'management_kpis', 'name' => 'Management KPIs', 'name_de' => 'Management-Kennzahlen'],
                ],
            ],
            'risk' => [
                'key' => 'risk',
                'name' => 'Risk Management',
                'name_de' => 'Risikomanagement',
                'icon' => 'exclamation-triangle',
                'color' => 'danger',
                'reports' => [
                    ['key' => 'risk_register', 'name' => 'Risk Register', 'name_de' => 'Risikoregister'],
                    ['key' => 'risk_trends', 'name' => 'Risk Trends', 'name_de' => 'Risikotrends'],
                    ['key' => 'treatment_plans', 'name' => 'Treatment Plans', 'name_de' => 'Behandlungspläne'],
                ],
            ],
            'bcm' => [
                'key' => 'bcm',
                'name' => 'Business Continuity',
                'name_de' => 'Business Continuity',
                'icon' => 'shield-check',
                'color' => 'success',
                'reports' => [
                    ['key' => 'bc_plans', 'name' => 'BC Plans Overview', 'name_de' => 'BC-Pläne Übersicht'],
                    ['key' => 'bc_exercises', 'name' => 'BC Exercises', 'name_de' => 'BC-Übungen'],
                    ['key' => 'bia_summary', 'name' => 'BIA Summary', 'name_de' => 'BIA-Zusammenfassung'],
                ],
            ],
            'compliance' => [
                'key' => 'compliance',
                'name' => 'Compliance',
                'name_de' => 'Compliance',
                'icon' => 'clipboard-check',
                'color' => 'info',
                'reports' => [
                    ['key' => 'compliance_status', 'name' => 'Compliance Status', 'name_de' => 'Compliance-Status'],
                    ['key' => 'soa_report', 'name' => 'Statement of Applicability', 'name_de' => 'Statement of Applicability'],
                ],
            ],
            'audit' => [
                'key' => 'audit',
                'name' => 'Audit Management',
                'name_de' => 'Audit-Management',
                'icon' => 'search',
                'color' => 'warning',
                'reports' => [
                    ['key' => 'audit_summary', 'name' => 'Audit Summary', 'name_de' => 'Audit-Zusammenfassung'],
                    ['key' => 'management_reviews', 'name' => 'Management Reviews', 'name_de' => 'Managementbewertungen'],
                ],
            ],
            'assets' => [
                'key' => 'assets',
                'name' => 'Asset Management',
                'name_de' => 'Asset-Management',
                'icon' => 'server',
                'color' => 'secondary',
                'reports' => [
                    ['key' => 'asset_inventory', 'name' => 'Asset Inventory', 'name_de' => 'Asset-Inventar'],
                ],
            ],
            'gdpr' => [
                'key' => 'gdpr',
                'name' => 'Data Protection (GDPR)',
                'name_de' => 'Datenschutz (DSGVO)',
                'icon' => 'shield-lock',
                'color' => 'dark',
                'reports' => [
                    ['key' => 'data_breach_summary', 'name' => 'Data Breach Summary', 'name_de' => 'Datenpannen-Übersicht'],
                ],
            ],
        ];
    }

    // ===================== MANAGEMENT KPIs =====================

    /**
     * Get module-aware management KPIs for reports
     *
     * Returns translated KPIs with their current values, status, and trends.
     * Suitable for inclusion in management reports and executive summaries.
     *
     * @param string $locale The locale for translations (de, en)
     * @return array Translated management KPIs grouped by category
     */
    public function getManagementKPIs(string $locale = 'de'): array
    {
        $rawKpis = $this->dashboardStatisticsService->getManagementKPIs();
        $translatedKpis = [];

        // Category translations
        $categoryTranslations = [
            'core' => $this->translator->trans('dashboard.management_kpis.core.title', [], 'dashboard', $locale),
            'risk_management' => $this->translator->trans('dashboard.management_kpis.risk_management.title', [], 'dashboard', $locale),
            'asset_management' => $this->translator->trans('dashboard.management_kpis.asset_management.title', [], 'dashboard', $locale),
            'incident_management' => $this->translator->trans('dashboard.management_kpis.incident_management.title', [], 'dashboard', $locale),
            'business_continuity' => $this->translator->trans('dashboard.management_kpis.business_continuity.title', [], 'dashboard', $locale),
            'training' => $this->translator->trans('dashboard.management_kpis.training.title', [], 'dashboard', $locale),
            'audits' => $this->translator->trans('dashboard.management_kpis.audits.title', [], 'dashboard', $locale),
            'supplier_management' => $this->translator->trans('dashboard.management_kpis.supplier_management.title', [], 'dashboard', $locale),
            'documentation' => $this->translator->trans('dashboard.management_kpis.documentation.title', [], 'dashboard', $locale),
        ];

        // Status translations
        $statusTranslations = [
            'good' => $this->translator->trans('dashboard.management_kpis.status.good', [], 'dashboard', $locale),
            'warning' => $this->translator->trans('dashboard.management_kpis.status.warning', [], 'dashboard', $locale),
            'danger' => $this->translator->trans('dashboard.management_kpis.status.danger', [], 'dashboard', $locale),
            'info' => $this->translator->trans('dashboard.management_kpis.status.info', [], 'dashboard', $locale),
        ];

        foreach ($rawKpis as $category => $kpis) {
            if ($category === 'active_modules') {
                continue; // Skip metadata
            }

            if (!is_array($kpis)) {
                continue;
            }

            $translatedCategory = [
                'key' => $category,
                'name' => $categoryTranslations[$category] ?? ucfirst(str_replace('_', ' ', $category)),
                'kpis' => [],
            ];

            foreach ($kpis as $key => $kpi) {
                if (!is_array($kpi)) {
                    continue;
                }

                $translatedCategory['kpis'][] = [
                    'key' => $key,
                    'label' => $this->translator->trans('dashboard.kpi.' . $key, [], 'dashboard', $locale),
                    'value' => $kpi['value'] ?? 0,
                    'unit' => $kpi['unit'] ?? '',
                    'status' => $kpi['status'] ?? 'info',
                    'status_label' => $statusTranslations[$kpi['status'] ?? 'info'] ?? $kpi['status'],
                    'details' => $kpi['details'] ?? null,
                ];
            }

            if (!empty($translatedCategory['kpis'])) {
                $translatedKpis[] = $translatedCategory;
            }
        }

        return [
            'generated_at' => new DateTime(),
            'locale' => $locale,
            'categories' => $translatedKpis,
            'active_modules' => $rawKpis['active_modules'] ?? [],
        ];
    }

    /**
     * Get KPI summary for executive report (single-page format)
     *
     * Returns a condensed KPI summary suitable for the first page of an executive report.
     *
     * @param string $locale The locale for translations
     * @return array Summary KPIs
     */
    public function getKPISummaryForReport(string $locale = 'de'): array
    {
        $kpis = $this->getManagementKPIs($locale);

        // Extract key metrics for executive summary
        $summaryKpis = [];

        foreach ($kpis['categories'] as $category) {
            foreach ($category['kpis'] as $kpi) {
                // Include critical KPIs in summary
                if (in_array($kpi['key'], [
                    'control_compliance',
                    'high_risks',
                    'critical_risks',
                    'open_incidents',
                    'overdue_treatments',
                    'training_completion_rate',
                    'bia_coverage',
                ])) {
                    $summaryKpis[] = [
                        'category' => $category['name'],
                        'label' => $kpi['label'],
                        'value' => $kpi['value'] . $kpi['unit'],
                        'status' => $kpi['status'],
                        'status_label' => $kpi['status_label'],
                    ];
                }
            }
        }

        return [
            'generated_at' => new DateTime(),
            'summary_kpis' => $summaryKpis,
            'full_kpis' => $kpis,
        ];
    }
}

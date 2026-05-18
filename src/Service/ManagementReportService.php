<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use App\Enum\RiskTreatmentPlanStatus;
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
use App\Entity\ManagementReview;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
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
final class ManagementReportService
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
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    // ===================== EXECUTIVE DASHBOARD =====================

    /**
     * Get executive summary data for management dashboard
     *
     * @param ?\DateTime $from Optional start date filter (by creation date)
     * @param ?\DateTime $to Optional end date filter (by creation date)
     * @return array Executive summary with key metrics
     */
    public function getExecutiveSummary(?\DateTime $from = null, ?\DateTime $to = null): array
    {
        $risks = $this->riskRepository->findAll();
        $controls = $this->controlRepository->findAll();

        // Apply date range filter if provided
        if ($from !== null || $to !== null) {
            $risks = $this->filterByDateRange($risks, $from, $to);
        }

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
        $openIncidents = array_filter($recentIncidents, fn(Incident $i): bool => in_array($i->getStatus(), [IncidentStatus::Reported, IncidentStatus::InInvestigation, IncidentStatus::InResolution], true));

        // Compliance calculation (implemented / applicable to match getComplianceStatusReport)
        $applicableControls = array_filter($controls, fn(Control $c): bool => $c->isApplicable());
        $compliancePercentage = count($applicableControls) > 0
            ? round((count($implementedControls) / count($applicableControls)) * 100, 1)
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
     * @param ?\DateTime $from Optional start date filter (by creation date)
     * @param ?\DateTime $to Optional end date filter (by creation date)
     * @return array Risk management report data
     */
    public function getRiskManagementReport(array $filters = [], ?\DateTime $from = null, ?\DateTime $to = null): array
    {
        $risks = $this->riskRepository->findAll();

        // Apply date range filter if provided
        if ($from !== null || $to !== null) {
            $risks = $this->filterByDateRange($risks, $from, $to);
        }

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
        $activePlans = array_filter($treatmentPlans, fn($p): bool => $p->getStatus() === RiskTreatmentPlanStatus::InProgress->value);
        $overduePlans = array_filter($treatmentPlans, fn($p): bool => $p->getTargetDate() !== null && $p->getTargetDate() < new DateTime() && $p->getStatus() !== RiskTreatmentPlanStatus::Completed->value);

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
     * Shows cumulative risk posture at each month-end: how many risks existed
     * (created before month-end) and how many of those were high/critical.
     * Also includes new_risks as a supplementary metric.
     *
     * @param int $months Number of months to analyze
     * @return array Monthly risk trend data with cumulative posture
     */
    public function getRiskTrendData(int $months = 12): array
    {
        $trends = [];
        $now = new DateTime();
        $allRisks = $this->riskRepository->findAll();

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = (clone $now)->modify("-{$i} months")->modify('first day of this month')->setTime(0, 0);
            $monthEnd = (clone $monthStart)->modify('last day of this month')->setTime(23, 59, 59);

            // Cumulative: count risks that EXISTED at month-end
            // (created before month-end, excluding closed risks for accuracy)
            $existingAtMonthEnd = 0;
            $highCriticalAtMonthEnd = 0;
            $newInMonth = 0;

            foreach ($allRisks as $risk) {
                $created = $risk->getCreatedAt();
                if ($created === null || $created > $monthEnd) {
                    continue; // Not yet created at this month-end
                }

                // Count as new if created within this specific month
                if ($created >= $monthStart && $created <= $monthEnd) {
                    $newInMonth++;
                }

                // Skip closed risks (they no longer represent active exposure)
                $status = $risk->getStatus();
                if ($status === 'closed') {
                    continue;
                }

                $existingAtMonthEnd++;
                if ($risk->getInherentRiskLevel() >= 12) {
                    $highCriticalAtMonthEnd++;
                }
            }

            $trends[] = [
                'month' => $monthStart->format('Y-m'),
                'month_name' => $monthStart->format('M Y'),
                'total' => $existingAtMonthEnd,
                'high_critical' => $highCriticalAtMonthEnd,
                'new_risks' => $newInMonth,
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
     * @param ?\DateTime $from Optional start date filter
     * @param ?\DateTime $to Optional end date filter
     * @return array Compliance status data
     */
    public function getComplianceStatusReport(?\DateTime $from = null, ?\DateTime $to = null): array
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
            // Vorzugsweise tatsächliches Datum (durchgeführt), sonst geplantes Datum.
            $date = $a->getActualDate() ?? $a->getPlannedDate();
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
            $classification = $asset->getDataClassification() ?? 'Unclassified';
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

            $highSeverity = array_filter($incidentsInMonth, fn(Incident $inc): bool => in_array($inc->getSeverity(), [IncidentSeverity::High, IncidentSeverity::Critical]));

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
                'icon' => 'nav-speedometer',
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
                'icon' => 'nav-exclamation-triangle',
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
                'icon' => 'nav-shield-check',
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
                'icon' => 'nav-clipboard-check',
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
                'icon' => 'ui-search',
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
                'icon' => 'asset-server',
                'color' => 'secondary',
                'reports' => [
                    ['key' => 'asset_inventory', 'name' => 'Asset Inventory', 'name_de' => 'Asset-Inventar'],
                ],
            ],
            'gdpr' => [
                'key' => 'gdpr',
                'name' => 'Data Protection (GDPR)',
                'name_de' => 'Datenschutz (DSGVO)',
                'icon' => 'nav-shield-lock',
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

    // ===================== MANAGEMENT REVIEW OUTPUT (ISO 27001 Clause 9.3) =====================

    /**
     * Get Management Review Output data per ISO 27001:2022 Clause 9.3
     *
     * Aggregates all required inputs for a management review:
     * - Status of actions from previous reviews
     * - Changes in internal/external issues (risk landscape)
     * - Performance information (KPIs, nonconformities, audit results)
     * - Feedback from interested parties
     * - Risk assessment/treatment results
     * - Opportunities for improvement
     *
     * @param string $locale The locale for KPI translations
     * @return array Complete management review output data
     */
    public function getManagementReviewReport(string $locale = 'en'): array
    {
        $executiveSummary = $this->getExecutiveSummary();
        $riskReport = $this->getRiskManagementReport();
        $auditReport = $this->getAuditManagementReport();
        $complianceReport = $this->getComplianceStatusReport();
        $kpiSummary = $this->getKPISummaryForReport($locale);

        // Treatment plan data
        $treatmentPlans = $this->riskTreatmentPlanRepository->findAll();
        $activePlans = array_filter($treatmentPlans, fn($p): bool => $p->getStatus() === RiskTreatmentPlanStatus::InProgress->value);
        $overduePlans = array_filter($treatmentPlans, fn($p): bool => $p->getTargetDate() !== null && $p->getTargetDate() < new DateTime() && $p->getStatus() !== RiskTreatmentPlanStatus::Completed->value);

        return [
            'generated_at' => new DateTime(),
            'executive_data' => $executiveSummary,
            'risk_data' => $riskReport,
            'audit_data' => $auditReport,
            'compliance_data' => $complianceReport,
            'kpi_summary' => $kpiSummary['summary_kpis'] ?? [],
            'treatment_data' => [
                'total' => count($treatmentPlans),
                'active' => count($activePlans),
                'overdue' => count($overduePlans),
            ],
        ];
    }

    // ===================== DATE RANGE FILTERING =====================

    /**
     * Filter entities by creation date range
     *
     * @param array $entities Entities with getCreatedAt() method
     * @param ?\DateTime $from Start date (inclusive)
     * @param ?\DateTime $to End date (inclusive, set to end of day)
     * @return array Filtered entities
     */
    private function filterByDateRange(array $entities, ?\DateTime $from = null, ?\DateTime $to = null): array
    {
        if ($to !== null) {
            $to = (clone $to)->setTime(23, 59, 59);
        }

        return array_filter($entities, function ($entity) use ($from, $to): bool {
            if (!method_exists($entity, 'getCreatedAt')) {
                return true;
            }
            $date = $entity->getCreatedAt();
            if ($date === null) {
                return true;
            }
            if ($from !== null && $date < $from) {
                return false;
            }
            if ($to !== null && $date > $to) {
                return false;
            }
            return true;
        });
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

    /**
     * V3 B4 / EF-1: Auto-Collect Management-Review aus existing §9.3 sources.
     *
     * Aggregiert ISO 27001 §9.3 Inputs (Risk-Status, Audit-Results, NC-Status,
     * Performance-KPIs, Treatment-Plan-Effectiveness, Improvement-Opportunities)
     * und persistiert eine vorbefuellte ManagementReview-Entity.
     *
     * Der CISO/Compliance-Manager kann diese danach noch editieren und finalisieren —
     * spart aber den Initial-Aggregations-Aufwand komplett.
     *
     * @param Tenant            $tenant         Tenant-Context fuer das neue Review
     * @param \DateTimeInterface $referenceDate Stichtag (typisch: Quartal-Ende oder Jahres-Ende)
     * @param string            $locale         Sprache fuer Status-Beschreibungen
     */
    public function createManagementReviewFromReport(
        Tenant $tenant,
        \DateTimeInterface $referenceDate,
        string $locale = 'de'
    ): ManagementReview {
        $report = $this->getManagementReviewReport($locale);

        $review = new ManagementReview();
        $review->setTenant($tenant);
        $review->setTitle(sprintf(
            '%s — %s',
            $this->translator->trans('management_review.auto_collect.title_prefix', [], 'management_review'),
            $referenceDate->format('Y-m-d')
        ));
        $review->setReviewDate($referenceDate instanceof \DateTime ? $referenceDate : new \DateTime($referenceDate->format('Y-m-d')));
        $review->setStatus('draft');
        $review->setCreatedAt(new DateTimeImmutable());

        // §9.3 (a) — Status of actions from previous management reviews
        // (left blank initially; user fills based on previous-review tracking)

        // §9.3 (b) — Changes in external/internal issues relevant to ISMS
        $review->setChangesRelevantToISMS($this->buildContextChangesNarrative($report, $locale));

        // §9.3 (c) — Feedback on ISMS performance
        // §9.3 (c.1) — Nonconformities & corrective actions
        $review->setNonConformitiesStatus($this->buildNcStatusNarrative($report, $locale));
        $review->setCorrectiveActionsStatus($this->buildCorrectiveActionsNarrative($report, $locale));
        // §9.3 (c.2) — Monitoring & measurement results
        $review->setPerformanceEvaluation($this->buildPerformanceNarrative($report, $locale));
        // §9.3 (c.3) — Audit results
        $review->setAuditResults($this->buildAuditResultsNarrative($report, $locale));
        // §9.3 (c.4) — Fulfilment of information security objectives
        // (mapped via objectives review later)

        // §9.3 (d) — Feedback from interested parties
        // (left blank — user fills based on interested-parties roster)

        // §9.3 (e) — Risks and opportunities
        $review->setRisksReview($this->buildRiskNarrative($report, $locale));

        // §9.3 (f) — Opportunities for improvement
        $review->setOpportunitiesForImprovement($this->buildImprovementOpportunitiesNarrative($report, $locale));

        // §9.3 outputs — decisions & action items (initially empty; filled in meeting)
        $review->setDecisions('');
        $review->setActionItems('');

        $this->entityManager->persist($review);
        $this->entityManager->flush();

        $this->auditLogger->logCreate(
            'ManagementReview',
            $review->getId(),
            [
                'title' => $review->getTitle(),
                'reviewDate' => $review->getReviewDate()?->format('Y-m-d'),
                'autoCollected' => true,
            ],
            'Management-Review auto-generated from §9.3 sources at ' . $referenceDate->format('Y-m-d')
        );

        return $review;
    }

    private function buildNcStatusNarrative(array $report, string $locale): string
    {
        $audit = $report['audit_data'];
        $totalFindings = $audit['total_findings'] ?? 0;
        $openFindings = $audit['open_findings'] ?? 0;
        $closedFindings = $totalFindings - $openFindings;

        if ($locale === 'de') {
            return sprintf(
                "Nonkonformitäten-Status (Stand: %s)\n\nGesamt: %d Findings\nOffen: %d\nGeschlossen: %d\n\nDetailaufschlüsselung siehe Audit-Findings-Modul.",
                date('Y-m-d'),
                $totalFindings,
                $openFindings,
                $closedFindings
            );
        }
        return sprintf(
            "Non-conformities status (as of %s)\n\nTotal: %d findings\nOpen: %d\nClosed: %d\n\nDetailed breakdown in Audit Findings module.",
            date('Y-m-d'),
            $totalFindings,
            $openFindings,
            $closedFindings
        );
    }

    private function buildCorrectiveActionsNarrative(array $report, string $locale): string
    {
        $treatment = $report['treatment_data'];
        if ($locale === 'de') {
            return sprintf(
                "Korrekturmaßnahmen-Status\n\nGesamt: %d Treatment-Pläne\nAktiv: %d\nÜberfällig: %d",
                $treatment['total'],
                $treatment['active'],
                $treatment['overdue']
            );
        }
        return sprintf(
            "Corrective actions status\n\nTotal: %d treatment plans\nActive: %d\nOverdue: %d",
            $treatment['total'],
            $treatment['active'],
            $treatment['overdue']
        );
    }

    private function buildPerformanceNarrative(array $report, string $locale): string
    {
        $kpis = $report['kpi_summary'] ?? [];
        $lines = [];
        foreach ($kpis as $kpi) {
            $lines[] = sprintf('- %s: %s [%s]', $kpi['label'], $kpi['value'], $kpi['status_label']);
        }
        $body = implode("\n", $lines);

        if ($locale === 'de') {
            return "Leistungsbeurteilung — KPI-Snapshot:\n\n" . $body;
        }
        return "Performance evaluation — KPI snapshot:\n\n" . $body;
    }

    private function buildAuditResultsNarrative(array $report, string $locale): string
    {
        $audit = $report['audit_data'];
        $completed = $audit['completed_audits'] ?? 0;
        $upcoming = $audit['upcoming_audits'] ?? 0;

        if ($locale === 'de') {
            return sprintf(
                "Audit-Ergebnisse\n\nDurchgeführte Audits: %d\nGeplante Audits: %d\n\nAusführliche Berichte im Audit-Modul.",
                $completed,
                $upcoming
            );
        }
        return sprintf(
            "Audit results\n\nCompleted audits: %d\nPlanned audits: %d\n\nDetailed reports in Audits module.",
            $completed,
            $upcoming
        );
    }

    private function buildRiskNarrative(array $report, string $locale): string
    {
        $risk = $report['risk_data'];
        if ($locale === 'de') {
            return sprintf(
                "Risikolage\n\nGesamt: %d Risiken\nKritisch: %d\nHoch: %d\nMittel: %d\nNiedrig: %d",
                $risk['total_risks'] ?? 0,
                $risk['critical_count'] ?? 0,
                $risk['high_count'] ?? 0,
                $risk['medium_count'] ?? 0,
                $risk['low_count'] ?? 0
            );
        }
        return sprintf(
            "Risk landscape\n\nTotal: %d risks\nCritical: %d\nHigh: %d\nMedium: %d\nLow: %d",
            $risk['total_risks'] ?? 0,
            $risk['critical_count'] ?? 0,
            $risk['high_count'] ?? 0,
            $risk['medium_count'] ?? 0,
            $risk['low_count'] ?? 0
        );
    }

    private function buildContextChangesNarrative(array $report, string $locale): string
    {
        if ($locale === 'de') {
            return "Kontext-Änderungen seit letztem Review\n\n[Vom CISO/Compliance-Manager auszufüllen — relevante Änderungen extern (Recht, Markt, Lieferkette) und intern (Org-Struktur, IT-Landschaft, Prozesse, Personen).]";
        }
        return "Context changes since last review\n\n[To be filled by CISO/Compliance Manager — relevant changes external (legal, market, supply chain) and internal (org structure, IT landscape, processes, people).]";
    }

    private function buildImprovementOpportunitiesNarrative(array $report, string $locale): string
    {
        $treatment = $report['treatment_data'];
        $kpis = $report['kpi_summary'] ?? [];
        $criticalKpis = array_filter($kpis, fn($k): bool => ($k['status'] ?? '') === 'critical');

        if ($locale === 'de') {
            $body = "Verbesserungspotenziale (auto-detektiert)\n\n";
            if ($treatment['overdue'] > 0) {
                $body .= sprintf("- %d überfällige Treatment-Pläne — Eskalations-Bedarf prüfen\n", $treatment['overdue']);
            }
            foreach ($criticalKpis as $kpi) {
                $body .= sprintf("- KPI '%s' im kritischen Bereich (%s)\n", $kpi['label'], $kpi['value']);
            }
            $body .= "\n[Manuell ergänzen: weitere Verbesserungschancen aus Mitarbeiter-Feedback, Audits, Vorfällen.]";
            return $body;
        }
        $body = "Improvement opportunities (auto-detected)\n\n";
        if ($treatment['overdue'] > 0) {
            $body .= sprintf("- %d overdue treatment plans — escalation needed\n", $treatment['overdue']);
        }
        foreach ($criticalKpis as $kpi) {
            $body .= sprintf("- KPI '%s' in critical range (%s)\n", $kpi['label'], $kpi['value']);
        }
        $body .= "\n[Manually extend: additional opportunities from staff feedback, audits, incidents.]";
        return $body;
    }
}

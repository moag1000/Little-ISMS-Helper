<?php

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\WorkflowInstanceRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Role-Based Dashboard Service
 *
 * Phase 7D: Provides role-specific dashboard data for CISO, Risk Manager,
 * Auditor, and Board dashboards.
 *
 * Each role sees KPIs and metrics most relevant to their responsibilities:
 * - CISO: Strategic view, compliance across frameworks, high-level risks
 * - Risk Manager: Operational risk view, treatment pipeline, appetite tracking
 * - Auditor: Evidence status, findings, audit timeline
 * - Board: Executive summary, RAG status, trends
 */
class RoleDashboardService
{
    public function __construct(
        private readonly DashboardStatisticsService $dashboardStatisticsService,
        private readonly ComplianceAnalyticsService $complianceAnalyticsService,
        private readonly ControlEffectivenessService $controlEffectivenessService,
        private readonly RiskForecastService $riskForecastService,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly ControlRepository $controlRepository,
        private readonly WorkflowInstanceRepository $workflowInstanceRepository,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly ?RiskTreatmentPlanRepository $treatmentPlanRepository = null,
        private readonly ?InternalAuditRepository $auditRepository = null,
    ) {
    }

    /**
     * Get CISO Dashboard data
     *
     * Focus: Strategic overview, compliance status, high-level risk posture
     */
    public function getCisoDashboard(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        // Framework compliance overview
        $frameworkComparison = $this->complianceAnalyticsService->getFrameworkComparison();

        // Control effectiveness summary
        $controlEffectiveness = $this->controlEffectivenessService->getEffectivenessDashboard();

        // Risk posture
        $riskVelocity = $this->riskForecastService->getRiskVelocity();
        $riskAppetite = $this->riskForecastService->getRiskAppetiteCompliance();

        // High-level statistics
        $stats = $this->dashboardStatisticsService->getDashboardStatistics();
        $managementKpis = $this->dashboardStatisticsService->getManagementKPIs();

        // Top risks requiring attention
        $criticalRisks = $this->getCriticalRisks($tenant, 5);

        // Pending approvals
        $pendingApprovals = $this->getPendingApprovals();

        // Recent incidents
        $recentIncidents = $this->getRecentIncidents($tenant, 5);

        return [
            'summary' => [
                'overall_compliance' => $frameworkComparison['summary']['average_compliance'] ?? 0,
                'control_implementation' => $stats['compliancePercentage'],
                'high_risks' => $stats['risks_high'],
                'open_incidents' => $stats['incidents_open'],
                'risk_trend' => $riskVelocity['trend'],
            ],
            'compliance' => [
                'frameworks' => array_slice($frameworkComparison['frameworks'] ?? [], 0, 5),
                'at_risk_count' => $frameworkComparison['summary']['at_risk'] ?? 0,
            ],
            'controls' => [
                'total' => $controlEffectiveness['metrics']['total_controls'],
                'implemented' => $controlEffectiveness['metrics']['implemented'],
                'effectiveness' => $controlEffectiveness['metrics']['average_effectiveness'],
                'overdue_reviews' => $controlEffectiveness['aging_analysis']['distribution']['overdue'] ?? 0,
            ],
            'risk_posture' => [
                'appetite_compliant' => $riskAppetite['is_compliant'],
                'compliance_score' => $riskAppetite['compliance_score'],
                'breaches' => $riskAppetite['breaches'],
                'velocity' => $riskVelocity,
            ],
            'critical_risks' => $criticalRisks,
            'pending_approvals' => $pendingApprovals,
            'recent_incidents' => $recentIncidents,
            'management_kpis' => $managementKpis,
        ];
    }

    /**
     * Get Risk Manager Dashboard data
     *
     * Focus: Risk treatment pipeline, appetite monitoring, mitigation effectiveness
     */
    public function getRiskManagerDashboard(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        // Risk treatment pipeline
        $treatmentPipeline = $this->getRiskTreatmentPipeline($tenant);

        // Risk distribution by category
        $risksByCategory = $this->getRisksByCategory($tenant);

        // Risk appetite compliance
        $riskAppetite = $this->riskForecastService->getRiskAppetiteCompliance();

        // Risk velocity and forecast
        $riskVelocity = $this->riskForecastService->getRiskVelocity();
        $riskForecast = $this->riskForecastService->getRiskForecast(3);

        // Control-risk matrix
        $controlRiskMatrix = $this->controlEffectivenessService->getControlRiskMatrix();

        // Overdue treatment plans
        $overdueTreatments = $this->getOverdueTreatmentPlans($tenant);

        // Top untreated risks
        $untreatedRisks = $this->getUntreatedRisks($tenant, 10);

        return [
            'summary' => [
                'total_risks' => count($this->riskRepository->findAll()),
                'high_critical' => $this->countHighCriticalRisks($tenant),
                'treated_percentage' => $treatmentPipeline['treated_percentage'],
                'overdue_treatments' => count($overdueTreatments),
                'appetite_status' => $riskAppetite['is_compliant'] ? 'compliant' : 'breach',
            ],
            'treatment_pipeline' => $treatmentPipeline,
            'risks_by_category' => $risksByCategory,
            'risk_appetite' => $riskAppetite,
            'risk_velocity' => $riskVelocity,
            'risk_forecast' => [
                'trend' => $riskForecast['trend'],
                'next_3_months' => array_slice($riskForecast['forecast'] ?? [], 0, 3),
            ],
            'control_effectiveness' => [
                'total_reduction' => $controlRiskMatrix['summary']['total_risk_reduction'] ?? 0,
                'controls_with_risks' => $controlRiskMatrix['summary']['controls_with_risks'] ?? 0,
            ],
            'overdue_treatments' => $overdueTreatments,
            'untreated_risks' => $untreatedRisks,
        ];
    }

    /**
     * Get Auditor Dashboard data
     *
     * Focus: Evidence collection, findings tracking, audit timeline
     */
    public function getAuditorDashboard(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        // Audit schedule and status
        $auditStatus = $this->getAuditStatus();

        // Control evidence status
        $evidenceStatus = $this->getEvidenceStatus();

        // Open findings
        $openFindings = $this->getOpenFindings();

        // Non-conformities
        $nonConformities = $this->getNonConformities();

        // Corrective actions
        $correctiveActions = $this->getCorrectiveActions();

        // Compliance gaps
        $complianceGaps = $this->complianceAnalyticsService->getGapAnalysis();

        // Control implementation status
        $controlStatus = $this->controlEffectivenessService->getEffectivenessDashboard();

        return [
            'summary' => [
                'audits_this_year' => $auditStatus['completed_this_year'],
                'upcoming_audits' => $auditStatus['upcoming_count'],
                'open_findings' => count($openFindings),
                'overdue_actions' => $correctiveActions['overdue_count'],
                'evidence_coverage' => $evidenceStatus['coverage_percentage'],
            ],
            'audit_status' => $auditStatus,
            'evidence_status' => $evidenceStatus,
            'open_findings' => $openFindings,
            'non_conformities' => $nonConformities,
            'corrective_actions' => $correctiveActions,
            'compliance_gaps' => [
                'total' => $complianceGaps['summary']['total_gaps'] ?? 0,
                'critical' => count($complianceGaps['by_priority']['critical'] ?? []),
                'high' => count($complianceGaps['by_priority']['high'] ?? []),
            ],
            'control_status' => [
                'implemented' => $controlStatus['implementation_status']['implemented'] ?? 0,
                'partially' => $controlStatus['implementation_status']['partially_implemented'] ?? 0,
                'not_implemented' => $controlStatus['implementation_status']['not_implemented'] ?? 0,
            ],
        ];
    }

    /**
     * Get Board Dashboard data
     *
     * Focus: Executive summary, RAG status, high-level trends
     */
    public function getBoardDashboard(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        // Get base statistics
        $stats = $this->dashboardStatisticsService->getDashboardStatistics();
        $frameworkComparison = $this->complianceAnalyticsService->getFrameworkComparison();
        $overallCompliance = $frameworkComparison['summary']['average_compliance'] ?? 0;

        // Risk data
        $riskAppetite = $this->riskForecastService->getRiskAppetiteCompliance();
        $riskVelocity = $this->riskForecastService->getRiskVelocity();
        $highCriticalRisks = $this->countHighCriticalRisks($tenant);

        // Build RAG status based on various metrics
        $ragStatus = $this->buildRAGStatus($overallCompliance, $riskAppetite, $stats);

        // Build trend data
        $trends = $this->buildBoardTrends($riskVelocity);

        // Build attention items from critical risks and overdue items
        $attentionItems = $this->buildAttentionItems($tenant);

        // Build executive summary text
        $executiveSummaryData = $this->buildExecutiveSummary($overallCompliance, $highCriticalRisks, $stats);

        // Current quarter info
        $currentQuarter = (int) ceil((int) date('n') / 3);
        $previousQuarter = $currentQuarter === 1 ? 4 : $currentQuarter - 1;

        return [
            // RAG Status Cards
            'rag_status' => $ragStatus['status'],
            'rag_details' => $ragStatus['details'],

            // Key Metrics
            'metrics' => [
                'overall_compliance' => $overallCompliance,
                'total_risks' => count($this->riskRepository->findAll()),
                'critical_risks' => $highCriticalRisks,
                'incidents_ytd' => $stats['incidents_total'] ?? 0,
                'controls_implemented' => $stats['compliancePercentage'],
                'audits_completed' => $stats['audits_completed'] ?? 0,
                'audits_planned' => $stats['audits_planned'] ?? 0,
            ],

            // Trend indicators
            'trends' => $trends,

            // Executive Summary
            'executive_summary' => $executiveSummaryData,

            // Milestones (empty for now, can be extended)
            'milestones' => [],

            // Items requiring attention
            'attention_items' => $attentionItems,

            // Resources (placeholder data)
            'resources' => [
                'fte_security' => '-',
                'open_positions' => 0,
                'initiatives' => [],
            ],

            // Quarterly comparison
            'quarterly' => [
                'previous_quarter' => $previousQuarter,
                'current_quarter' => $currentQuarter,
                'metrics' => $this->buildQuarterlyMetrics($overallCompliance, $highCriticalRisks),
            ],

            // Legacy structure for backwards compatibility
            'headline' => [
                'compliance_score' => $overallCompliance,
                'compliance_status' => $this->getRAGStatus($overallCompliance, 80, 60),
                'risk_status' => $riskAppetite['is_compliant'] ? 'green' : 'red',
                'trend' => $riskVelocity['trend'],
            ],
            'critical_items' => $this->getTop3CriticalItems($tenant),
        ];
    }

    /**
     * Build RAG status for board dashboard
     */
    private function buildRAGStatus(float $compliance, array $riskAppetite, array $stats): array
    {
        $securityStatus = $this->getRAGStatus($compliance, 80, 60);
        $complianceStatus = $this->getRAGStatus($compliance, 80, 60);
        $riskStatus = $riskAppetite['is_compliant'] ? 'green' : 'red';
        $operationsStatus = ($stats['incidents_open'] ?? 0) > 5 ? 'red' : (($stats['incidents_open'] ?? 0) > 0 ? 'amber' : 'green');

        return [
            'status' => [
                'security' => $securityStatus,
                'compliance' => $complianceStatus,
                'risk' => $riskStatus,
                'operations' => $operationsStatus,
            ],
            'details' => [
                'security' => $compliance . '% implementiert',
                'compliance' => $compliance . '% konform',
                'risk' => $riskAppetite['compliance_score'] . '% Appetit',
                'operations' => ($stats['incidents_open'] ?? 0) . ' offene Vorfälle',
            ],
        ];
    }

    /**
     * Build trend data for board dashboard
     */
    private function buildBoardTrends(array $riskVelocity): array
    {
        $riskChange = $riskVelocity['last_30_days']['net_change'] ?? 0;

        return [
            'compliance' => 0, // Would need historical data
            'risks' => $riskChange,
            'critical' => 0,
            'incidents' => 0,
            'controls' => 0,
        ];
    }

    /**
     * Build attention items for board dashboard
     */
    private function buildAttentionItems(?Tenant $tenant): array
    {
        $items = [];

        // Critical risks
        $criticalRisks = $this->getCriticalRisks($tenant, 3);
        foreach ($criticalRisks as $risk) {
            $items[] = [
                'priority' => 'critical',
                'title' => $risk['title'],
                'description' => 'Kritisches Risiko erfordert sofortige Behandlung',
            ];
        }

        // Overdue treatments
        $overdueTreatments = $this->getOverdueTreatmentPlans($tenant);
        foreach (array_slice($overdueTreatments, 0, 2) as $treatment) {
            $items[] = [
                'priority' => 'high',
                'title' => $treatment['risk_title'],
                'description' => $treatment['days_overdue'] . ' Tage überfällig',
            ];
        }

        return array_slice($items, 0, 5);
    }

    /**
     * Build executive summary data
     */
    private function buildExecutiveSummary(float $compliance, int $criticalRisks, array $stats): array
    {
        $achievements = [];
        $concerns = [];

        // Achievements
        if ($compliance >= 80) {
            $achievements[] = 'Compliance-Ziel von 80% erreicht';
        }
        if (($stats['controls_implemented'] ?? 0) > 90) {
            $achievements[] = 'Über 90% der Maßnahmen implementiert';
        }

        // Concerns
        if ($criticalRisks > 0) {
            $concerns[] = $criticalRisks . ' kritische Risiken erfordern Aufmerksamkeit';
        }
        if (($stats['incidents_open'] ?? 0) > 5) {
            $concerns[] = 'Erhöhte Anzahl offener Vorfälle';
        }

        // Security posture description
        $postureLevel = $compliance >= 80 ? 'gut' : ($compliance >= 60 ? 'akzeptabel' : 'verbesserungswürdig');

        return [
            'security_posture' => "Die aktuelle Sicherheitslage ist {$postureLevel} mit einer Gesamtkonformität von {$compliance}%.",
            'achievements' => $achievements,
            'concerns' => $concerns,
        ];
    }

    /**
     * Build quarterly metrics for board dashboard
     */
    private function buildQuarterlyMetrics(float $compliance, int $criticalRisks): array
    {
        return [
            [
                'name' => 'Compliance',
                'previous' => $compliance - 2, // Simulated previous quarter
                'current' => $compliance,
                'change' => 2,
                'unit' => '%',
                'positive_is_good' => true,
                'target' => 80,
                'on_target' => $compliance >= 80,
            ],
            [
                'name' => 'Kritische Risiken',
                'previous' => $criticalRisks + 1,
                'current' => $criticalRisks,
                'change' => -1,
                'unit' => '',
                'positive_is_good' => false,
                'target' => 0,
                'on_target' => $criticalRisks === 0,
            ],
        ];
    }

    /**
     * Determine which dashboard a user should see based on their role
     */
    public function getRecommendedDashboard(): string
    {
        $user = $this->security->getUser();

        if ($user === null) {
            return 'default';
        }

        // Check roles in order of specificity
        if ($this->security->isGranted('ROLE_SUPER_ADMIN') || $this->security->isGranted('ROLE_ADMIN')) {
            return 'ciso'; // Admins see CISO view
        }

        if ($this->security->isGranted('ROLE_MANAGER')) {
            return 'risk_manager';
        }

        if ($this->security->isGranted('ROLE_AUDITOR')) {
            return 'auditor';
        }

        return 'default';
    }

    // ==================== Private Helper Methods ====================

    private function getCriticalRisks(?Tenant $tenant, int $limit): array
    {
        $risks = $this->riskRepository->findAll();

        $criticalRisks = array_filter(
            $risks,
            fn($r) => $r->getInherentRiskLevel() >= 16
        );

        usort($criticalRisks, fn($a, $b) => $b->getInherentRiskLevel() <=> $a->getInherentRiskLevel());

        return array_map(fn($r) => [
            'id' => $r->getId(),
            'title' => $r->getTitle(),
            'level' => $r->getInherentRiskLevel(),
            'status' => $r->getStatus(),
            'treatment' => $r->getTreatmentStrategy(),
        ], array_slice($criticalRisks, 0, $limit));
    }

    private function getPendingApprovals(): array
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return [];
        }

        $pending = $this->workflowInstanceRepository->findPendingForUser($user);

        return array_map(fn($w) => [
            'id' => $w->getId(),
            'title' => $w->getDefinition()?->getName() ?? 'Unknown',
            'entity_type' => $w->getEntityType(),
            'created_at' => $w->getCreatedAt(),
        ], array_slice($pending, 0, 5));
    }

    private function getRecentIncidents(?Tenant $tenant, int $limit): array
    {
        $incidents = $this->incidentRepository->findAll();

        usort($incidents, fn($a, $b) => ($b->getDetectedAt() ?? new \DateTime('1970-01-01')) <=> ($a->getDetectedAt() ?? new \DateTime('1970-01-01')));

        return array_map(fn($i) => [
            'id' => $i->getId(),
            'title' => $i->getTitle(),
            'severity' => $i->getSeverity(),
            'status' => $i->getStatus(),
            'detected_at' => $i->getDetectedAt(),
        ], array_slice($incidents, 0, $limit));
    }

    private function getRiskTreatmentPipeline(?Tenant $tenant): array
    {
        $risks = $this->riskRepository->findAll();
        $total = count($risks);

        $byStrategy = [
            'mitigate' => 0,
            'accept' => 0,
            'transfer' => 0,
            'avoid' => 0,
            'untreated' => 0,
        ];

        foreach ($risks as $risk) {
            $strategy = $risk->getTreatmentStrategy();
            if (empty($strategy)) {
                $byStrategy['untreated']++;
            } elseif (isset($byStrategy[$strategy])) {
                $byStrategy[$strategy]++;
            }
        }

        $treated = $total - $byStrategy['untreated'];

        return [
            'total' => $total,
            'treated' => $treated,
            'untreated' => $byStrategy['untreated'],
            'treated_percentage' => $total > 0 ? round(($treated / $total) * 100) : 0,
            'by_strategy' => $byStrategy,
        ];
    }

    private function getRisksByCategory(?Tenant $tenant): array
    {
        $risks = $this->riskRepository->findAll();

        $byCategory = [];
        foreach ($risks as $risk) {
            $category = $risk->getCategory() ?? 'Uncategorized';
            if (!isset($byCategory[$category])) {
                $byCategory[$category] = ['total' => 0, 'high' => 0, 'critical' => 0];
            }
            $byCategory[$category]['total']++;
            if ($risk->getInherentRiskLevel() >= 16) {
                $byCategory[$category]['critical']++;
            } elseif ($risk->getInherentRiskLevel() >= 12) {
                $byCategory[$category]['high']++;
            }
        }

        arsort($byCategory);

        return array_slice($byCategory, 0, 10, true);
    }

    private function countHighCriticalRisks(?Tenant $tenant): int
    {
        $risks = $this->riskRepository->findAll();

        return count(array_filter($risks, fn($r) => $r->getInherentRiskLevel() >= 12));
    }

    private function getOverdueTreatmentPlans(?Tenant $tenant): array
    {
        if ($this->treatmentPlanRepository === null) {
            return [];
        }

        $plans = $this->treatmentPlanRepository->findAll();
        $now = new \DateTime();

        $overdue = array_filter($plans, fn($p) => $p->getTargetCompletionDate() !== null
            && $p->getTargetCompletionDate() < $now
            && $p->getStatus() !== 'completed'
        );

        return array_map(fn($p) => [
            'id' => $p->getId(),
            'risk_title' => $p->getRisk()?->getTitle() ?? 'Unknown',
            'target_date' => $p->getTargetCompletionDate(),
            'days_overdue' => $p->getTargetCompletionDate()->diff($now)->days,
        ], array_slice($overdue, 0, 10));
    }

    private function getUntreatedRisks(?Tenant $tenant, int $limit): array
    {
        $risks = $this->riskRepository->findAll();

        $untreated = array_filter($risks, fn($r) => empty($r->getTreatmentStrategy()));

        usort($untreated, fn($a, $b) => $b->getInherentRiskLevel() <=> $a->getInherentRiskLevel());

        return array_map(fn($r) => [
            'id' => $r->getId(),
            'title' => $r->getTitle(),
            'level' => $r->getInherentRiskLevel(),
            'category' => $r->getCategory(),
        ], array_slice($untreated, 0, $limit));
    }

    private function getAuditStatus(): array
    {
        if ($this->auditRepository === null) {
            return [
                'completed_this_year' => 0,
                'upcoming_count' => 0,
                'upcoming' => [],
            ];
        }

        $audits = $this->auditRepository->findAll();
        $thisYear = (new \DateTime())->format('Y');
        $now = new \DateTime();

        $completedThisYear = count(array_filter($audits, fn($a) => $a->getStatus() === 'completed'
            && $a->getPlannedDate()?->format('Y') === $thisYear
        ));

        $upcoming = array_filter($audits, fn($a) => $a->getPlannedDate() !== null
            && $a->getPlannedDate() > $now
            && $a->getStatus() !== 'completed'
        );

        usort($upcoming, fn($a, $b) => $a->getPlannedDate() <=> $b->getPlannedDate());

        return [
            'completed_this_year' => $completedThisYear,
            'upcoming_count' => count($upcoming),
            'upcoming' => array_map(fn($a) => [
                'id' => $a->getId(),
                'title' => $a->getTitle(),
                'planned_date' => $a->getPlannedDate(),
                'type' => $a->getType(),
            ], array_slice($upcoming, 0, 5)),
        ];
    }

    private function getEvidenceStatus(): array
    {
        $controls = $this->controlRepository->findApplicableControls();
        $total = count($controls);

        // Controls with evidence (having linked documents or reviews)
        $withEvidence = count(array_filter($controls, fn($c) => $c->getLastReviewDate() !== null
        ));

        return [
            'total_controls' => $total,
            'with_evidence' => $withEvidence,
            'coverage_percentage' => $total > 0 ? round(($withEvidence / $total) * 100) : 0,
        ];
    }

    private function getOpenFindings(): array
    {
        // Simplified - in real implementation, this would query audit findings
        return [];
    }

    private function getNonConformities(): array
    {
        // Simplified - in real implementation, this would query non-conformities
        return [
            'major' => 0,
            'minor' => 0,
            'observations' => 0,
        ];
    }

    private function getCorrectiveActions(): array
    {
        // Simplified - in real implementation, this would query corrective actions
        return [
            'total' => 0,
            'open' => 0,
            'overdue_count' => 0,
            'items' => [],
        ];
    }

    private function getTop3CriticalItems(?Tenant $tenant): array
    {
        $items = [];

        // Critical risks
        $criticalRisks = $this->getCriticalRisks($tenant, 1);
        if (!empty($criticalRisks)) {
            $items[] = [
                'type' => 'risk',
                'title' => $criticalRisks[0]['title'],
                'status' => 'red',
                'action' => 'Immediate treatment required',
            ];
        }

        // Overdue treatments
        $overdueTreatments = $this->getOverdueTreatmentPlans($tenant);
        if (!empty($overdueTreatments)) {
            $items[] = [
                'type' => 'treatment',
                'title' => $overdueTreatments[0]['risk_title'] . ' treatment overdue',
                'status' => 'amber',
                'action' => $overdueTreatments[0]['days_overdue'] . ' days overdue',
            ];
        }

        // Open incidents
        $incidents = $this->getRecentIncidents($tenant, 1);
        $openIncidents = array_filter($incidents, fn($i) => $i['status'] === 'open');
        if (!empty($openIncidents)) {
            $incident = reset($openIncidents);
            $items[] = [
                'type' => 'incident',
                'title' => $incident['title'],
                'status' => $incident['severity'] === 'critical' ? 'red' : 'amber',
                'action' => 'Investigation in progress',
            ];
        }

        return array_slice($items, 0, 3);
    }

    private function getRAGMetrics(?Tenant $tenant): array
    {
        $stats = $this->dashboardStatisticsService->getDashboardStatistics();
        $riskAppetite = $this->riskForecastService->getRiskAppetiteCompliance();

        return [
            'compliance' => [
                'label' => 'Control Compliance',
                'value' => $stats['compliancePercentage'],
                'unit' => '%',
                'status' => $this->getRAGStatus($stats['compliancePercentage'], 80, 60),
            ],
            'risk_appetite' => [
                'label' => 'Risk Appetite',
                'value' => $riskAppetite['compliance_score'],
                'unit' => '%',
                'status' => $riskAppetite['is_compliant'] ? 'green' : 'red',
            ],
            'high_risks' => [
                'label' => 'High/Critical Risks',
                'value' => $stats['risks_high'],
                'unit' => '',
                'status' => $stats['risks_high'] > 10 ? 'red' : ($stats['risks_high'] > 5 ? 'amber' : 'green'),
            ],
            'incidents' => [
                'label' => 'Open Incidents',
                'value' => $stats['incidents_open'],
                'unit' => '',
                'status' => $stats['incidents_open'] > 5 ? 'red' : ($stats['incidents_open'] > 0 ? 'amber' : 'green'),
            ],
        ];
    }

    private function getTrendIndicators(?Tenant $tenant): array
    {
        $riskVelocity = $this->riskForecastService->getRiskVelocity();

        return [
            'risks' => [
                'direction' => $riskVelocity['trend'],
                'change' => $riskVelocity['last_30_days']['net_change'],
            ],
            'compliance' => [
                'direction' => 'stable', // Would need historical data
                'change' => 0,
            ],
        ];
    }

    private function getRAGStatus(float $value, float $greenThreshold, float $amberThreshold): string
    {
        if ($value >= $greenThreshold) {
            return 'green';
        }
        if ($value >= $amberThreshold) {
            return 'amber';
        }
        return 'red';
    }
}

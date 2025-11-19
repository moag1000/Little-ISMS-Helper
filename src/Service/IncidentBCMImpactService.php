<?php

namespace App\Service;

use App\Entity\Incident;
use App\Entity\BusinessProcess;
use App\Entity\Asset;
use App\Repository\BusinessProcessRepository;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CRITICAL-05: Incident ↔ BCM Integration Service
 *
 * Analyzes business impact of incidents using Business Impact Analysis (BIA) data.
 * Provides automatic process identification, financial impact calculation, and recovery prioritization.
 *
 * Architecture: Data Reuse Pattern
 * - Reuses BIA data (RTO, RPO, MTPD, financial impact)
 * - Links incidents to processes via shared assets
 * - Validates theoretical BIA assumptions with real incident data
 */
class IncidentBCMImpactService
{
    public function __construct(
        private BusinessProcessRepository $businessProcessRepository,
        private AssetRepository $assetRepository,
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext
    ) {}

    /**
     * Analyze complete business impact of an incident
     *
     * Returns comprehensive impact analysis including:
     * - Affected business processes
     * - Financial impact calculations
     * - RTO compliance status
     * - Recovery priority recommendations
     * - Historical comparison
     *
     * @param Incident $incident The incident to analyze
     * @param int|null $estimatedDowntimeHours Override downtime (default: calculate from incident)
     * @return array Comprehensive impact analysis
     */
    public function analyzeBusinessImpact(Incident $incident, ?int $estimatedDowntimeHours = null): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        // Calculate actual or estimated downtime
        $downtimeHours = $estimatedDowntimeHours ?? $this->calculateActualDowntime($incident);

        // Get affected processes (manual + auto-detected)
        $manualProcesses = $incident->getAffectedBusinessProcesses()->toArray();
        $autoDetectedProcesses = $this->identifyAffectedProcesses($incident);

        // Merge and deduplicate
        $allProcesses = array_unique(
            array_merge($manualProcesses, $autoDetectedProcesses),
            SORT_REGULAR
        );

        // Calculate impacts per process
        $processImpacts = [];
        $totalFinancialImpact = 0.0;
        $criticalProcessCount = 0;
        $rtoViolations = [];

        foreach ($allProcesses as $process) {
            $impact = $this->calculateDowntimeImpact($process, $downtimeHours);
            $processImpacts[] = $impact;

            $totalFinancialImpact += $impact['financial_impact'];

            if ($process->getCriticality() === 'critical') {
                $criticalProcessCount++;
            }

            if ($impact['rto_violated']) {
                $rtoViolations[] = [
                    'process' => $process,
                    'rto_hours' => $process->getRto(),
                    'actual_hours' => $downtimeHours,
                    'excess_hours' => $downtimeHours - $process->getRto(),
                ];
            }
        }

        // Get recovery priority
        $recoveryPriority = $this->suggestRecoveryPriority($incident, $allProcesses);

        // Historical context
        $historicalContext = $this->getHistoricalContext($allProcesses);

        return [
            'incident_id' => $incident->getId(),
            'incident_number' => $incident->getIncidentNumber(),
            'severity' => $incident->getSeverity(),
            'status' => $incident->getStatus(),

            'downtime' => [
                'actual_hours' => $downtimeHours,
                'is_estimated' => $estimatedDowntimeHours !== null,
                'detected_at' => $incident->getDetectedAt(),
                'resolved_at' => $incident->getResolvedAt(),
            ],

            'affected_processes' => [
                'total_count' => count($allProcesses),
                'manual_count' => count($manualProcesses),
                'auto_detected_count' => count($autoDetectedProcesses),
                'critical_count' => $criticalProcessCount,
                'processes' => $processImpacts,
            ],

            'financial_impact' => [
                'total_eur' => round($totalFinancialImpact, 2),
                'per_hour_eur' => count($allProcesses) > 0 ? round($totalFinancialImpact / $downtimeHours, 2) : 0,
                'currency' => 'EUR',
            ],

            'rto_compliance' => [
                'violations_count' => count($rtoViolations),
                'is_compliant' => count($rtoViolations) === 0,
                'violations' => $rtoViolations,
            ],

            'recovery_priority' => $recoveryPriority,

            'historical_context' => $historicalContext,

            'recommendations' => $this->generateRecommendations($incident, $allProcesses, $rtoViolations),
        ];
    }

    /**
     * Automatically identify business processes affected by an incident
     *
     * Strategy:
     * 1. Get all assets affected by the incident
     * 2. Find business processes that depend on these assets
     * 3. Return processes not already manually linked
     *
     * Data Reuse: Asset-Process relationships from BIA
     *
     * @param Incident $incident The incident
     * @return BusinessProcess[] Auto-detected processes
     */
    public function identifyAffectedProcesses(Incident $incident): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $affectedAssets = $incident->getAffectedAssets();

        if ($affectedAssets->isEmpty()) {
            return [];
        }

        // Get manually linked processes to avoid duplicates
        $manualProcessIds = $incident->getAffectedBusinessProcesses()
            ->map(fn($p) => $p->getId())
            ->toArray();

        $autoDetectedProcesses = [];

        // Find processes that use these affected assets
        foreach ($affectedAssets as $asset) {
            $processes = $this->businessProcessRepository->createQueryBuilder('bp')
                ->join('bp.supportingAssets', 'a')
                ->where('a.id = :assetId')
                ->andWhere('bp.tenant = :tenant')
                ->setParameter('assetId', $asset->getId())
                ->setParameter('tenant', $tenant)
                ->getQuery()
                ->getResult();

            foreach ($processes as $process) {
                // Only add if not already manually linked
                if (!in_array($process->getId(), $manualProcessIds)) {
                    $autoDetectedProcesses[$process->getId()] = $process;
                }
            }
        }

        return array_values($autoDetectedProcesses);
    }

    /**
     * Calculate detailed downtime impact for a specific process
     *
     * Data Reuse: BIA financial impact, RTO, criticality, impact scores
     *
     * @param BusinessProcess $process The affected process
     * @param int $downtimeHours Downtime duration in hours
     * @return array Detailed impact analysis
     */
    public function calculateDowntimeImpact(BusinessProcess $process, int $downtimeHours): array
    {
        $impactPerHour = (float) ($process->getFinancialImpactPerHour() ?? 0);
        $impactPerDay = (float) ($process->getFinancialImpactPerDay() ?? 0);

        // Calculate financial impact
        $financialImpact = $impactPerHour * $downtimeHours;

        // Check RTO compliance
        $rto = $process->getRto();
        $rtoViolated = $downtimeHours > $rto;
        $rtoExcessHours = max(0, $downtimeHours - $rto);

        // Check MTPD (Maximum Tolerable Period of Disruption)
        $mtpd = $process->getMtpd();
        $mtpdViolated = $downtimeHours > $mtpd;

        // Calculate impact severity
        $impactSeverity = $this->calculateImpactSeverity(
            $process,
            $downtimeHours,
            $rtoViolated,
            $mtpdViolated
        );

        return [
            'process_id' => $process->getId(),
            'process_name' => $process->getName(),
            'process_owner' => $process->getProcessOwner(),
            'criticality' => $process->getCriticality(),

            'bia_data' => [
                'rto_hours' => $rto,
                'rpo_hours' => $process->getRpo(),
                'mtpd_hours' => $mtpd,
                'financial_impact_per_hour' => $impactPerHour,
                'financial_impact_per_day' => $impactPerDay,
            ],

            'financial_impact' => round($financialImpact, 2),

            'rto_compliance' => [
                'rto_hours' => $rto,
                'actual_hours' => $downtimeHours,
                'violated' => $rtoViolated,
                'excess_hours' => $rtoExcessHours,
                'compliance_percentage' => $rto > 0 ? min(100, round(($rto / $downtimeHours) * 100, 2)) : 0,
            ],

            'rto_violated' => $rtoViolated,
            'mtpd_violated' => $mtpdViolated,

            'impact_scores' => [
                'reputational' => $process->getReputationalImpact(),
                'regulatory' => $process->getRegulatoryImpact(),
                'operational' => $process->getOperationalImpact(),
                'aggregated' => $process->getBusinessImpactScore(),
            ],

            'impact_severity' => $impactSeverity,

            'recovery_strategy' => $process->getRecoveryStrategy(),
        ];
    }

    /**
     * Suggest recovery priority based on BCM data and incident severity
     *
     * Priority levels:
     * - immediate: Critical processes or RTO ≤ 1 hour
     * - high: RTO ≤ 4 hours or high severity incident
     * - medium: RTO ≤ 24 hours
     * - low: RTO > 24 hours
     *
     * @param Incident $incident The incident
     * @param array $affectedProcesses List of affected processes
     * @return array Priority recommendation with reasoning
     */
    public function suggestRecoveryPriority(Incident $incident, array $affectedProcesses): array
    {
        if (empty($affectedProcesses)) {
            return [
                'level' => 'medium',
                'reasoning' => 'No business processes identified - standard incident priority',
                'recommended_actions' => [
                    'Identify affected business processes',
                    'Assess business impact',
                    'Link affected assets to processes',
                ],
            ];
        }

        // Find most critical process (lowest RTO)
        $lowestRTO = PHP_INT_MAX;
        $mostCriticalProcess = null;
        $criticalCount = 0;

        foreach ($affectedProcesses as $process) {
            if ($process->getRto() < $lowestRTO) {
                $lowestRTO = $process->getRto();
                $mostCriticalProcess = $process;
            }

            if ($process->getCriticality() === 'critical') {
                $criticalCount++;
            }
        }

        // Determine priority level
        $level = 'medium';
        $reasoning = [];
        $actions = [];

        if ($lowestRTO <= 1 || $criticalCount > 0) {
            $level = 'immediate';
            $reasoning[] = $lowestRTO <= 1
                ? "Process '{$mostCriticalProcess->getName()}' has RTO ≤ 1 hour"
                : "{$criticalCount} critical business process(es) affected";
            $actions = [
                'Activate crisis management team',
                'Implement recovery strategy immediately',
                'Notify process owners and stakeholders',
                'Monitor RTO compliance continuously',
            ];
        } elseif ($lowestRTO <= 4 || $incident->getSeverity() === 'critical') {
            $level = 'high';
            $reasoning[] = $lowestRTO <= 4
                ? "Process '{$mostCriticalProcess->getName()}' has RTO ≤ 4 hours"
                : 'Critical severity incident';
            $actions = [
                'Prioritize recovery resources',
                'Begin recovery procedures within 1 hour',
                'Notify stakeholders',
                'Document recovery timeline',
            ];
        } elseif ($lowestRTO <= 24) {
            $level = 'medium';
            $reasoning[] = "Process '{$mostCriticalProcess->getName()}' has RTO ≤ 24 hours";
            $actions = [
                'Plan recovery within business hours',
                'Coordinate with process owners',
                'Prepare recovery resources',
            ];
        } else {
            $level = 'low';
            $reasoning[] = "All affected processes have RTO > 24 hours";
            $actions = [
                'Schedule recovery during maintenance window',
                'Minimal impact on business operations',
            ];
        }

        return [
            'level' => $level,
            'reasoning' => implode('. ', $reasoning),
            'most_critical_process' => $mostCriticalProcess?->getName(),
            'lowest_rto_hours' => $lowestRTO !== PHP_INT_MAX ? $lowestRTO : null,
            'critical_process_count' => $criticalCount,
            'recommended_actions' => $actions,
        ];
    }

    /**
     * Generate impact report data for templates/PDFs
     *
     * @param Incident $incident The incident
     * @return array Report-ready data structure
     */
    public function generateImpactReport(Incident $incident): array
    {
        $analysis = $this->analyzeBusinessImpact($incident);

        return [
            'report_generated_at' => new \DateTimeImmutable(),
            'incident' => [
                'number' => $incident->getIncidentNumber(),
                'title' => $incident->getTitle(),
                'severity' => $incident->getSeverity(),
                'status' => $incident->getStatus(),
                'detected_at' => $incident->getDetectedAt(),
                'resolved_at' => $incident->getResolvedAt(),
            ],
            'executive_summary' => $this->generateExecutiveSummary($analysis),
            'detailed_analysis' => $analysis,
            'charts_data' => $this->prepareChartsData($analysis),
        ];
    }

    /**
     * Calculate actual downtime from incident timestamps
     *
     * @param Incident $incident
     * @return int Downtime in hours (0 if not resolved, 24 if estimated)
     */
    private function calculateActualDowntime(Incident $incident): int
    {
        if ($incident->getResolvedAt() === null) {
            // Not resolved yet - estimate 24 hours or time since detection
            $now = new \DateTimeImmutable();
            $interval = $incident->getDetectedAt()->diff($now);
            return min(24, ($interval->days * 24) + $interval->h);
        }

        $interval = $incident->getDetectedAt()->diff($incident->getResolvedAt());
        return ($interval->days * 24) + $interval->h;
    }

    /**
     * Get historical context for affected processes
     *
     * @param array $processes
     * @return array Historical statistics
     */
    private function getHistoricalContext(array $processes): array
    {
        $totalIncidents = 0;
        $totalDowntime = 0;
        $totalFinancialLoss = 0.0;
        $rtoViolationCount = 0;

        foreach ($processes as $process) {
            $totalIncidents += $process->getIncidentCount();
            $totalDowntime += $process->getTotalDowntimeFromIncidents();
            $totalFinancialLoss += $process->getHistoricalFinancialLoss();

            if ($process->hasRTOViolations()) {
                $rtoViolationCount++;
            }
        }

        return [
            'total_past_incidents' => $totalIncidents,
            'total_past_downtime_hours' => $totalDowntime,
            'total_past_financial_loss_eur' => round($totalFinancialLoss, 2),
            'processes_with_rto_violations' => $rtoViolationCount,
            'avg_incidents_per_process' => count($processes) > 0 ? round($totalIncidents / count($processes), 1) : 0,
        ];
    }

    /**
     * Calculate impact severity (low/medium/high/critical)
     *
     * @param BusinessProcess $process
     * @param int $downtimeHours
     * @param bool $rtoViolated
     * @param bool $mtpdViolated
     * @return string Severity level
     */
    private function calculateImpactSeverity(
        BusinessProcess $process,
        int $downtimeHours,
        bool $rtoViolated,
        bool $mtpdViolated
    ): string {
        // MTPD violation is always critical
        if ($mtpdViolated) {
            return 'critical';
        }

        // Critical process or severe RTO violation
        if ($process->getCriticality() === 'critical' ||
            ($rtoViolated && $downtimeHours > $process->getRto() * 2)) {
            return 'critical';
        }

        // RTO violation or high business impact
        if ($rtoViolated || $process->getBusinessImpactScore() >= 4) {
            return 'high';
        }

        // Moderate impact
        if ($process->getBusinessImpactScore() >= 3) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Generate actionable recommendations
     *
     * @param Incident $incident
     * @param array $processes
     * @param array $rtoViolations
     * @return array List of recommendations
     */
    private function generateRecommendations(Incident $incident, array $processes, array $rtoViolations): array
    {
        $recommendations = [];

        if (count($rtoViolations) > 0) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'rto_compliance',
                'title' => 'RTO Violations Detected',
                'description' => sprintf(
                    '%d process(es) exceeded their Recovery Time Objectives. Review and update recovery strategies.',
                    count($rtoViolations)
                ),
                'action' => 'Update BIA with realistic RTO values based on actual recovery times',
            ];
        }

        if ($incident->getAffectedAssets()->count() > $incident->getAffectedBusinessProcesses()->count()) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'process_mapping',
                'title' => 'Incomplete Process Mapping',
                'description' => 'Some affected assets are not linked to business processes in BIA.',
                'action' => 'Complete Business Impact Analysis for all critical assets',
            ];
        }

        foreach ($processes as $process) {
            if ($process->getRecoveryStrategy() === null || trim($process->getRecoveryStrategy()) === '') {
                $recommendations[] = [
                    'priority' => 'medium',
                    'category' => 'recovery_planning',
                    'title' => sprintf('Missing Recovery Strategy: %s', $process->getName()),
                    'description' => 'Process has no documented recovery strategy.',
                    'action' => 'Document and test recovery procedures',
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Generate executive summary
     *
     * @param array $analysis Full analysis data
     * @return array Executive summary
     */
    private function generateExecutiveSummary(array $analysis): array
    {
        return [
            'total_processes_affected' => $analysis['affected_processes']['total_count'],
            'critical_processes_affected' => $analysis['affected_processes']['critical_count'],
            'estimated_financial_impact' => $analysis['financial_impact']['total_eur'],
            'recovery_priority' => $analysis['recovery_priority']['level'],
            'rto_compliant' => $analysis['rto_compliance']['is_compliant'],
            'key_findings' => [
                sprintf(
                    '%d business process(es) affected with total estimated impact of €%s',
                    $analysis['affected_processes']['total_count'],
                    number_format($analysis['financial_impact']['total_eur'], 2)
                ),
                sprintf(
                    'Recovery priority: %s (%s)',
                    strtoupper($analysis['recovery_priority']['level']),
                    $analysis['recovery_priority']['reasoning']
                ),
                $analysis['rto_compliance']['is_compliant']
                    ? 'All RTO thresholds met'
                    : sprintf('%d RTO violation(s) detected', $analysis['rto_compliance']['violations_count']),
            ],
        ];
    }

    /**
     * Prepare data for charts/visualizations
     *
     * @param array $analysis Full analysis data
     * @return array Chart-ready data
     */
    private function prepareChartsData(array $analysis): array
    {
        $processes = $analysis['affected_processes']['processes'];

        return [
            'financial_by_process' => array_map(fn($p) => [
                'name' => $p['process_name'],
                'value' => $p['financial_impact'],
            ], $processes),

            'criticality_distribution' => $this->aggregateBy($processes, 'criticality'),

            'rto_compliance' => [
                'compliant' => count(array_filter($processes, fn($p) => !$p['rto_violated'])),
                'violated' => count(array_filter($processes, fn($p) => $p['rto_violated'])),
            ],

            'impact_severity' => $this->aggregateBy($processes, 'impact_severity'),
        ];
    }

    /**
     * Helper: Aggregate data by field
     *
     * @param array $items
     * @param string $field
     * @return array Aggregated counts
     */
    private function aggregateBy(array $items, string $field): array
    {
        $result = [];
        foreach ($items as $item) {
            $value = $item[$field];
            $result[$value] = ($result[$value] ?? 0) + 1;
        }
        return $result;
    }
}

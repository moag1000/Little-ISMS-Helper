<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\IncidentSeverity;
use DateTimeImmutable;
use DateTimeInterface;
use App\Entity\Incident;
use App\Entity\BusinessProcess;
use App\Repository\BusinessProcessRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {}

    /** Texts of the analysis in the user's language (domain `incident`, keys below incident.bcm_impact_page). */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans('incident.bcm_impact_page.' . $key, $params, 'incident');
    }

    /** @return list<string> */
    private function actions(string ...$keys): array
    {
        return array_map(fn(string $k): string => $this->t('action.' . $k), $keys);
    }

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
        $this->tenantContext->getCurrentTenant();

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

        foreach ($allProcesses as $allProcess) {
            $impact = $this->calculateDowntimeImpact($allProcess, $downtimeHours);
            $processImpacts[] = $impact;

            $totalFinancialImpact += $impact['financial_impact'];

            if ($allProcess->getCriticality() === 'critical') {
                $criticalProcessCount++;
            }

            if ($impact['rto_violated']) {
                $rtoViolations[] = [
                    'process' => $allProcess,
                    'rto_hours' => $allProcess->getRto(),
                    'actual_hours' => $downtimeHours,
                    'excess_hours' => $downtimeHours - $allProcess->getRto(),
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
            'severity' => $incident->getSeverity()?->value,
            'status' => $incident->getStatus()?->value,

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
            ->map(fn($p): ?int => $p->getId())
            ->toArray();

        $autoDetectedProcesses = [];

        // Find processes that use these affected assets
        foreach ($affectedAssets as $affectedAsset) {
            $processes = $this->businessProcessRepository->createQueryBuilder('bp')
                ->join('bp.supportingAssets', 'a')
                ->where('a.id = :assetId')
                ->andWhere('bp.tenant = :tenant')
                ->setParameter('assetId', $affectedAsset->getId())
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
     * @param BusinessProcess $businessProcess The affected process
     * @param int $downtimeHours Downtime duration in hours
     * @return array Detailed impact analysis
     */
    public function calculateDowntimeImpact(BusinessProcess $businessProcess, int $downtimeHours): array
    {
        $impactPerHour = (float) ($businessProcess->getFinancialImpactPerHour() ?? 0);
        $impactPerDay = (float) ($businessProcess->getFinancialImpactPerDay() ?? 0);

        // Calculate financial impact
        $financialImpact = $impactPerHour * $downtimeHours;

        // Check RTO compliance
        $rto = $businessProcess->getRto();
        $rtoViolated = $downtimeHours > $rto;
        $rtoExcessHours = max(0, $downtimeHours - $rto);

        // Check MTPD (Maximum Tolerable Period of Disruption)
        $mtpd = $businessProcess->getMtpd();
        $mtpdViolated = $downtimeHours > $mtpd;

        // Calculate impact severity
        $impactSeverity = $this->calculateImpactSeverity(
            $businessProcess,
            $downtimeHours,
            $rtoViolated,
            $mtpdViolated
        );

        return [
            'process_id' => $businessProcess->getId(),
            'process_name' => $businessProcess->getName(),
            'process_owner' => $businessProcess->getEffectiveProcessOwner(),
            'criticality' => $businessProcess->getCriticality(),

            'bia_data' => [
                'rto_hours' => $rto,
                'rpo_hours' => $businessProcess->getRpo(),
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
                'reputational' => $businessProcess->getReputationalImpact(),
                'regulatory' => $businessProcess->getRegulatoryImpact(),
                'operational' => $businessProcess->getOperationalImpact(),
                'aggregated' => $businessProcess->getBusinessImpactScore(),
            ],

            'impact_severity' => $impactSeverity,

            'recovery_strategy' => $businessProcess->getRecoveryStrategy(),
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
        if ($affectedProcesses === []) {
            return [
                'level' => 'medium',
                'reasoning' => $this->t('reason.no_processes'),
                'recommended_actions' => $this->actions('identify_processes', 'assess_impact', 'link_assets'),
            ];
        }

        // Find most critical process (lowest RTO)
        $lowestRTO = PHP_INT_MAX;
        $mostCriticalProcess = null;
        $criticalCount = 0;

        foreach ($affectedProcesses as $affectedProcess) {
            if ($affectedProcess->getRto() < $lowestRTO) {
                $lowestRTO = $affectedProcess->getRto();
                $mostCriticalProcess = $affectedProcess;
            }

            if ($affectedProcess->getCriticality() === 'critical') {
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
                ? $this->t('reason.rto_max', ['%process%' => $mostCriticalProcess->getName(), '%hours%' => 1])
                : $this->t('reason.critical_processes', ['%count%' => $criticalCount]);
            $actions = $this->actions('activate_crisis_team', 'recover_now', 'notify_owners', 'monitor_rto');
        } elseif ($lowestRTO <= 4 || $incident->getSeverity() === IncidentSeverity::Critical) {
            $level = 'high';
            $reasoning[] = $lowestRTO <= 4
                ? $this->t('reason.rto_max', ['%process%' => $mostCriticalProcess->getName(), '%hours%' => 4])
                : $this->t('reason.critical_severity');
            $actions = $this->actions('prioritize_resources', 'recover_within_hour', 'notify_stakeholders', 'document_timeline');
        } elseif ($lowestRTO <= 24) {
            $level = 'medium';
            $reasoning[] = $this->t('reason.rto_max', ['%process%' => $mostCriticalProcess->getName(), '%hours%' => 24]);
            $actions = $this->actions('plan_business_hours', 'coordinate_owners', 'prepare_resources');
        } else {
            $level = 'low';
            $reasoning[] = $this->t('reason.rto_above_day');
            $actions = $this->actions('maintenance_window', 'minimal_impact');
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
            'report_generated_at' => new DateTimeImmutable(),
            'incident' => [
                'number' => $incident->getIncidentNumber(),
                'title' => $incident->getTitle(),
                'severity' => $incident->getSeverity()?->value,
                'status' => $incident->getStatus()?->value,
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
     * @return int Downtime in hours (0 if not resolved, 24 if estimated)
     */
    private function calculateActualDowntime(Incident $incident): int
    {
        if (!$incident->getResolvedAt() instanceof DateTimeInterface) {
            // Not resolved yet - estimate 24 hours or time since detection
            $now = new DateTimeImmutable();
            $interval = $incident->getDetectedAt()->diff($now);
            return min(24, ($interval->days * 24) + $interval->h);
        }

        $interval = $incident->getDetectedAt()->diff($incident->getResolvedAt());
        return ($interval->days * 24) + $interval->h;
    }

    /**
     * Get historical context for affected processes
     *
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
     * @return string Severity level
     */
    private function calculateImpactSeverity(
        BusinessProcess $businessProcess,
        int $downtimeHours,
        bool $rtoViolated,
        bool $mtpdViolated
    ): string {
        // MTPD violation is always critical
        if ($mtpdViolated) {
            return 'critical';
        }

        // Critical process or severe RTO violation
        if ($businessProcess->getCriticality() === 'critical' ||
            ($rtoViolated && $downtimeHours > $businessProcess->getRto() * 2)) {
            return 'critical';
        }

        // RTO violation or high business impact
        if ($rtoViolated || $businessProcess->getBusinessImpactScore() >= 4) {
            return 'high';
        }

        // Moderate impact
        if ($businessProcess->getBusinessImpactScore() >= 3) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Generate actionable recommendations
     *
     * @return array List of recommendations
     */
    private function generateRecommendations(Incident $incident, array $processes, array $rtoViolations): array
    {
        $recommendations = [];

        if (count($rtoViolations) > 0) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'rto_compliance',
                'title' => $this->t('recommendation.rto_violations.title'),
                'description' => $this->t('recommendation.rto_violations.description', ['%count%' => count($rtoViolations)]),
                'action' => $this->t('recommendation.rto_violations.action'),
            ];
        }

        if ($incident->getAffectedAssets()->count() > $incident->getAffectedBusinessProcesses()->count()) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'process_mapping',
                'title' => $this->t('recommendation.process_mapping.title'),
                'description' => $this->t('recommendation.process_mapping.description'),
                'action' => $this->t('recommendation.process_mapping.action'),
            ];
        }

        foreach ($processes as $process) {
            if ($process->getRecoveryStrategy() === null || trim($process->getRecoveryStrategy()) === '') {
                $recommendations[] = [
                    'priority' => 'medium',
                    'category' => 'recovery_planning',
                    'title' => $this->t('recommendation.recovery_strategy.title', ['%process%' => $process->getName()]),
                    'description' => $this->t('recommendation.recovery_strategy.description'),
                    'action' => $this->t('recommendation.recovery_strategy.action'),
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
                $this->t('finding.processes', [
                    '%count%' => $analysis['affected_processes']['total_count'],
                    '%amount%' => number_format((float) $analysis['financial_impact']['total_eur'], 2, ',', '.'),
                ]),
                $this->t('finding.priority', [
                    '%level%' => $this->t('level.' . $analysis['recovery_priority']['level']),
                    '%reason%' => $analysis['recovery_priority']['reasoning'],
                ]),
                $analysis['rto_compliance']['is_compliant']
                    ? $this->t('finding.rto_met')
                    : $this->t('finding.rto_violations', ['%count%' => $analysis['rto_compliance']['violations_count']]),
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
            'financial_by_process' => array_map(fn(array $p): array => [
                'name' => $p['process_name'],
                'value' => $p['financial_impact'],
            ], $processes),

            'criticality_distribution' => $this->aggregateBy($processes, 'criticality'),

            'rto_compliance' => [
                'compliant' => count(array_filter($processes, fn(array $p): bool => !$p['rto_violated'])),
                'violated' => count(array_filter($processes, fn(array $p) => $p['rto_violated'])),
            ],

            'impact_severity' => $this->aggregateBy($processes, 'impact_severity'),
        ];
    }

    /**
     * Helper: Aggregate data by field
     *
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

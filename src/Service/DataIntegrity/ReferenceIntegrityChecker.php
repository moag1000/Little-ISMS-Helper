<?php

declare(strict_types=1);

namespace App\Service\DataIntegrity;

use App\Enum\IncidentStatus;
use App\Enum\InternalAuditStatus;
use App\Enum\TreatmentStrategy;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\ControlRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\KpiSnapshotRepository;
use App\Repository\RiskRepository;
use App\Repository\TrainingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Checks structural reference integrity: broken FK references, missing required
 * relationships, and inconsistent entity data (status vs. date mismatches etc.).
 *
 * Extracted from DataIntegrityService to isolate reference-integrity concerns.
 *
 * ### findAll() caching
 *
 * findBrokenReferences(), findMissingRelationships(), and findInconsistentData()
 * all operate on the same entity collections (Risks, Incidents, Controls).
 * When the facade calls all three sequentially via runFullIntegrityCheck(), each
 * used to independently issue findAll() — 6+ unbounded hydrations on large datasets.
 *
 * The getAllEntities() helper centralises the three heavy loads and caches the
 * result in $entityCache for the lifetime of the request. Individual public
 * methods remain unchanged for backward-compat.
 *
 * @see \App\Service\DataIntegrityService::findBrokenReferences()
 * @see \App\Service\DataIntegrityService::findMissingRelationships()
 * @see \App\Service\DataIntegrityService::findInconsistentData()
 */
final class ReferenceIntegrityChecker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly ControlRepository $controlRepository,
        private readonly InternalAuditRepository $auditRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly ?DataSubjectRequestRepository $dataSubjectRequestRepository = null,
        private readonly ?KpiSnapshotRepository $kpiSnapshotRepository = null,
    ) {
    }

    /**
     * Per-request entity cache: populated on first call, reused on subsequent calls
     * within the same runFullIntegrityCheck() sweep.
     *
     * @var array<string, array<object>>|null
     */
    private ?array $entityCache = null;

    /**
     * Load all entity collections once per check-sweep and cache them.
     *
     * All three public check methods need Risks, Incidents, and Controls.
     * Loading them once here avoids 6+ redundant unbounded findAll() hydrations
     * when the facade calls all three sequentially.
     *
     * @return array<string, array<object>>
     */
    private function getAllEntities(): array
    {
        if ($this->entityCache !== null) {
            return $this->entityCache;
        }

        $this->entityCache = [
            'risks' => $this->riskRepository->findAll(),
            'incidents' => $this->incidentRepository->findAll(),
            'controls' => $this->controlRepository->findAll(),
            'audits' => $this->auditRepository->findAll(),
            'bcPlans' => $this->bcPlanRepository->findAll(),
            'trainings' => $this->trainingRepository->findAll(),
        ];

        return $this->entityCache;
    }

    /**
     * Reset the per-request entity cache.
     *
     * Call this if you need fresh data after a repair operation.
     */
    public function resetCache(): void
    {
        $this->entityCache = null;
    }

    /**
     * Find broken foreign key references
     */
    public function findBrokenReferences(): array
    {
        $broken = [];
        $entities = $this->getAllEntities();

        // Check risks with invalid asset references
        foreach ($entities['risks'] as $risk) {
            $asset = $risk->getAsset();
            if ($asset && !$this->entityManager->contains($asset)) {
                $broken[] = [
                    'type' => 'risk_invalid_asset',
                    'entity_type' => 'Risk',
                    'entity_id' => $risk->getId(),
                    'entity_name' => $risk->getTitle(),
                    'issue' => 'References non-existent asset',
                ];
            }

            // Check tenant mismatch
            if ($asset && $risk->getTenant() && $asset->getTenant() &&
                $risk->getTenant()->getId() !== $asset->getTenant()->getId()) {
                $broken[] = [
                    'type' => 'risk_asset_tenant_mismatch',
                    'entity_type' => 'Risk',
                    'entity_id' => $risk->getId(),
                    'entity_name' => $risk->getTitle(),
                    'issue' => sprintf('Risk tenant (%s) differs from asset tenant (%s)',
                        $risk->getTenant()->getName(),
                        $asset->getTenant()->getName()),
                ];
            }
        }

        // Check incidents with invalid asset references
        foreach ($entities['incidents'] as $incident) {
            foreach ($incident->getAffectedAssets() as $asset) {
                if (!$this->entityManager->contains($asset)) {
                    $broken[] = [
                        'type' => 'incident_invalid_asset',
                        'entity_type' => 'Incident',
                        'entity_id' => $incident->getId(),
                        'entity_name' => $incident->getTitle(),
                        'issue' => 'References non-existent asset',
                    ];
                    break;
                }

                // Check tenant mismatch
                if ($incident->getTenant() && $asset->getTenant() &&
                    $incident->getTenant()->getId() !== $asset->getTenant()->getId()) {
                    $broken[] = [
                        'type' => 'incident_asset_tenant_mismatch',
                        'entity_type' => 'Incident',
                        'entity_id' => $incident->getId(),
                        'entity_name' => $incident->getTitle(),
                        'issue' => sprintf('Incident tenant (%s) differs from asset tenant (%s)',
                            $incident->getTenant()->getName(),
                            $asset->getTenant()->getName()),
                    ];
                    break;
                }
            }
        }

        // Check controls with invalid risk references
        foreach ($entities['controls'] as $control) {
            foreach ($control->getRisks() as $risk) {
                if (!$this->entityManager->contains($risk)) {
                    $broken[] = [
                        'type' => 'control_invalid_risk',
                        'entity_type' => 'Control',
                        'entity_id' => $control->getId(),
                        'entity_name' => $control->getName(),
                        'issue' => 'References non-existent risk',
                    ];
                    break;
                }
            }
        }

        return $broken;
    }

    /**
     * Find entities with missing required relationships
     */
    public function findMissingRelationships(): array
    {
        $missing = [];
        $entities = $this->getAllEntities();

        // Risks without assets
        $risksWithoutAsset = $this->riskRepository->createQueryBuilder('r')
            ->where('r.asset IS NULL')
            ->getQuery()->getResult();
        if (count($risksWithoutAsset) > 0) {
            $missing['risks_without_asset'] = $risksWithoutAsset;
        }

        // Incidents without affected assets (use cached collection)
        $incidentsWithoutAssets = [];
        foreach ($entities['incidents'] as $incident) {
            if ($incident->getAffectedAssets()->isEmpty()) {
                $incidentsWithoutAssets[] = $incident;
            }
        }
        if (count($incidentsWithoutAssets) > 0) {
            $missing['incidents_without_assets'] = $incidentsWithoutAssets;
        }

        // Applicable controls without risks (and without framework mapping)
        $controlsWithoutRisks = [];
        foreach ($entities['controls'] as $control) {
            if ($control->isApplicable() && $control->getRisks()->isEmpty()) {
                $controlsWithoutRisks[] = $control;
            }
        }
        if (count($controlsWithoutRisks) > 0) {
            $missing['controls_without_risks'] = $controlsWithoutRisks;
        }

        // Applicable controls without protected assets
        $controlsWithoutAssets = [];
        foreach ($entities['controls'] as $control) {
            if ($control->isApplicable() && $control->getProtectedAssets()->isEmpty()) {
                $controlsWithoutAssets[] = $control;
            }
        }
        if (count($controlsWithoutAssets) > 0) {
            $missing['controls_without_assets'] = $controlsWithoutAssets;
        }

        // BC Plans without business processes (use cached collection)
        $bcPlansWithoutProcesses = [];
        foreach ($entities['bcPlans'] as $plan) {
            if (!$plan->getBusinessProcess()) {
                $bcPlansWithoutProcesses[] = $plan;
            }
        }
        if (count($bcPlansWithoutProcesses) > 0) {
            $missing['bc_plans_without_process'] = $bcPlansWithoutProcesses;
        }

        // Trainings without participants assigned (use cached collection)
        $trainingsWithoutParticipants = [];
        foreach ($entities['trainings'] as $training) {
            if (empty($training->getParticipants())) {
                $trainingsWithoutParticipants[] = $training;
            }
        }
        if (count($trainingsWithoutParticipants) > 0) {
            $missing['trainings_without_participants'] = $trainingsWithoutParticipants;
        }

        // DataSubjectRequests without assignee
        if ($this->dataSubjectRequestRepository !== null) {
            $unassignedDsr = $this->dataSubjectRequestRepository->createQueryBuilder('d')
                ->where('d.assignedTo IS NULL')
                ->andWhere('d.status NOT IN (:terminal)')
                ->setParameter('terminal', ['completed', 'rejected'])
                ->getQuery()->getResult();
            if (count($unassignedDsr) > 0) {
                $missing['dsr_without_assignee'] = $unassignedDsr;
            }
        }

        return $missing;
    }

    /**
     * Find inconsistent data (e.g., dates, status)
     */
    public function findInconsistentData(): array
    {
        $inconsistent = [];
        $entities = $this->getAllEntities();

        // Audits with completed status but no actual completion date (use cached collection)
        foreach ($entities['audits'] as $audit) {
            if (in_array($audit->getStatus(), [InternalAuditStatus::Completed->value, InternalAuditStatus::Reported->value]) && !$audit->getActualDate()) {
                $inconsistent['audits_completed_without_date'][] = $audit;
            }
        }

        // Risks with residual risk higher than inherent risk (use cached collection)
        foreach ($entities['risks'] as $risk) {
            if ($risk->getResidualRiskLevel() && $risk->getInherentRiskLevel() &&
                $risk->getResidualRiskLevel() > $risk->getInherentRiskLevel()) {
                $inconsistent['risks_residual_higher_than_inherent'][] = $risk;
            }
        }

        // Incidents with resolved status but no resolution date (use cached collection)
        foreach ($entities['incidents'] as $incident) {
            if ($incident->getStatus() === IncidentStatus::Resolved && !$incident->getResolvedAt()) {
                $inconsistent['incidents_resolved_without_date'][] = $incident;
            }
        }

        // Risk status validation — pass enum values (strings) to DQL, not enum cases.
        $validRiskStatuses = array_map(static fn(\App\Enum\RiskStatus $s): string => $s->value, \App\Enum\RiskStatus::cases());
        try {
            $invalidRiskStatuses = $this->riskRepository->createQueryBuilder('r')
                ->where('r.status NOT IN (:valid)')->setParameter('valid', $validRiskStatuses)
                ->getQuery()->getResult();
            if (is_array($invalidRiskStatuses) && count($invalidRiskStatuses) > 0) {
                $inconsistent['invalid_risk_status'] = $invalidRiskStatuses;
            }
        } catch (\Throwable) {
            // Skip if query fails (e.g., in unit tests with mocked repos)
        }

        // Risk: accept without formal acceptance
        // Risk: accept without formal acceptance (use cached collection)
        $unacceptedAccepts = array_filter($entities['risks'], fn($r) => $r->getTreatmentStrategy() === TreatmentStrategy::Accept && !$r->isFormallyAccepted());
        if (count($unacceptedAccepts) > 0) {
            $inconsistent['accept_without_formal'] = array_values($unacceptedAccepts);
        }

        // Incident status validation
        $validIncidentStatuses = ['reported', 'in_investigation', 'in_resolution', 'resolved', 'closed'];
        try {
            $invalidIncidentStatuses = $this->incidentRepository->createQueryBuilder('i')
                ->where('i.status NOT IN (:valid)')->setParameter('valid', $validIncidentStatuses)
                ->getQuery()->getResult();
            if (is_array($invalidIncidentStatuses) && count($invalidIncidentStatuses) > 0) {
                $inconsistent['invalid_incident_status'] = $invalidIncidentStatuses;
            }
        } catch (\Throwable) {
        }

        // DataSubjectRequest checks
        if ($this->dataSubjectRequestRepository !== null) {
            $validDsrStatuses = ['received', 'identity_verification', 'in_progress', 'completed', 'rejected', 'extended'];
            $invalidDsr = $this->dataSubjectRequestRepository->createQueryBuilder('d')
                ->where('d.status NOT IN (:valid)')->setParameter('valid', $validDsrStatuses)
                ->getQuery()->getResult();
            if (count($invalidDsr) > 0) {
                $inconsistent['invalid_dsr_status'] = $invalidDsr;
            }

            $allDsr = $this->dataSubjectRequestRepository->findAll();
            $overdueOpen = array_filter($allDsr, fn($d) =>
                $d->getEffectiveDeadline() !== null &&
                $d->getEffectiveDeadline() < new \DateTimeImmutable() &&
                !in_array($d->getStatus(), ['completed', 'rejected'])
            );
            if (count($overdueOpen) > 0) {
                $inconsistent['overdue_data_subject_requests'] = array_values($overdueOpen);
            }

            $completedNoResponse = array_filter($allDsr, fn($d) =>
                $d->getStatus() === 'completed' && empty($d->getResponseDescription())
            );
            if (count($completedNoResponse) > 0) {
                $inconsistent['completed_dsr_without_response'] = array_values($completedNoResponse);
            }
        }

        // KpiSnapshot with empty data
        if ($this->kpiSnapshotRepository !== null) {
            $emptySnapshots = array_filter(
                $this->kpiSnapshotRepository->findAll(),
                fn($s) => empty($s->getKpiData())
            );
            if (count($emptySnapshots) > 0) {
                $inconsistent['empty_kpi_snapshots'] = array_values($emptySnapshots);
            }
        }

        // Documents without owner (now nullable after schema change)
        try {
            $docsWithoutOwner = $this->documentRepository->createQueryBuilder('d')
                ->where('d.user IS NULL')->getQuery()->getResult();
            if (is_array($docsWithoutOwner) && count($docsWithoutOwner) > 0) {
                $inconsistent['documents_without_owner'] = $docsWithoutOwner;
            }
        } catch (\Throwable) {
        }

        return $inconsistent;
    }
}

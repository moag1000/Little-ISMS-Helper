<?php

namespace App\Service;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\TenantRepository;
use App\Repository\ControlRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\DocumentRepository;
use App\Repository\TrainingRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\DataBreachRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\SupplierRepository;
use App\Repository\LocationRepository;
use App\Repository\PersonRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\KpiSnapshotRepository;

/**
 * Comprehensive data integrity checker for tenant isolation and data consistency
 *
 * Detects and reports:
 * - Orphaned entities (no tenant assigned)
 * - Duplicate entities within the same tenant
 * - Broken foreign key references
 * - Inconsistent entity relationships
 * - Missing required relationships
 */
class DataIntegrityService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly TenantRepository $tenantRepository,
        private readonly ControlRepository $controlRepository,
        private readonly InternalAuditRepository $auditRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly LocationRepository $locationRepository,
        private readonly PersonRepository $personRepository,
        private readonly ?DataSubjectRequestRepository $dataSubjectRequestRepository = null,
        private readonly ?KpiSnapshotRepository $kpiSnapshotRepository = null
    ) {
    }

    /**
     * Run comprehensive integrity check and return all issues found
     */
    public function runFullIntegrityCheck(): array
    {
        return [
            'orphaned_entities' => $this->findAllOrphanedEntities(),
            'duplicates' => $this->findDuplicateEntities(),
            'broken_references' => $this->findBrokenReferences(),
            'missing_relationships' => $this->findMissingRelationships(),
            'inconsistent_data' => $this->findInconsistentData(),
            'entity_counts' => $this->getEntityCountsByTenant(),
        ];
    }

    /**
     * Find all entities without tenant assignment
     *
     * WICHTIG: TenantFilter muss hier deaktiviert sein, sonst kombiniert
     * Doctrine das "tenant IS NULL" mit dem automatischen
     * "tenant_id = :current" zu einer widersprüchlichen Bedingung
     * und liefert 0 Resultate zurück. Orphans bleiben unsichtbar.
     */
    public function findAllOrphanedEntities(): array
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        try {
            return $this->queryOrphanedEntities();
        } finally {
            if ($wasEnabled) {
                $filters->enable('tenant_filter');
            }
        }
    }

    /**
     * Generischer Scan: alle Doctrine-gemappten Entities mit tenant-Assoziation
     * auf NULL-Tenant prüfen. Entdeckt automatisch neue Entity-Typen — kein
     * Ctor-Argument pro Entity-Klasse mehr nötig.
     */
    private function queryOrphanedEntities(): array
    {
        $orphaned = [];
        $metadataFactory = $this->entityManager->getMetadataFactory();

        // User wird ausgeschlossen — Super-Admins dürfen legitim tenant-los sein.
        $excludedClasses = [Tenant::class, \App\Entity\User::class];

        foreach ($metadataFactory->getAllMetadata() as $metadata) {
            $className = $metadata->getName();

            if (in_array($className, $excludedClasses, true) || !$metadata->hasAssociation('tenant')) {
                continue;
            }

            // Abstract/Mapped-Superclass können nicht direkt abgefragt werden
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }

            $orphans = $this->entityManager->createQueryBuilder()
                ->select('e')
                ->from($className, 'e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult();

            if (count($orphans) > 0) {
                // Key ist kurzer Entity-Name in snake_case-Plural (z.B. DataBreach → data_breaches)
                $shortName = substr($className, strrpos($className, '\\') + 1);
                $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $shortName));
                $key = $snake . (str_ends_with($snake, 's') ? '' : 's');
                $orphaned[$key] = $orphans;
            }
        }

        ksort($orphaned);
        return $orphaned;
    }

    /**
     * Find duplicate entities within the same tenant
     * (e.g., same audit number, same asset name)
     */
    public function findDuplicateEntities(): array
    {
        $duplicates = [];

        // Find audits with duplicate audit numbers within same tenant
        $audits = $this->auditRepository->findAll();
        $auditsByTenant = [];
        foreach ($audits as $audit) {
            if ($audit->getTenant()) {
                $key = $audit->getTenant()->getId() . '_' . $audit->getAuditNumber();
                if (!isset($auditsByTenant[$key])) {
                    $auditsByTenant[$key] = [];
                }
                $auditsByTenant[$key][] = $audit;
            }
        }
        foreach ($auditsByTenant as $key => $group) {
            if (count($group) > 1) {
                $duplicates['audits'][] = [
                    'key' => $key,
                    'count' => count($group),
                    'entities' => $group,
                    'field' => 'auditNumber',
                    'value' => $group[0]->getAuditNumber(),
                ];
            }
        }

        // Find assets with duplicate names within same tenant
        $assets = $this->assetRepository->findAll();
        $assetsByTenant = [];
        foreach ($assets as $asset) {
            if ($asset->getTenant()) {
                $key = $asset->getTenant()->getId() . '_' . strtolower((string) $asset->getName());
                if (!isset($assetsByTenant[$key])) {
                    $assetsByTenant[$key] = [];
                }
                $assetsByTenant[$key][] = $asset;
            }
        }
        foreach ($assetsByTenant as $key => $group) {
            if (count($group) > 1) {
                $duplicates['assets'][] = [
                    'key' => $key,
                    'count' => count($group),
                    'entities' => $group,
                    'field' => 'name',
                    'value' => $group[0]->getName(),
                ];
            }
        }

        // Find risks with duplicate titles within same tenant
        $risks = $this->riskRepository->findAll();
        $risksByTenant = [];
        foreach ($risks as $risk) {
            if ($risk->getTenant()) {
                $key = $risk->getTenant()->getId() . '_' . strtolower((string) $risk->getTitle());
                if (!isset($risksByTenant[$key])) {
                    $risksByTenant[$key] = [];
                }
                $risksByTenant[$key][] = $risk;
            }
        }
        foreach ($risksByTenant as $key => $group) {
            if (count($group) > 1) {
                $duplicates['risks'][] = [
                    'key' => $key,
                    'count' => count($group),
                    'entities' => $group,
                    'field' => 'title',
                    'value' => $group[0]->getTitle(),
                ];
            }
        }

        // Incident duplicates by title
        $incidentsByTenant = [];
        foreach ($this->incidentRepository->findAll() as $incident) {
            if ($incident->getTenant()) {
                $key = $incident->getTenant()->getId() . '_' . strtolower(trim($incident->getTitle()));
                $incidentsByTenant[$key][] = $incident;
            }
        }
        foreach ($incidentsByTenant as $group) {
            if (count($group) > 1) {
                $duplicates['incidents'][] = $group;
            }
        }

        // Document duplicates by original filename (Document has no getTitle())
        $docsByTenant = [];
        foreach ($this->documentRepository->findAll() as $doc) {
            $name = $doc->getOriginalFilename() ?? $doc->getFilename();
            if ($doc->getTenant() && $name !== null && $name !== '') {
                $key = $doc->getTenant()->getId() . '_' . strtolower(trim($name));
                $docsByTenant[$key][] = $doc;
            }
        }
        foreach ($docsByTenant as $group) {
            if (count($group) > 1) {
                $duplicates['documents'][] = $group;
            }
        }

        return $duplicates;
    }

    /**
     * Find broken foreign key references
     */
    public function findBrokenReferences(): array
    {
        $broken = [];

        // Check risks with invalid asset references
        $allRisks = $this->riskRepository->findAll();
        foreach ($allRisks as $risk) {
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
        $allIncidents = $this->incidentRepository->findAll();
        foreach ($allIncidents as $incident) {
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
        $allControls = $this->controlRepository->findAll();
        foreach ($allControls as $control) {
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

        // Risks without assets
        $risksWithoutAsset = $this->riskRepository->createQueryBuilder('r')
            ->where('r.asset IS NULL')
            ->getQuery()->getResult();
        if (count($risksWithoutAsset) > 0) {
            $missing['risks_without_asset'] = $risksWithoutAsset;
        }

        // Incidents without affected assets
        $incidentsWithoutAssets = [];
        $allIncidents = $this->incidentRepository->findAll();
        foreach ($allIncidents as $incident) {
            if ($incident->getAffectedAssets()->isEmpty()) {
                $incidentsWithoutAssets[] = $incident;
            }
        }
        if (count($incidentsWithoutAssets) > 0) {
            $missing['incidents_without_assets'] = $incidentsWithoutAssets;
        }

        // Applicable controls without risks (and without framework mapping)
        $controlsWithoutRisks = [];
        $allControls = $this->controlRepository->findAll();
        foreach ($allControls as $control) {
            if ($control->isApplicable() && $control->getRisks()->isEmpty()) {
                $controlsWithoutRisks[] = $control;
            }
        }
        if (count($controlsWithoutRisks) > 0) {
            $missing['controls_without_risks'] = $controlsWithoutRisks;
        }

        // Applicable controls without protected assets
        $controlsWithoutAssets = [];
        foreach ($allControls as $control) {
            if ($control->isApplicable() && $control->getProtectedAssets()->isEmpty()) {
                $controlsWithoutAssets[] = $control;
            }
        }
        if (count($controlsWithoutAssets) > 0) {
            $missing['controls_without_assets'] = $controlsWithoutAssets;
        }

        // BC Plans without business processes
        $bcPlansWithoutProcesses = [];
        $allBcPlans = $this->bcPlanRepository->findAll();
        foreach ($allBcPlans as $plan) {
            if (!$plan->getBusinessProcess()) {
                $bcPlansWithoutProcesses[] = $plan;
            }
        }
        if (count($bcPlansWithoutProcesses) > 0) {
            $missing['bc_plans_without_process'] = $bcPlansWithoutProcesses;
        }

        // Trainings without participants assigned
        $trainingsWithoutParticipants = [];
        foreach ($this->trainingRepository->findAll() as $training) {
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

        // Audits with completed status but no actual completion date
        $audits = $this->auditRepository->findAll();
        foreach ($audits as $audit) {
            if (in_array($audit->getStatus(), ['completed', 'reported']) && !$audit->getActualDate()) {
                $inconsistent['audits_completed_without_date'][] = $audit;
            }
        }

        // Risks with residual risk higher than inherent risk
        $risks = $this->riskRepository->findAll();
        foreach ($risks as $risk) {
            if ($risk->getResidualRiskLevel() && $risk->getInherentRiskLevel() &&
                $risk->getResidualRiskLevel() > $risk->getInherentRiskLevel()) {
                $inconsistent['risks_residual_higher_than_inherent'][] = $risk;
            }
        }

        // Incidents with resolved status but no resolution date
        $incidents = $this->incidentRepository->findAll();
        foreach ($incidents as $incident) {
            if ($incident->getStatus() === 'resolved' && !$incident->getResolvedAt()) {
                $inconsistent['incidents_resolved_without_date'][] = $incident;
            }
        }

        // Risk status validation
        $validRiskStatuses = ['identified', 'assessed', 'treated', 'monitored', 'closed', 'accepted'];
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
        $unacceptedAccepts = array_filter($risks, fn($r) => $r->getTreatmentStrategy() === 'accept' && !$r->isFormallyAccepted());
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

    /**
     * Get entity counts grouped by tenant
     */
    public function getEntityCountsByTenant(): array
    {
        $tenants = $this->tenantRepository->findAll();
        $counts = [];

        foreach ($tenants as $tenant) {
            $counts[$tenant->getId()] = [
                'tenant' => $tenant,
                'assets' => (int) $this->assetRepository->createQueryBuilder('a')->select('COUNT(a.id)')->where('a.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'risks' => (int) $this->riskRepository->createQueryBuilder('r')->select('COUNT(r.id)')->where('r.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'incidents' => (int) $this->incidentRepository->createQueryBuilder('i')->select('COUNT(i.id)')->where('i.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'audits' => (int) $this->auditRepository->createQueryBuilder('au')->select('COUNT(au.id)')->where('au.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'documents' => (int) $this->documentRepository->createQueryBuilder('d')->select('COUNT(d.id)')->where('d.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'trainings' => (int) $this->trainingRepository->createQueryBuilder('tr')->select('COUNT(tr.id)')->where('tr.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'business_processes' => (int) $this->businessProcessRepository->createQueryBuilder('bp')->select('COUNT(bp.id)')->where('bp.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'bc_plans' => (int) $this->bcPlanRepository->createQueryBuilder('bc')->select('COUNT(bc.id)')->where('bc.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'data_breaches' => (int) $this->dataBreachRepository->createQueryBuilder('db')->select('COUNT(db.id)')->where('db.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'processing_activities' => (int) $this->processingActivityRepository->createQueryBuilder('pa')->select('COUNT(pa.id)')->where('pa.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'suppliers' => (int) $this->supplierRepository->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'locations' => (int) $this->locationRepository->createQueryBuilder('l')->select('COUNT(l.id)')->where('l.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'people' => (int) $this->personRepository->createQueryBuilder('p')->select('COUNT(p.id)')->where('p.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
            ];

            if ($this->dataSubjectRequestRepository !== null) {
                $counts[$tenant->getId()]['data_subject_requests'] = (int) $this->dataSubjectRequestRepository->createQueryBuilder('dsr')->select('COUNT(dsr.id)')->where('dsr.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult();
            }
            if ($this->kpiSnapshotRepository !== null) {
                $counts[$tenant->getId()]['kpi_snapshots'] = (int) $this->kpiSnapshotRepository->createQueryBuilder('ks')->select('COUNT(ks.id)')->where('ks.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult();
            }
        }

        return $counts;
    }

    /**
     * Get summary statistics for dashboard display
     */
    public function getSummaryStatistics(): array
    {
        $orphaned = $this->findAllOrphanedEntities();
        $missing = $this->findMissingRelationships();
        $broken = $this->findBrokenReferences();
        $duplicates = $this->findDuplicateEntities();
        $inconsistent = $this->findInconsistentData();

        $totalOrphaned = 0;
        foreach ($orphaned as $entities) {
            $totalOrphaned += count($entities);
        }

        $totalMissing = 0;
        foreach ($missing as $entities) {
            $totalMissing += count($entities);
        }

        $totalDuplicates = 0;
        foreach ($duplicates as $groups) {
            $totalDuplicates += count($groups);
        }

        $totalInconsistent = 0;
        foreach ($inconsistent as $entities) {
            $totalInconsistent += count($entities);
        }

        return [
            'total_issues' => $totalOrphaned + $totalMissing + count($broken) + $totalDuplicates + $totalInconsistent,
            'orphaned_count' => $totalOrphaned,
            'missing_relationships_count' => $totalMissing,
            'broken_references_count' => count($broken),
            'duplicates_count' => $totalDuplicates,
            'inconsistent_count' => $totalInconsistent,
            'health_score' => $this->calculateHealthScore($totalOrphaned, $totalMissing, count($broken), $totalDuplicates, $totalInconsistent),
        ];
    }

    /**
     * Calculate overall data health score (0-100)
     */
    private function calculateHealthScore(int $orphaned, int $missing, int $broken, int $duplicates, int $inconsistent): int
    {
        $totalEntities = count($this->assetRepository->findAll()) +
                        count($this->riskRepository->findAll()) +
                        count($this->incidentRepository->findAll()) +
                        count($this->auditRepository->findAll()) +
                        count($this->documentRepository->findAll());

        if ($totalEntities === 0) {
            return 100;
        }

        $totalIssues = ($orphaned * 3) + ($broken * 5) + ($missing) + ($duplicates * 2) + ($inconsistent);
        $maxPossibleIssues = $totalEntities * 5; // Max severity weight

        $healthScore = max(0, 100 - (($totalIssues / $maxPossibleIssues) * 100));

        return (int) round($healthScore);
    }
}

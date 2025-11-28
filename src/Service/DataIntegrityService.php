<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\TenantRepository;
use App\Repository\ControlRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\DocumentRepository;
use App\Repository\UserRepository;
use App\Repository\TrainingRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\DataBreachRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\SupplierRepository;
use App\Repository\LocationRepository;
use App\Repository\PersonRepository;

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
        private readonly UserRepository $userRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly LocationRepository $locationRepository,
        private readonly PersonRepository $personRepository
    ) {
    }

    /**
     * Run comprehensive integrity check and return all issues found
     */
    public function runFullIntegrityCheck(): array
    {
        $issues = [
            'orphaned_entities' => $this->findAllOrphanedEntities(),
            'duplicates' => $this->findDuplicateEntities(),
            'broken_references' => $this->findBrokenReferences(),
            'missing_relationships' => $this->findMissingRelationships(),
            'inconsistent_data' => $this->findInconsistentData(),
            'entity_counts' => $this->getEntityCountsByTenant(),
        ];

        return $issues;
    }

    /**
     * Find all entities without tenant assignment
     */
    public function findAllOrphanedEntities(): array
    {
        return [
            'assets' => $this->assetRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'risks' => $this->riskRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'incidents' => $this->incidentRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'audits' => $this->auditRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'documents' => $this->documentRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'trainings' => $this->trainingRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'business_processes' => $this->businessProcessRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'bc_plans' => $this->bcPlanRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'data_breaches' => $this->dataBreachRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'processing_activities' => $this->processingActivityRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'suppliers' => $this->supplierRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'locations' => $this->locationRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
            'people' => $this->personRepository->createQueryBuilder('e')
                ->where('e.tenant IS NULL')
                ->getQuery()->getResult(),
        ];
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
                $key = $asset->getTenant()->getId() . '_' . strtolower($asset->getName());
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
                $key = $risk->getTenant()->getId() . '_' . strtolower($risk->getTitle());
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
                'assets' => count($this->assetRepository->findByTenant($tenant)),
                'risks' => count($this->riskRepository->findByTenant($tenant)),
                'incidents' => count($this->incidentRepository->findByTenant($tenant)),
                'audits' => count($this->auditRepository->createQueryBuilder('a')
                    ->where('a.tenant = :tenant')
                    ->setParameter('tenant', $tenant)
                    ->getQuery()->getResult()),
                'documents' => count($this->documentRepository->createQueryBuilder('d')
                    ->where('d.tenant = :tenant')
                    ->setParameter('tenant', $tenant)
                    ->getQuery()->getResult()),
                'trainings' => count($this->trainingRepository->createQueryBuilder('t')
                    ->where('t.tenant = :tenant')
                    ->setParameter('tenant', $tenant)
                    ->getQuery()->getResult()),
                'business_processes' => count($this->businessProcessRepository->createQueryBuilder('bp')
                    ->where('bp.tenant = :tenant')
                    ->setParameter('tenant', $tenant)
                    ->getQuery()->getResult()),
                'bc_plans' => count($this->bcPlanRepository->createQueryBuilder('bc')
                    ->where('bc.tenant = :tenant')
                    ->setParameter('tenant', $tenant)
                    ->getQuery()->getResult()),
                'data_breaches' => count($this->dataBreachRepository->createQueryBuilder('db')
                    ->where('db.tenant = :tenant')
                    ->setParameter('tenant', $tenant)
                    ->getQuery()->getResult()),
                'processing_activities' => count($this->processingActivityRepository->createQueryBuilder('pa')
                    ->where('pa.tenant = :tenant')
                    ->setParameter('tenant', $tenant)
                    ->getQuery()->getResult()),
                'suppliers' => count($this->supplierRepository->createQueryBuilder('s')
                    ->where('s.tenant = :tenant')
                    ->setParameter('tenant', $tenant)
                    ->getQuery()->getResult()),
                'locations' => count($this->locationRepository->createQueryBuilder('l')
                    ->where('l.tenant = :tenant')
                    ->setParameter('tenant', $tenant)
                    ->getQuery()->getResult()),
                'people' => count($this->personRepository->createQueryBuilder('p')
                    ->where('p.tenant = :tenant')
                    ->setParameter('tenant', $tenant)
                    ->getQuery()->getResult()),
            ];
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

        $totalIssues = ($orphaned * 3) + ($broken * 5) + ($missing * 1) + ($duplicates * 2) + ($inconsistent * 1);
        $maxPossibleIssues = $totalEntities * 5; // Max severity weight

        $healthScore = max(0, 100 - (($totalIssues / $maxPossibleIssues) * 100));

        return (int) round($healthScore);
    }
}

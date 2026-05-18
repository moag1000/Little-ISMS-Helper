<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification\NotificationTemplate;
use App\Entity\Tenant;
use App\Enum\IncidentStatus;
use App\Enum\TreatmentStrategy;
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
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\KpiSnapshotRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Repository\RiskTreatmentPlanRepository;

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
final class DataIntegrityService
{
    /**
     * Entity classes that are INTENTIONALLY globally scoped (tenant_id = NULL by design).
     *
     * These entities are shared across all tenants as catalogue data. The orphan-repair
     * logic MUST NOT reassign a tenant_id to them — doing so triggers a
     * UniqueConstraintViolationException when multiple seeded rows share the same
     * unique key (e.g. NotificationTemplate.uniq_template_key_tenant).
     *
     * Add to this list when a new globally-scoped entity type is introduced.
     * Each entry must be an FQCN of a Doctrine-mapped entity class.
     */
    private const GLOBAL_CATALOGUE_ENTITIES = [
        NotificationTemplate::class, // Sprint-6a: global notification templates, tenant_id=NULL by design
    ];
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
        private readonly ?KpiSnapshotRepository $kpiSnapshotRepository = null,
        private readonly ?DataProtectionImpactAssessmentRepository $dpiaRepository = null,
        private readonly ?AuditFindingRepository $auditFindingRepository = null,
        private readonly ?CorrectiveActionRepository $correctiveActionRepository = null,
        private readonly ?ManagementReviewRepository $managementReviewRepository = null,
        private readonly ?WorkflowInstanceRepository $workflowInstanceRepository = null,
        private readonly ?RiskTreatmentPlanRepository $riskTreatmentPlanRepository = null,
        /**
         * %kernel.project_dir% — needed for filesystem-orphan scan (uploads-vs-DB).
         * Optional with default null so existing constructor call-sites + unit-tests
         * keep compiling; downstream methods handle the null case gracefully.
         */
        private readonly ?string $projectDir = null,
    ) {
    }

    /**
     * Returns the list of entity FQCN that are globally scoped (tenant_id=NULL by design).
     * The repair-orphan logic skips these to prevent UniqueConstraintViolationException.
     *
     * @return list<class-string>
     */
    public function getGlobalCatalogueEntityClasses(): array
    {
        return self::GLOBAL_CATALOGUE_ENTITIES;
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
            // Extended coverage (2026-05): file-orphans, cascade orphans,
            // JSON-schema violations, AuditLog integrity gaps, status-enum drift.
            // All detection-only except the first two — repair paths live
            // in DataRepairController.
            'orphaned_uploads' => $this->findOrphanedUploads(),
            'cascade_orphans' => $this->findCascadeOrphans(),
            'json_schema_violations' => $this->findJsonSchemaViolations(),
            'audit_log_integrity' => $this->findAuditLogIntegrityIssues(),
            'status_enum_drift' => $this->findStatusEnumDriftIssues(),
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
        // GLOBAL_CATALOGUE_ENTITIES are excluded: their tenant_id=NULL is intentional.
        $excludedClasses = array_merge(
            [Tenant::class, \App\Entity\User::class],
            self::GLOBAL_CATALOGUE_ENTITIES,
        );

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
            if ($incident->getStatus() === IncidentStatus::Resolved && !$incident->getResolvedAt()) {
                $inconsistent['incidents_resolved_without_date'][] = $incident;
            }
        }

        // Risk status validation
        $validRiskStatuses = \App\Enum\RiskStatus::cases();
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
        $unacceptedAccepts = array_filter($risks, fn($r) => $r->getTreatmentStrategy() === TreatmentStrategy::Accept && !$r->isFormallyAccepted());
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
     * Risk-specific health checks (ISO 27005 / ISO 27001 Clause 6.1.2).
     *
     * Returns four keyed arrays, each an array of Risk objects:
     *   - 'risks_missing_treatment_strategy': status not 'identified' but no treatment strategy set
     *   - 'risks_residual_exceeds_inherent': residual risk level > inherent (mathematically impossible)
     *   - 'risks_treatment_plan_without_controls': treatmentDescription filled but no controls linked
     *   - 'risks_past_review_date': reviewDate is in the past and risk is not closed/treated
     */
    public function findRiskHealthIssues(): array
    {
        $issues = [];
        $now = new \DateTimeImmutable();

        // Terminal statuses where review/treatment checks no longer apply
        $terminalStatuses = [
            \App\Enum\RiskStatus::Closed,
            \App\Enum\RiskStatus::Treated,
        ];

        $risks = $this->riskRepository->findAll();

        $missingStrategy = [];
        $residualExceedsInherent = [];
        $treatmentWithoutControls = [];
        $pastReviewDate = [];

        foreach ($risks as $risk) {
            $status = $risk->getStatus();

            // Check 1: Non-identified status but no treatment strategy set
            if (
                $status !== \App\Enum\RiskStatus::Identified
                && $risk->getTreatmentStrategy() === null
                && !in_array($status, $terminalStatuses, true)
            ) {
                $missingStrategy[] = $risk;
            }

            // Check 2: Residual risk > inherent risk (impossible in a correctly assessed risk)
            if ($risk->getResidualRiskLevel() > $risk->getInherentRiskLevel()) {
                $residualExceedsInherent[] = $risk;
            }

            // Check 3: Treatment description filled but no controls linked
            if (
                !empty($risk->getTreatmentDescription())
                && $risk->getControls()->isEmpty()
                && !in_array($status, $terminalStatuses, true)
            ) {
                $treatmentWithoutControls[] = $risk;
            }

            // Check 4: Review date in the past and risk not in a terminal status
            $reviewDate = $risk->getReviewDate();
            if (
                $reviewDate !== null
                && $reviewDate < $now
                && !in_array($status, $terminalStatuses, true)
            ) {
                $pastReviewDate[] = $risk;
            }
        }

        if (count($missingStrategy) > 0) {
            $issues['risks_missing_treatment_strategy'] = $missingStrategy;
        }
        if (count($residualExceedsInherent) > 0) {
            $issues['risks_residual_exceeds_inherent'] = $residualExceedsInherent;
        }
        if (count($treatmentWithoutControls) > 0) {
            $issues['risks_treatment_plan_without_controls'] = $treatmentWithoutControls;
        }
        if (count($pastReviewDate) > 0) {
            $issues['risks_past_review_date'] = $pastReviewDate;
        }

        return $issues;
    }

    /**
     * Compliance-specific health checks (GDPR / ISO 27001 privacy extensions).
     *
     * Returns five keyed arrays:
     *   - 'assets_without_cia'    : Asset objects where all three CIA values are NULL or 0
     *   - 'breaches_overdue_72h'  : DataBreach objects requiring authority notification but overdue
     *   - 'dsr_overdue_30d'       : DataSubjectRequest objects past their deadline and still open
     *   - 'dpia_without_dpo'      : DataProtectionImpactAssessment objects approved without DPO consultation
     *   - 'vvt_incomplete'        : ProcessingActivity objects active but incomplete per Art. 30
     */
    public function findComplianceHealthIssues(): array
    {
        $issues = [];

        // Check 1: Assets without CIA values (all three NULL or 0 = no classification)
        try {
            $assetsWithoutCia = $this->assetRepository->createQueryBuilder('a')
                ->where(
                    '(a.confidentialityValue IS NULL OR a.confidentialityValue = 0) AND ' .
                    '(a.integrityValue IS NULL OR a.integrityValue = 0) AND ' .
                    '(a.availabilityValue IS NULL OR a.availabilityValue = 0)'
                )
                ->getQuery()
                ->getResult();
            if (count($assetsWithoutCia) > 0) {
                $issues['assets_without_cia'] = $assetsWithoutCia;
            }
        } catch (\Throwable) {
        }

        // Check 2: Data Breaches overdue 72h supervisory authority notification (GDPR Art. 33)
        try {
            $cutoff = new \DateTimeImmutable('-72 hours');
            $overdueBreaches = $this->dataBreachRepository->createQueryBuilder('db')
                ->where('db.requiresAuthorityNotification = :req')
                ->andWhere('db.supervisoryAuthorityNotifiedAt IS NULL')
                ->andWhere('db.detectedAt IS NOT NULL')
                ->andWhere('db.detectedAt < :cutoff')
                ->setParameter('req', true)
                ->setParameter('cutoff', $cutoff)
                ->getQuery()
                ->getResult();
            if (count($overdueBreaches) > 0) {
                $issues['breaches_overdue_72h'] = $overdueBreaches;
            }
        } catch (\Throwable) {
        }

        // Check 3: Data Subject Requests past 30-day deadline and still open (GDPR Art. 12(3))
        if ($this->dataSubjectRequestRepository !== null) {
            try {
                $now = new \DateTimeImmutable();
                $overdueDsr = $this->dataSubjectRequestRepository->createQueryBuilder('d')
                    ->where('d.deadlineAt IS NOT NULL')
                    ->andWhere('d.deadlineAt < :now')
                    ->andWhere('d.status NOT IN (:terminal)')
                    ->setParameter('now', $now)
                    ->setParameter('terminal', ['completed', 'rejected'])
                    ->getQuery()
                    ->getResult();
                if (count($overdueDsr) > 0) {
                    $issues['dsr_overdue_30d'] = $overdueDsr;
                }
            } catch (\Throwable) {
            }
        }

        // Check 4: DPIA approved without DPO consultation (GDPR Art. 35/36)
        if ($this->dpiaRepository !== null) {
            try {
                $dpiaWithoutDpo = $this->dpiaRepository->createQueryBuilder('d')
                    ->where('d.status = :approved')
                    ->andWhere('d.dpoConsultationDate IS NULL')
                    ->setParameter('approved', 'approved')
                    ->getQuery()
                    ->getResult();
                if (count($dpiaWithoutDpo) > 0) {
                    $issues['dpia_without_dpo'] = $dpiaWithoutDpo;
                }
            } catch (\Throwable) {
            }
        }

        // Check 5: Active processing activities incomplete per Art. 30 VVT
        try {
            $allActiveActivities = $this->processingActivityRepository->createQueryBuilder('pa')
                ->where('pa.status = :active')
                ->setParameter('active', 'active')
                ->getQuery()
                ->getResult();
            $incomplete = array_filter(
                $allActiveActivities,
                fn($pa): bool =>
                    empty($pa->getName()) ||
                    empty($pa->getPurposes()) ||
                    empty($pa->getLegalBasis())
            );
            if (count($incomplete) > 0) {
                $issues['vvt_incomplete'] = array_values($incomplete);
            }
        } catch (\Throwable) {
        }

        return $issues;
    }

    /**
     * Operational health checks (ISO 27001 Tier 2 operational gaps).
     *
     * Returns up to 7 keyed arrays:
     *   - 'suppliers_unassessed'  : Supplier with criticality='critical' and no security assessment
     *   - 'bc_plans_untested'     : BusinessContinuityPlan active but never tested
     *   - 'findings_overdue'      : AuditFinding open/in_progress past due date
     *   - 'capa_overdue'          : CorrectiveAction in_progress past planned completion date
     *   - 'training_overdue'      : Training whose scheduledDate passed but not completed/cancelled
     *   - 'documents_stale'       : Policy/procedure/guideline documents not updated for >1 year
     *   - 'reviews_overdue'       : ManagementReview planned but reviewDate in the past
     */
    public function findOperationalHealthIssues(): array
    {
        $issues = [];
        $now = new \DateTimeImmutable();

        // Check 1: Critical suppliers never assessed
        try {
            $unassessed = $this->supplierRepository->createQueryBuilder('s')
                ->where('s.criticality = :crit')
                ->andWhere('s.lastSecurityAssessment IS NULL')
                ->setParameter('crit', 'critical')
                ->getQuery()
                ->getResult();
            if (count($unassessed) > 0) {
                $issues['suppliers_unassessed'] = $unassessed;
            }
        } catch (\Throwable) {
        }

        // Check 2: Active BC Plans never tested
        try {
            $untested = $this->bcPlanRepository->createQueryBuilder('bc')
                ->where('bc.status = :active')
                ->andWhere('bc.lastTested IS NULL')
                ->setParameter('active', 'active')
                ->getQuery()
                ->getResult();
            if (count($untested) > 0) {
                $issues['bc_plans_untested'] = $untested;
            }
        } catch (\Throwable) {
        }

        // Check 3: Audit findings overdue (open/in_progress past dueDate)
        if ($this->auditFindingRepository !== null) {
            try {
                $overdueFindings = $this->auditFindingRepository->createQueryBuilder('af')
                    ->where('af.status IN (:open)')
                    ->andWhere('af.dueDate IS NOT NULL')
                    ->andWhere('af.dueDate < :now')
                    ->setParameter('open', ['open', 'in_progress'])
                    ->setParameter('now', $now)
                    ->getQuery()
                    ->getResult();
                if (count($overdueFindings) > 0) {
                    $issues['findings_overdue'] = $overdueFindings;
                }
            } catch (\Throwable) {
            }
        }

        // Check 4: Corrective actions in_progress past plannedCompletionDate
        if ($this->correctiveActionRepository !== null) {
            try {
                $overdueCapas = $this->correctiveActionRepository->createQueryBuilder('ca')
                    ->where('ca.status = :prog')
                    ->andWhere('ca.plannedCompletionDate IS NOT NULL')
                    ->andWhere('ca.plannedCompletionDate < :now')
                    ->setParameter('prog', 'in_progress')
                    ->setParameter('now', $now)
                    ->getQuery()
                    ->getResult();
                if (count($overdueCapas) > 0) {
                    $issues['capa_overdue'] = $overdueCapas;
                }
            } catch (\Throwable) {
            }
        }

        // Check 5: Trainings with scheduledDate in the past and not completed/cancelled
        try {
            $overdueTrainings = $this->trainingRepository->createQueryBuilder('tr')
                ->where('tr.scheduledDate < :now')
                ->andWhere('tr.status NOT IN (:terminal)')
                ->setParameter('now', $now)
                ->setParameter('terminal', ['completed', 'cancelled'])
                ->getQuery()
                ->getResult();
            if (count($overdueTrainings) > 0) {
                $issues['training_overdue'] = $overdueTrainings;
            }
        } catch (\Throwable) {
        }

        // Check 6: Policy/procedure/guideline documents stale (no update in >1 year)
        try {
            $staleThreshold = $now->modify('-1 year');
            $staleDocuments = $this->documentRepository->createQueryBuilder('d')
                ->where('d.category IN (:cats)')
                ->andWhere('d.updatedAt IS NOT NULL')
                ->andWhere('d.updatedAt < :threshold')
                ->setParameter('cats', ['policy', 'procedure', 'guideline'])
                ->setParameter('threshold', $staleThreshold)
                ->getQuery()
                ->getResult();
            if (count($staleDocuments) > 0) {
                $issues['documents_stale'] = $staleDocuments;
            }
        } catch (\Throwable) {
        }

        // Check 7: Management reviews planned but reviewDate in the past
        if ($this->managementReviewRepository !== null) {
            try {
                $overdueReviews = $this->managementReviewRepository->createQueryBuilder('mr')
                    ->where('mr.status = :planned')
                    ->andWhere('mr.reviewDate < :now')
                    ->setParameter('planned', 'planned')
                    ->setParameter('now', $now)
                    ->getQuery()
                    ->getResult();
                if (count($overdueReviews) > 0) {
                    $issues['reviews_overdue'] = $overdueReviews;
                }
            } catch (\Throwable) {
            }
        }

        return $issues;
    }

    /**
     * Tier 3 data quality checks — business-process issues that go beyond
     * structural integrity and compliance but indicate operational gaps.
     *
     * Returns up to four keyed arrays:
     *   - 'workflows_stuck'       : WorkflowInstance in_progress for > 30 days
     *   - 'risks_zero_values'     : Risk with probability/impact NULL or 0, not closed
     *   - 'incidents_no_rca'      : Incident closed without root cause
     *   - 'treatments_unreviewed' : RiskTreatmentPlan completed without actualCompletionDate
     */
    public function findDataQualityIssues(): array
    {
        $issues = [];

        // Check 1: Workflow instances stuck in progress for more than 30 days
        if ($this->workflowInstanceRepository !== null) {
            try {
                $cutoff = new \DateTimeImmutable('-30 days');
                $stuckWorkflows = $this->workflowInstanceRepository->createQueryBuilder('wi')
                    ->where('wi.status = :status')
                    ->andWhere('wi.startedAt < :cutoff')
                    ->setParameter('status', 'in_progress')
                    ->setParameter('cutoff', $cutoff)
                    ->orderBy('wi.startedAt', 'ASC')
                    ->getQuery()
                    ->getResult();
                if (count($stuckWorkflows) > 0) {
                    $issues['workflows_stuck'] = $stuckWorkflows;
                }
            } catch (\Throwable) {
            }
        }

        // Check 2: Risks with zero or null probability/impact that are not closed
        try {
            $risksZeroValues = $this->riskRepository->createQueryBuilder('r')
                ->where('(r.probability = 0 OR r.probability IS NULL)')
                ->andWhere('r.status NOT IN (:excludedStatuses)')
                ->setParameter('excludedStatuses', [\App\Enum\RiskStatus::Closed])
                ->orderBy('r.id', 'ASC')
                ->getQuery()
                ->getResult();
            if (count($risksZeroValues) > 0) {
                $issues['risks_zero_values'] = $risksZeroValues;
            }
        } catch (\Throwable) {
        }

        // Check 3: Incidents closed without a root cause analysis
        try {
            $incidentsNoRca = $this->incidentRepository->createQueryBuilder('i')
                ->where('i.status = :status')
                ->andWhere('i.rootCause IS NULL')
                ->setParameter('status', 'closed')
                ->orderBy('i.id', 'ASC')
                ->getQuery()
                ->getResult();
            if (count($incidentsNoRca) > 0) {
                $issues['incidents_no_rca'] = $incidentsNoRca;
            }
        } catch (\Throwable) {
        }

        // Check 4: Risk treatment plans completed without an actual completion date (effectiveness review missing)
        if ($this->riskTreatmentPlanRepository !== null) {
            try {
                $unreviewedTreatments = $this->riskTreatmentPlanRepository->createQueryBuilder('rtp')
                    ->where('rtp.status = :status')
                    ->andWhere('rtp.actualCompletionDate IS NULL')
                    ->setParameter('status', 'completed')
                    ->orderBy('rtp.id', 'ASC')
                    ->getQuery()
                    ->getResult();
                if (count($unreviewedTreatments) > 0) {
                    $issues['treatments_unreviewed'] = $unreviewedTreatments;
                }
            } catch (\Throwable) {
            }
        }

        return $issues;
    }

    /**
     * Merge duplicate entities for a given entity type, keeping the entity
     * with the lowest ID (oldest) and removing the rest.
     *
     * Returns the number of deleted duplicate entities.
     *
     * Supported entity types: audits, assets, risks, incidents, documents
     */
    public function mergeDuplicates(string $entityType): int
    {
        $duplicates = $this->findDuplicateEntities();

        if (!isset($duplicates[$entityType]) || count($duplicates[$entityType]) === 0) {
            return 0;
        }

        $deleted = 0;

        foreach ($duplicates[$entityType] as $group) {
            // Normalise: groups for audits/assets/risks have an 'entities' key;
            // incidents/documents groups are plain entity arrays.
            $entities = is_array($group) && isset($group['entities'])
                ? $group['entities']
                : (array) $group;

            if (count($entities) < 2) {
                continue;
            }

            // Sort ascending by ID so the oldest survives
            usort($entities, fn($a, $b) => ($a->getId() ?? 0) <=> ($b->getId() ?? 0));

            // Keep the first (lowest ID), delete the rest
            $toDelete = array_slice($entities, 1);
            foreach ($toDelete as $entity) {
                $this->entityManager->remove($entity);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        return $deleted;
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

    // ====================================================================
    // Extended coverage (2026-05) — file-orphans, cascade orphans,
    // JSON-schema violations, AuditLog integrity gaps, status-enum drift.
    // Detection only; repair-paths (where they exist) live in
    // DataRepairController. Designed to be safe on bare unit-test mocks:
    // every method that touches the DB or filesystem wraps the call in
    // a try/catch and returns an empty result when the dependency is
    // unavailable (e.g. when ProjectDir is null in a constructor-mock test).
    // ====================================================================

    /**
     * Scans the filesystem under {projectDir}/public/uploads/ and cross-checks
     * every regular file against the set of file-paths actually referenced
     * by Doctrine entities (Document.filePath, DocumentVersion.filePath,
     * Tenant.logoPath, User.profilePicture). Reports files present on disk
     * with no DB owner.
     *
     * Repair path: {@see DataRepairController::quarantineOrphanedUploads()}
     * — soft move to `var/quarantine/<date>/`, never `unlink`.
     *
     * @return array{
     *     files: list<array{path: string, relative: string, size: int, mtime: int}>,
     *     scanned: int,
     *     referenced: int,
     *     uploads_dir: string|null,
     * }
     */
    public function findOrphanedUploads(): array
    {
        $empty = ['files' => [], 'scanned' => 0, 'referenced' => 0, 'uploads_dir' => null];
        if ($this->projectDir === null) {
            return $empty;
        }
        $uploadsDir = $this->projectDir . '/public/uploads';
        if (!is_dir($uploadsDir)) {
            return $empty;
        }

        // 1. Collect referenced file paths from entity columns.
        $referenced = $this->collectReferencedUploadPaths();

        // 2. Walk the uploads/ tree and flag every regular file that is
        //    NOT in the referenced-set. Skip .gitkeep and dot-files.
        $orphans = [];
        $scanned = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadsDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
        } catch (\Throwable) {
            return $empty;
        }

        $basePath = rtrim((string) realpath($uploadsDir), '/');
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }
            $name = $fileInfo->getFilename();
            if ($name === '.gitkeep' || str_starts_with($name, '.')) {
                continue;
            }
            $scanned++;

            $realPath = (string) $fileInfo->getRealPath();
            if ($realPath === '' || !str_starts_with($realPath, $basePath)) {
                continue;
            }
            // Build the relative path the way entities store it (with or without leading slash).
            $relative = '/uploads' . substr($realPath, strlen($basePath));
            $relativeNoSlash = ltrim($relative, '/');

            if (isset($referenced[$relative]) || isset($referenced[$relativeNoSlash])) {
                continue;
            }
            // tenants/ and users/ logos can be stored either with or without
            // the `uploads/` prefix — the BackupService comments document this.
            $logoStyle = preg_replace('#^/uploads/#', '', $relative);
            if (is_string($logoStyle) && isset($referenced[$logoStyle])) {
                continue;
            }

            $orphans[] = [
                'path' => $realPath,
                'relative' => $relative,
                'size' => (int) $fileInfo->getSize(),
                'mtime' => (int) $fileInfo->getMTime(),
            ];
        }

        // Cap the response list — typical orphan counts after a few backup
        // restores can reach thousands; the template only needs the top N.
        usort($orphans, static fn(array $a, array $b): int => $b['size'] <=> $a['size']);

        return [
            'files' => array_slice($orphans, 0, 500),
            'scanned' => $scanned,
            'referenced' => count($referenced),
            'uploads_dir' => $uploadsDir,
        ];
    }

    /**
     * Builds the set of file-paths currently referenced by Doctrine entities.
     * Returns an associative array keyed by path (both `/uploads/...` and
     * `uploads/...` styles are present where the entity stores either form).
     *
     * @return array<string, true>
     */
    private function collectReferencedUploadPaths(): array
    {
        $set = [];
        $columnMap = [
            // FQCN => property name
            \App\Entity\Document::class => 'filePath',
            \App\Entity\DocumentVersion::class => 'filePath',
            \App\Entity\Tenant::class => 'logoPath',
            \App\Entity\User::class => 'profilePicture',
        ];

        $factory = $this->entityManager->getMetadataFactory();
        foreach ($columnMap as $fqcn => $property) {
            try {
                $metadata = $factory->getMetadataFor($fqcn);
            } catch (\Throwable) {
                continue;
            }
            if (!$metadata->hasField($property)) {
                continue;
            }
            try {
                $rows = $this->entityManager->createQueryBuilder()
                    ->select('e.' . $property . ' AS path')
                    ->from($fqcn, 'e')
                    ->where('e.' . $property . ' IS NOT NULL')
                    ->getQuery()
                    ->getScalarResult();
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                $path = (string) ($row['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $set[$path] = true;
                // Also store the "other" form to be tolerant of mixed storage.
                if (str_starts_with($path, '/')) {
                    $set[ltrim($path, '/')] = true;
                } else {
                    $set['/' . $path] = true;
                }
            }
        }
        return $set;
    }

    /**
     * Cross-entity cascade cleanup detection: entities whose ManyToOne target
     * was deleted but the cascade didn't fire. The five buckets each have
     * a distinct repair-path:
     *   - workflow_instances : target entity-class+id no longer resolves
     *   - mfa_tokens         : `expires_at` < NOW() and the token never logged a usage
     *   - sso_user_approvals : `reviewed_by` user no longer exists
     *   - evidence_tasks     : referenced DocumentVersion + Control are both NULL
     *   - notification_deliveries : NotificationRule that owned the delivery is gone
     *
     * The detection is read-only. Repair runs through
     * {@see DataRepairController::cleanupDanglingRefs()} under a single
     * AuditLogger::logBulk() batch (one batch_id covers all five categories).
     *
     * @return array<string, list<array{id: int, label: string, hint?: string}>>
     */
    public function findCascadeOrphans(): array
    {
        $result = [
            'workflow_instances' => [],
            'mfa_tokens' => [],
            'sso_user_approvals' => [],
            'evidence_tasks' => [],
            'notification_deliveries' => [],
        ];

        // 1. WorkflowInstance — entity_type + entity_id pointing nowhere.
        try {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('wi.id, wi.entityType, wi.entityId')
                ->from(\App\Entity\WorkflowInstance::class, 'wi')
                ->where('wi.entityType IS NOT NULL AND wi.entityId IS NOT NULL')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $type = (string) ($row['entityType'] ?? '');
                $id = (int) ($row['entityId'] ?? 0);
                if ($type === '' || $id === 0 || !class_exists($type)) {
                    continue;
                }
                $target = $this->entityManager->find($type, $id);
                if ($target === null) {
                    $result['workflow_instances'][] = [
                        'id' => (int) $row['id'],
                        'label' => sprintf('WorkflowInstance#%d → %s#%d (missing target)', (int) $row['id'], $type, $id),
                    ];
                }
            }
        } catch (\Throwable) {
            // Pre-flush state or missing table — skip silently.
        }

        // 2. MfaToken — past expiry, never re-used.
        try {
            $now = new \DateTimeImmutable();
            $rows = $this->entityManager->createQueryBuilder()
                ->select('m.id, m.tokenType, m.expiresAt, m.lastUsedAt')
                ->from(\App\Entity\MfaToken::class, 'm')
                ->where('m.expiresAt IS NOT NULL AND m.expiresAt < :now')
                ->setParameter('now', $now)
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                // Only flag tokens that were never used after expiry — used tokens
                // are kept for forensic audit-trail.
                $lastUsed = $row['lastUsedAt'] ?? null;
                if ($lastUsed instanceof \DateTimeInterface && $lastUsed > ($row['expiresAt'] ?? $now)) {
                    continue;
                }
                $result['mfa_tokens'][] = [
                    'id' => (int) $row['id'],
                    'label' => sprintf('MfaToken#%d (%s) expired %s', (int) $row['id'], (string) ($row['tokenType'] ?? 'unknown'), $row['expiresAt'] instanceof \DateTimeInterface ? $row['expiresAt']->format('Y-m-d') : 'unknown'),
                ];
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 3. SsoUserApproval where reviewer User was deleted.
        try {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('s.id, s.email, IDENTITY(s.reviewedBy) AS reviewerId')
                ->from(\App\Entity\SsoUserApproval::class, 's')
                ->where('s.reviewedBy IS NOT NULL')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $reviewerId = (int) ($row['reviewerId'] ?? 0);
                if ($reviewerId === 0) {
                    continue;
                }
                $reviewer = $this->entityManager->find(\App\Entity\User::class, $reviewerId);
                if ($reviewer === null) {
                    $result['sso_user_approvals'][] = [
                        'id' => (int) $row['id'],
                        'label' => sprintf('SsoUserApproval#%d (%s) → User#%d (deleted)', (int) $row['id'], (string) ($row['email'] ?? ''), $reviewerId),
                    ];
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 4. EvidenceReverificationTask where BOTH targets (DocumentVersion + Control)
        //    were deleted — the task has no anchor.
        try {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('t.id, IDENTITY(t.documentVersion) AS dvId, IDENTITY(t.control) AS ctrlId, IDENTITY(t.complianceFulfillment) AS cfId')
                ->from(\App\Entity\EvidenceReverificationTask::class, 't')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $dvId = (int) ($row['dvId'] ?? 0);
                $ctrlId = (int) ($row['ctrlId'] ?? 0);
                $cfId = (int) ($row['cfId'] ?? 0);
                if ($dvId === 0 && $ctrlId === 0 && $cfId === 0) {
                    $result['evidence_tasks'][] = [
                        'id' => (int) $row['id'],
                        'label' => sprintf('EvidenceReverificationTask#%d (no anchor)', (int) $row['id']),
                    ];
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 5. NotificationDelivery whose owning NotificationRule was deleted.
        try {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('d.id, IDENTITY(d.rule) AS ruleId')
                ->from(\App\Entity\Notification\NotificationDelivery::class, 'd')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $ruleId = (int) ($row['ruleId'] ?? 0);
                if ($ruleId === 0) {
                    $result['notification_deliveries'][] = [
                        'id' => (int) $row['id'],
                        'label' => sprintf('NotificationDelivery#%d (rule missing)', (int) $row['id']),
                    ];
                    continue;
                }
                $rule = $this->entityManager->find(\App\Entity\Notification\NotificationRule::class, $ruleId);
                if ($rule === null) {
                    $result['notification_deliveries'][] = [
                        'id' => (int) $row['id'],
                        'label' => sprintf('NotificationDelivery#%d → Rule#%d (deleted)', (int) $row['id'], $ruleId),
                    ];
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        return $result;
    }

    /**
     * Decodes JSON columns and validates their minimal shape. NO auto-repair —
     * surfaces every violation as a manual-review row in the template.
     *
     * Targets:
     *   - Tenant.settings           : object/null, free-form k/v
     *   - TenantPolicySetting.value : any JSON-decodable value (object/scalar/array)
     *   - NotificationRule.conditions : list<{field:string, op:string, value:mixed}>
     *   - WorkflowStep.metadata     : object with optional auto_progression shape
     *
     * @return array<string, list<array{id: int, tenant?: ?string, error: string}>>
     */
    public function findJsonSchemaViolations(): array
    {
        $result = [
            'tenant_settings' => [],
            'tenant_policy_settings' => [],
            'notification_rule_conditions' => [],
            'workflow_step_metadata' => [],
        ];

        // 1. Tenant.settings — null OR associative object expected.
        try {
            $tenants = $this->tenantRepository->findAll();
            foreach ($tenants as $tenant) {
                $value = $tenant->getSettings();
                if ($value === null) {
                    continue;
                }
                if (!is_array($value)) {
                    $result['tenant_settings'][] = [
                        'id' => (int) $tenant->getId(),
                        'tenant' => $tenant->getName(),
                        'error' => 'settings is not an array/object (got ' . gettype($value) . ')',
                    ];
                    continue;
                }
                // Reject pure list-shape — `settings` is supposed to be a k/v map.
                if ($value !== [] && array_is_list($value)) {
                    $result['tenant_settings'][] = [
                        'id' => (int) $tenant->getId(),
                        'tenant' => $tenant->getName(),
                        'error' => 'settings is a list, expected k/v object',
                    ];
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 2. TenantPolicySetting.value — must be JSON-decodable (Doctrine stores
        //    it as JSON; if the column contains corrupted UTF-8 the load throws).
        try {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('p.id, p.key, IDENTITY(p.tenant) AS tenantId')
                ->from(\App\Entity\TenantPolicySetting::class, 'p')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                try {
                    $entity = $this->entityManager->find(\App\Entity\TenantPolicySetting::class, $id);
                    if ($entity === null) {
                        continue;
                    }
                    $value = $entity->getValue();
                    // Just touching the property triggers the JSON-decode path —
                    // a corrupted column throws on hydration.
                    if (is_object($value)) {
                        $result['tenant_policy_settings'][] = [
                            'id' => $id,
                            'error' => sprintf('value for key "%s" hydrated to unexpected object', (string) ($row['key'] ?? '')),
                        ];
                    }
                } catch (\Throwable $e) {
                    $result['tenant_policy_settings'][] = [
                        'id' => $id,
                        'error' => sprintf('value for key "%s" failed to decode: %s', (string) ($row['key'] ?? ''), $e->getMessage()),
                    ];
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 3. NotificationRule.conditions — expect list<{field, op, value}>.
        try {
            $rules = $this->entityManager->createQueryBuilder()
                ->select('r')
                ->from(\App\Entity\Notification\NotificationRule::class, 'r')
                ->getQuery()
                ->getResult();
            foreach ((array) $rules as $rule) {
                $conds = $rule->getConditions();
                if ($conds === []) {
                    continue;
                }
                if (!array_is_list($conds)) {
                    $result['notification_rule_conditions'][] = [
                        'id' => (int) $rule->getId(),
                        'error' => 'conditions is not a list',
                    ];
                    continue;
                }
                foreach ($conds as $idx => $item) {
                    if (!is_array($item)) {
                        $result['notification_rule_conditions'][] = [
                            'id' => (int) $rule->getId(),
                            'error' => sprintf('conditions[%d] is not an object', $idx),
                        ];
                        continue 2;
                    }
                    foreach (['field', 'op'] as $required) {
                        if (!array_key_exists($required, $item) || !is_string($item[$required])) {
                            $result['notification_rule_conditions'][] = [
                                'id' => (int) $rule->getId(),
                                'error' => sprintf('conditions[%d] missing required string key "%s"', $idx, $required),
                            ];
                            continue 3;
                        }
                    }
                    if (!array_key_exists('value', $item)) {
                        $result['notification_rule_conditions'][] = [
                            'id' => (int) $rule->getId(),
                            'error' => sprintf('conditions[%d] missing key "value"', $idx),
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 4. WorkflowStep.metadata — null OR object; if auto_progression key
        //    is set, it must itself be an object with `conditions` list.
        try {
            $steps = $this->entityManager->createQueryBuilder()
                ->select('s')
                ->from(\App\Entity\WorkflowStep::class, 's')
                ->getQuery()
                ->getResult();
            foreach ((array) $steps as $step) {
                $meta = $step->getMetadata();
                if ($meta === null) {
                    continue;
                }
                if (!is_array($meta)) {
                    $result['workflow_step_metadata'][] = [
                        'id' => (int) $step->getId(),
                        'error' => 'metadata is not an array/object',
                    ];
                    continue;
                }
                if ($meta !== [] && array_is_list($meta)) {
                    $result['workflow_step_metadata'][] = [
                        'id' => (int) $step->getId(),
                        'error' => 'metadata is a list, expected k/v object',
                    ];
                    continue;
                }
                if (array_key_exists('auto_progression', $meta) && $meta['auto_progression'] !== null) {
                    $ap = $meta['auto_progression'];
                    if (!is_array($ap) || (isset($ap['conditions']) && !is_array($ap['conditions']))) {
                        $result['workflow_step_metadata'][] = [
                            'id' => (int) $step->getId(),
                            'error' => 'metadata.auto_progression must be object with conditions list',
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        return $result;
    }

    /**
     * AuditLog integrity gap detection.
     * The AuditLog is append-only and HMAC-chained (see AuditLogger).
     * This method surfaces structural anomalies the chain alone cannot catch:
     *
     *   - bulk_batch_mismatches : ACTION_BULK row recorded `per_entity_count = N`
     *                             but fewer per-entity rows actually carry the batch_id
     *   - day_gaps              : days with zero AuditLog entries between days with entries
     *                             over the last 30 days (suspicious in production)
     *   - null_tenant_entries   : rows with tenant_id IS NULL (post-merge backfill leak)
     *
     * Detection-only.
     *
     * @return array{
     *     bulk_batch_mismatches: list<array{batch_id: string, expected: int, actual: int}>,
     *     day_gaps: list<array{date: string}>,
     *     null_tenant_entries: list<array{id: int, action: string, entity_type: string}>,
     * }
     */
    public function findAuditLogIntegrityIssues(): array
    {
        $result = [
            'bulk_batch_mismatches' => [],
            'day_gaps' => [],
            'null_tenant_entries' => [],
        ];

        $connection = $this->entityManager->getConnection();

        // 1. bulk-batch row-count mismatch. We pull every ACTION_BULK row from
        //    the last 90 days, extract batch_id + per_entity_count from new_values,
        //    then count actual rows carrying _batch_id=<X> in new_values.
        try {
            $cutoff = (new \DateTimeImmutable('-90 days'))->format('Y-m-d H:i:s');
            $batchRows = $connection->fetchAllAssociative(
                'SELECT id, new_values FROM audit_log WHERE action = :a AND created_at >= :c',
                ['a' => 'bulk', 'c' => $cutoff],
            );
            foreach ($batchRows as $row) {
                $newValues = $row['new_values'] ?? null;
                if (!is_string($newValues) || $newValues === '') {
                    continue;
                }
                $decoded = json_decode($newValues, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $batchId = (string) ($decoded['batch_id'] ?? '');
                $expected = (int) ($decoded['per_entity_count'] ?? 0);
                if ($batchId === '' || $expected === 0) {
                    continue;
                }
                // Count actual per-entity rows.
                $actual = (int) $connection->fetchOne(
                    "SELECT COUNT(*) FROM audit_log WHERE action <> :bulk AND new_values LIKE :like",
                    ['bulk' => 'bulk', 'like' => '%"_batch_id":"' . $batchId . '"%'],
                );
                if ($actual < $expected) {
                    $result['bulk_batch_mismatches'][] = [
                        'batch_id' => $batchId,
                        'expected' => $expected,
                        'actual' => $actual,
                    ];
                }
            }
        } catch (\Throwable) {
            // Table missing in tests — skip.
        }

        // 2. day-gaps in the last 30 days. SELECT DISTINCT DATE(created_at);
        //    detect gaps where a day with entries was followed/preceded by a
        //    day with 0 entries inside the active window.
        try {
            $days = $connection->fetchAllAssociative(
                "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM audit_log WHERE created_at >= :c GROUP BY DATE(created_at) ORDER BY d",
                ['c' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d 00:00:00')],
            );
            if (count($days) >= 2) {
                $first = new \DateTimeImmutable((string) $days[0]['d']);
                $last = new \DateTimeImmutable((string) end($days)['d']);
                $observed = [];
                foreach ($days as $d) {
                    $observed[(string) $d['d']] = (int) $d['c'];
                }
                $cursor = $first;
                while ($cursor <= $last) {
                    $key = $cursor->format('Y-m-d');
                    if (!isset($observed[$key])) {
                        $result['day_gaps'][] = ['date' => $key];
                    }
                    $cursor = $cursor->modify('+1 day');
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 3. null tenant_id entries (post-merge backfill leak).
        try {
            $nullRows = $connection->fetchAllAssociative(
                "SELECT id, action, entity_type FROM audit_log WHERE tenant_id IS NULL ORDER BY id DESC LIMIT 100",
            );
            foreach ($nullRows as $row) {
                $result['null_tenant_entries'][] = [
                    'id' => (int) $row['id'],
                    'action' => (string) ($row['action'] ?? ''),
                    'entity_type' => (string) ($row['entity_type'] ?? ''),
                ];
            }
        } catch (\Throwable) {
            // Skip.
        }

        return $result;
    }

    /**
     * Status-Enum drift detection (Item-5 Phase-1 follow-up).
     * For each `App\Enum\<Entity>Status` enum, query the DISTINCT values
     * actually stored in the entity's `status` column and report any value
     * not present in the enum's case-set.
     *
     * Detection-only. Repair is a manual triage decision (rename/migrate
     * legacy values via a one-off SQL or a dedicated console command) —
     * automated repair would risk hiding a real lifecycle bug.
     *
     * @return list<array{
     *     entity: string,
     *     enum: string,
     *     unknown_values: array<string, int>,
     * }>
     */
    public function findStatusEnumDriftIssues(): array
    {
        $result = [];
        // Mapping is intentionally explicit (rather than guessing entity-FQCN
        // from enum-FQCN) because not every *Status enum is paired with the
        // identically-named entity (e.g. BCExerciseStatus → BCExercise).
        $pairs = [
            \App\Entity\Asset::class => \App\Enum\AssetStatus::class,
            \App\Entity\Risk::class => \App\Enum\RiskStatus::class,
            \App\Entity\Incident::class => \App\Enum\IncidentStatus::class,
            \App\Entity\Document::class => \App\Enum\DocumentStatus::class,
            \App\Entity\InternalAudit::class => \App\Enum\InternalAuditStatus::class,
            \App\Entity\AuditFinding::class => \App\Enum\AuditFindingStatus::class,
            \App\Entity\CorrectiveAction::class => \App\Enum\CorrectiveActionStatus::class,
            \App\Entity\BusinessContinuityPlan::class => \App\Enum\BusinessContinuityPlanStatus::class,
            \App\Entity\DataBreach::class => \App\Enum\DataBreachStatus::class,
            \App\Entity\ProcessingActivity::class => \App\Enum\ProcessingActivityStatus::class,
            \App\Entity\Supplier::class => \App\Enum\SupplierStatus::class,
            \App\Entity\RiskTreatmentPlan::class => \App\Enum\RiskTreatmentPlanStatus::class,
            \App\Entity\SsoUserApproval::class => \App\Enum\SsoUserApprovalStatus::class,
            \App\Entity\EvidenceReverificationTask::class => \App\Enum\EvidenceReverificationTaskStatus::class,
            \App\Entity\ChangeRequest::class => \App\Enum\ChangeRequestStatus::class,
            \App\Entity\ManagementReview::class => \App\Enum\ManagementReviewStatus::class,
            \App\Entity\Training::class => \App\Enum\TrainingStatus::class,
        ];

        $factory = $this->entityManager->getMetadataFactory();
        foreach ($pairs as $entityFqcn => $enumFqcn) {
            if (!class_exists($entityFqcn) || !enum_exists($enumFqcn)) {
                continue;
            }
            try {
                $metadata = $factory->getMetadataFor($entityFqcn);
            } catch (\Throwable) {
                continue;
            }
            if (!$metadata->hasField('status')) {
                continue;
            }

            $allowed = [];
            foreach ($enumFqcn::cases() as $case) {
                $allowed[(string) $case->value] = true;
            }

            try {
                $rows = $this->entityManager->createQueryBuilder()
                    ->select('e.status AS status, COUNT(e.id) AS cnt')
                    ->from($entityFqcn, 'e')
                    ->where('e.status IS NOT NULL')
                    ->groupBy('e.status')
                    ->getQuery()
                    ->getArrayResult();
            } catch (\Throwable) {
                continue;
            }

            $unknown = [];
            foreach ($rows as $row) {
                $value = (string) ($row['status'] ?? '');
                if ($value === '' || isset($allowed[$value])) {
                    continue;
                }
                $unknown[$value] = (int) ($row['cnt'] ?? 0);
            }

            if ($unknown !== []) {
                $shortEntity = substr($entityFqcn, strrpos($entityFqcn, '\\') + 1);
                $shortEnum = substr($enumFqcn, strrpos($enumFqcn, '\\') + 1);
                $result[] = [
                    'entity' => $shortEntity,
                    'enum' => $shortEnum,
                    'unknown_values' => $unknown,
                ];
            }
        }

        return $result;
    }
}

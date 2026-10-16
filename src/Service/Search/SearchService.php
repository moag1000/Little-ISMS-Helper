<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Asset;
use App\Entity\AuditFinding;
use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\ChangeRequest;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Consent;
use App\Entity\Control;
use App\Entity\CorrectiveAction;
use App\Entity\CrisisTeam;
use App\Entity\DataBreach;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\DataSubjectRequest;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\InterestedParty;
use App\Entity\InternalAudit;
use App\Entity\ISMSObjective;
use App\Entity\ManagementReview;
use App\Entity\Patch;
use App\Entity\Person;
use App\Entity\ProcessingActivity;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\ThreatIntelligence;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\Vulnerability;
use App\Repository\AssetRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ChangeRequestRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ConsentRepository;
use App\Repository\ControlRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\CrisisTeamRepository;
use App\Repository\DataBreachRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\PatchRepository;
use App\Repository\PersonRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;
use App\Repository\ThreatIntelligenceRepository;
use App\Repository\TrainingRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\ModuleConfigurationService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SearchService
{
    private const MAX_RESULTS_PER_CATEGORY = 5;

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly ControlRepository $controlRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly DataProtectionImpactAssessmentRepository $dpiaRepository,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly AuditFindingRepository $auditFindingRepository,
        private readonly CorrectiveActionRepository $correctiveActionRepository,
        private readonly ChangeRequestRepository $changeRequestRepository,
        private readonly InternalAuditRepository $internalAuditRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly BCExerciseRepository $bcExerciseRepository,
        private readonly CrisisTeamRepository $crisisTeamRepository,
        private readonly ManagementReviewRepository $managementReviewRepository,
        private readonly ISMSObjectiveRepository $ismsObjectiveRepository,
        private readonly VulnerabilityRepository $vulnerabilityRepository,
        private readonly PatchRepository $patchRepository,
        private readonly ThreatIntelligenceRepository $threatIntelligenceRepository,
        private readonly PersonRepository $personRepository,
        private readonly InterestedPartyRepository $interestedPartyRepository,
        private readonly ConsentRepository $consentRepository,
        private readonly DataSubjectRequestRepository $dataSubjectRequestRepository,
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly NavigationSearch $navigationSearch,
        private readonly ModuleConfigurationService $moduleConfig,
    ) {}

    /**
     * Run a global search across all entity categories and navigation targets.
     *
     * @return array{total: int, navigation: array, assets: array, risks: array, controls: array, incidents: array, trainings: array, documents: array, suppliers: array, processing_activities: array, dpias: array, data_breaches: array, audit_findings: array, corrective_actions: array, change_requests: array, internal_audits: array, business_processes: array, bc_plans: array, bc_exercises: array, crisis_teams: array, management_reviews: array, objectives: array, vulnerabilities: array, patches: array, threat_intelligence: array, persons: array, interested_parties: array, consents: array, data_subject_requests: array, compliance_frameworks: array, compliance_requirements: array, query: string}
     */
    public function search(string $query, ?Tenant $tenant): array
    {
        $navigation = $this->searchNavigation($query);
        $assets = $this->searchAssets($query, $tenant);
        $risks = $this->searchRisks($query, $tenant);
        $controls = $this->searchControls($query, $tenant);
        $incidents = $this->searchIncidents($query, $tenant);
        $trainings = $this->searchTrainings($query, $tenant);
        $documents = $this->searchDocuments($query, $tenant);
        $suppliers = $this->searchSuppliers($query, $tenant);
        $processingActivities = $this->searchProcessingActivities($query, $tenant);
        $dpias = $this->searchDpias($query, $tenant);
        $dataBreaches = $this->searchDataBreaches($query, $tenant);
        $auditFindings = $this->searchAuditFindings($query, $tenant);
        $correctiveActions = $this->searchCorrectiveActions($query, $tenant);
        $changeRequests = $this->searchChangeRequests($query, $tenant);
        $internalAudits = $this->searchInternalAudits($query, $tenant);
        $businessProcesses = $this->searchBusinessProcesses($query, $tenant);
        $bcPlans = $this->searchBcPlans($query, $tenant);
        $bcExercises = $this->searchBcExercises($query, $tenant);
        $crisisTeams = $this->searchCrisisTeams($query, $tenant);
        $managementReviews = $this->searchManagementReviews($query, $tenant);
        $objectives = $this->searchObjectives($query, $tenant);
        $vulnerabilities = $this->searchVulnerabilities($query, $tenant);
        $patches = $this->searchPatches($query, $tenant);
        $threatIntelligence = $this->searchThreatIntelligence($query, $tenant);
        $persons = $this->searchPersons($query, $tenant);
        $interestedParties = $this->searchInterestedParties($query, $tenant);
        $consents = $this->searchConsents($query, $tenant);
        $dataSubjectRequests = $this->searchDataSubjectRequests($query, $tenant);
        $complianceFrameworks = $this->searchComplianceFrameworks($query);
        $complianceRequirements = $this->searchComplianceRequirements($query);

        $total = count($navigation)
            + count($assets) + count($risks) + count($controls)
            + count($incidents) + count($trainings) + count($documents)
            + count($suppliers) + count($processingActivities) + count($dpias)
            + count($dataBreaches) + count($auditFindings) + count($correctiveActions)
            + count($changeRequests) + count($internalAudits) + count($businessProcesses)
            + count($bcPlans) + count($bcExercises) + count($crisisTeams)
            + count($managementReviews) + count($objectives) + count($vulnerabilities)
            + count($patches) + count($threatIntelligence) + count($persons)
            + count($interestedParties) + count($consents) + count($dataSubjectRequests)
            + count($complianceFrameworks) + count($complianceRequirements);

        return [
            'total' => $total,
            'navigation' => $navigation,
            'assets' => $assets,
            'risks' => $risks,
            'controls' => $controls,
            'incidents' => $incidents,
            'trainings' => $trainings,
            'documents' => $documents,
            'suppliers' => $suppliers,
            'processing_activities' => $processingActivities,
            'dpias' => $dpias,
            'data_breaches' => $dataBreaches,
            'audit_findings' => $auditFindings,
            'corrective_actions' => $correctiveActions,
            'change_requests' => $changeRequests,
            'internal_audits' => $internalAudits,
            'business_processes' => $businessProcesses,
            'bc_plans' => $bcPlans,
            'bc_exercises' => $bcExercises,
            'crisis_teams' => $crisisTeams,
            'management_reviews' => $managementReviews,
            'objectives' => $objectives,
            'vulnerabilities' => $vulnerabilities,
            'patches' => $patches,
            'threat_intelligence' => $threatIntelligence,
            'persons' => $persons,
            'interested_parties' => $interestedParties,
            'consents' => $consents,
            'data_subject_requests' => $dataSubjectRequests,
            'compliance_frameworks' => $complianceFrameworks,
            'compliance_requirements' => $complianceRequirements,
            'query' => $query,
        ];
    }

    /**
     * Search navigation targets (settings, admin pages, dashboards).
     * Role- and module-filtered; see {@see NavigationSearch}.
     */
    public function searchNavigation(string $query): array
    {
        return $this->navigationSearch->search($query);
    }

    public function searchAssets(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('assets')) {
            return [];
        }

        $qb = $this->assetRepository->createQueryBuilder('a')
            ->where('a.name LIKE :query OR a.description LIKE :query OR a.owner LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('a.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Asset $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_asset_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-assets',
            'type' => 'asset',
            'badge' => $e->getAssetType(),
        ], $qb->getQuery()->getResult());
    }

    public function searchRisks(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('risks')) {
            return [];
        }

        $qb = $this->riskRepository->createQueryBuilder('r')
            ->where('r.title LIKE :query OR r.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('r.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(function (Risk $e): array {
            $level = $e->getInherentRiskLevel();
            $badge = $level >= 15 ? 'Hoch' : ($level >= 9 ? 'Mittel' : 'Niedrig');

            return [
                'id' => $e->getId(),
                'title' => $e->getTitle(),
                'description' => $this->truncate($e->getDescription(), 100),
                'url' => $this->urlGenerator->generate('app_risk_show', ['id' => $e->getId()]),
                'icon' => 'fa-icon--nav-shield-alert',
                'type' => 'risk',
                'badge' => $badge,
            ];
        }, $qb->getQuery()->getResult());
    }

    public function searchControls(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('controls')) {
            return [];
        }

        $qb = $this->controlRepository->createQueryBuilder('c')
            ->where('c.controlId LIKE :query OR c.name LIKE :query OR c.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('c.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Control $e): array => [
            'id' => $e->getId(),
            'title' => $e->getControlId() . ' - ' . $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_soa_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-shield-check',
            'type' => 'control',
            'badge' => $e->getImplementationStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchIncidents(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('incidents')) {
            return [];
        }

        $qb = $this->incidentRepository->createQueryBuilder('i')
            ->where('i.title LIKE :query OR i.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('i.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Incident $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_incident_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--incident',
            'type' => 'incident',
            'badge' => $e->getSeverity()?->value,
        ], $qb->getQuery()->getResult());
    }

    public function searchTrainings(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('training')) {
            return [];
        }

        $qb = $this->trainingRepository->createQueryBuilder('t')
            ->where('t.title LIKE :query OR t.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('t.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Training $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_training_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-mortarboard',
            'type' => 'training',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchDocuments(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('documents')) {
            return [];
        }

        $qb = $this->documentRepository->createQueryBuilder('d')
            ->where('d.originalFilename LIKE :query OR d.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('d.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Document $e): array => [
            'id' => $e->getId(),
            'title' => $e->getOriginalFilename() ?? $e->getFilename(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_document_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-document',
            'type' => 'document',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchSuppliers(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('suppliers')) {
            return [];
        }

        $qb = $this->supplierRepository->createQueryBuilder('s')
            ->where('s.name LIKE :query OR s.description LIKE :query OR s.serviceProvided LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('s.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Supplier $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_supplier_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-truck',
            'type' => 'supplier',
            'badge' => $e->getCriticality(),
        ], $qb->getQuery()->getResult());
    }

    public function searchProcessingActivities(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->processingActivityRepository->createQueryBuilder('pa')
            ->where('pa.name LIKE :query OR pa.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('pa.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(ProcessingActivity $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_processing_activity_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-shield-lock',
            'type' => 'processing_activity',
            'badge' => null,
        ], $qb->getQuery()->getResult());
    }

    public function searchDpias(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->dpiaRepository->createQueryBuilder('dp')
            ->where('dp.title LIKE :query OR dp.processingDescription LIKE :query OR dp.processingPurposes LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('dp.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(DataProtectionImpactAssessment $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getProcessingDescription(), 100),
            'url' => $this->urlGenerator->generate('app_dpia_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-shield-lock',
            'type' => 'dpia',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchDataBreaches(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->dataBreachRepository->createQueryBuilder('db')
            ->where('db.title LIKE :query OR db.breachNature LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('db.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(DataBreach $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle() ?? $e->getReferenceNumber(),
            'description' => $this->truncate($e->getBreachNature(), 100),
            'url' => $this->urlGenerator->generate('app_data_breach_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-breach-octagon',
            'type' => 'data_breach',
            'badge' => $e->getSeverity(),
        ], $qb->getQuery()->getResult());
    }

    public function searchAuditFindings(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('audits')) {
            return [];
        }

        $qb = $this->auditFindingRepository->createQueryBuilder('af')
            ->where('af.title LIKE :query OR af.description LIKE :query OR af.clauseReference LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('af.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(AuditFinding $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_audit_finding_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-clipboard-check',
            'type' => 'audit_finding',
            'badge' => $e->getSeverity(),
        ], $qb->getQuery()->getResult());
    }

    public function searchCorrectiveActions(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('corrective_actions')) {
            return [];
        }

        $qb = $this->correctiveActionRepository->createQueryBuilder('ca')
            ->where('ca.title LIKE :query OR ca.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('ca.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(CorrectiveAction $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_corrective_action_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--corrective-action',
            'type' => 'corrective_action',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchChangeRequests(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('change_requests')) {
            return [];
        }

        $qb = $this->changeRequestRepository->createQueryBuilder('cr')
            ->where('cr.title LIKE :query OR cr.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('cr.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(ChangeRequest $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_change_request_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-change',
            'type' => 'change_request',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchInternalAudits(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('audits')) {
            return [];
        }

        $qb = $this->internalAuditRepository->createQueryBuilder('ia')
            ->where('ia.title LIKE :query OR ia.scope LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('ia.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(InternalAudit $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getScope(), 100),
            'url' => $this->urlGenerator->generate('app_audit_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-clipboard-check',
            'type' => 'internal_audit',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchBusinessProcesses(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('bcm')) {
            return [];
        }

        $qb = $this->businessProcessRepository->createQueryBuilder('bp')
            ->where('bp.name LIKE :query OR bp.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('bp.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(BusinessProcess $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_business_process_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-process',
            'type' => 'business_process',
            'badge' => null,
        ], $qb->getQuery()->getResult());
    }

    public function searchBcPlans(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('bcm')) {
            return [];
        }

        $qb = $this->bcPlanRepository->createQueryBuilder('bc')
            ->where('bc.name LIKE :query OR bc.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('bc.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(BusinessContinuityPlan $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_bc_plan_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-flow',
            'type' => 'bc_plan',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchBcExercises(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('bcm')) {
            return [];
        }

        $qb = $this->bcExerciseRepository->createQueryBuilder('bce')
            ->where('bce.name LIKE :query OR bce.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('bce.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(BCExercise $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_bc_exercise_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-flow',
            'type' => 'bc_exercise',
            'badge' => $e->getExerciseType(),
        ], $qb->getQuery()->getResult());
    }

    public function searchCrisisTeams(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('bcm')) {
            return [];
        }

        $qb = $this->crisisTeamRepository->createQueryBuilder('ct')
            ->where('ct.teamName LIKE :query OR ct.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('ct.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(CrisisTeam $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTeamName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_crisis_team_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-team',
            'type' => 'crisis_team',
            'badge' => $e->getTeamType(),
        ], $qb->getQuery()->getResult());
    }

    public function searchManagementReviews(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('reviews')) {
            return [];
        }

        $qb = $this->managementReviewRepository->createQueryBuilder('mr')
            ->where('mr.title LIKE :query OR mr.decisions LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('mr.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(ManagementReview $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDecisions(), 100),
            'url' => $this->urlGenerator->generate('app_management_review_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-clipboard-check',
            'type' => 'management_review',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchObjectives(string $query, ?Tenant $tenant): array
    {
        $qb = $this->ismsObjectiveRepository->createQueryBuilder('o')
            ->where('o.title LIKE :query OR o.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('o.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(ISMSObjective $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_objective_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-bullseye',
            'type' => 'objective',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchVulnerabilities(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('vulnerability_intel')) {
            return [];
        }

        $qb = $this->vulnerabilityRepository->createQueryBuilder('v')
            ->where('v.cveId LIKE :query OR v.title LIKE :query OR v.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('v.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Vulnerability $e): array => [
            'id' => $e->getId(),
            'title' => ($e->getCveId() ? $e->getCveId() . ' – ' : '') . $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_vulnerability_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--bug',
            'type' => 'vulnerability',
            'badge' => $e->getSeverity(),
        ], $qb->getQuery()->getResult());
    }

    public function searchPatches(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('patches')) {
            return [];
        }

        $qb = $this->patchRepository->createQueryBuilder('p')
            ->where('p.title LIKE :query OR p.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('p.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Patch $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_patch_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-patch',
            'type' => 'patch',
            'badge' => $e->getPriority(),
        ], $qb->getQuery()->getResult());
    }

    public function searchThreatIntelligence(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('vulnerability_intel')) {
            return [];
        }

        $qb = $this->threatIntelligenceRepository->createQueryBuilder('ti')
            ->where('ti.title LIKE :query OR ti.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('ti.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(ThreatIntelligence $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_threat_intelligence_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-breach-octagon',
            'type' => 'threat_intelligence',
            'badge' => $e->getSeverity(),
        ], $qb->getQuery()->getResult());
    }

    public function searchPersons(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('persons')) {
            return [];
        }

        $qb = $this->personRepository->createQueryBuilder('pe')
            ->where('pe.fullName LIKE :query OR pe.email LIKE :query OR pe.jobTitle LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('pe.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Person $e): array => [
            'id' => $e->getId(),
            'title' => $e->getFullName(),
            'description' => $this->truncate(($e->getJobTitle() ?? '') . ($e->getEmail() ? ' · ' . $e->getEmail() : ''), 100),
            'url' => $this->urlGenerator->generate('app_person_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-users',
            'type' => 'person',
            'badge' => $e->getPersonType(),
        ], $qb->getQuery()->getResult());
    }

    public function searchInterestedParties(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('interested_parties')) {
            return [];
        }

        $qb = $this->interestedPartyRepository->createQueryBuilder('ip')
            ->where('ip.name LIKE :query OR ip.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('ip.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(InterestedParty $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_interested_party_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-people',
            'type' => 'interested_party',
            'badge' => $e->getPartyType(),
        ], $qb->getQuery()->getResult());
    }

    public function searchConsents(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->consentRepository->createQueryBuilder('co')
            ->where('co.dataSubjectIdentifier LIKE :query OR co.consentText LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('co.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Consent $e): array => [
            'id' => $e->getId(),
            'title' => $e->getDataSubjectIdentifier(),
            'description' => $this->truncate($e->getConsentText(), 100),
            'url' => $this->urlGenerator->generate('app_consent_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-shield-lock',
            'type' => 'consent',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchDataSubjectRequests(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->dataSubjectRequestRepository->createQueryBuilder('dsr')
            ->where('dsr.dataSubjectName LIKE :query OR dsr.description LIKE :query OR dsr.dataSubjectIdentifier LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('dsr.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(DataSubjectRequest $e): array => [
            'id' => $e->getId(),
            'title' => $e->getDataSubjectName() ?? $e->getDataSubjectIdentifier(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_data_subject_request_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-shield-lock',
            'type' => 'data_subject_request',
            'badge' => $e->getRequestType(),
        ], $qb->getQuery()->getResult());
    }

    /**
     * Compliance frameworks are NOT tenant-scoped (shared library).
     */
    public function searchComplianceFrameworks(string $query): array
    {
        if (!$this->moduleConfig->isModuleActive('compliance')) {
            return [];
        }

        $qb = $this->complianceFrameworkRepository->createQueryBuilder('cf')
            ->where('cf.name LIKE :query OR cf.version LIKE :query OR cf.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        return array_map(fn(ComplianceFramework $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName() . ($e->getVersion() ? ' ' . $e->getVersion() : ''),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_compliance_framework_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--compliance-shield',
            'type' => 'compliance_framework',
            'badge' => null,
        ], $qb->getQuery()->getResult());
    }

    /**
     * Compliance requirements are NOT tenant-scoped (shared library).
     */
    public function searchComplianceRequirements(string $query): array
    {
        if (!$this->moduleConfig->isModuleActive('compliance')) {
            return [];
        }

        $qb = $this->complianceRequirementRepository->createQueryBuilder('req')
            ->where('req.requirementId LIKE :query OR req.title LIKE :query OR req.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        return array_map(fn(ComplianceRequirement $e): array => [
            'id' => $e->getId(),
            'title' => ($e->getRequirementId() ? $e->getRequirementId() . ' – ' : '') . $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_compliance_requirement_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--compliance-shield',
            'type' => 'compliance_requirement',
            'badge' => $e->getCategory(),
        ], $qb->getQuery()->getResult());
    }

    private function truncate(?string $text, int $length): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . '…';
    }
}

<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Tenant;
use App\Enum\RiskTreatmentPlanStatus;
use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ConsentRepository;
use App\Repository\ControlRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckRegistry;

/**
 * CoverageCheckService
 *
 * Extracted from ComplianceWizardService (god-class decomposition).
 * Handles all data-driven compliance checks dispatched by ComplianceWizardService::runCheck().
 *
 * Each public method corresponds to a check `type` value in the category arrays
 * (control_coverage, risk_coverage, asset_coverage, incident_process, bcm_coverage,
 * training_coverage, audit_status, supplier_assessment, document_review,
 * treatment_plan, consent_coverage, dsr_coverage, dpia_coverage, policy_wizard).
 *
 * Gap arrays use raw translation keys for `title`, `description`, and `action`.
 * Templates call |trans({}, 'wizard') on these values. Parameterised descriptions
 * additionally carry a `description_params` key.
 */
final class CoverageCheckService
{
    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly RiskRepository $riskRepository,
        private readonly AssetRepository $assetRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly InternalAuditRepository $auditRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly RiskTreatmentPlanRepository $treatmentPlanRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly ConsentRepository $consentRepository,
        private readonly DataSubjectRequestRepository $dataSubjectRequestRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly PolicyWizardCheckRegistry $policyWizardCheckRegistry,
        private readonly ISMSContextRepository $ismsContextRepository,
        private readonly InterestedPartyRepository $interestedPartyRepository,
        private readonly ISMSObjectiveRepository $ismsObjectiveRepository,
        private readonly ManagementReviewRepository $managementReviewRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly VulnerabilityRepository $vulnerabilityRepository,
        private readonly ?ComplianceRequirementRepository $requirementRepository = null,
    ) {
    }

    /**
     * Generic "is this clause addressed by ≥1 tenant record?" check.
     *
     * Backs the formerly-`manual` ISO/GDPR clauses that DO map to a concrete
     * entity (Cl. 4.1/4.3 → ISMSContext, Cl. 4.2 → InterestedParty, Cl. 6.2 →
     * ISMSObjective, Cl. 9.3 → ManagementReview, GDPR Art. 30 → ProcessingActivity,
     * …). Scores 100 once the tenant has the data, so populated tenants stop
     * seeing false "critical gap" rows.
     *
     * Config keys: `entity` (registry key, required), `min_count` (default 1).
     *
     * @param array<string, mixed> $check
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function checkEntityPresence(array $check, ?Tenant $tenant): array
    {
        $entityKey = (string) ($check['entity'] ?? '');
        $threshold = max(1, (int) ($check['min_count'] ?? 1));
        $count = $tenant === null ? 0 : $this->countTenantEntities($entityKey, $tenant);

        $score = match (true) {
            $count >= $threshold => 100.0,
            $count > 0 => 50.0,
            default => 0.0,
        };

        $details = [
            'type' => 'entity_presence',
            'entity' => $entityKey,
            'count' => $count,
            'threshold' => $threshold,
        ];

        $gap = null;
        if ($score < 100.0) {
            $gap = [
                'title' => $check['name'] ?? 'wizard.check.generic',
                'description' => $check['description'] ?? '',
                'priority' => $check['priority'] ?? 'medium',
                'action' => $check['action'] ?? null,
                'route' => $check['route'] ?? null,
            ];
        }

        return ['score' => $score, 'details' => $details, 'gap' => $gap];
    }

    /**
     * Count tenant-scoped rows for an `entity_presence` registry key.
     * Unknown keys return 0 (check then renders as an open gap, fail-safe).
     */
    private function countTenantEntities(string $entityKey, Tenant $tenant): int
    {
        $repository = match ($entityKey) {
            'isms_context' => $this->ismsContextRepository,
            'interested_party' => $this->interestedPartyRepository,
            'isms_objective' => $this->ismsObjectiveRepository,
            'management_review' => $this->managementReviewRepository,
            'processing_activity' => $this->processingActivityRepository,
            'supplier' => $this->supplierRepository,
            'asset' => $this->assetRepository,
            'document' => $this->documentRepository,
            'bc_plan' => $this->bcPlanRepository,
            'vulnerability' => $this->vulnerabilityRepository,
            default => null,
        };

        if ($repository === null) {
            return 0;
        }

        return $repository->count(['tenant' => $tenant]);
    }

    /**
     * Catalogue coverage: how many ComplianceRequirements of the framework
     * are fulfilled by the tenant (via ComplianceRequirementFulfillment).
     *
     * @return array{total: int, covered: int, percent: float}
     */
    public function getCatalogueCoverage(string $frameworkCode, ?Tenant $tenant): array
    {
        if ($this->requirementRepository === null) {
            return ['total' => 0, 'covered' => 0, 'percent' => 0.0];
        }
        $framework = $this->frameworkRepository->findOneBy(['code' => $frameworkCode]);
        if ($framework === null) {
            return ['total' => 0, 'covered' => 0, 'percent' => 0.0];
        }
        // Denominator = the in-scope catalogue. For a BYO/tenant-uploaded
        // framework (e.g. TISAX, where the assessor uploaded their own VDA-ISA
        // workbook) the in-scope controls are the tenant's OWN uploaded rows —
        // NOT the full library skeleton. Counting the skeleton (134) instead of
        // the uploaded scope (~80) understates coverage and shows the client
        // controls they never assessed. For shared-catalogue frameworks
        // (ISO/NIS2/…) there is no per-tenant upload, so the catalogue is all
        // requirements. In both cases exclude non-catalogue rows ('section'
        // headers, 'legacy_unmapped' parked ad-hoc ids).
        $notJunk = "(r.category IS NULL OR r.category NOT IN ('section', 'legacy_unmapped'))";

        $uploadedTotal = $tenant === null ? 0 : (int) $this->requirementRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.framework = :fw')
            ->andWhere('r.uploadTenant = :tenant')
            ->andWhere($notJunk)
            ->setParameter('fw', $framework)
            ->setParameter('tenant', $tenant)
            ->getQuery()->getSingleScalarResult();

        if ($uploadedTotal > 0) {
            $total = $uploadedTotal;
            $coveredQb = $this->fulfillmentRepository->createQueryBuilder('f')
                ->select('COUNT(f.id)')->join('f.requirement', 'r')
                ->where('r.framework = :fw')->andWhere('r.uploadTenant = :tenant')
                ->andWhere('f.tenant = :tenant')->andWhere('f.status IN (:status)')->andWhere($notJunk)
                ->setParameter('fw', $framework)->setParameter('tenant', $tenant)
                ->setParameter('status', ['implemented', 'verified']);
        } else {
            $total = (int) $this->requirementRepository->createQueryBuilder('r')
                ->select('COUNT(r.id)')->where('r.framework = :fw')->andWhere($notJunk)
                ->setParameter('fw', $framework)->getQuery()->getSingleScalarResult();
            if ($total === 0 || $tenant === null) {
                return ['total' => $total, 'covered' => 0, 'percent' => 0.0];
            }
            $coveredQb = $this->fulfillmentRepository->createQueryBuilder('f')
                ->select('COUNT(f.id)')->join('f.requirement', 'r')
                ->where('r.framework = :fw')->andWhere('f.tenant = :tenant')
                ->andWhere('f.status IN (:status)')->andWhere($notJunk)
                ->setParameter('fw', $framework)->setParameter('tenant', $tenant)
                ->setParameter('status', ['implemented', 'verified']);
        }

        $covered = min((int) $coveredQb->getQuery()->getSingleScalarResult(), $total);
        $percent = round(($covered / $total) * 100, 1);
        return ['total' => $total, 'covered' => $covered, 'percent' => $percent];
    }

    /**
     * Get status label from score
     */
    public function getStatusFromScore(float $score): string
    {
        return match (true) {
            $score >= 95 => 'compliant',
            $score >= 75 => 'partial',
            $score >= 50 => 'in_progress',
            $score > 0 => 'needs_work',
            default => 'not_started',
        };
    }

    /**
     * Check control implementation coverage
     */
    public function checkControlCoverage(array $check, ?Tenant $tenant): array
    {
        $controlIds = $check['control_ids'] ?? [];
        $minImplementation = $check['min_implementation'] ?? 100;

        if (empty($controlIds)) {
            // Check all applicable controls
            $controls = $tenant
                ? $this->controlRepository->findApplicableControls($tenant)
                : [];
        } else {
            $controls = $tenant
                ? $this->controlRepository->findByControlIds($tenant, $controlIds)
                : [];
        }

        $totalControls = 0;
        $excludedControls = 0;
        $implementedControls = 0;
        $partialControls = 0;
        $notImplemented = [];

        foreach ($controls as $control) {
            // Controls explicitly marked not-applicable (SoA "Ausschluss") are a
            // documented, justified exclusion — NOT an implementation gap. They
            // must be dropped from both the denominator and the not-implemented
            // list, otherwise a fully-handled SoA (only implemented + excluded)
            // wrongly scores ~50% with phantom "not implemented" gaps.
            if ($control->isApplicable() === false) {
                $excludedControls++;
                continue;
            }

            $totalControls++;
            $status = $control->getImplementationStatus();
            $percentage = $control->getImplementationPercentage() ?? 0;

            if ($status === 'implemented' || $percentage >= $minImplementation) {
                $implementedControls++;
            } elseif ($status === 'in_progress' || $percentage > 0) {
                $partialControls++;
            } else {
                $notImplemented[] = [
                    'id' => $control->getControlId(),
                    'name' => $control->getName(),
                ];
            }
        }

        // No applicable controls: if everything was excluded (Ausschluss) the
        // scope is fully handled (100%); only a genuinely empty control set is 0.
        $score = $totalControls > 0
            ? round((($implementedControls + ($partialControls * 0.5)) / $totalControls) * 100, 1)
            : ($excludedControls > 0 ? 100.0 : 0);

        $gap = null;
        if ($score < 100) {
            $gap = [
                'title' => 'wizard.gap.controls_not_implemented',
                'description' => 'wizard.gap.controls_description',
                'description_params' => ['%count%' => count($notImplemented)],
                'priority' => count($notImplemented) > 5 ? 'critical' : 'high',
                'action' => 'wizard.action.implement_controls',
                'route' => 'app_soa_index',
                'items' => array_slice($notImplemented, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalControls,
                'implemented' => $implementedControls,
                'partial' => $partialControls,
                'not_implemented' => count($notImplemented),
                'excluded' => $excludedControls,
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check risk management coverage
     */
    public function checkRiskCoverage(array $check, ?Tenant $tenant): array
    {
        $risks = $tenant
            ? $this->riskRepository->findByTenant($tenant)
            : $this->riskRepository->findAll();

        $totalRisks = count($risks);
        $treatedRisks = 0;
        $highUntreated = [];

        foreach ($risks as $risk) {
            $treatment = $risk->getTreatmentStrategy();
            if ($treatment !== null) {
                $treatedRisks++;
            } else {
                $riskLevel = $risk->getInherentRiskLevel();
                if ($riskLevel >= 12) { // High risk threshold
                    $highUntreated[] = [
                        'id' => $risk->getId(),
                        'title' => $risk->getTitle(),
                        'level' => $riskLevel,
                    ];
                }
            }
        }

        $score = $totalRisks > 0
            ? round(($treatedRisks / $totalRisks) * 100, 1)
            : 100; // No risks = 100% (not applicable)

        $gap = null;
        if (!empty($highUntreated)) {
            $gap = [
                'title' => 'wizard.gap.high_risks_untreated',
                'description' => 'wizard.gap.high_risks_description',
                'description_params' => ['%count%' => count($highUntreated)],
                'priority' => 'critical',
                'action' => 'wizard.action.treat_risks',
                'route' => 'app_risk_index',
                'items' => array_slice($highUntreated, 0, 5),
            ];
        } elseif ($score < 100) {
            $gap = [
                'title' => 'wizard.gap.risks_need_treatment',
                'description' => 'wizard.gap.risks_treatment_description',
                'description_params' => ['%treated%' => $treatedRisks, '%total%' => $totalRisks],
                'priority' => 'medium',
                'action' => 'wizard.action.review_risks',
                'route' => 'app_risk_index',
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalRisks,
                'treated' => $treatedRisks,
                'high_untreated' => count($highUntreated),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check asset inventory coverage
     */
    public function checkAssetCoverage(array $check, ?Tenant $tenant): array
    {
        $assets = $tenant
            ? $this->assetRepository->findByTenant($tenant)
            : $this->assetRepository->findAll();

        $totalAssets = count($assets);
        $classifiedAssets = 0;
        $criticalAssets = 0;
        $unclassified = [];

        foreach ($assets as $asset) {
            $cia = $asset->getConfidentialityValue() + $asset->getIntegrityValue() + $asset->getAvailabilityValue();

            if ($cia > 0) {
                $classifiedAssets++;
                if ($asset->getConfidentialityValue() >= 4 || $asset->getAvailabilityValue() >= 4) {
                    $criticalAssets++;
                }
            } else {
                $unclassified[] = [
                    'id' => $asset->getId(),
                    'name' => $asset->getName(),
                ];
            }
        }

        $score = $totalAssets > 0
            ? round(($classifiedAssets / $totalAssets) * 100, 1)
            : 0;

        $gap = null;
        if (!empty($unclassified)) {
            $gap = [
                'title' => 'wizard.gap.assets_not_classified',
                'description' => 'wizard.gap.assets_classification_description',
                'description_params' => ['%count%' => count($unclassified)],
                'priority' => count($unclassified) > 10 ? 'high' : 'medium',
                'action' => 'wizard.action.classify_assets',
                'route' => 'app_asset_index',
                'items' => array_slice($unclassified, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalAssets,
                'classified' => $classifiedAssets,
                'critical' => $criticalAssets,
                'unclassified' => count($unclassified),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check incident management process
     */
    public function checkIncidentProcess(array $check, ?Tenant $tenant): array
    {
        $incidents = $tenant
            ? $this->incidentRepository->findByTenant($tenant)
            : $this->incidentRepository->findAll();

        $totalIncidents = count($incidents);
        $resolvedInTime = 0;
        $overdue = [];

        // Check SLA compliance for incident handling
        $slaHours = $check['sla_hours'] ?? 72;

        foreach ($incidents as $incident) {
            $detectedAt = $incident->getDetectedAt();
            $resolvedAt = $incident->getResolvedAt();

            if ($resolvedAt && $detectedAt) {
                $diff = $detectedAt->diff($resolvedAt);
                $hours = ($diff->days * 24) + $diff->h;

                if ($hours <= $slaHours) {
                    $resolvedInTime++;
                }
            } elseif (!$resolvedAt && $detectedAt) {
                // Check if currently overdue
                $now = new \DateTime();
                $diff = $detectedAt->diff($now);
                $hours = ($diff->days * 24) + $diff->h;

                if ($hours > $slaHours) {
                    $overdue[] = [
                        'id' => $incident->getId(),
                        'title' => $incident->getTitle(),
                        'hours_overdue' => $hours - $slaHours,
                    ];
                }
            }
        }

        // Score based on SLA compliance and no overdue incidents
        $score = $totalIncidents > 0
            ? round((($resolvedInTime / max($totalIncidents, 1)) * 100) - (count($overdue) * 5), 1)
            : 100;

        $score = max(0, min(100, $score));

        $gap = null;
        if (!empty($overdue)) {
            $gap = [
                'title' => 'wizard.gap.incidents_overdue',
                'description' => 'wizard.gap.incidents_overdue_description',
                'description_params' => ['%count%' => count($overdue), '%sla%' => $slaHours],
                'priority' => 'critical',
                'action' => 'wizard.action.resolve_incidents',
                'route' => 'app_incident_index',
                'items' => array_slice($overdue, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalIncidents,
                'resolved_in_time' => $resolvedInTime,
                'overdue' => count($overdue),
                'sla_hours' => $slaHours,
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check BCM coverage (Business Continuity Management)
     */
    public function checkBcmCoverage(array $check, ?Tenant $tenant): array
    {
        $processes = $tenant
            ? $this->businessProcessRepository->findByTenant($tenant)
            : $this->businessProcessRepository->findAll();

        $totalProcesses = count($processes);
        $withBia = 0;
        $withBcPlan = 0;
        $testedPlans = 0;
        $criticalWithoutPlan = [];

        // Get all BC plans to check which processes have plans
        $allBcPlans = $this->bcPlanRepository->findAll();
        $processPlansMap = [];
        foreach ($allBcPlans as $plan) {
            $bp = $plan->getBusinessProcess();
            if ($bp !== null) {
                $processPlansMap[$bp->getId()][] = $plan;
            }
        }

        foreach ($processes as $process) {
            // Check if BIA is completed
            if ($process->getRto() !== null && $process->getRpo() !== null) {
                $withBia++;
            }

            // Check if BC plan exists (via our map)
            $bcPlans = $processPlansMap[$process->getId()] ?? [];
            if (count($bcPlans) > 0) {
                $withBcPlan++;

                // Check if plan was tested
                foreach ($bcPlans as $plan) {
                    if ($plan->getLastTested() !== null) {
                        $testedPlans++;
                        break;
                    }
                }
            } elseif ($process->getCriticality() === 'critical' || $process->getCriticality() === 'high') {
                $criticalWithoutPlan[] = [
                    'id' => $process->getId(),
                    'name' => $process->getName(),
                    'criticality' => $process->getCriticality(),
                ];
            }
        }

        $biaCoverage = $totalProcesses > 0 ? ($withBia / $totalProcesses) * 100 : 0;
        $planCoverage = $totalProcesses > 0 ? ($withBcPlan / $totalProcesses) * 100 : 0;
        $testCoverage = $withBcPlan > 0 ? ($testedPlans / $withBcPlan) * 100 : 0;

        // Weighted score: BIA 30%, Plans 40%, Testing 30%
        $score = round(($biaCoverage * 0.3) + ($planCoverage * 0.4) + ($testCoverage * 0.3), 1);

        $gap = null;
        if (!empty($criticalWithoutPlan)) {
            $gap = [
                'title' => 'wizard.gap.critical_no_bcplan',
                'description' => 'wizard.gap.critical_bcplan_description',
                'description_params' => ['%count%' => count($criticalWithoutPlan)],
                'priority' => 'critical',
                'action' => 'wizard.action.create_bcplan',
                'route' => 'app_bcm_index',
                'items' => array_slice($criticalWithoutPlan, 0, 5),
            ];
        } elseif ($score < 80) {
            $gap = [
                'title' => 'wizard.gap.bcm_incomplete',
                'description' => 'wizard.gap.bcm_incomplete_description',
                'priority' => 'high',
                'action' => 'wizard.action.complete_bcm',
                'route' => 'app_bcm_index',
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total_processes' => $totalProcesses,
                'with_bia' => $withBia,
                'with_bc_plan' => $withBcPlan,
                'tested_plans' => $testedPlans,
                'critical_without_plan' => count($criticalWithoutPlan),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check training coverage
     */
    public function checkTrainingCoverage(array $check, ?Tenant $tenant): array
    {
        $trainings = $tenant
            ? $this->trainingRepository->findByTenant($tenant)
            : $this->trainingRepository->findAll();

        $totalTrainings = count($trainings);
        $completed = 0;
        $overdue = [];

        foreach ($trainings as $training) {
            $status = $training->getStatus();
            if ($status === 'completed') {
                $completed++;
            } elseif ($status === 'overdue' || ($training->getScheduledDate() && $training->getScheduledDate() < new \DateTime())) {
                $overdue[] = [
                    'id' => $training->getId(),
                    'name' => $training->getTitle(),
                ];
            }
        }

        $score = $totalTrainings > 0
            ? round(($completed / $totalTrainings) * 100, 1)
            : 100;

        $gap = null;
        if (!empty($overdue)) {
            $gap = [
                'title' => 'wizard.gap.trainings_overdue',
                'description' => 'wizard.gap.trainings_overdue_description',
                'description_params' => ['%count%' => count($overdue)],
                'priority' => 'high',
                'action' => 'wizard.action.complete_trainings',
                'route' => 'app_training_index',
                'items' => array_slice($overdue, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalTrainings,
                'completed' => $completed,
                'overdue' => count($overdue),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check audit status
     */
    public function checkAuditStatus(array $check, ?Tenant $tenant): array
    {
        $audits = $tenant
            ? $this->auditRepository->findByTenant($tenant)
            : $this->auditRepository->findAll();

        $totalAudits = count($audits);
        $completed = 0;
        $findingsClosed = 0;
        $totalFindings = 0;
        $openCriticalFindings = [];

        foreach ($audits as $audit) {
            if ($audit->getStatus() === 'completed') {
                $completed++;
            }

            $findings = $audit->getFindings() ?? [];
            $totalFindings += count($findings);

            foreach ($findings as $finding) {
                if ($finding['status'] ?? '' === 'closed') {
                    $findingsClosed++;
                } elseif (($finding['severity'] ?? '') === 'critical') {
                    $openCriticalFindings[] = [
                        'audit_id' => $audit->getId(),
                        'finding' => $finding['title'] ?? 'Finding',
                    ];
                }
            }
        }

        $completionRate = $totalAudits > 0 ? ($completed / $totalAudits) * 100 : 100;
        $findingsClosureRate = $totalFindings > 0 ? ($findingsClosed / $totalFindings) * 100 : 100;

        $score = round(($completionRate * 0.5) + ($findingsClosureRate * 0.5), 1);

        $gap = null;
        if (!empty($openCriticalFindings)) {
            $gap = [
                'title' => 'wizard.gap.critical_findings_open',
                'description' => 'wizard.gap.critical_findings_description',
                'description_params' => ['%count%' => count($openCriticalFindings)],
                'priority' => 'critical',
                'action' => 'wizard.action.close_findings',
                'route' => 'app_audit_index',
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total_audits' => $totalAudits,
                'completed' => $completed,
                'total_findings' => $totalFindings,
                'findings_closed' => $findingsClosed,
                'critical_open' => count($openCriticalFindings),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check supplier/third-party assessment (DORA requirement)
     */
    public function checkSupplierAssessment(array $check, ?Tenant $tenant): array
    {
        $suppliers = $tenant
            ? $this->supplierRepository->findByTenant($tenant)
            : $this->supplierRepository->findAll();

        $totalSuppliers = count($suppliers);
        $assessed = 0;
        $criticalUnassessed = [];

        foreach ($suppliers as $supplier) {
            // Check if supplier has been assessed (has security assessment date or score)
            $hasAssessment = $supplier->getLastSecurityAssessment() !== null || $supplier->getSecurityScore() !== null;
            $criticality = $supplier->getCriticality() ?? 'low';

            if ($hasAssessment) {
                $assessed++;
            } elseif ($criticality === 'critical' || $criticality === 'high') {
                $criticalUnassessed[] = [
                    'id' => $supplier->getId(),
                    'name' => $supplier->getName(),
                    'criticality' => $criticality,
                ];
            }
        }

        $score = $totalSuppliers > 0
            ? round(($assessed / $totalSuppliers) * 100, 1)
            : 100;

        $gap = null;
        if (!empty($criticalUnassessed)) {
            $gap = [
                'title' => 'wizard.gap.suppliers_not_assessed',
                'description' => 'wizard.gap.suppliers_assessment_description',
                'description_params' => ['%count%' => count($criticalUnassessed)],
                'priority' => 'critical',
                'action' => 'wizard.action.assess_suppliers',
                'route' => 'app_supplier_index',
                'items' => array_slice($criticalUnassessed, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalSuppliers,
                'assessed' => $assessed,
                'critical_unassessed' => count($criticalUnassessed),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Check document review status
     */
    public function checkDocumentReview(array $check, ?Tenant $tenant): array
    {
        // Placeholder for document review - implement based on Document entity
        return [
            'score' => 100,
            'details' => ['type' => 'not_implemented'],
            'gap' => null,
        ];
    }

    /**
     * Check treatment plan status
     */
    public function checkTreatmentPlanStatus(array $check, ?Tenant $tenant): array
    {
        $plans = $tenant
            ? $this->treatmentPlanRepository->findByTenant($tenant)
            : $this->treatmentPlanRepository->findAll();

        $totalPlans = count($plans);
        $completed = 0;
        $overdue = [];

        foreach ($plans as $plan) {
            if ($plan->getStatus() === RiskTreatmentPlanStatus::Completed->value) {
                $completed++;
            } elseif ($plan->getTargetCompletionDate() && $plan->getTargetCompletionDate() < new \DateTime()) {
                $overdue[] = [
                    'id' => $plan->getId(),
                    'title' => $plan->getTitle(),
                    'due_date' => $plan->getTargetCompletionDate()->format('Y-m-d'),
                ];
            }
        }

        $score = $totalPlans > 0
            ? round(($completed / $totalPlans) * 100, 1)
            : 100;

        $gap = null;
        if (!empty($overdue)) {
            $gap = [
                'title' => 'wizard.gap.treatment_plans_overdue',
                'description' => 'wizard.gap.treatment_plans_description',
                'description_params' => ['%count%' => count($overdue)],
                'priority' => 'high',
                'action' => 'wizard.action.complete_treatment',
                'route' => 'app_risk_treatment_plan_index',
                'items' => array_slice($overdue, 0, 5),
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'total' => $totalPlans,
                'completed' => $completed,
                'overdue' => count($overdue),
            ],
            'gap' => $gap,
        ];
    }

    /**
     * Counts active vs. revoked consents (GDPR Art. 6/7 + ISO 27701 7.2.4).
     * Score = % of consents that are not revoked, rounded to 1 decimal.
     *
     * Semantic divergence from sibling checks like checkRiskCoverage which
     * return score=100 when no entities exist: for consent-tracking we
     * treat "zero consents" as a gap because GDPR Art. 6/7 requires
     * demonstrable consent records for any tenant processing personal data
     * on consent legal basis. Tenants on other legal bases (contract,
     * legitimate interest) can ignore this wizard category — the gap is
     * informational, not a hard fail.
     */
    public function checkConsentCoverage(array $check, ?Tenant $tenant): array
    {
        // Without a tenant context there is no meaningful scope to assess consent
        // coverage — returning a cross-tenant aggregate would be both misleading
        // and non-deterministic (stale records from other tenants / test runs).
        // Return score=0 with a gap so callers always get the expected shape.
        if ($tenant === null) {
            return [
                'score' => 0,
                'details' => ['total' => 0, 'active' => 0],
                'gap' => [
                    'title' => 'wizard.gap.no_consents',
                    'description' => 'wizard.gap.no_consents_desc',
                    'priority' => 'high',
                    'route' => $check['route'] ?? 'app_consent_index',
                ],
            ];
        }

        $qbTotal = $this->consentRepository->createQueryBuilder('c')->select('COUNT(c.id)')
            ->where('c.tenant = :t')->setParameter('t', $tenant);
        $qbActive = $this->consentRepository->createQueryBuilder('c')->select('COUNT(c.id)')
            ->where('c.isRevoked = :revoked')->setParameter('revoked', false)
            ->andWhere('c.tenant = :t')->setParameter('t', $tenant);

        $total = (int) $qbTotal->getQuery()->getSingleScalarResult();
        $active = (int) $qbActive->getQuery()->getSingleScalarResult();

        if ($total === 0) {
            return [
                'score' => 0,
                'details' => ['total' => 0, 'active' => 0],
                'gap' => [
                    'title' => 'wizard.gap.no_consents',
                    'description' => 'wizard.gap.no_consents_desc',
                    'priority' => 'high',
                    'route' => $check['route'] ?? 'app_consent_index',
                ],
            ];
        }

        $score = round(($active / $total) * 100, 1);

        $gap = null;
        if ($score < 90) {
            $gap = [
                'title' => 'wizard.gap.consent_revoked_high',
                'description' => 'wizard.gap.consent_revoked_high_desc',
                'priority' => 'medium',
                'route' => $check['route'] ?? 'app_consent_index',
            ];
        }

        return [
            'score' => $score,
            'details' => ['total' => $total, 'active' => $active],
            'gap' => $gap,
        ];
    }

    /**
     * Counts open vs. completed Data-Subject Requests (GDPR Art. 12-22).
     * Score = % of requests in terminal status (completed or rejected),
     * rounded to 1 decimal. When zero requests exist, score=100 + an
     * advisory gap is emitted because GDPR doesn't require requests but
     * the absence of any DSR records suggests the process is untested.
     */
    public function checkDsrCoverage(array $check, ?Tenant $tenant): array
    {
        $qbTotal = $this->dataSubjectRequestRepository->createQueryBuilder('d')->select('COUNT(d.id)');
        $qbDone = $this->dataSubjectRequestRepository->createQueryBuilder('d')->select('COUNT(d.id)')
            ->where('d.status IN (:done)')->setParameter('done', ['completed', 'rejected']);
        if ($tenant !== null) {
            $qbTotal->andWhere('d.tenant = :t')->setParameter('t', $tenant);
            $qbDone->andWhere('d.tenant = :t')->setParameter('t', $tenant);
        }

        $total = (int) $qbTotal->getQuery()->getSingleScalarResult();
        $done = (int) $qbDone->getQuery()->getSingleScalarResult();

        if ($total === 0) {
            return [
                'score' => 100,
                'details' => ['total' => 0, 'completed' => 0, 'message' => 'no_requests_yet'],
                'gap' => [
                    'title' => 'wizard.gap.no_dsr',
                    'description' => 'wizard.gap.no_dsr_desc',
                    'priority' => 'medium',
                    'route' => $check['route'] ?? 'app_data_subject_request_index',
                ],
            ];
        }

        $score = round(($done / $total) * 100, 1);

        $gap = null;
        if ($score < 90) {
            $gap = [
                'title' => 'wizard.gap.dsr_open_high',
                'description' => 'wizard.gap.dsr_open_high_desc',
                'priority' => 'high',
                'route' => $check['route'] ?? 'app_data_subject_request_index',
            ];
        }

        return [
            'score' => $score,
            'details' => ['total' => $total, 'completed' => $done],
            'gap' => $gap,
        ];
    }

    /**
     * Of all ProcessingActivity rows flagged isHighRisk=true (= DPIA required
     * per Art. 35 DSGVO), how many have at least one linked Risk row whose
     * requiresDPIA flag is also true? Lower bound: 0 (no DPIA performed).
     * Upper bound: 100 (every high-risk activity has a documented DPIA risk).
     * When no high-risk activities exist, score=100 (vacuously true).
     */
    public function checkDpiaCoverage(array $check, ?Tenant $tenant): array
    {
        $qbHighRisk = $this->processingActivityRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.isHighRisk = :hr')->setParameter('hr', true);
        if ($tenant !== null) {
            $qbHighRisk->andWhere('p.tenant = :t')->setParameter('t', $tenant);
        }
        $highRisk = (int) $qbHighRisk->getQuery()->getSingleScalarResult();

        if ($highRisk === 0) {
            return [
                'score' => 100,
                'details' => ['high_risk_activities' => 0, 'documented_dpias' => 0],
                'gap' => null,
            ];
        }

        $qbDpia = $this->riskRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.requiresDPIA = :rq')->setParameter('rq', true);
        if ($tenant !== null) {
            $qbDpia->andWhere('r.tenant = :t')->setParameter('t', $tenant);
        }
        $documented = (int) $qbDpia->getQuery()->getSingleScalarResult();

        // Cap at 100 — a tenant might over-document (more DPIA-risks than
        // high-risk activities); we don't want a score >100.
        $score = round(min(100, ($documented / $highRisk) * 100), 1);

        $gap = null;
        if ($score < 100) {
            $gap = [
                'title' => 'wizard.gap.dpia_missing',
                'description' => 'wizard.gap.dpia_missing_desc',
                'priority' => 'critical',
                'route' => $check['route'] ?? 'app_processing_activity_index',
            ];
        }

        return [
            'score' => $score,
            'details' => ['high_risk_activities' => $highRisk, 'documented_dpias' => $documented],
            'gap' => $gap,
        ];
    }

    /**
     * Dispatch a `policy_wizard` check-type entry through the
     * {@see PolicyWizardCheckRegistry}.
     *
     * Reads the `check_id` (e.g. `policy_top_level_present`,
     * `policy_topic_access_control_present`) from the category-row, resolves
     * the matching {@see PolicyWizardCheckInterface} implementation and
     * adapts the {@see PolicyWizardCheckResult} into the legacy
     * `runCheck()` array shape (`score`, `details`, `gap`).
     *
     * Unknown / missing check ids fail closed (score=0, gap surfaced) so
     * mis-wired category rows degrade gracefully rather than raising.
     *
     * @param array<string, mixed> $check
     * @return array{score: float|int, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function dispatchPolicyWizardCheck(array $check, ?Tenant $tenant): array
    {
        $checkId = (string) ($check['check_id'] ?? '');
        if ($checkId === '') {
            return [
                'score' => 0,
                'details' => ['error' => 'missing_check_id'],
                'gap' => [
                    'title' => $check['name'] ?? 'Policy-Wizard check misconfigured',
                    'description' => 'check_id missing in category row',
                    'priority' => 'high',
                ],
            ];
        }

        $impl = $this->policyWizardCheckRegistry->get($checkId);
        if ($impl === null) {
            return [
                'score' => 0,
                'details' => ['error' => 'unknown_check_id', 'check_id' => $checkId],
                'gap' => [
                    'title' => $check['name'] ?? sprintf('Unknown Policy-Wizard check: %s', $checkId),
                    'description' => sprintf('No PolicyWizardCheckInterface implementation registered for "%s".', $checkId),
                    'priority' => $check['priority'] ?? 'high',
                    'route' => $check['route'] ?? null,
                ],
            ];
        }

        $result = $impl->run($tenant);

        return [
            'score' => $result->score,
            'details' => $result->details,
            'gap' => $result->gap,
        ];
    }
}

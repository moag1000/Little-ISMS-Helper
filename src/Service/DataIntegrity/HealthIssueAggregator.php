<?php

declare(strict_types=1);

namespace App\Service\DataIntegrity;

use App\Repository\AssetRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\DataBreachRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use App\Repository\WorkflowInstanceRepository;

/**
 * Aggregates Tier 1–3 health checks across risk, compliance, operational, and data-quality domains.
 *
 * Extracted from DataIntegrityService to isolate health-issue detection concerns.
 *
 * @see \App\Service\DataIntegrityService::findRiskHealthIssues()
 * @see \App\Service\DataIntegrityService::findComplianceHealthIssues()
 * @see \App\Service\DataIntegrityService::findOperationalHealthIssues()
 * @see \App\Service\DataIntegrityService::findDataQualityIssues()
 */
final class HealthIssueAggregator
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly AssetRepository $assetRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly ?DataSubjectRequestRepository $dataSubjectRequestRepository = null,
        private readonly ?DataProtectionImpactAssessmentRepository $dpiaRepository = null,
        private readonly ?AuditFindingRepository $auditFindingRepository = null,
        private readonly ?CorrectiveActionRepository $correctiveActionRepository = null,
        private readonly ?ManagementReviewRepository $managementReviewRepository = null,
        private readonly ?WorkflowInstanceRepository $workflowInstanceRepository = null,
        private readonly ?RiskTreatmentPlanRepository $riskTreatmentPlanRepository = null,
    ) {
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
                ->setParameter('excludedStatuses', [\App\Enum\RiskStatus::Closed->value])
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
}

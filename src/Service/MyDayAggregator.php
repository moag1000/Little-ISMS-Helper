<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditChecklist;
use App\Entity\AuditFinding;
use App\Entity\ChangeRequest;
use App\Entity\CorrectiveAction;
use App\Entity\DataBreach;
use App\Entity\DataSubjectRequest;
use App\Entity\Document;
use App\Entity\FourEyesApprovalRequest;
use App\Entity\Incident;
use App\Entity\ManagementReview;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\Entity\Vulnerability;
use App\Entity\WizardSession;
use App\Entity\WorkflowInstance;
use App\Repository\AuditChecklistRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\ChangeRequestRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\DataBreachRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Repository\IncidentRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\RiskRepository;
use App\Repository\TrainingParticipationRepository;
use App\Repository\VulnerabilityRepository;
use App\Repository\WizardSessionRepository;
use App\Repository\WorkflowInstanceRepository;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Audit V3 C1 — Mein-Tag (Central Inbox) Aggregator.
 *
 * Aggregates open items for the current user across the distributed inboxes.
 * Original 7 buckets (Audit V3):
 *   - workflow/pending + workflow/overdue
 *   - four_eyes/inbox
 *   - policy_acknowledgement/inbox
 *   - audit_finding (assigned-to-me)
 *   - data_subject_request (assigned-to-me)
 *   - corrective_action overdue
 *
 * Audit V4 V4-LB-1 Round-1 (ISB-Practitioner) — added 3 ISB-Pflicht-Buckets:
 *   - risk_acceptance_expiring  (Risk.acceptanceExpiryDate ≤ today+30d)
 *   - documents_review_overdue  (Document.nextReviewDate  < today)
 *   - trainings_pending         (TrainingParticipation.status = pending for me)
 *
 * Audit V4 V4-LB-1 Round-2 — added 6 ISB-Tagesgeschäft-Buckets covering
 * incident handling, GDPR breach notification, vulnerability management,
 * audit programme execution, change governance, and management-review
 * cadence:
 *   - incidents_open_assigned          (Incident.status open, assigned-to-me)
 *   - data_breaches_72h_ticking        (DataBreach detected within 72h, no
 *                                        authority notification yet — GDPR Art. 33)
 *   - vulnerabilities_critical_unpatched (Vulnerability severity high|critical,
 *                                        status not closed/accepted/patched —
 *                                        NIS2 Art. 21 (2) (e))
 *   - audit_checklist_due              (AuditChecklist verifiedAt IS NULL,
 *                                        plannedDate < today+7d, auditor=me —
 *                                        ISO 27001 Clause 9.2)
 *   - change_requests_pending_approval (ChangeRequest status=submitted/under_review
 *                                        — ISO 27001 Clause 6.3, surfaced to
 *                                        Manager/Admin/CAB)
 *   - management_review_upcoming       (ManagementReview status=planned,
 *                                        reviewDate ≤ today+90d — ISO 27001
 *                                        Clause 9.3 cadence)
 *
 * Total: 16 buckets (7 V3 + 3 V4 R1 + 6 V4 R2).
 *
 * (Notification-bell bucket dropped — no Notification entity exists yet;
 *  WorkflowInstance pending already covers the "things I started" side.)
 *
 * Returns an associative result with categorised buckets and per-item
 * dictionaries shaped for the Aurora UI:
 *   { priority: high|medium|low, due_date: ?DateTimeImmutable, link: string,
 *     entity_type: string, tone: success|warning|danger|info, title: string,
 *     subtitle: string, badge: ?string }
 */
class MyDayAggregator
{
    /** Days-window for Risk-acceptance-expiry surfacing (Audit V4 V4-LB-1). */
    private const RISK_ACCEPTANCE_WARN_DAYS = 30;

    /** Audit-checklist `plannedDate` look-ahead window (V4 R2). */
    private const AUDIT_CHECKLIST_WARN_DAYS = 7;

    /** Management-review look-ahead window — ISO 27001 §9.3 cadence (V4 R2). */
    private const MGMT_REVIEW_WARN_DAYS = 90;

    /** Incident-priority look-ahead window for "due-soon" badge (V4 R2). */
    private const INCIDENT_PRIORITY_DAYS = 7;

    /** V4-EF-7: Wizard stall window in days before surfaced as "overdue" to CM. */
    private const WIZARD_OVERDUE_DAYS = 90;

    /** V4-EF-7: Framework coverage threshold below which a gap is "critical". */
    private const FRAMEWORK_GAP_CRITICAL_PCT = 60.0;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WorkflowInstanceRepository $workflowInstances,
        private readonly FourEyesApprovalRequestRepository $fourEyesRepo,
        private readonly PolicyAcknowledgementRepository $policyAckRepo,
        private readonly AuditFindingRepository $auditFindingRepo,
        private readonly DataSubjectRequestRepository $dsrRepo,
        private readonly CorrectiveActionRepository $caRepo,
        private readonly DocumentRepository $documentRepo,
        private readonly RiskRepository $riskRepo,
        private readonly TrainingParticipationRepository $trainingParticipationRepo,
        private readonly IncidentRepository $incidentRepo,
        private readonly DataBreachRepository $dataBreachRepo,
        private readonly VulnerabilityRepository $vulnerabilityRepo,
        private readonly AuditChecklistRepository $auditChecklistRepo,
        private readonly ChangeRequestRepository $changeRequestRepo,
        private readonly ManagementReviewRepository $managementReviewRepo,
        private readonly WizardSessionRepository $wizardSessionRepo,
        private readonly ComplianceAnalyticsService $complianceAnalytics,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Aggregate all inbox items for the user. Returns categorised buckets.
     *
     * @return array{
     *   summary: array<string, int>,
     *   workflows_pending: array<int, array<string, mixed>>,
     *   workflows_overdue: array<int, array<string, mixed>>,
     *   four_eyes: array<int, array<string, mixed>>,
     *   acknowledgements: array<int, array<string, mixed>>,
     *   findings: array<int, array<string, mixed>>,
     *   dsrs: array<int, array<string, mixed>>,
     *   corrective_actions_overdue: array<int, array<string, mixed>>,
     *   risk_acceptance_expiring: array<int, array<string, mixed>>,
     *   documents_review_overdue: array<int, array<string, mixed>>,
     *   trainings_pending: array<int, array<string, mixed>>,
     *   incidents_open_assigned: array<int, array<string, mixed>>,
     *   data_breaches_72h_ticking: array<int, array<string, mixed>>,
     *   vulnerabilities_critical_unpatched: array<int, array<string, mixed>>,
     *   audit_checklist_due: array<int, array<string, mixed>>,
     *   change_requests_pending_approval: array<int, array<string, mixed>>,
     *   management_review_upcoming: array<int, array<string, mixed>>,
     *   documents_pending_approval_for_me: array<int, array<string, mixed>>,
     *   wizard_overdue: array<int, array<string, mixed>>,
     *   framework_gaps_critical: array<int, array<string, mixed>>,
     *   total: int
     * }
     */
    public function aggregate(User $user): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $today = new DateTimeImmutable();

        // Audit V3 W2-C2: tenant-scope all aggregator queries to prevent
        // Cross-Tenant-Leakage. Without an active tenant we surface nothing
        // — better empty than mixed-tenant.
        $workflowsPending = $tenant ? $this->buildWorkflowsPending($user, $tenant) : [];
        $workflowsOverdue = $tenant ? $this->buildWorkflowsOverdue($user, $tenant) : [];
        $fourEyes        = $tenant ? $this->buildFourEyes($user, $tenant) : [];
        $acks            = $tenant ? $this->buildAcknowledgements($user, $tenant) : [];
        $findings        = $tenant ? $this->buildFindings($user, $tenant) : [];
        $dsrs            = $tenant ? $this->buildDsrs($user, $tenant) : [];
        $caOverdue       = $tenant ? $this->buildCorrectiveActionsOverdue($user, $tenant) : [];
        // Audit V4 V4-LB-1 Round-1 — ISB-Pflicht-Buckets.
        $riskAcceptance  = $tenant ? $this->buildRiskAcceptanceExpiring($user, $tenant) : [];
        $docsReviewOver  = $tenant ? $this->buildDocumentsReviewOverdue($user, $tenant) : [];
        $trainingsPending = $tenant ? $this->buildTrainingsPending($user, $tenant) : [];
        // Audit V4 V4-LB-1 Round-2 — ISB-Tagesgeschäft-Buckets.
        $incidentsAssigned = $tenant ? $this->buildIncidentsOpenAssigned($user, $tenant) : [];
        $breaches72h       = $tenant ? $this->buildDataBreaches72hTicking($user, $tenant) : [];
        $vulnsCritical     = $tenant ? $this->buildVulnerabilitiesCriticalUnpatched($user, $tenant) : [];
        $checklistDue      = $tenant ? $this->buildAuditChecklistDue($user, $tenant) : [];
        $changesPending    = $tenant ? $this->buildChangeRequestsPendingApproval($user, $tenant) : [];
        $reviewsUpcoming   = $tenant ? $this->buildManagementReviewUpcoming($user, $tenant) : [];
        // V4-EF-7 — Compliance-Manager CM-Buckets (visibility-gated to ROLE_COMPLIANCE_MANAGER).
        $docsPendingApproval = ($tenant && $this->isComplianceManager($user))
            ? $this->buildDocumentsPendingApproval($user, $tenant)
            : [];
        $wizardOverdue       = ($tenant && $this->isComplianceManager($user))
            ? $this->buildWizardOverdue($user, $tenant)
            : [];
        $frameworkGaps       = ($tenant && $this->isComplianceManager($user))
            ? $this->buildFrameworkGapsCritical($user)
            : [];

        $total = count($workflowsPending) + count($workflowsOverdue)
            + count($fourEyes) + count($acks) + count($findings)
            + count($dsrs) + count($caOverdue)
            + count($riskAcceptance) + count($docsReviewOver) + count($trainingsPending)
            + count($incidentsAssigned) + count($breaches72h) + count($vulnsCritical)
            + count($checklistDue) + count($changesPending) + count($reviewsUpcoming)
            + count($docsPendingApproval) + count($wizardOverdue) + count($frameworkGaps);

        return [
            'summary' => [
                'workflows_pending' => count($workflowsPending),
                'workflows_overdue' => count($workflowsOverdue),
                'four_eyes'         => count($fourEyes),
                'acknowledgements'  => count($acks),
                'findings'          => count($findings),
                'dsrs'              => count($dsrs),
                'corrective_actions_overdue' => count($caOverdue),
                'risk_acceptance_expiring' => count($riskAcceptance),
                'documents_review_overdue' => count($docsReviewOver),
                'trainings_pending' => count($trainingsPending),
                'incidents_open_assigned' => count($incidentsAssigned),
                'data_breaches_72h_ticking' => count($breaches72h),
                'vulnerabilities_critical_unpatched' => count($vulnsCritical),
                'audit_checklist_due' => count($checklistDue),
                'change_requests_pending_approval' => count($changesPending),
                'management_review_upcoming' => count($reviewsUpcoming),
                'documents_pending_approval_for_me' => count($docsPendingApproval),
                'wizard_overdue' => count($wizardOverdue),
                'framework_gaps_critical' => count($frameworkGaps),
            ],
            'workflows_pending' => $workflowsPending,
            'workflows_overdue' => $workflowsOverdue,
            'four_eyes'         => $fourEyes,
            'acknowledgements'  => $acks,
            'findings'          => $findings,
            'dsrs'              => $dsrs,
            'corrective_actions_overdue' => $caOverdue,
            'risk_acceptance_expiring' => $riskAcceptance,
            'documents_review_overdue' => $docsReviewOver,
            'trainings_pending' => $trainingsPending,
            'incidents_open_assigned' => $incidentsAssigned,
            'data_breaches_72h_ticking' => $breaches72h,
            'vulnerabilities_critical_unpatched' => $vulnsCritical,
            'audit_checklist_due' => $checklistDue,
            'change_requests_pending_approval' => $changesPending,
            'management_review_upcoming' => $reviewsUpcoming,
            'documents_pending_approval_for_me' => $docsPendingApproval,
            'wizard_overdue'    => $wizardOverdue,
            'framework_gaps_critical' => $frameworkGaps,
            'total'             => $total,
            'generated_at'      => $today,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildWorkflowsPending(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->workflowInstances->findPendingForUser($user, $tenant) as $instance) {
            /** @var WorkflowInstance $instance */
            $items[] = $this->mapWorkflow($instance, false);
        }
        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildWorkflowsOverdue(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->workflowInstances->findOverdueForTenant($tenant) as $instance) {
            /** @var WorkflowInstance $instance */
            // Filter overdue to those visible to this user (initiated or pending approver).
            if ($this->isWorkflowRelevant($instance, $user)) {
                $items[] = $this->mapWorkflow($instance, true);
            }
        }
        return $items;
    }

    private function isWorkflowRelevant(WorkflowInstance $instance, User $user): bool
    {
        if ($instance->getInitiatedBy() && $instance->getInitiatedBy()->getId() === $user->getId()) {
            return true;
        }
        // Loose match: pending workflows touched by user already returned via findPendingForUser
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapWorkflow(WorkflowInstance $instance, bool $isOverdue): array
    {
        $entityType = $instance->getEntityType() !== null
            ? (string) preg_replace('#^.*\\\\#', '', $instance->getEntityType())
            : 'Workflow';

        $title = sprintf(
            '%s #%s — %s',
            $entityType,
            $instance->getEntityId() ?? '?',
            $instance->getWorkflow()?->getName() ?? '—',
        );

        return [
            'priority'    => $isOverdue ? 'high' : 'medium',
            'due_date'    => $instance->getDueDate(),
            'link'        => $this->urls->generate('app_workflow_instance_show', ['id' => $instance->getId()]),
            'entity_type' => 'workflow',
            'tone'        => $isOverdue ? 'danger' : 'warning',
            'title'       => $title,
            'subtitle'    => $instance->getCurrentStep()?->getName() ?? '—',
            'badge'       => $isOverdue ? 'overdue' : 'pending',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFourEyes(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->fourEyesRepo->findPendingFor($user, $tenant) as $req) {
            /** @var FourEyesApprovalRequest $req */
            $items[] = [
                'priority'    => 'medium',
                'due_date'    => method_exists($req, 'getRequestedAt') ? $req->getRequestedAt() : null,
                'link'        => $this->urls->generate('app_four_eyes_inbox'),
                'entity_type' => 'four_eyes',
                'tone'        => 'warning',
                'title'       => sprintf('%s — %s',
                    method_exists($req, 'getOperation') ? ($req->getOperation() ?? '4-Eyes') : '4-Eyes',
                    method_exists($req, 'getEntityType') ? ($req->getEntityType() ?? '') : ''),
                'subtitle'    => $req->getRequestedBy()
                    ? trim(($req->getRequestedBy()->getFirstName() ?? '') . ' ' . ($req->getRequestedBy()->getLastName() ?? ''))
                    : '—',
                'badge'       => 'pending',
            ];
        }
        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildAcknowledgements(User $user, Tenant $tenant): array
    {
        // Find approved Documents requiring ack that user has NOT acknowledged.
        $items = [];
        $allApproved = $this->documentRepo->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'approved')
            ->getQuery()
            ->getResult();

        foreach ($allApproved as $document) {
            /** @var Document $document */
            // Only documents that require ack (best-effort field check)
            if (method_exists($document, 'getRequiresAcknowledgement')
                && !$document->getRequiresAcknowledgement()) {
                continue;
            }
            // Check if ack exists for this user + version. Audit V3 W2-C4:
            // PENDING rows (created by Auto-Acknowledgement-Campaign) keep
            // the document on the user's My-Day list — only ACKNOWLEDGED
            // rows close the item.
            $version = method_exists($document, 'getVersion') ? (string) ($document->getVersion() ?? '') : '';
            $existing = $this->policyAckRepo->findOneFor($tenant, $document, $user, $version);
            if ($existing instanceof PolicyAcknowledgement
                && $existing->getStatus() === PolicyAcknowledgement::STATUS_ACKNOWLEDGED) {
                continue;
            }

            $items[] = [
                'priority'    => 'medium',
                'due_date'    => null,
                'link'        => $this->urls->generate('app_policy_ack_inbox'),
                'entity_type' => 'acknowledgement',
                'tone'        => 'info',
                'title'       => method_exists($document, 'getTitle') ? (string) $document->getTitle() : 'Document',
                'subtitle'    => $version !== '' ? 'v' . $version : '—',
                'badge'       => 'awaiting_ack',
            ];
        }
        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFindings(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->auditFindingRepo->findOpenByTenant($tenant) as $finding) {
            /** @var AuditFinding $finding */
            // Filter: assigned-to-me (best-effort via responsible person)
            if (!$this->findingAssignedToUser($finding, $user)) {
                continue;
            }
            $severity = method_exists($finding, 'getSeverity') ? $finding->getSeverity() : 'medium';
            $tone = match ($severity) {
                'critical', 'high' => 'danger',
                'medium' => 'warning',
                default => 'info',
            };
            $items[] = [
                'priority'    => in_array($severity, ['critical', 'high'], true) ? 'high' : 'medium',
                'due_date'    => method_exists($finding, 'getDueDate') ? $finding->getDueDate() : null,
                'link'        => $this->urls->generate('app_audit_finding_show', ['id' => $finding->getId()]),
                'entity_type' => 'audit_finding',
                'tone'        => $tone,
                'title'       => (string) $finding->getTitle(),
                'subtitle'    => sprintf('%s · %s', $finding->getType() ?? '—', $severity ?? '—'),
                'badge'       => $finding->getStatus(),
            ];
        }
        return $items;
    }

    private function findingAssignedToUser(AuditFinding $finding, User $user): bool
    {
        if (method_exists($finding, 'getResponsiblePersonUser')) {
            $resp = $finding->getResponsiblePersonUser();
            if ($resp instanceof User && $resp->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDsrs(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->dsrRepo->findByTenant($tenant) as $dsr) {
            /** @var DataSubjectRequest $dsr */
            if (!$this->dsrAssignedToUser($dsr, $user)) {
                continue;
            }
            $status = method_exists($dsr, 'getStatus') ? $dsr->getStatus() : null;
            if (in_array($status, ['fulfilled', 'rejected', 'closed'], true)) {
                continue;
            }
            $items[] = [
                'priority'    => 'high',
                'due_date'    => method_exists($dsr, 'getDeadline') ? $dsr->getDeadline() : null,
                'link'        => $this->urls->generate('app_data_subject_request_show', ['id' => $dsr->getId()]),
                'entity_type' => 'dsr',
                'tone'        => 'danger',
                'title'       => sprintf('DSR #%d — %s', $dsr->getId(), method_exists($dsr, 'getRequestType') ? ($dsr->getRequestType() ?? '') : ''),
                'subtitle'    => method_exists($dsr, 'getDataSubjectName') ? ($dsr->getDataSubjectName() ?? '') : '',
                'badge'       => $status,
            ];
        }
        return $items;
    }

    private function dsrAssignedToUser(DataSubjectRequest $dsr, User $user): bool
    {
        // Audit V3 W2-C2 DSGVO-Fix: DSRs contain personally-identifiable
        // data of the data subject (Art. 4 (1) DSGVO). Surface them ONLY
        // to the explicitly assigned handler — no See-All-Fallback for
        // generic ROLE_MANAGER. The DPO/Compliance-Manager-Persona has
        // a dedicated dashboard via ROLE_DPO (W2-C5); seeing every open
        // DSR via My-Day would breach Art. 5 (1) (c) (Datenminimierung).
        if (method_exists($dsr, 'getAssignedTo')) {
            $u = $dsr->getAssignedTo();
            if ($u instanceof User && $u->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCorrectiveActionsOverdue(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->caRepo->findOverdue($tenant) as $ca) {
            /** @var CorrectiveAction $ca */
            if (!$this->caAssignedToUser($ca, $user)) {
                continue;
            }
            $items[] = [
                'priority'    => 'high',
                'due_date'    => method_exists($ca, 'getPlannedCompletionDate') ? $ca->getPlannedCompletionDate() : null,
                'link'        => $this->urls->generate('app_corrective_action_show', ['id' => $ca->getId()]),
                'entity_type' => 'corrective_action',
                'tone'        => 'danger',
                'title'       => (string) $ca->getTitle(),
                'subtitle'    => 'overdue',
                'badge'       => $ca->getStatus(),
            ];
        }
        return $items;
    }

    private function caAssignedToUser(CorrectiveAction $ca, User $user): bool
    {
        if (method_exists($ca, 'getResponsiblePersonUser')) {
            $resp = $ca->getResponsiblePersonUser();
            if ($resp instanceof User && $resp->getId() === $user->getId()) {
                return true;
            }
        }
        if (in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_MANAGER', $user->getRoles(), true)) {
            return true;
        }
        return false;
    }

    /**
     * Audit V4 V4-LB-1 — Risks whose formal acceptance expires within
     * RISK_ACCEPTANCE_WARN_DAYS (or already has). The ISB has to renew
     * acceptance OR pivot to a different treatment-strategy. Surfaces to
     * the risk owner; CISO/Manager (ROLE_ADMIN/ROLE_MANAGER) sees them all
     * because acceptance review is a governance-level duty.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRiskAcceptanceExpiring(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->riskRepo->findAcceptanceExpiring($tenant, self::RISK_ACCEPTANCE_WARN_DAYS) as $risk) {
            /** @var Risk $risk */
            if (!$this->riskAcceptanceVisibleToUser($risk, $user)) {
                continue;
            }
            $expiry = $risk->getAcceptanceExpiryDate();
            $isExpired = $expiry !== null && $expiry < new DateTimeImmutable('today');
            $items[] = [
                'priority'    => $isExpired ? 'high' : 'medium',
                'due_date'    => $expiry,
                'link'        => $this->urls->generate('app_risk_show', ['id' => $risk->getId()]),
                'entity_type' => 'risk_acceptance',
                'tone'        => $isExpired ? 'danger' : 'warning',
                'title'       => (string) $risk->getTitle(),
                'subtitle'    => $isExpired ? 'expired' : 'expiring',
                'badge'       => $isExpired ? 'expired' : 'expiring',
            ];
        }
        return $items;
    }

    private function riskAcceptanceVisibleToUser(Risk $risk, User $user): bool
    {
        $owner = $risk->getRiskOwner();
        if ($owner instanceof User && $owner->getId() === $user->getId()) {
            return true;
        }
        // CISO / ISB-level governance roles see every accepted risk near
        // expiry — that's the whole point of the ISB-Pflicht-Bucket.
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)
            || in_array('ROLE_MANAGER', $roles, true)
            || in_array('ROLE_GROUP_CISO', $roles, true)) {
            return true;
        }
        return false;
    }

    /**
     * Audit V4 V4-LB-1 — Approved Documents whose review-cycle is overdue.
     * ISO 27001 Clause 7.5.3 requires periodic re-validation of documented
     * information. Surfaces to the document-owner; falls back to ROLE_ADMIN
     * / ROLE_MANAGER (governance-wide responsibility).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDocumentsReviewOverdue(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->documentRepo->findReviewOverdue($tenant) as $document) {
            /** @var Document $document */
            if (!$this->documentReviewVisibleToUser($document, $user)) {
                continue;
            }
            $title = method_exists($document, 'getTitle')
                ? (string) ($document->getTitle() ?? '')
                : (string) ($document->getOriginalFilename() ?? $document->getFilename() ?? 'Document');
            $items[] = [
                'priority'    => 'high',
                'due_date'    => $document->getNextReviewDate(),
                'link'        => $this->urls->generate('app_document_show', ['id' => $document->getId()]),
                'entity_type' => 'document_review',
                'tone'        => 'danger',
                'title'       => $title,
                'subtitle'    => 'review_overdue',
                'badge'       => 'overdue',
            ];
        }
        return $items;
    }

    private function documentReviewVisibleToUser(Document $document, User $user): bool
    {
        if (method_exists($document, 'getOwner')) {
            $owner = $document->getOwner();
            if ($owner instanceof User && $owner->getId() === $user->getId()) {
                return true;
            }
        }
        if (method_exists($document, 'getUploadedBy')) {
            $uploader = $document->getUploadedBy();
            if ($uploader instanceof User && $uploader->getId() === $user->getId()) {
                return true;
            }
        }
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_MANAGER', $roles, true)) {
            return true;
        }
        return false;
    }

    /**
     * Audit V4 V4-LB-1 — TrainingParticipations with status=pending for the
     * current user. Strict per-user filter (no governance fallback): a
     * pending training is the responsibility of the assignee, not of the
     * Manager.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTrainingsPending(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->trainingParticipationRepo->findPendingForUser($user, $tenant) as $participation) {
            /** @var TrainingParticipation $participation */
            $training = $participation->getTraining();
            if ($training === null) {
                continue;
            }
            $title = method_exists($training, 'getTitle')
                ? (string) ($training->getTitle() ?? '')
                : 'Training';
            $items[] = [
                'priority'    => 'medium',
                'due_date'    => $participation->getAssignedAt(),
                'link'        => $this->urls->generate('app_training_show', ['id' => $training->getId()]),
                'entity_type' => 'training',
                'tone'        => 'info',
                'title'       => $title,
                'subtitle'    => 'pending',
                'badge'       => 'pending',
            ];
        }
        return $items;
    }

    /**
     * Audit V4 V4-LB-1 Round-2 — Open security incidents currently assigned
     * to the user (or reported by user). ISO 27035-1 §6.3 — ongoing incident
     * monitoring is the operational duty of the assignee. Governance roles
     * (Admin/Manager/CISO) see ALL open incidents because incident oversight
     * is part of their job description.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildIncidentsOpenAssigned(User $user, Tenant $tenant): array
    {
        $items = [];

        if ($this->incidentVisibleToGovernanceRole($user)) {
            $incidents = $this->incidentRepo->findOpenIncidents($tenant);
        } else {
            $incidents = $this->incidentRepo->findOpenAssignedToUser($user, $tenant);
        }

        foreach ($incidents as $incident) {
            /** @var Incident $incident */
            $severity = $incident->getSeverity();
            $severityValue = $severity?->value ?? 'medium';
            $tone = match ($severityValue) {
                'critical', 'high' => 'danger',
                'medium' => 'warning',
                default => 'info',
            };
            $items[] = [
                'priority'    => in_array($severityValue, ['critical', 'high'], true) ? 'high' : 'medium',
                'due_date'    => $incident->getDetectedAt(),
                'link'        => $this->urls->generate('app_incident_show', ['id' => $incident->getId()]),
                'entity_type' => 'incident',
                'tone'        => $tone,
                'title'       => sprintf(
                    '%s — %s',
                    (string) ($incident->getIncidentNumber() ?? '#?'),
                    (string) ($incident->getTitle() ?? '—'),
                ),
                'subtitle'    => sprintf('%s · %s', $incident->getCategory() ?? '—', $severityValue),
                'badge'       => $incident->getStatus()?->value,
            ];
        }
        return $items;
    }

    private function incidentVisibleToGovernanceRole(User $user): bool
    {
        $roles = $user->getRoles();
        return in_array('ROLE_ADMIN', $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $roles, true)
            || in_array('ROLE_MANAGER', $roles, true)
            || in_array('ROLE_GROUP_CISO', $roles, true);
    }

    /**
     * Audit V4 V4-LB-1 Round-2 — Data breaches whose GDPR Art. 33 72h
     * authority-notification clock is still ticking. Surfaces ONLY to
     * DPO / Manager / Admin — not every employee should see breach details
     * (DSGVO Art. 5 (1) (c) Datenminimierung). Already-overdue breaches
     * fall into the existing alerting via `findAuthorityNotificationOverdue`
     * and are NOT duplicated here to keep the inbox actionable.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDataBreaches72hTicking(User $user, Tenant $tenant): array
    {
        if (!$this->dataBreachVisibleToUser($user)) {
            return [];
        }

        $items = [];
        foreach ($this->dataBreachRepo->findAuthorityNotification72hTicking($tenant) as $breach) {
            /** @var DataBreach $breach */
            $detectedAt = $breach->getDetectedAt();
            $deadline = $detectedAt instanceof \DateTimeInterface
                ? DateTimeImmutable::createFromInterface($detectedAt)->modify('+72 hours')
                : null;
            $items[] = [
                'priority'    => 'high',
                'due_date'    => $deadline,
                'link'        => $this->urls->generate('app_data_breach_show', ['id' => $breach->getId()]),
                'entity_type' => 'data_breach',
                'tone'        => 'danger',
                'title'       => sprintf(
                    '%s — %s',
                    (string) ($breach->getReferenceNumber() ?? '#?'),
                    (string) ($breach->getTitle() ?? '—'),
                ),
                'subtitle'    => sprintf('GDPR Art. 33 · %s', (string) ($breach->getSeverity() ?? '—')),
                'badge'       => '72h',
            ];
        }
        return $items;
    }

    private function dataBreachVisibleToUser(User $user): bool
    {
        $roles = $user->getRoles();
        return in_array('ROLE_ADMIN', $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $roles, true)
            || in_array('ROLE_MANAGER', $roles, true)
            || in_array('ROLE_DPO', $roles, true)
            || in_array('ROLE_GROUP_CISO', $roles, true);
    }

    /**
     * Audit V4 V4-LB-1 Round-2 — Critical / high vulnerabilities still
     * open (NIS2 Art. 21 (2) (e)). Visible to ISB/CISO/Manager and the
     * named `responsiblePerson` (string-match best-effort).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildVulnerabilitiesCriticalUnpatched(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->vulnerabilityRepo->findCriticalUnpatchedByTenant($tenant) as $vuln) {
            /** @var Vulnerability $vuln */
            if (!$this->vulnerabilityVisibleToUser($vuln, $user)) {
                continue;
            }
            $severity = $vuln->getSeverity() ?? 'medium';
            $items[] = [
                'priority'    => 'high',
                'due_date'    => $vuln->getRemediationDeadline(),
                'link'        => $this->urls->generate('app_vulnerability_show', ['id' => $vuln->getId()]),
                'entity_type' => 'vulnerability',
                'tone'        => $severity === 'critical' ? 'danger' : 'warning',
                'title'       => sprintf(
                    '%s — %s',
                    $vuln->getCveId() !== null ? (string) $vuln->getCveId() : '#' . (string) $vuln->getId(),
                    (string) ($vuln->getTitle() ?? '—'),
                ),
                'subtitle'    => sprintf('CVSS %s · %s', $vuln->getCvssScore() ?? '?', $severity),
                'badge'       => $severity,
            ];
        }
        return $items;
    }

    private function vulnerabilityVisibleToUser(Vulnerability $vuln, User $user): bool
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $roles, true)
            || in_array('ROLE_MANAGER', $roles, true)
            || in_array('ROLE_GROUP_CISO', $roles, true)) {
            return true;
        }
        // Best-effort string-match on responsiblePerson (legacy free-text col).
        $responsible = $vuln->getResponsiblePerson();
        if ($responsible !== null && $responsible !== '') {
            $needle = strtolower($user->getUserIdentifier());
            $email = method_exists($user, 'getEmail') ? strtolower((string) $user->getEmail()) : '';
            $haystack = strtolower($responsible);
            if (($needle !== '' && str_contains($haystack, $needle))
                || ($email !== '' && str_contains($haystack, $email))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Audit V4 V4-LB-1 Round-2 — Audit-checklist items due within 7 days,
     * not yet verified, for the assigned auditor. ISO 27001 Clause 9.2 —
     * Internal-Audit programme execution. Visible to assigned auditor +
     * audit-coordinator roles (Admin / Manager / Auditor).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildAuditChecklistDue(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->auditChecklistRepo->findDueForUser($user, $tenant, self::AUDIT_CHECKLIST_WARN_DAYS) as $item) {
            /** @var AuditChecklist $item */
            $audit = $item->getAudit();
            $requirement = $item->getRequirement();
            if ($audit === null || $requirement === null) {
                continue;
            }
            $items[] = [
                'priority'    => 'medium',
                'due_date'    => $audit->getPlannedDate(),
                'link'        => $this->urls->generate('app_audit_checklist', ['id' => $audit->getId()]),
                'entity_type' => 'audit_checklist',
                'tone'        => 'info',
                'title'       => sprintf(
                    '%s — %s',
                    (string) ($audit->getTitle() ?? '#' . (string) $audit->getId()),
                    (string) ($requirement->getRequirementId() ?? '—'),
                ),
                'subtitle'    => (string) ($requirement->getTitle() ?? '—'),
                'badge'       => $item->getVerificationStatus(),
            ];
        }
        return $items;
    }

    /**
     * Audit V4 V4-LB-1 Round-2 — Change-requests pending approval. ISO 27001
     * Clause 6.3 (Planning of Changes) requires evidence-trail for every
     * approval. Visible to ROLE_MANAGER / ROLE_ADMIN (CAB-equivalent) — the
     * `approvedBy` column is a free-text auto-fill on approval and cannot
     * route requests, so per-user filtering is impossible without a CAB FK.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildChangeRequestsPendingApproval(User $user, Tenant $tenant): array
    {
        if (!$this->changeApproverVisibleToUser($user)) {
            return [];
        }
        $items = [];
        foreach ($this->changeRequestRepo->findPendingApprovalByTenant($tenant) as $cr) {
            /** @var ChangeRequest $cr */
            $items[] = [
                'priority'    => $cr->getPriority() === 'critical' || $cr->getPriority() === 'high' ? 'high' : 'medium',
                'due_date'    => $cr->getPlannedImplementationDate(),
                'link'        => $this->urls->generate('app_change_request_show', ['id' => $cr->getId()]),
                'entity_type' => 'change_request',
                'tone'        => 'info',
                'title'       => sprintf(
                    '%s — %s',
                    (string) ($cr->getChangeNumber() ?? '#?'),
                    (string) ($cr->getTitle() ?? '—'),
                ),
                'subtitle'    => sprintf('%s · %s', $cr->getChangeType() ?? '—', $cr->getPriority() ?? '—'),
                'badge'       => $cr->getStatus(),
            ];
        }
        return $items;
    }

    private function changeApproverVisibleToUser(User $user): bool
    {
        $roles = $user->getRoles();
        return in_array('ROLE_ADMIN', $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $roles, true)
            || in_array('ROLE_MANAGER', $roles, true)
            || in_array('ROLE_GROUP_CISO', $roles, true);
    }

    /**
     * Audit V4 V4-LB-1 Round-2 — Management reviews scheduled within the
     * next 90 days. ISO 27001 Clause 9.3 mandates planned-interval reviews;
     * the bucket reminds top-management + ISB to prepare inputs in time.
     * Visible to top-management proxies (Admin / Manager / CISO) and the
     * named reviewer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildManagementReviewUpcoming(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->managementReviewRepo->findUpcomingByTenant($tenant, self::MGMT_REVIEW_WARN_DAYS) as $review) {
            /** @var ManagementReview $review */
            if (!$this->managementReviewVisibleToUser($review, $user)) {
                continue;
            }
            $reviewDate = $review->getReviewDate();
            $daysUntil = null;
            if ($reviewDate instanceof \DateTimeInterface) {
                $diff = (new DateTimeImmutable('today'))->diff($reviewDate);
                $daysUntil = (int) $diff->days * ($diff->invert === 1 ? -1 : 1);
            }
            $items[] = [
                'priority'    => $daysUntil !== null && $daysUntil <= 14 ? 'high' : 'medium',
                'due_date'    => $reviewDate,
                'link'        => $this->urls->generate('app_management_review_show', ['id' => $review->getId()]),
                'entity_type' => 'management_review',
                'tone'        => 'success',
                'title'       => (string) ($review->getTitle() ?? '#' . (string) $review->getId()),
                'subtitle'    => $daysUntil !== null
                    ? sprintf('in %d Tagen', $daysUntil)
                    : 'planned',
                'badge'       => $review->getStatus(),
            ];
        }
        return $items;
    }

    private function managementReviewVisibleToUser(ManagementReview $review, User $user): bool
    {
        $reviewedBy = $review->getReviewedBy();
        if ($reviewedBy instanceof User && $reviewedBy->getId() === $user->getId()) {
            return true;
        }
        // Participants list — registered users invited to the review.
        foreach ($review->getParticipants() as $participant) {
            if ($participant instanceof User && $participant->getId() === $user->getId()) {
                return true;
            }
        }
        $roles = $user->getRoles();
        return in_array('ROLE_ADMIN', $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $roles, true)
            || in_array('ROLE_MANAGER', $roles, true)
            || in_array('ROLE_GROUP_CISO', $roles, true);
    }

    // =========================================================================
    //  V4-EF-7 — Compliance-Manager CM-Buckets (visibility-gated)
    // =========================================================================

    /**
     * V4-EF-7 CM-Bucket 1 — Documents in 'in_review' status, awaiting formal
     * approval by a Compliance Manager. ISO 27001 Clause 7.5.2 requires that
     * documented information is reviewed and approved for adequacy and
     * suitability prior to release.
     *
     * Visibility: ROLE_COMPLIANCE_MANAGER only (gated in aggregate()).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDocumentsPendingApproval(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->documentRepo->findPendingApprovalForTenant($tenant) as $document) {
            /** @var Document $document */
            $title = method_exists($document, 'getTitle')
                ? (string) ($document->getTitle() ?? '')
                : (string) ($document->getOriginalFilename() ?? $document->getFilename() ?? 'Document');
            $items[] = [
                'priority'    => 'medium',
                'due_date'    => null,
                'link'        => $this->urls->generate('app_document_show', ['id' => $document->getId()]),
                'entity_type' => 'document_pending_approval',
                'tone'        => 'warning',
                'title'       => $title,
                'subtitle'    => sprintf('v%s · in_review', $document->getVersion() ?? '—'),
                'badge'       => 'in_review',
            ];
        }
        return $items;
    }

    /**
     * V4-EF-7 CM-Bucket 2 — In-progress WizardSessions whose lastActivityAt
     * has not been updated in WIZARD_OVERDUE_DAYS days — i.e. stalled
     * compliance assessments. ISO 27001 Clause 9.1 requires regular
     * monitoring; abandoned assessments are a compliance-programme risk.
     *
     * Visibility: ROLE_COMPLIANCE_MANAGER only (gated in aggregate()).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildWizardOverdue(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->wizardSessionRepo->findOverdueByTenant($tenant, self::WIZARD_OVERDUE_DAYS) as $session) {
            /** @var WizardSession $session */
            $lastActivity = $session->getLastActivityAt();
            $daysSince = null;
            if ($lastActivity !== null) {
                $diff = (new DateTimeImmutable('today'))->diff($lastActivity);
                $daysSince = (int) $diff->days;
            }
            $items[] = [
                'priority'    => 'medium',
                'due_date'    => $lastActivity,
                'link'        => $this->urls->generate('app_compliance_wizard_index'),
                'entity_type' => 'wizard_overdue',
                'tone'        => 'warning',
                'title'       => $session->getWizardName(),
                'subtitle'    => $daysSince !== null
                    ? sprintf('stalled %dd · %s', $daysSince, $session->getUser()?->getUserIdentifier() ?? '—')
                    : 'stalled',
                'badge'       => 'overdue',
            ];
        }
        return $items;
    }

    /**
     * V4-EF-7 CM-Bucket 3 — Active compliance frameworks with coverage below
     * FRAMEWORK_GAP_CRITICAL_PCT (60 %). ISO 27001 Clause 9.1 — evaluation of
     * compliance obligations. A framework below 60 % represents a critical gap
     * that the CM must escalate or plan remediation for.
     *
     * Visibility: ROLE_COMPLIANCE_MANAGER only (gated in aggregate()).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFrameworkGapsCritical(User $user): array
    {
        $items = [];
        foreach ($this->complianceAnalytics->findFrameworkGapsCritical(self::FRAMEWORK_GAP_CRITICAL_PCT) as $f) {
            $items[] = [
                'priority'    => 'high',
                'due_date'    => null,
                'link'        => $this->urls->generate('app_compliance_index'),
                'entity_type' => 'framework_gap',
                'tone'        => 'danger',
                'title'       => sprintf('%s — %s', $f['code'] ?? '?', $f['name'] ?? '—'),
                'subtitle'    => sprintf('%.1f%% compliant', $f['compliance_percentage']),
                'badge'       => $f['mandatory'] ? 'mandatory' : 'optional',
            ];
        }
        return $items;
    }

    /** V4-EF-7: Check whether the user holds ROLE_COMPLIANCE_MANAGER. */
    private function isComplianceManager(User $user): bool
    {
        $roles = $user->getRoles();
        return in_array('ROLE_COMPLIANCE_MANAGER', $roles, true)
            || in_array('ROLE_ADMIN', $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $roles, true);
    }
}

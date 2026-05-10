<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\Document;
use App\Entity\DataSubjectRequest;
use App\Entity\FourEyesApprovalRequest;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Repository\AuditFindingRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\WorkflowInstanceRepository;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Audit V3 C1 — Mein-Tag (Central Inbox) Aggregator.
 *
 * Aggregates open items for the current user across the 7 distributed inboxes:
 *   - workflow/pending + workflow/overdue
 *   - four_eyes/inbox
 *   - policy_acknowledgement/inbox
 *   - audit_finding (assigned-to-me)
 *   - data_subject_request (assigned-to-me)
 *   - notification-bell items (open WorkflowInstances initiated by me)
 *   - corrective_action overdue
 *
 * Returns an associative result with categorised buckets and per-item
 * dictionaries shaped for the Aurora UI:
 *   { priority: high|medium|low, due_date: ?DateTimeImmutable, link: string,
 *     entity_type: string, tone: success|warning|danger|info, title: string,
 *     subtitle: string, badge: ?string }
 */
class MyDayAggregator
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WorkflowInstanceRepository $workflowInstances,
        private readonly FourEyesApprovalRequestRepository $fourEyesRepo,
        private readonly PolicyAcknowledgementRepository $policyAckRepo,
        private readonly AuditFindingRepository $auditFindingRepo,
        private readonly DataSubjectRequestRepository $dsrRepo,
        private readonly CorrectiveActionRepository $caRepo,
        private readonly DocumentRepository $documentRepo,
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

        $total = count($workflowsPending) + count($workflowsOverdue)
            + count($fourEyes) + count($acks) + count($findings)
            + count($dsrs) + count($caOverdue);

        return [
            'summary' => [
                'workflows_pending' => count($workflowsPending),
                'workflows_overdue' => count($workflowsOverdue),
                'four_eyes'         => count($fourEyes),
                'acknowledgements'  => count($acks),
                'findings'          => count($findings),
                'dsrs'              => count($dsrs),
                'corrective_actions_overdue' => count($caOverdue),
            ],
            'workflows_pending' => $workflowsPending,
            'workflows_overdue' => $workflowsOverdue,
            'four_eyes'         => $fourEyes,
            'acknowledgements'  => $acks,
            'findings'          => $findings,
            'dsrs'              => $dsrs,
            'corrective_actions_overdue' => $caOverdue,
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
}

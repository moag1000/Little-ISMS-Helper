<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\FourEyesApprovalRequest;
use App\Entity\ManagementDecision;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardSession;
use App\Entity\WorkflowInstance;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\WizardSessionRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Security\Persona\PersonaResolver;
use App\Service\Deadline\Obligation;
use App\Service\Deadline\ObligationInbox;
use App\Service\PolicyWizard\PolicyAcknowledgementService;
use App\Service\Risk\RiskAcceptanceRequest;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Mein Tag — the one inbox.
 *
 * Every duty with a deadline comes from the deadline sources
 * ({@see ObligationInbox}): each appears exactly once, at the person the
 * {@see \App\Service\Responsibility\ResponsibilityResolver} names (record
 * owner/assignee first, the role only when nobody is set). The bucket of an
 * item is its source's profile bucket, so a new source shows up here without
 * code changes.
 *
 * Built here on top: approvals and four-eyes requests carry their in-place
 * decision payload; policy acknowledgements (documents the user still has to
 * confirm), audit checklist items and the compliance manager's portfolio
 * buckets (documents in review, stalled wizards, critical framework gaps).
 *
 * Item shape for the Aurora UI:
 *   { priority: high|medium|low, due_date: ?DateTimeInterface, link: string,
 *     entity_type: string, tone: success|warning|danger|info, title: string,
 *     subtitle: string, badge: ?string }
 */
final class MyDayAggregator
{
    /** Wizard stall window in days before surfaced as "overdue" to CM. */
    private const WIZARD_OVERDUE_DAYS = 90;

    /** Framework coverage threshold below which a gap is "critical". */
    private const FRAMEWORK_GAP_CRITICAL_PCT = 60.0;

    /** Days before the deadline an item counts as "due soon" (warning tone). */
    private const DUE_SOON_DAYS = 7;

    /** Buckets built here from their own query; the inbox items of these sources are skipped. */
    private const OWN_BUCKETS = ['acknowledgements'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WorkflowInstanceRepository $workflowInstances,
        private readonly PolicyAcknowledgementRepository $policyAckRepo,
        private readonly DocumentRepository $documentRepo,
        private readonly WizardSessionRepository $wizardSessionRepo,
        private readonly ComplianceAnalyticsService $complianceAnalytics,
        private readonly UrlGeneratorInterface $urls,
        private readonly PersonaResolver $personaResolver,
        private readonly ObligationInbox $inbox,
    ) {
    }

    /**
     * Aggregate all inbox items for the user, categorised by bucket.
     *
     * @return array<string, mixed> bucket key => list of items, plus
     *   summary (bucket => count), deadline_buckets (keys from the sources),
     *   total and generated_at
     */
    public function aggregate(User $user): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $today = new DateTimeImmutable();
        // Without an active tenant we surface nothing — better empty than mixed-tenant.
        if ($tenant === null) {
            return ['summary' => [], 'deadline_buckets' => [], 'total' => 0, 'generated_at' => $today];
        }

        $buckets = [];
        foreach ($this->inbox->forUser($user, $tenant) as $obligation) {
            $bucket = $obligation->profile()->bucket;
            if (in_array($bucket, self::OWN_BUCKETS, true)) {
                continue;
            }
            $buckets[$bucket][] = $this->mapObligation($obligation);
        }
        $deadlineBuckets = array_keys($buckets);

        $buckets['workflows_overdue'] = $this->buildWorkflowsOverdue($user, $tenant);
        $buckets['acknowledgements'] = $this->buildAcknowledgements($user, $tenant);
        if ($this->isComplianceManager($user)) {
            $buckets['documents_pending_approval_for_me'] = $this->buildDocumentsPendingApproval($tenant);
            $buckets['wizard_overdue'] = $this->buildWizardOverdue($tenant);
            $buckets['framework_gaps_critical'] = $this->buildFrameworkGapsCritical();
        }

        $summary = array_map('count', $buckets);

        return $buckets + [
            'summary' => $summary,
            'deadline_buckets' => $deadlineBuckets,
            'total' => array_sum($summary),
            'generated_at' => $today,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapObligation(Obligation $obligation): array
    {
        $record = $obligation->record;
        if ($record instanceof WorkflowInstance) {
            return $this->mapWorkflow($record, false); // the approver decides here, overdue or not
        }
        if ($record instanceof FourEyesApprovalRequest) {
            return [
                'priority'    => 'medium',
                'due_date'    => $record->getExpiresAt(),
                'link'        => $this->urls->generate('app_four_eyes_inbox'),
                'entity_type' => 'four_eyes',
                'tone'        => 'warning',
                'title'       => $record->getActionType(), // translated by MyDayInboxEnricher
                'subtitle'    => $record->getRequestedBy()?->getFullName() ?? '—',
                'badge'       => 'pending',
                'four_eyes_id' => $record->getId(),
            ];
        }

        $due = $obligation->dueDate;
        $soon = $due !== null && $due <= new DateTimeImmutable(sprintf('+%d days', self::DUE_SOON_DAYS));
        [$priority, $tone, $badge] = match (true) {
            $obligation->overdue || $obligation->urgent => ['high', 'danger', 'overdue'],
            $soon => ['medium', 'warning', 'due'],
            $due === null => ['medium', 'warning', 'open'],
            default => ['low', 'info', null],
        };
        if ($obligation->urgent && !$obligation->overdue) {
            $badge = 'due';
        }
        $link = $obligation->link();

        return [
            'priority'    => $priority,
            'due_date'    => $due,
            'link'        => $link !== null ? $this->urls->generate($link->route, $link->parameters) : '#',
            'entity_type' => $obligation->source->slug(),
            'tone'        => $tone,
            'title'       => $obligation->title,
            'subtitle'    => $obligation->profile()->normReference ?? $obligation->source->label(),
            'badge'       => $badge,
            'hours_countdown' => $obligation->profile()->urgentHours !== null,
        ] + match (true) {
            // decided right here with the decision dialog (top management)
            $record instanceof ManagementDecision => ['management_decision_id' => $record->getId()],
            // requested risk acceptance: approve/reject dialog (MyDayInboxEnricher keeps it for approvers only)
            $record instanceof RiskAcceptanceRequest => ['acceptance_risk_id' => $record->risk->getId(), 'priority' => 'high'],
            default => [],
        };
    }

    /**
     * Approvals the user started that are past their deadline (the approver
     * sees them under "workflows_pending").
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildWorkflowsOverdue(User $user, Tenant $tenant): array
    {
        $items = [];
        foreach ($this->workflowInstances->findOverdueForTenant($tenant) as $instance) {
            /** @var WorkflowInstance $instance */
            if ($instance->getInitiatedBy()?->getId() === $user->getId()) {
                $items[] = $this->mapWorkflow($instance, true);
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapWorkflow(WorkflowInstance $instance, bool $isOverdue): array
    {
        $entityType = (string) preg_replace('#^.*\\\\#', '', $instance->getEntityType() ?? 'Workflow');
        // MyDayInboxEnricher replaces title/subtitle with the record's name.
        return [
            'priority'    => $isOverdue ? 'high' : 'medium',
            'due_date'    => $instance->getDueDate(),
            'link'        => $this->urls->generate('app_workflow_instance_show', ['id' => $instance->getId()]),
            'entity_type' => 'workflow',
            'tone'        => $isOverdue ? 'danger' : 'warning',
            'title'       => sprintf('%s #%s', $entityType, $instance->getEntityId() ?? '?'),
            'subtitle'    => $instance->getCurrentStep()?->getName() ?? '—',
            'decision'    => ['id' => $instance->getId(), 'name' => $instance->getWorkflow()?->getName() ?? '—',
                'subject_type' => $instance->getEntityType(), 'subject_id' => $instance->getEntityId(), 'decidable' => !$isOverdue],
            'badge'       => $isOverdue ? 'overdue' : 'pending',
        ];
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
            if (!PolicyAcknowledgementService::needsAcknowledgement($document)) {
                continue;
            }
            // Check if ack exists for this user + version. 
            // PENDING rows (created by Auto-Acknowledgement-Campaign) keep
            // the document on the user's My-Day list — only ACKNOWLEDGED
            // rows close the item.
            $version = $document->getVersion();
            $existing = $this->policyAckRepo->findOneFor($tenant, $document, $user, PolicyAcknowledgementService::versionOf($document));
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
                'title'       => $this->documentTitle($document),
                'subtitle'    => $version !== '' ? 'v' . $version : '—',
                'badge'       => 'awaiting_ack',
            ];
        }
        return $items;
    }

    /**
     * Documents have no title column; the uploaded file name is what the
     * list and detail pages show.
     */
    private function documentTitle(Document $document): string
    {
        return (string) ($document->getOriginalFilename() ?? $document->getFilename() ?? 'Document');
    }

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
    private function buildDocumentsPendingApproval(Tenant $tenant): array
    {
        $items = [];
        foreach ($this->documentRepo->findPendingApprovalForTenant($tenant) as $document) {
            /** @var Document $document */
            $title = $this->documentTitle($document);
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
    private function buildWizardOverdue(Tenant $tenant): array
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
    private function buildFrameworkGapsCritical(): array
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
        return $this->personaResolver->reaches($user, 'ROLE_COMPLIANCE_MANAGER');
    }
}

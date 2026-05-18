<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Command\SeedPolicyApprovalWorkflowCommand;
use App\Entity\Document;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Enum\WorkflowInstanceStatus;
use App\Repository\EntityTagRepository;
use App\Repository\UserRepository;
use App\Repository\WorkflowRepository;
use App\Service\AuditLogger;
use App\Service\TenantSettingResolver\TenantSettingResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Policy-Wizard W3-I — ApprovalKickoffService.
 *
 * Closes the production trigger gap "Step 7 generates Documents but
 * never enqueues the approval workflow". For every freshly generated
 * Document the wizard hands a `WorkflowInstance` of the seeded
 * `policy-approval` Workflow into the existing `Workflow*` machinery
 * (see `docs/plans/policy-wizard/05-architecture.md` §9.1).
 *
 * The instance is created in the `prepared` step (auto by wizard) and
 * immediately advanced to `ciso_review`, mirroring §9.1 step 1 → 2.
 *
 * Skip semantics:
 *  - Sandbox runs (`WizardRun.mode='sandbox'`) skip the kickoff
 *    entirely (architecture §6.4 — sandbox = no persistence).
 *  - Tenants without a seeded `policy-approval` workflow get a logger
 *    warning + the kickoff is silently skipped so a fresh deploy that
 *    has not yet run `app:seed-policy-approval-workflow` does not
 *    explode the wizard pipeline. The Document is still emitted; the
 *    operator simply has to re-fire approvals manually until the seed
 *    command runs.
 *  - DORA-supervised tenants get the WorkflowInstance approval-history
 *    annotated with `bulk_approval_dual_signoff=true` plus
 *    `dora_dual_signoff_enforced=true` per §9.2.1 defang #2 (W4-A
 *    Task 4). DORA scope is detected by EntityTag rows
 *    (`standard:dora` / `dora-extension:applied`) emitted by
 *    {@see DocumentGenerator}; the override fires regardless of the
 *    `bulk_approval_dual_signoff` TenantSettingResolver result.
 *
 * Audit-trail: every kickoff writes a `policy-approval` tagged audit
 * entry via {@see PerDocumentAuditLogger::logPerDocApproval} so the
 * external auditor sees the dispatch event per evidence artefact.
 */
final class ApprovalKickoffService
{
    private const string AUDIT_TAG = 'policy-approval';
    private const string TENANT_SETTING_DUAL_SIGNOFF = 'bulk_approval_dual_signoff';

    /**
     * Canonical role-name for the Top-Management persona (Geschäftsführung).
     * Mirrored in {@see \App\Security\Voter\PolicyWizardVoter::canBulkApprove}
     * so the bulk-approval voter and the kickoff-router agree on what
     * "GF user" means for routing + permission decisions.
     */
    private const string ROLE_TOP_MGMT = 'ROLE_TOP_MGMT';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowRepository $workflowRepository,
        private readonly AuditLogger $auditLogger,
        private readonly ?TenantSettingResolver $tenantSettingResolver = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EntityTagRepository $entityTagRepository = null,
        private readonly ?UserRepository $userRepository = null,
    ) {
    }

    /**
     * Dispatch a `policy-approval` WorkflowInstance for the given Document.
     *
     * Returns the persisted WorkflowInstance, or null when the kickoff
     * was intentionally skipped (sandbox / unseeded workflow).
     */
    public function kickoff(Document $document, User $initiator): ?WorkflowInstance
    {
        $run = $document->getGeneratedFromWizardRun();
        if ($run !== null && $run->getMode() === WizardStepKeys::MODE_SANDBOX) {
            // §6.4: sandbox never persists workflow state.
            $this->logger->info('PolicyWizard ApprovalKickoff: sandbox run, skipping workflow dispatch', [
                'document_id' => $document->getId(),
                'wizard_run_id' => $run->getId(),
            ]);
            return null;
        }

        $workflow = $this->workflowRepository->findOneBy([
            'name'       => SeedPolicyApprovalWorkflowCommand::WORKFLOW_NAME,
            'entityType' => SeedPolicyApprovalWorkflowCommand::WORKFLOW_ENTITY_TYPE,
            'isActive'   => true,
        ]);
        if (!$workflow instanceof Workflow) {
            $this->logger->warning(
                'PolicyWizard ApprovalKickoff: policy-approval workflow not seeded yet — '
                . 'run app:seed-policy-approval-workflow. Skipping kickoff.',
                [
                    'document_id' => $document->getId(),
                    'wizard_run_id' => $run?->getId(),
                ],
            );
            return null;
        }

        $instance = new WorkflowInstance();
        $instance->setWorkflow($workflow);
        $instance->setEntityType('Document');
        $instance->setEntityId((int) $document->getId());
        $instance->setInitiatedBy($initiator);
        $instance->setStartedAt(new DateTimeImmutable());
        $instance->setStatus(WorkflowInstanceStatus::InProgress);
        $instance->setTenant($document->getTenant());

        // §9.1: instance enters at `prepared` (auto), then immediately
        // transitions to `ciso_review` since the wizard's emit-event IS
        // the prepared signal.
        $preparedStep = $this->stepByName($workflow, 'prepared');
        $cisoStep = $this->stepByName($workflow, 'ciso_review');
        if ($preparedStep instanceof WorkflowStep) {
            $instance->addCompletedStep((int) $preparedStep->getId());
        }
        if ($cisoStep instanceof WorkflowStep) {
            $instance->setCurrentStep($cisoStep);
        }

        $instance->addApprovalHistoryEntry([
            'event'       => 'kickoff',
            'document_id' => $document->getId(),
            'from_step'   => 'prepared',
            'to_step'     => 'ciso_review',
            'at'          => (new DateTimeImmutable())->format(DATE_ATOM),
            'tag'         => self::AUDIT_TAG,
        ]);

        // User-mandate (2026-05-08): when the tenant has a Top-Management
        // user (ROLE_TOP_MGMT, "Geschäftsführung"), route the approval
        // chain explicitly to that user so a GF-Freigabeflow is emitted.
        // Recorded in approval-history (no per-instance assignedTo column
        // exists on WorkflowInstance) + an explicit audit-event so the
        // dispatch shows up unambiguously in the trail.
        $topMgmtApprover = $this->resolveTopManagementApprover($document->getTenant());
        if ($topMgmtApprover instanceof User) {
            $instance->addApprovalHistoryEntry([
                'event'                 => 'approval_routed_to_top_management',
                'top_management_user_id' => $topMgmtApprover->getId(),
                'document_id'           => $document->getId(),
                'wizard_run_id'         => $run?->getId(),
                'at'                    => (new DateTimeImmutable())->format(DATE_ATOM),
                'tag'                   => self::AUDIT_TAG,
            ]);
        }

        // §9.2.1 defang #2: regulated tenants force dual sign-off ON.
        // W4-A Task 4: DORA-supervised tenants override the resolver
        // result. Detection scans for any persisted Document carrying
        // a `standard:dora` or `dora-extension:applied` tag — both
        // signals are emitted by DocumentGenerator when DORA scope is
        // active. The resolver-based path remains for non-DORA cases.
        $doraScope = $this->tenantHasDoraScope($document->getTenant());
        $dualSignoff = $doraScope || $this->resolveDualSignoff($document->getTenant());
        if ($dualSignoff) {
            $instance->addApprovalHistoryEntry([
                'event'                       => 'dual_signoff_enforced',
                'bulk_approval_dual_signoff'  => true,
                'dora_dual_signoff_enforced'  => $doraScope,
                'reason'                      => $doraScope
                    ? 'tenant.dora_scope'
                    : 'tenant.regulated_scope',
                'tag'                         => self::AUDIT_TAG,
            ]);
        }

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'policy_approval_kickoff',
            entityType: 'Document',
            entityId: $document->getId(),
            oldValues: null,
            newValues: [
                'workflow_id'                 => $workflow->getId(),
                'workflow_instance_id'        => $instance->getId(),
                'initial_step'                => 'prepared',
                'current_step'                => 'ciso_review',
                'wizard_run_id'               => $run?->getId(),
                'initiator_id'                => $initiator->getId(),
                'dual_signoff_required'       => $dualSignoff,
                'dora_dual_signoff_enforced'  => $doraScope,
                'tag'                         => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] Workflow instance #%d dispatched for Document #%d (prepared → ciso_review)',
                self::AUDIT_TAG,
                $instance->getId() ?? 0,
                $document->getId() ?? 0,
            ),
        );

        // User-mandate emission: separate audit-event for the GF-routing
        // so `policy_wizard.approval_routed_to_top_management` lands in
        // the trail alongside the generic kickoff event.
        if ($topMgmtApprover instanceof User) {
            $this->auditLogger->logCustom(
                action: 'policy_wizard.approval_routed_to_top_management',
                entityType: 'Document',
                entityId: $document->getId(),
                oldValues: null,
                newValues: [
                    'user_id'              => $topMgmtApprover->getId(),
                    'document_id'          => $document->getId(),
                    'wizard_run_id'        => $run?->getId(),
                    'workflow_instance_id' => $instance->getId(),
                    'tag'                  => self::AUDIT_TAG,
                ],
                description: sprintf(
                    '[%s] Approval routed to ROLE_TOP_MGMT user #%d for Document #%d',
                    self::AUDIT_TAG,
                    $topMgmtApprover->getId() ?? 0,
                    $document->getId() ?? 0,
                ),
            );
        }

        return $instance;
    }

    /**
     * Resolve the Top-Management approver for the tenant.
     *
     * Returns the first active User carrying {@see self::ROLE_TOP_MGMT}
     * (Geschäftsführung) within the tenant, or null when no such user
     * exists. Falls back to null on missing repository (legacy unit
     * tests instantiate the service without UserRepository) or on any
     * read-side error so kickoff cannot be blocked by a routing-pipeline
     * hiccup — the regular default-approver-chain takes over.
     */
    private function resolveTopManagementApprover(?Tenant $tenant): ?User
    {
        if (!$tenant instanceof Tenant || $this->userRepository === null) {
            return null;
        }
        try {
            $candidates = $this->userRepository->findByRoleInTenant(self::ROLE_TOP_MGMT, $tenant);
        } catch (Throwable $error) {
            $this->logger->warning(
                'PolicyWizard ApprovalKickoff: top-management approver resolution failed; falling back to default chain',
                [
                    'tenant_id' => $tenant->getId(),
                    'role'      => self::ROLE_TOP_MGMT,
                    'error'     => $error->getMessage(),
                ],
            );
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate instanceof User) {
                return $candidate;
            }
        }
        return null;
    }

    private function stepByName(Workflow $workflow, string $name): ?WorkflowStep
    {
        foreach ($workflow->getSteps() as $step) {
            if ($step->getName() === $name) {
                return $step;
            }
        }
        return null;
    }

    /**
     * W7-B — record a witness/co-signature on the approval-trail.
     *
     * GDPR DPO/CISO joint sign-offs (Art. 38(3) DPO independence) and
     * BSI 4-eyes ceremonies use this method to attach the second
     * signatory beside the regular approver chain. Idempotent on
     * repeated calls with the same witness; throws on attempts to
     * overwrite a different witness (audit-trail immutability).
     *
     * Spec: docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md
     *       lines 302-304 (CISO "What's missing" Witnessing).
     */
    public function recordWitness(WorkflowInstance $instance, User $witness): void
    {
        $existing = $instance->getWitnessUser();
        if ($existing instanceof User && $existing->getId() !== $witness->getId()) {
            throw new \LogicException(sprintf(
                'WorkflowInstance #%d already witnessed by user #%d — refusing to overwrite (audit-trail immutability).',
                $instance->getId() ?? 0,
                $existing->getId() ?? 0,
            ));
        }
        if ($existing instanceof User && $existing->getId() === $witness->getId()) {
            // Already recorded — idempotent no-op.
            return;
        }

        $witnessedAt = new DateTimeImmutable();
        $instance->setWitnessUser($witness);
        $instance->setWitnessedAt($witnessedAt);
        $instance->addApprovalHistoryEntry([
            'event'          => 'witness_recorded',
            'witness_user_id' => $witness->getId(),
            'witnessed_at'   => $witnessedAt->format(DATE_ATOM),
            'tag'            => self::AUDIT_TAG,
        ]);

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'policy_approval_witness_recorded',
            entityType: 'WorkflowInstance',
            entityId: $instance->getId(),
            oldValues: null,
            newValues: [
                'witness_user_id' => $witness->getId(),
                'witnessed_at'    => $witnessedAt->format(DATE_ATOM),
                'tag'             => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] Witness/co-signature recorded for WorkflowInstance #%d by user #%d',
                self::AUDIT_TAG,
                $instance->getId() ?? 0,
                $witness->getId() ?? 0,
            ),
        );
    }

    /**
     * W6 Gap-E — guard called BEFORE adding a Document to a bulk-approval
     * batch. GDPR Art. 38(3) DPO-independence requirement: the DPO Charter
     * MUST be approved standalone so the independence sign-off is on the
     * audit trail unbundled from any other document. Privacy Policy is
     * grouped with the DPO Charter for the same reason — the top-level
     * data-protection commitment is a separate ceremonial act.
     *
     * Detection: the source PolicyTemplate's topic is checked against the
     * known forbidden topics. Documents WITHOUT a generated-from-template
     * link cannot be DPO Charters by definition (the wizard is the only
     * producer) and pass without further inspection.
     *
     * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
     * line 285 (Auditor "Open questions for Phase 4" #1, lines 291-293).
     *
     * @throws DpoCharterBulkApprovalException when $document is a
     *         DPO Charter or Privacy Policy.
     */
    public function assertNotDpoCharterInBulk(Document $document): void
    {
        $template = $document->getGeneratedFromTemplate();
        if ($template === null) {
            return;
        }
        $topic = $template->getTopic();
        if ($topic === null) {
            return;
        }
        if (in_array($topic, self::DPO_CHARTER_BULK_FORBIDDEN_TOPICS, true)) {
            $this->auditLogger->logCustom(
                action: 'dpo_charter_bulk_block',
                entityType: 'Document',
                entityId: $document->getId(),
                oldValues: null,
                newValues: [
                    'topic' => $topic,
                    'tag' => self::AUDIT_TAG,
                    'reason' => 'GDPR Art. 38(3) DPO independence — must approve standalone',
                ],
                description: sprintf(
                    '[%s] Bulk-approval rejected for Document #%d (topic="%s") — DPO Charter / Privacy Policy must be approved standalone',
                    self::AUDIT_TAG,
                    $document->getId() ?? 0,
                    $topic,
                ),
            );
            throw new DpoCharterBulkApprovalException($document, $topic);
        }
    }

    /**
     * W6 Gap-E — Topic keys forbidden from bulk batches (GDPR Art. 38(3)).
     * Kept narrow on purpose: only the two artefacts that establish the
     * DPO independence + data-protection commitment chain. Other GDPR
     * documents (RoPA, DPIA Methodology, DSR Procedure, Retention) MAY
     * be bulked because they describe operational practice, not the
     * appointment itself.
     */
    private const array DPO_CHARTER_BULK_FORBIDDEN_TOPICS = [
        'dpo_charter',
        'privacy_policy',
    ];

    /**
     * W4-A Task 4 — does the tenant carry any DORA-tagged Document?
     *
     * Scans EntityTag rows for the canonical DORA markers emitted by
     * {@see DocumentGenerator::applyTags}: `standard:dora` (any of the
     * 6 NEW DORA standalone Documents seeded by
     * {@see \App\Command\SeedDoraPolicyTemplatesCommand}) and
     * `dora-extension:applied` (any ISO body that grew a DORA-Erweiterung
     * section per the {@see DoraExtensionCatalogue}).
     *
     * Falls back to `false` when:
     *  - tenant is null
     *  - EntityTagRepository is not wired (legacy tests)
     *  - no Tag rows match (tenant has not run the wizard with DORA
     *    standard adopted, or has run it but not yet hit DocumentGenerator)
     *
     * Performance: one repository call per kickoff (a single SELECT
     * over EntityTag joined on Tag) — kickoff is rare enough that
     * this is acceptable. Result is NOT cached because the kickoff
     * is short-lived; subsequent kickoffs in the same request would
     * re-read but only when multiple Documents land in the same run.
     */
    private function tenantHasDoraScope(?Tenant $tenant): bool
    {
        if (!$tenant instanceof Tenant || $this->entityTagRepository === null) {
            return false;
        }

        try {
            $rows = $this->entityManager->getRepository(Tag::class)->findBy([
                'tenant' => $tenant,
            ]);
        } catch (Throwable $error) {
            $this->logger->warning(
                'PolicyWizard ApprovalKickoff: DORA scope detection failed; defaulting to false',
                [
                    'tenant_id' => $tenant->getId(),
                    'error'     => $error->getMessage(),
                ],
            );
            return false;
        }

        foreach ($rows as $tag) {
            if (!$tag instanceof Tag) {
                continue;
            }
            $name = $tag->getName();
            if ($name === 'standard:dora' || $name === 'dora-extension:applied') {
                $entityIds = $this->entityTagRepository->findEntityIdsWithTag($tag, Document::class);
                if ($entityIds !== []) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Resolve `bulk_approval_dual_signoff` per tenant via
     * TenantSettingResolver. Falls back to false on resolver absence
     * or any read-side error so kickoff cannot be blocked by setting
     * pipeline failures.
     */
    private function resolveDualSignoff(?Tenant $tenant): bool
    {
        if (!$tenant instanceof Tenant || $this->tenantSettingResolver === null) {
            return false;
        }
        try {
            $resolved = $this->tenantSettingResolver->resolveFor(
                $tenant,
                self::TENANT_SETTING_DUAL_SIGNOFF,
                false,
            );
            $value = $resolved->getValue();
            return is_bool($value) ? $value : (bool) $value;
        } catch (Throwable $error) {
            $this->logger->warning(
                'PolicyWizard ApprovalKickoff: tenant setting resolution failed; defaulting to false',
                [
                    'tenant_id' => $tenant->getId(),
                    'setting'   => self::TENANT_SETTING_DUAL_SIGNOFF,
                    'error'     => $error->getMessage(),
                ],
            );
            return false;
        }
    }
}

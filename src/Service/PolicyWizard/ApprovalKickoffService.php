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
use App\Repository\EntityTagRepository;
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

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowRepository $workflowRepository,
        private readonly AuditLogger $auditLogger,
        private readonly ?TenantSettingResolver $tenantSettingResolver = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EntityTagRepository $entityTagRepository = null,
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
        $instance->setStatus('in_progress');
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

        return $instance;
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

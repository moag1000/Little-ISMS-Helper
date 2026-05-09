<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Entity\WorkflowInstance;
use App\Repository\DocumentSectionRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\EmailNotificationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * PolicySectionApprovalService — Phase 4-C / Sprint W3-C.
 *
 * Drives the privacy-section sub-workflow `privacy-section-approval`
 * defined in `docs/plans/policy-wizard/06-dpo-input.md` §0.A.
 *
 * Public surface:
 *
 *   - approve()  : DPO sign-off → DocumentSection.status = approved.
 *                  When ALL gated sections of the host Document are
 *                  approved AND the host workflow is at the
 *                  `top_mgmt_signoff` step, advances the host workflow
 *                  to `published`.
 *   - reject()   : DPO veto → DocumentSection.status = rejected,
 *                  rationale required (Art. 38(3) audit-trail).
 *
 * Every transition emits an audit-log entry tagged with
 * `policy-section-approval` so the per-section history is queryable
 * separately from the host-document workflow history.
 */
class PolicySectionApprovalService
{
    private const string AUDIT_TAG = 'policy-section-approval';

    /**
     * W6-A §0.A.7 — tenant-setting key holding the org-level GDPR-scope
     * marker. When `true` the tenant processes personal data of natural
     * persons and the DPO role MUST be in the approval chain. When
     * `false`/missing, BSI-pure tenants still load `dpo`-roled templates
     * but the resolver gracefully falls back to a CISO sign-off so the
     * gate never deadlocks.
     */
    public const string SETTING_GDPR_SUBJECT = 'org.is_gdpr_subject';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentSectionRepository $sectionRepository,
        private readonly AuditLogger $auditLogger,
        private readonly ?UserRepository $userRepository = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?GenerationApprovalElapsedGuard $elapsedGuard = null,
        private readonly ?EmailNotificationService $emailNotificationService = null,
    ) {
    }

    /**
     * DPO signs off the section. Idempotent: re-approving an already-
     * approved section is a no-op (returns silently).
     *
     * @throws InvalidArgumentException if the section is in `rejected`
     *         state and not first reset to `draft` via a save.
     */
    public function approve(DocumentSection $section, User $approver): void
    {
        if ($section->isApproved()) {
            return;
        }

        if ($section->getStatus() === DocumentSection::STATUS_REJECTED) {
            throw new InvalidArgumentException(
                'Cannot approve a section in `rejected` state — section must be edited '
                . 'and re-saved (back to draft) before re-approval.',
            );
        }

        // W6-A §0.A.5 — Art. 38(3) self-approval prohibition: the user
        // who authored the section content MUST NOT be the user who
        // signs it off. Throws InvalidArgumentException on violation.
        $this->assertNotSelfApproval($section, $approver);

        // W1 audit-defang gap #2 — generation-to-approval min-elapsed
        // gate. Blocks the same-second approval anti-pattern by
        // requiring a plausible reading window between Document
        // generation and DPO sign-off (`docs/plans/policy-wizard/
        // persona-reviews/06-external-auditor-review.md` lines 175-178).
        // Optional: when the guard is not wired (legacy DI graphs /
        // unit fixtures) we fall through to the old behaviour.
        if ($this->elapsedGuard !== null) {
            $document = $section->getDocument();
            if ($document instanceof Document) {
                $this->elapsedGuard->assertMinimumElapsed($document, $approver);
            }
        }

        $previousStatus = $section->getStatus();
        $section->setStatus(DocumentSection::STATUS_APPROVED);
        $section->setApprovedAt(new DateTimeImmutable());
        $section->setApprovedByUser($approver);
        // Clear any prior rejection metadata so the audit trail reads
        // cleanly after a draft → dpo_sign_off → approved cycle.
        $section->setRejectedAt(null);
        $section->setRejectedByUser(null);
        $section->setRejectionReason(null);

        // W6-A §0.A.4 — once a `dpo`-roled section receives sign-off the
        // CISO must be locked out of further edits. `joint`/`ciso` rows
        // also lock so a re-edit can never silently mutate signed-off
        // evidence.
        $this->lockSectionForCisoEdits($section);

        $this->entityManager->persist($section);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'section_approved',
            entityType: 'DocumentSection',
            entityId: $section->getId(),
            oldValues: ['status' => $previousStatus],
            newValues: [
                'status' => DocumentSection::STATUS_APPROVED,
                'section_key' => $section->getSectionKey(),
                'document_id' => $section->getDocument()?->getId(),
                'approver_id' => $approver->getId(),
                'tag' => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] DPO approved privacy section "%s" of document #%d',
                self::AUDIT_TAG,
                $section->getSectionKey() ?? '?',
                $section->getDocument()?->getId() ?? 0,
            ),
        );

        $this->maybeAdvanceHostWorkflow($section);
    }

    /**
     * DPO veto. Rationale is mandatory — Art. 38(3) needs a positive
     * audit-trail of WHY the DPO blocked publication.
     */
    public function reject(DocumentSection $section, User $approver, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException(
                'A rejection reason is mandatory (GDPR Art. 38(3) audit-trail requirement).',
            );
        }

        // W6-A §0.A.5 — same self-approval prohibition applies to vetoes.
        // An author cannot use their own veto power on the same section.
        $this->assertNotSelfApproval($section, $approver);

        $previousStatus = $section->getStatus();
        $section->setStatus(DocumentSection::STATUS_REJECTED);
        $section->setRejectedAt(new DateTimeImmutable());
        $section->setRejectedByUser($approver);
        $section->setRejectionReason($reason);
        // Clear any approved-flags so the row reflects the latest state.
        $section->setApprovedAt(null);
        $section->setApprovedByUser(null);
        // W6-A §0.A.4 — DPO veto reverts the section to a re-editable
        // state; the author / CISO must rework the content before another
        // sign-off attempt.
        $section->setEditLocked(false);

        $this->entityManager->persist($section);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'section_rejected',
            entityType: 'DocumentSection',
            entityId: $section->getId(),
            oldValues: ['status' => $previousStatus],
            newValues: [
                'status' => DocumentSection::STATUS_REJECTED,
                'section_key' => $section->getSectionKey(),
                'document_id' => $section->getDocument()?->getId(),
                'rejected_by_id' => $approver->getId(),
                'reason' => $reason,
                'tag' => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] DPO REJECTED privacy section "%s" of document #%d — %s',
                self::AUDIT_TAG,
                $section->getSectionKey() ?? '?',
                $section->getDocument()?->getId() ?? 0,
                mb_substr($reason, 0, 120),
            ),
        );

        // W3 Gap-B — notify the Document owner (or wizard-run starter as
        // fallback) of the rejection so the rework cycle starts immediately
        // instead of waiting for the next inbox poll. ISB review
        // "Bulk-approval ergonomics" #2 (07-phase4-sprint-reconciliation.md
        // line 232).
        $this->notifyRejectionTarget($section, $approver, $reason);
    }

    /**
     * If every gated section has reached `approved` AND the host
     * workflow is parked at `top_mgmt_signoff`, advance the host to
     * `published`.
     *
     * The check is intentionally defensive: when no host WorkflowInstance
     * exists (test fixtures, ad-hoc renders), we silently skip — the
     * approval still persisted and the audit-log entry was written.
     */
    private function maybeAdvanceHostWorkflow(DocumentSection $section): void
    {
        $document = $section->getDocument();
        if (!$document instanceof Document) {
            return;
        }

        if (!$this->sectionRepository->allSectionsApproved($document)) {
            return;
        }

        $hostInstance = $this->entityManager->getRepository(WorkflowInstance::class)
            ->findOneBy([
                'entityType' => 'Document',
                'entityId'   => $document->getId(),
            ]);
        if (!$hostInstance instanceof WorkflowInstance) {
            return;
        }

        $currentStep = $hostInstance->getCurrentStep();
        if ($currentStep === null) {
            return;
        }

        // Per spec §0.A.2 step 5: only advance when the host is parked
        // at `top_mgmt_signoff` waiting for the gate to release.
        $stepName = strtolower($currentStep->getName() ?? '');
        if (!str_contains($stepName, 'top_mgmt_signoff')
            && !str_contains($stepName, 'top-mgmt-signoff')
            && !str_contains($stepName, 'top mgmt signoff')
            && !str_contains($stepName, 'privacy_section_gate')
        ) {
            return;
        }

        $previousStatus = $hostInstance->getStatus();
        $hostInstance->setStatus('approved');
        $hostInstance->setCompletedAt(new DateTimeImmutable());
        $hostInstance->addApprovalHistoryEntry([
            'event'      => 'privacy_section_gate_released',
            'document'   => $document->getId(),
            'released_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'tag'        => self::AUDIT_TAG,
        ]);
        $this->entityManager->persist($hostInstance);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'host_workflow_advanced',
            entityType: 'WorkflowInstance',
            entityId: $hostInstance->getId(),
            oldValues: ['status' => $previousStatus],
            newValues: [
                'status'       => 'approved',
                'document_id'  => $document->getId(),
                'next_state'   => 'published',
                'tag'          => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] Host workflow advanced after all gated sections approved (document #%d)',
                self::AUDIT_TAG,
                $document->getId(),
            ),
        );
    }

    /**
     * W6-A §0.A.4 — once a section is signed off, set the `editLocked`
     * flag so the CISO can no longer rework it. Idempotent on already-
     * locked rows. The DPO is exempt: {@see assertSectionEditable} treats
     * ROLE_DPO actors as authorised regardless of the flag, and the same
     * voter routes "DPO re-edit" through {@see reopenForDpoEdit} which
     * clears the lock and re-opens the section.
     */
    public function lockSectionForCisoEdits(DocumentSection $section): void
    {
        if ($section->isEditLocked()) {
            return;
        }
        $section->setEditLocked(true);
        $this->entityManager->persist($section);
        // Caller (approve()) flushes downstream — we avoid an extra
        // flush here so the audit-log entry sits in the same UoW.
    }

    /**
     * W6-A §0.A.4 — guard called by the section editor controller before
     * accepting any save from a non-DPO actor. CISO/Top-Mgmt edits raise
     * {@see LockedSectionException}; DPO edits pass through (and the
     * caller is expected to {@see reopenForDpoEdit} the section so the
     * approval state machine resets).
     *
     * @throws LockedSectionException when $editor is not a ROLE_DPO actor
     *                                AND $section is locked.
     */
    public function assertSectionEditable(DocumentSection $section, User $editor): void
    {
        if (!$section->isEditLocked()) {
            return;
        }
        $editorIsDpo = in_array('ROLE_DPO', $editor->getRoles(), true);
        if ($editorIsDpo) {
            return;
        }

        $this->auditLogger->logCustom(
            action: 'section_edit_blocked',
            entityType: 'DocumentSection',
            entityId: $section->getId(),
            oldValues: null,
            newValues: [
                'section_key' => $section->getSectionKey(),
                'document_id' => $section->getDocument()?->getId(),
                'editor_id'   => $editor->getId(),
                'editor_roles' => $editor->getRoles(),
                'tag'         => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] CISO/Top-Mgmt edit blocked on locked section "%s" (document #%d, editor #%d)',
                self::AUDIT_TAG,
                $section->getSectionKey() ?? '?',
                $section->getDocument()?->getId() ?? 0,
                $editor->getId() ?? 0,
            ),
        );

        throw new LockedSectionException($section, $editor);
    }

    /**
     * W6-A §0.A.4 — DPO re-edits an already-signed-off section. Resets
     * status to `dpo_sign_off` (per §0.A.2 the section sub-state goes
     * back into the pending pool), clears the edit-lock so the
     * subsequent flow can re-approve, and emits a `dpo_section_reopened`
     * audit entry. The host workflow is NOT auto-advanced — that fires
     * only on the next approve() call.
     */
    public function reopenForDpoEdit(DocumentSection $section, User $editor): void
    {
        $previousStatus = $section->getStatus();
        $section->setStatus(DocumentSection::STATUS_DPO_SIGN_OFF);
        $section->setEditLocked(false);
        $section->setApprovedAt(null);
        $section->setApprovedByUser(null);
        $section->setAuthoredByUser($editor);
        $this->entityManager->persist($section);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'section_reopened_by_dpo',
            entityType: 'DocumentSection',
            entityId: $section->getId(),
            oldValues: ['status' => $previousStatus, 'edit_locked' => true],
            newValues: [
                'status'      => DocumentSection::STATUS_DPO_SIGN_OFF,
                'edit_locked' => false,
                'editor_id'   => $editor->getId(),
                'tag'         => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] DPO re-opened locked section "%s" (document #%d) — status reverted to dpo_sign_off',
                self::AUDIT_TAG,
                $section->getSectionKey() ?? '?',
                $section->getDocument()?->getId() ?? 0,
            ),
        );
    }

    /**
     * W6-A §0.A.5 — Art. 38(3) self-approval prohibition. Throws if the
     * user who authored the section content is the same user attempting
     * to approve / veto it. Preserves the §9.5 carve-out documented for
     * the DPO Charter (which is itself authored about the DPO but never
     * BY the DPO via this service — a separate workflow excludes the
     * DPO from the approval chain entirely).
     *
     * @throws InvalidArgumentException on violation.
     */
    public function assertNotSelfApproval(DocumentSection $section, User $approver): void
    {
        $author = $section->getAuthoredByUser();
        if ($author === null) {
            return;
        }
        if ($author->getId() === null || $approver->getId() === null) {
            return;
        }
        if ($author->getId() !== $approver->getId()) {
            return;
        }

        $this->auditLogger->logCustom(
            action: 'section_self_approval_blocked',
            entityType: 'DocumentSection',
            entityId: $section->getId(),
            oldValues: null,
            newValues: [
                'section_key' => $section->getSectionKey(),
                'document_id' => $section->getDocument()?->getId(),
                'actor_id'    => $approver->getId(),
                'tag'         => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] Self-approval blocked on section "%s" (document #%d, actor #%d) — Art. 38(3)',
                self::AUDIT_TAG,
                $section->getSectionKey() ?? '?',
                $section->getDocument()?->getId() ?? 0,
                $approver->getId() ?? 0,
            ),
        );

        throw new InvalidArgumentException(sprintf(
            'Self-approval blocked on section #%d: author and approver are the same user (Art. 38(3) GDPR / §0.A.5).',
            $section->getId() ?? 0,
        ));
    }

    /**
     * W6-A §0.A.7 — BSI-tenant graceful degradation. Resolve which role
     * actually owns the approval for ($tenant, $sectionKey).
     *
     * Logic:
     *  1. If the host template requested `dpo` BUT the tenant has no
     *     DPO appointed AND no `org.is_gdpr_subject=true` setting, fall
     *     back to `ciso`. Logs a `dpo_role_fallback_to_ciso` warning so
     *     the operator sees the silent suppression in their feed.
     *  2. Otherwise return the requested role unchanged.
     *
     * Callers pass the templated role (typically read from the seed
     * config or {@see PolicyTemplate::getDpoGatedSectionKeys()} metadata).
     * Returning a single approver-role string keeps the caller branch-
     * less.
     *
     * @param string $requestedRole one of APPROVAL_ROLE_* constants.
     * @return string resolved role, one of APPROVAL_ROLE_* constants.
     */
    public function resolveApprovalRole(
        Tenant $tenant,
        string $sectionKey,
        string $requestedRole = DocumentSection::APPROVAL_ROLE_DPO,
    ): string {
        if (!in_array($requestedRole, DocumentSection::ALLOWED_APPROVAL_ROLES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown approval role "%s" requested for section "%s".',
                $requestedRole,
                $sectionKey,
            ));
        }

        // CISO-roled sections never degrade — short-circuit so the
        // user-repository lookup stays off the hot path.
        if ($requestedRole === DocumentSection::APPROVAL_ROLE_CISO) {
            return DocumentSection::APPROVAL_ROLE_CISO;
        }

        $isGdprSubject = $this->isGdprSubject($tenant);
        $hasDpoAppointed = $this->tenantHasDpoAppointed($tenant);

        if ($requestedRole === DocumentSection::APPROVAL_ROLE_DPO
            && !$hasDpoAppointed
            && !$isGdprSubject
        ) {
            $this->logger->warning(
                'PolicySectionApprovalService: dpo_role_fallback_to_ciso',
                [
                    'tenant_id'   => $tenant->getId(),
                    'section_key' => $sectionKey,
                    'reason'      => 'no DPO appointed and tenant is not flagged as GDPR subject',
                ],
            );
            $this->auditLogger->logCustom(
                action: 'dpo_role_fallback_to_ciso',
                entityType: 'Tenant',
                entityId: $tenant->getId(),
                oldValues: ['requested_role' => $requestedRole],
                newValues: [
                    'resolved_role' => DocumentSection::APPROVAL_ROLE_CISO,
                    'section_key'   => $sectionKey,
                    'tag'           => self::AUDIT_TAG,
                ],
                description: sprintf(
                    '[%s] Tenant #%d has no DPO appointment and gdpr_subject=false → '
                    . 'section "%s" falls back from dpo to ciso approval',
                    self::AUDIT_TAG,
                    $tenant->getId() ?? 0,
                    $sectionKey,
                ),
            );
            return DocumentSection::APPROVAL_ROLE_CISO;
        }

        // `joint` with no DPO appointed → still degrade to ciso to keep
        // the gate from deadlocking. The same warning fires so the
        // operator notices the missing DPO.
        if ($requestedRole === DocumentSection::APPROVAL_ROLE_JOINT
            && !$hasDpoAppointed
            && !$isGdprSubject
        ) {
            $this->logger->warning(
                'PolicySectionApprovalService: dpo_role_fallback_to_ciso (joint)',
                [
                    'tenant_id'   => $tenant->getId(),
                    'section_key' => $sectionKey,
                ],
            );
            return DocumentSection::APPROVAL_ROLE_CISO;
        }

        return $requestedRole;
    }

    /**
     * W6-A §0.A.7 helper — does the tenant have at least one user
     * carrying ROLE_DPO? Defensive: when no UserRepository is wired (DI
     * graphs missing it in legacy tests) we return false so the
     * fallback path runs and never hangs the gate.
     */
    private function tenantHasDpoAppointed(Tenant $tenant): bool
    {
        if ($this->userRepository === null) {
            return false;
        }
        $candidates = $this->userRepository->findByRole('ROLE_DPO');
        foreach ($candidates as $user) {
            if (!$user instanceof User) {
                continue;
            }
            // A user "belongs" to the tenant when the User entity carries
            // the same Tenant reference. Custom-role-only DPOs are also
            // covered because findByRole() inspects the JSON `roles` field.
            if (method_exists($user, 'getTenant') && $user->getTenant() === $tenant) {
                return true;
            }
            // Fall through: when the User entity does not expose getTenant
            // (older fixtures), assume the role match is enough.
            if (!method_exists($user, 'getTenant')) {
                return true;
            }
        }
        return false;
    }

    /**
     * W6-A §0.A.7 helper — read the `org.is_gdpr_subject` flag out of
     * the tenant settings JSON. Returns false on missing / non-boolean
     * values so a misconfigured tenant defaults to the safe fallback.
     */
    private function isGdprSubject(Tenant $tenant): bool
    {
        $settings = $tenant->getSettings();
        if (!is_array($settings)) {
            return false;
        }
        // Support both flat `is_gdpr_subject` and nested `org.is_gdpr_subject`
        // (current TenantSettingResolver convention) for forward-compat.
        $flag = $settings[self::SETTING_GDPR_SUBJECT]
            ?? ($settings['org']['is_gdpr_subject'] ?? null);
        return $flag === true;
    }

    /**
     * W3 Gap-B — pick the User to notify when a DocumentSection is
     * rejected. Spec: ISB review "Bulk-approval ergonomics" #2 — the
     * Document.owner (modelled here as `Document.uploadedBy`) is the
     * primary recipient; when missing, fall back to
     * {@see WizardRun::getStartedByUser()}.
     *
     * Returns null when neither target is available — the caller still
     * writes the audit-log event (`policy_wizard.rejection_notification`)
     * so the rejection trail is preserved even without a notify target.
     */
    public function resolveRejectionNotifyTarget(Document $document, ?WizardRun $run): ?User
    {
        $owner = $document->getUploadedBy();
        if ($owner instanceof User) {
            return $owner;
        }
        $starter = $run?->getStartedByUser();
        if ($starter instanceof User) {
            return $starter;
        }
        return null;
    }

    /**
     * W3 Gap-B — wraps the notify-target resolution + email dispatch so
     * the rejection path stays single-purpose. Failures (no target, no
     * mailer wired, transport error) degrade silently to an audit-log
     * entry — rejection persistence MUST never be blocked by a notify
     * pipeline failure.
     */
    private function notifyRejectionTarget(DocumentSection $section, User $approver, string $reason): void
    {
        $document = $section->getDocument();
        if (!$document instanceof Document) {
            return;
        }

        $run = $document->getGeneratedFromWizardRun();
        $target = $this->resolveRejectionNotifyTarget($document, $run);

        $this->auditLogger->logCustom(
            action: 'policy_wizard.rejection_notification',
            entityType: 'DocumentSection',
            entityId: $section->getId(),
            oldValues: null,
            newValues: [
                'document_id'   => $document->getId(),
                'section_key'   => $section->getSectionKey(),
                'rejected_by_id' => $approver->getId(),
                'notify_target_id' => $target?->getId(),
                'notify_source' => $target === null
                    ? 'none'
                    : ($document->getUploadedBy() === $target ? 'document_owner' : 'wizard_run_starter'),
                'reason_excerpt' => mb_substr($reason, 0, 240),
                'tag' => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] Rejection notification dispatched for section "%s" of document #%d → user #%s',
                self::AUDIT_TAG,
                $section->getSectionKey() ?? '?',
                $document->getId() ?? 0,
                $target?->getId() ?? 'none',
            ),
        );

        if ($target === null || $this->emailNotificationService === null) {
            return;
        }

        try {
            $this->emailNotificationService->sendGenericNotification(
                subject: '[ISMS] Policy section rejected — rework required',
                template: 'emails/policy_wizard_rejection_notification.html.twig',
                context: [
                    'document'    => $document,
                    'section'     => $section,
                    'approver'    => $approver,
                    'reason'      => $reason,
                    'notify_target' => $target,
                ],
                recipients: [$target],
            );
        } catch (Throwable $error) {
            $this->logger->warning(
                'PolicySectionApprovalService: rejection notification email dispatch failed',
                [
                    'document_id' => $document->getId(),
                    'section_id'  => $section->getId(),
                    'target_id'   => $target->getId(),
                    'error'       => $error->getMessage(),
                ],
            );
        }
    }
}

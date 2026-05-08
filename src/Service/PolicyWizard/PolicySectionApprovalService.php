<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Repository\DocumentSectionRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

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

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentSectionRepository $sectionRepository,
        private readonly AuditLogger $auditLogger,
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

        $previousStatus = $section->getStatus();
        $section->setStatus(DocumentSection::STATUS_APPROVED);
        $section->setApprovedAt(new DateTimeImmutable());
        $section->setApprovedByUser($approver);
        // Clear any prior rejection metadata so the audit trail reads
        // cleanly after a draft → dpo_sign_off → approved cycle.
        $section->setRejectedAt(null);
        $section->setRejectedByUser(null);
        $section->setRejectionReason(null);

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

        $previousStatus = $section->getStatus();
        $section->setStatus(DocumentSection::STATUS_REJECTED);
        $section->setRejectedAt(new DateTimeImmutable());
        $section->setRejectedByUser($approver);
        $section->setRejectionReason($reason);
        // Clear any approved-flags so the row reflects the latest state.
        $section->setApprovedAt(null);
        $section->setApprovedByUser(null);

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
}

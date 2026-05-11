<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Document;
use App\Repository\UserRepository;
use App\Service\Document\DocumentEvidenceAttachmentInterface;
use App\Service\Document\DocumentEvidenceAttachmentService;
use App\Service\EmailNotificationService;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-LB-8 + WS-9 + multi-framework evidence attachment.
 *
 *  LB-8 (review-cycle auto-set):
 *    On status transition → 'approved' AND nextReviewDate is null,
 *    populate `nextReviewDate = today + reviewIntervalMonths` (default
 *    12). ISO 27001 Cl.7.5.2 expects every documented information to
 *    have a scheduled review cadence; the listener guarantees that no
 *    approval slips through without a calendar entry.
 *
 *  WS-9 (ICT-Policy → CSIRT notify):
 *    Whenever an approved document carries `category='ict_policy'`,
 *    notify the tenant CSIRT-team (ROLE_CISO + ROLE_DPO) so they can
 *    review the policy before circulation.
 *
 *  Multi-framework evidence attachment (Phase 1):
 *    On status → 'approved', collect newly-approved Documents into
 *    a deferred queue during `preUpdate`. After the outer flush
 *    completes (postFlush), the queue is drained: DocumentControlLink
 *    rows are created for ISO 27001 Annex A refs and
 *    ComplianceRequirement::evidenceDocuments entries are added for all
 *    other frameworks. A second flush is performed only if any new
 *    entities were created (ISO 27001 Cl. 7.5.3 audit trail).
 *
 * Idempotency: change-set guard ensures we only act on the first
 * approval transition. The DocumentEvidenceAttachmentService is
 * idempotent (UNIQUE constraint on DCL; contains() check on
 * requirement evidenceDocuments).
 */
#[AsEntityListener(event: Events::preUpdate, entity: Document::class)]
#[AsDoctrineListener(event: Events::postFlush)]
class DocumentApprovalListener
{
    /**
     * Documents that transitioned to 'approved' during the current flush cycle.
     * Drained in postFlush; cleared after drain to stay idempotent.
     *
     * @var list<Document>
     */
    private array $pendingEvidenceAttachment = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?EmailNotificationService $emailNotifier = null,
        private readonly ?UserRepository $userRepository = null,
        private readonly ?DocumentEvidenceAttachmentInterface $evidenceAttachmentService = null,
    ) {
    }

    public function preUpdate(Document $document, PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('status')) {
            return;
        }
        $oldStatus = $args->getOldValue('status');
        $newStatus = $args->getNewValue('status');
        if ($newStatus !== 'approved' || $oldStatus === 'approved') {
            return;
        }

        $this->setReviewDate($document, $args);
        $this->maybeNotifyCsirt($document);

        // Queue for evidence attachment in postFlush.
        if ($this->evidenceAttachmentService !== null && $document->getGeneratedFromTemplate() !== null) {
            $this->pendingEvidenceAttachment[] = $document;
        }
    }

    /**
     * Drain the deferred evidence-attachment queue.
     *
     * Runs AFTER the outer flush completes so we can safely call
     * EntityManager::persist() + flush() without triggering the
     * "no nested flush" constraint of preUpdate.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if (empty($this->pendingEvidenceAttachment) || $this->evidenceAttachmentService === null) {
            return;
        }

        $pending = $this->pendingEvidenceAttachment;
        // Clear first to prevent re-entry if the second flush triggers another postFlush.
        $this->pendingEvidenceAttachment = [];

        $em = $args->getObjectManager();
        $didPersist = false;

        foreach ($pending as $document) {
            try {
                $stats = $this->evidenceAttachmentService->attachOnApproval($document);
                if ($stats['iso27001_links'] > 0 || $stats['requirement_links'] > 0) {
                    $didPersist = true;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('DocumentApprovalListener: evidence attachment failed', [
                    'document_id' => $document->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($didPersist) {
            try {
                $em->flush();
            } catch (\Throwable $e) {
                $this->logger->error('DocumentApprovalListener: postFlush secondary flush failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * V3 W2-LB-8 — populate nextReviewDate when missing.
     *
     * Runs in preUpdate so the new value is part of the same flush.
     * Uses PreUpdateEventArgs::setNewValue() to register the change
     * inside the active changeset — calling setNextReviewDate() alone
     * would not propagate to the DB without a (forbidden) nested flush.
     */
    private function setReviewDate(Document $document, PreUpdateEventArgs $args): void
    {
        if ($document->getNextReviewDate() instanceof \DateTimeInterface) {
            return;
        }
        try {
            $months = max(1, $document->getReviewIntervalMonths());
            $next = new DateTime(sprintf('+%d months', $months));
            $oldValue = $document->getNextReviewDate();
            $document->setNextReviewDate($next);
            $em = $args->getObjectManager();
            $uow = $em->getUnitOfWork();
            $uow->propertyChanged($document, 'nextReviewDate', $oldValue, $next);

            $this->logger->info('Document review-cycle auto-set on approval', [
                'document_id' => $document->getId(),
                'next_review_date' => $next->format('Y-m-d'),
                'interval_months' => $months,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Document review-cycle auto-set failed', [
                'document_id' => $document->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * V3 W2-WS-9 — Notify CSIRT-team for approved ICT-policy documents.
     * Falls back gracefully when neither emailNotifier nor userRepository
     * is wired (test contexts).
     */
    private function maybeNotifyCsirt(Document $document): void
    {
        if ($document->getCategory() !== 'ict_policy') {
            return;
        }
        if ($this->emailNotifier === null || $this->userRepository === null) {
            return;
        }
        try {
            $tenant = $document->getTenant();
            // CSIRT operational stand-ins: CISO + DPO + Risk-Manager.
            // (no dedicated ROLE_CSIRT in security.yaml yet.)
            $recipients = [];
            foreach (['ROLE_CISO', 'ROLE_DPO', 'ROLE_RISK_MANAGER'] as $role) {
                $recipients = array_merge(
                    $recipients,
                    $this->userRepository->findByRoleInTenant($role, $tenant) ?? []
                );
            }
            // Deduplicate by user id.
            $unique = [];
            foreach ($recipients as $user) {
                $id = method_exists($user, 'getId') ? $user->getId() : null;
                if ($id !== null && !isset($unique[$id])) {
                    $unique[$id] = $user;
                }
            }
            if ($unique === []) {
                return;
            }

            $subject = sprintf(
                'Approved ICT-Policy: %s',
                (string) ($document->getOriginalFilename() ?? $document->getFilename() ?? 'document')
            );
            $this->emailNotifier->sendGenericNotification(
                $subject,
                'emails/document_approved_ict_policy.html.twig',
                [
                    'document' => $document,
                ],
                array_values($unique),
            );
            $this->logger->info('CSIRT notified about approved ICT-policy', [
                'document_id' => $document->getId(),
                'recipient_count' => count($unique),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('CSIRT notification for ICT-policy failed', [
                'document_id' => $document->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}

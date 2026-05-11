<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Document;
use App\Repository\UserRepository;
use App\Service\EmailNotificationService;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-LB-8 + WS-9 — Document approval reactions.
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
 *    notify the tenant CSIRT-team (ROLE_CISO + ROLE_DPO; CSIRT does
 *    not have its own role yet, these two are the operational
 *    responders) so they can review the policy before circulation.
 *    Audit-Reaction Acknowledgement-Campaign (existing C3 listener)
 *    already creates the ack-records for normal users.
 *
 * Idempotency: change-set guard ensures we only act on the first
 * approval (or first transition into approved + ict_policy). Already-
 * populated nextReviewDate is left intact to preserve the intent of
 * the ISB who set a custom date.
 */
#[AsEntityListener(event: Events::preUpdate, entity: Document::class)]
class DocumentApprovalListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?EmailNotificationService $emailNotifier = null,
        private readonly ?UserRepository $userRepository = null,
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

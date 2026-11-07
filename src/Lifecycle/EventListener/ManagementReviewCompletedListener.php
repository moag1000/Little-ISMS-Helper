<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ManagementDecisionType;
use App\Service\ManagementDecision\ManagementDecisionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Completing a management review is top management's decision on its results
 * (ISO 27001 9.3.3, NIS-2 Art. 20 (1)): the decision is recorded with who
 * completed it, when and with which comment, next to the board's other
 * decisions.
 */
#[AsEventListener(event: 'workflow.management_review_lifecycle.completed', method: 'onCompleted')]
final class ManagementReviewCompletedListener
{
    public function __construct(
        private readonly ManagementDecisionService $decisions,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onCompleted(CompletedEvent $event): void
    {
        $review = $event->getSubject();
        if (!$review instanceof ManagementReview || $event->getTransition()?->getName() !== 'complete') {
            return;
        }
        $tenant = $review->getTenant();
        if (!$tenant instanceof Tenant) {
            return;
        }
        $context = $event->getContext();
        $user = $context['user'] ?? null;
        $reason = $context['reason'] ?? null;

        $text = trim((string) $review->getDecisions());
        if ($text === '') {
            $text = trim((string) $review->getSummary());
        }

        try {
            $this->decisions->record(
                tenant: $tenant,
                type: ManagementDecisionType::ManagementReview,
                title: $this->translator->trans(
                    'management_decision.recorded.review_title',
                    ['%review%' => (string) $review->getTitle()],
                    'management_decision',
                ),
                text: $text !== '' ? $text : null,
                decidedBy: $user instanceof User ? $user : null,
                comment: is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
                subjectType: 'ManagementReview',
                subjectId: $review->getId(),
                review: $review,
            );
        } catch (\Throwable $e) {
            // The review stays completed; the decision log misses one entry,
            // the audit log still holds the transition.
            $this->logger->error('Recording the management review decision failed', [
                'review_id' => $review->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}

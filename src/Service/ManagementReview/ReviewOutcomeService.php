<?php

declare(strict_types=1);

namespace App\Service\ManagementReview;

use App\Entity\ActionItem;
use App\Entity\ActionItemReference;
use App\Entity\ManagementDecision;
use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ActionItemRepository;
use App\Repository\ManagementDecisionRepository;
use App\Service\AuditLogger;
use App\Service\Planning\ActionItemStatusService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Results of a management review (ISO 27001 9.3.3): the actions it decided
 * (ActionItem with origin management_review_action) and the decisions top management
 * took in it. The next review reads both back as input a (9.3.2 a).
 */
final class ReviewOutcomeService
{
    public function __construct(
        private readonly ActionItemRepository $actionItemRepository,
        private readonly ManagementDecisionRepository $decisionRepository,
        private readonly ActionItemStatusService $statusService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @return list<ActionItem> */
    public function tasks(ManagementReview $review): array
    {
        $tenant = $review->getTenant();
        if (!$tenant instanceof Tenant || $review->getId() === null) {
            return [];
        }

        return $this->actionItemRepository->findForManagementReview($tenant, $review->getId());
    }

    /** @return list<ManagementDecision> */
    public function decisions(ManagementReview $review): array
    {
        return $review->getId() === null ? [] : $this->decisionRepository->findForReview($review);
    }

    /** An empty task of the review's organisation, for the "add action" form. */
    public function newTask(ManagementReview $review): ActionItem
    {
        return (new ActionItem())->setTenant($review->getTenant());
    }

    /** Writes an action decided in the review as task referring to it. */
    public function addTask(ManagementReview $review, ActionItem $task): void
    {
        $task->setTenant($review->getTenant())
            ->setOrigin(ActionItemRepository::MANAGEMENT_REVIEW_REF);
        $reference = (new ActionItemReference())
            ->setRefType(ActionItemRepository::MANAGEMENT_REVIEW_REF)
            ->setRefId($review->getId())
            ->setTenant($review->getTenant());
        $task->addReference($reference);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $this->auditLogger->logCreate('ActionItem', $task->getId(), [
            'title' => $task->getTitle(),
            'dueDate' => $task->getDueDate()?->format('Y-m-d'),
            'managementReview' => $review->getId(),
        ], 'Action from management review');
    }

    /** Marks a review action done (open/planned pass through in progress). */
    public function completeTask(ActionItem $task, User $user): void
    {
        if (in_array($task->getStatus(), [ActionItem::STATUS_OPEN, ActionItem::STATUS_PLANNED], true)) {
            $this->statusService->transition($task, ActionItem::STATUS_IN_PROGRESS, $user);
        }
        $this->statusService->transition($task, ActionItem::STATUS_DONE, $user);
    }

    public function belongsTo(ActionItem $task, ManagementReview $review): bool
    {
        foreach ($task->getReferences() as $reference) {
            if ($reference->getRefType() === ActionItemRepository::MANAGEMENT_REVIEW_REF
                && $reference->getRefId() === $review->getId()) {
                return true;
            }
        }

        return false;
    }
}

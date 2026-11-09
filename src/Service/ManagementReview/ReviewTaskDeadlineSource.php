<?php

declare(strict_types=1);

namespace App\Service\ManagementReview;

use App\Entity\ActionItem;
use App\Entity\Tenant;
use App\Repository\ActionItemRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * Tasks top management decided in a management review (ISO 27001 9.3.3).
 * They already are Maßnahmen (ActionItem with a reference to the review), so
 * this source is inbox-only; the planner must not convert them again.
 */
final readonly class ReviewTaskDeadlineSource implements DeadlineSource
{
    public function __construct(
        private ActionItemRepository $repository,
    ) {
    }

    public function slug(): string
    {
        return ActionItemRepository::MANAGEMENT_REVIEW_REF;
    }

    public function label(): string
    {
        return 'Aufgabe aus der Managementbewertung';
    }

    /** The record page lives in this module; with it off the reminder would open a "module off" page (W6). */
    public function requiredModule(): string
    {
        return 'reviews';
    }

    /** @return list<ActionItem> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $this->repository->findOpenManagementReviewTasks($tenant);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ActionItem);

        return $item->getDueDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ActionItem);

        return (string) $item->getTitle();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ActionItem);

        return $item->isClosed();
    }

    public function refId(object $item): int
    {
        assert($item instanceof ActionItem);

        return (int) $item->getId();
    }

    public function supports(object $item): bool
    {
        return $item instanceof ActionItem && $this->reviewIdOf($item) !== null;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof ActionItem);

        return Responsibility::holders($item->getResponsibleUser(), $item->getResponsiblePerson())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): ?RecordLink
    {
        assert($item instanceof ActionItem);
        $reviewId = $this->reviewIdOf($item);

        return $reviewId === null
            ? null
            : new RecordLink('app_management_review_show', ['id' => $reviewId, '_fragment' => 'tasks']);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(
            bucket: 'management_review_tasks',
            leadDays: 14,
            normReference: 'ISO 27001 9.3.3',
        );
    }

    private function reviewIdOf(ActionItem $item): ?int
    {
        foreach ($item->getReferences() as $reference) {
            if ($reference->getRefType() === ActionItemRepository::MANAGEMENT_REVIEW_REF) {
                return $reference->getRefId();
            }
        }

        return null;
    }
}

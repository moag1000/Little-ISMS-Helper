<?php

declare(strict_types=1);

namespace App\Service\Planning;

use App\Entity\ActionItem;
use App\Entity\ActionItemReference;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Materialises the follow-up ActionItem when a recurring one completes.
 *
 * Anchor is the COMPLETION date, not the old due date: nextDue = completedAt +
 * recurrenceMonths. This prevents a late-completed recurring item from being
 * almost-overdue again immediately (an 11-month delay must not mean "due again
 * in 4 weeks"). The materialised dueDate stays editable so the user can pull
 * fixed-deadline items forward manually.
 *
 * History is preserved: the original item stays `done`; the follow-up is a new
 * row linked via {@see ActionItem::setNextActionItem()}.
 */
final class ActionItemRecurrenceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Create the follow-up for a just-completed item, or null if it is one-off.
     * Does NOT flush — the caller owns the transaction boundary.
     */
    public function materialiseFollowUp(ActionItem $completed): ?ActionItem
    {
        $months = $completed->getRecurrenceMonths();
        if ($months === null || $months <= 0) {
            return null;
        }

        $anchor = $completed->getCompletedAt() ?? new \DateTimeImmutable('today');
        $nextDue = $anchor->modify(sprintf('+%d months', $months));

        $follow = new ActionItem();
        $follow->setTitle($completed->getTitle())
            ->setOrigin($completed->getOrigin())
            ->setResponsibleUser($completed->getResponsibleUser())
            ->setResponsiblePerson($completed->getResponsiblePerson())
            ->setRoadmapTask($completed->getRoadmapTask())
            ->setScopes($completed->getScopes())
            ->setPlannedEffortPt($completed->getPlannedEffortPt())
            ->setRecurrenceMonths($months)
            ->setDueDate($nextDue)
            ->setStatus(ActionItem::STATUS_OPEN)
            ->setTenant($completed->getTenant());

        foreach ($completed->getTeams() as $team) {
            $follow->addTeam($team);
        }

        // Copy provenance references (association, not mirror).
        foreach ($completed->getReferences() as $ref) {
            $copy = new ActionItemReference();
            $copy->setRefType($ref->getRefType())
                ->setRefId((int) $ref->getRefId())
                ->setTenant($ref->getTenant());
            $follow->addReference($copy);
        }

        $this->entityManager->persist($follow);
        $completed->setNextActionItem($follow);

        return $follow;
    }
}

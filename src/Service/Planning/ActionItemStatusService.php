<?php

declare(strict_types=1);

namespace App\Service\Planning;

use App\Entity\ActionItem;
use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Canonical facade for ActionItem status transitions (Engineering-Spec §7).
 *
 * All status changes MUST go through this service — never a raw setStatus() in
 * a controller. It enforces the transition matrix, stamps completedAt, triggers
 * recurrence, and writes the audit trail (old→new + user + reason). Designed so
 * a later escalation to the full LifecycleService is a swap behind this facade,
 * not a call-site sweep. Enum values + transition names mirror a future
 * config/workflows/action_item.yaml.
 */
final class ActionItemStatusService
{
    /**
     * Allowed target states per source state.
     *
     * @var array<string, list<string>>
     */
    private const array TRANSITIONS = [
        ActionItem::STATUS_OPEN        => [ActionItem::STATUS_PLANNED, ActionItem::STATUS_IN_PROGRESS, ActionItem::STATUS_DISMISSED],
        ActionItem::STATUS_PLANNED     => [ActionItem::STATUS_IN_PROGRESS, ActionItem::STATUS_DISMISSED],
        ActionItem::STATUS_IN_PROGRESS => [ActionItem::STATUS_DONE, ActionItem::STATUS_DISMISSED],
        ActionItem::STATUS_DONE        => [],
        ActionItem::STATUS_DISMISSED   => [],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly ActionItemRecurrenceService $recurrenceService,
    ) {
    }

    /**
     * @return list<string> allowed target states from the item's current status
     */
    public function allowedTargets(ActionItem $item): array
    {
        return self::TRANSITIONS[$item->getStatus()] ?? [];
    }

    public function canTransition(ActionItem $item, string $to): bool
    {
        return in_array($to, $this->allowedTargets($item), true);
    }

    /**
     * Transition an item to a new status, flushing the change + audit entry.
     * Returns the materialised follow-up ActionItem when the transition to
     * `done` triggers recurrence, otherwise null.
     *
     * @throws InvalidActionItemTransitionException when the target is not allowed
     */
    public function transition(ActionItem $item, string $to, User $user, ?string $reason = null): ?ActionItem
    {
        $from = $item->getStatus();
        if ($from === $to) {
            return null;
        }
        if (!$this->canTransition($item, $to)) {
            throw InvalidActionItemTransitionException::for($from, $to);
        }

        $item->setStatus($to);
        $item->setUpdatedAt(new \DateTimeImmutable());

        $followUp = null;
        if ($to === ActionItem::STATUS_DONE) {
            if ($item->getCompletedAt() === null) {
                $item->setCompletedAt(new \DateTimeImmutable('today'));
            }
            $followUp = $this->recurrenceService->materialiseFollowUp($item);
        }

        $this->auditLogger->log(
            'action_item.transition',
            'ActionItem',
            $item->getId(),
            ['status' => $from],
            ['status' => $to, 'reason' => $reason],
            sprintf('ActionItem #%s: %s → %s', (string) $item->getId(), $from, $to),
            $user->getUserIdentifier(),
        );

        $this->entityManager->flush();

        return $followUp;
    }
}

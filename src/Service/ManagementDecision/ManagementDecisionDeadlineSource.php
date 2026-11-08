<?php

declare(strict_types=1);

namespace App\Service\ManagementDecision;

use App\Entity\ManagementDecision;
use App\Entity\Tenant;
use App\Enum\ManagementDecisionStatus;
use App\Repository\ManagementDecisionRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * Proposals waiting on top management (ISO 27001 5.1, 9.3.3; NIS-2 Art. 20).
 * Inbox-only: a decision is taken, not planned as a Maßnahme.
 */
final readonly class ManagementDecisionDeadlineSource implements DeadlineSource
{
    public function __construct(
        private ManagementDecisionRepository $repository,
    ) {
    }

    public function slug(): string
    {
        return 'management_decision';
    }

    public function label(): string
    {
        return 'Leitungsentscheidung';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return list<ManagementDecision> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $this->repository->findPending($tenant);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ManagementDecision);

        return $item->getDueDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ManagementDecision);

        return (string) $item->getTitle();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ManagementDecision);

        return $item->getStatus() !== ManagementDecisionStatus::Pending->value;
    }

    public function refId(object $item): int
    {
        assert($item instanceof ManagementDecision);

        return (int) $item->getId();
    }

    public function supports(object $item): bool
    {
        return $item instanceof ManagementDecision;
    }

    public function responsibleOf(object $item): Responsibility
    {
        // Only top management decides (ISO 27001 5.1). Without a holder the
        // decision waits; BoardUnassignedSource tells the admins to assign the
        // role instead of handing them a decision that answers 403.
        return Responsibility::roles('ROLE_TOP_MGMT')->withoutAdminFallback();
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof ManagementDecision);

        return new RecordLink('app_management_decision_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(
            bucket: 'management_decisions_pending',
            leadDays: null,
            normReference: 'ISO 27001 5.1, 9.3.3',
            decision: true,
        );
    }
}

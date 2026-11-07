<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\ChangeRequest;
use App\Entity\Tenant;
use App\Enum\ChangeRequestStatus;
use App\Repository\ChangeRequestRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for ChangeRequest (ISO 27001 §6.3 / §8.1 Change Management).
 *
 * Deadline field : plannedImplementationDate (the approved target date for
 *                  executing the change — the primary SLA commitment)
 * Terminal statuses: Closed, Cancelled, Rejected
 *   (Verified is intentionally NOT terminal here — it marks acceptance-test
 *    sign-off but the formal closure step still follows; Closed signals that
 *    all change-management obligations have been recorded and archived)
 */
final class ChangeRequestAdapter implements SourceAdapter
{
    public function __construct(
        private readonly ChangeRequestRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'change_request';
    }

    public function label(): string
    {
        return 'Change Request';
    }

    public function requiredModule(): string
    {
        return 'change_requests';
    }

    /** @return iterable<ChangeRequest> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy(['tenant' => $tenant]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ChangeRequest);
        return $item->getPlannedImplementationDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ChangeRequest);
        return $item->getTitle() ?? '#' . $item->getId();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ChangeRequest);
        return in_array($item->getStatusEnum(), [
            ChangeRequestStatus::Closed,
            ChangeRequestStatus::Cancelled,
            ChangeRequestStatus::Rejected,
        ], true);
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof ChangeRequest);
        return (int) $item->getId();
    }

    /** @return iterable<ChangeRequest> */
    public function findOpen(Tenant $tenant): iterable
    {
        foreach ($this->findConvertible($tenant) as $item) {
            // No date, no duty to remind of; requests still waiting for approval
            // are the approvers' decision (ChangeApprovalSource), not the implementer's.
            if ($this->dueDateOf($item) !== null
                && !in_array($item->getStatus(), ['submitted', 'under_review'], true)) {
                yield $item;
            }
        }
    }

    public function supports(object $item): bool
    {
        return $item instanceof ChangeRequest;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof ChangeRequest);

        // The approver who released the change answers for its implementation (P5).
        return Responsibility::holders($item->getApproverPerson())->orRoles('ROLE_CISO', 'ROLE_ISB');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof ChangeRequest);

        return new RecordLink('app_change_request_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'change_requests_due', leadDays: 14, normReference: 'ISO 27001 6.3 / A.8.32');
    }
}

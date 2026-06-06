<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\ChangeRequest;
use App\Entity\Tenant;
use App\Enum\ChangeRequestStatus;
use App\Repository\ChangeRequestRepository;
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

    public function requiredModule(): ?string
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
}

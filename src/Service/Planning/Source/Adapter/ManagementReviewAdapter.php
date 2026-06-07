<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Enum\ManagementReviewStatus;
use App\Repository\ManagementReviewRepository;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for ManagementReview (ISO 27001 Clause 9.3).
 *
 * Deadline field : reviewDate (the scheduled review date)
 * Terminal statuses: completed
 *   (ManagementReviewStatus has three cases: planned, completed, follow_up_required.
 *    Only "completed" is terminal — "follow_up_required" still demands action.)
 */
final class ManagementReviewAdapter implements SourceAdapter
{
    public function __construct(
        private readonly ManagementReviewRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'management_review';
    }

    public function label(): string
    {
        return 'Management-Review';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return iterable<ManagementReview> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy(['tenant' => $tenant]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ManagementReview);
        return $item->getReviewDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ManagementReview);
        return $item->getTitle() ?? '#' . $item->getId();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ManagementReview);
        return $item->getStatus() === ManagementReviewStatus::Completed->value;
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof ManagementReview);
        return (int) $item->getId();
    }
}

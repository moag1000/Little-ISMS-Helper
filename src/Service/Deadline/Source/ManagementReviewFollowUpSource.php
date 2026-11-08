<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Enum\ManagementReviewStatus;
use App\Repository\ManagementReviewRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The next management review (ISO 27001 9.3: at planned intervals, at least
 * yearly). Once the newest review of the organisation is completed, the next
 * one is due at its `nextReviewDate` — or twelve months after it when none is
 * set — until a later review is planned. Cross-record (newest review only), so
 * a class rather than an entry in config/deadline_types.yaml; the planned review
 * itself is {@see \App\Service\Planning\Source\Adapter\ManagementReviewAdapter}.
 */
final class ManagementReviewFollowUpSource implements DeadlineSource
{
    public function __construct(
        private readonly ManagementReviewRepository $reviews,
    ) {
    }

    public function slug(): string
    {
        return 'management_review_follow_up';
    }

    public function label(): string
    {
        return 'Nächste Managementbewertung planen';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return list<ManagementReview> the newest review when it is completed */
    public function findOpen(Tenant $tenant): iterable
    {
        $latest = $this->reviews->findLatestForTenant($tenant);

        return $latest instanceof ManagementReview && !$this->isCompleted($latest) ? [$latest] : [];
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ManagementReview);
        if ($item->getNextReviewDate() !== null) {
            return $item->getNextReviewDate();
        }
        $held = $item->getReviewDate();

        return $held === null ? null : DateTimeImmutable::createFromInterface($held)->modify('+12 months');
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ManagementReview);

        return $item->getTitle() ?? '#' . $item->getId();
    }

    /** A review that is not completed is still the open one: no follow-up yet. */
    public function isCompleted(object $item): bool
    {
        assert($item instanceof ManagementReview);

        return $item->getStatus() !== ManagementReviewStatus::Completed->value;
    }

    public function refId(object $item): int
    {
        assert($item instanceof ManagementReview);

        return (int) $item->getId();
    }

    public function supports(object $item): bool
    {
        return $item instanceof ManagementReview && $item->getStatus() === ManagementReviewStatus::Completed->value;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof ManagementReview);

        // The ISB prepares the review; top management holds it (9.3.1) and is told.
        return Responsibility::holders($item->getReviewedBy(), $item->getReviewedByPerson())
            ->orRoles('ROLE_ISB', 'ROLE_CISO')
            ->alsoInform('ROLE_TOP_MGMT');
    }

    public function linkOf(object $item): RecordLink
    {
        return new RecordLink('app_management_review_index', []);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'management_review_upcoming', leadDays: 60, normReference: 'ISO 27001 9.3');
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Risk;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Risiko-Review: getReviewDate() of Risk. */
final class RiskReviewSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return Risk::class;
    }

    public function slug(): string
    {
        return 'risk_review';
    }

    public function label(): string
    {
        return 'Risiko-Review';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Risk);

        return $item->getReviewDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Risk);

        return (string) ($item->getTitle() ?? 'Risk #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Risk);

        // One review rule for the whole app: every risk except a closed one (ISO 27001 8.2).
        return !($item->getStatus()?->requiresPeriodicReview() ?? true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof Risk);

        return Responsibility::holders($item->getRiskOwner(), $item->getRiskOwnerPerson())
            ->withDeputies(...$item->getRiskOwnerDeputyPersons())
            ->orRoles('ROLE_RISK_MANAGER');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof Risk);

        return new RecordLink('app_risk_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'risk_review_overdue', leadDays: 14, normReference: 'ISO 27001 6.1.3 / 8.2');
    }
}

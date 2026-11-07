<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\CorrectiveAction;
use App\Entity\Tenant;
use App\Repository\CorrectiveActionRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for CorrectiveAction (ISO 27001 Clause 10.1 CAPA).
 *
 * Deadline field : plannedCompletionDate
 * Terminal statuses: verified, verified_effective, verified_ineffective
 *   (status=completed is intentionally NOT terminal — it still awaits
 *    the ISO 27001 Cl. 10.1 d effectiveness review step)
 */
final class CorrectiveActionAdapter implements SourceAdapter
{
    public function __construct(
        private readonly CorrectiveActionRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'corrective_action';
    }

    public function label(): string
    {
        return 'Korrekturmaßnahme';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return iterable<CorrectiveAction> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy(['tenant' => $tenant]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof CorrectiveAction);
        return $item->getPlannedCompletionDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof CorrectiveAction);
        return $item->getTitle() ?? '#' . $item->getId();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof CorrectiveAction);
        return in_array($item->getStatus(), [
            CorrectiveAction::STATUS_VERIFIED,
            CorrectiveAction::STATUS_VERIFIED_EFFECTIVE,
            CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE,
        ], true);
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof CorrectiveAction);
        return (int) $item->getId();
    }

    /** @return iterable<CorrectiveAction> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $this->findConvertible($tenant);
    }

    public function supports(object $item): bool
    {
        return $item instanceof CorrectiveAction;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof CorrectiveAction);

        return Responsibility::holders($item->getResponsiblePersonUser(), $item->getResponsiblePerson())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): ?RecordLink
    {
        assert($item instanceof CorrectiveAction);

        return new RecordLink('app_corrective_action_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'corrective_actions_overdue', leadDays: 7, normReference: 'ISO 27001 10.2');
    }
}

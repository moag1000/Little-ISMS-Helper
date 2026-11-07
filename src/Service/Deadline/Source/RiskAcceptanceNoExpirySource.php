<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * Accepted risks without an acceptance expiry date: an accepted residual risk
 * needs a review trigger (ISO 27001 6.1.3 f). Undated — shown while open.
 */
final class RiskAcceptanceNoExpirySource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return Risk::class;
    }

    public function slug(): string
    {
        return 'risk_acceptance_no_expiry';
    }

    public function label(): string
    {
        return 'Risikoakzeptanz ohne Ablaufdatum';
    }

    public function findOpen(Tenant $tenant): iterable
    {
        foreach ($this->entityManager->getRepository(Risk::class)->findBy(['tenant' => $tenant]) as $risk) {
            if (!$this->isCompleted($risk)) {
                yield $risk;
            }
        }
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        return null;
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Risk);

        return (string) ($item->getTitle() ?? 'Risk #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Risk);
        $accepted = $item->getTreatmentStrategy() === TreatmentStrategy::Accept
            || $item->getStatus() === RiskStatus::Accepted;

        return !$accepted || $item->getStatus() === RiskStatus::Closed || $item->getAcceptanceExpiryDate() !== null;
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
        return new DeadlineProfile(bucket: 'risk_acceptance_no_expiry', leadDays: null, normReference: 'ISO 27001 6.1.3 f');
    }
}

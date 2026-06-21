<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\RiskStatus;
use App\Repository\RiskRepository;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for Risk acceptance expiry (ISO 27001 Cl. 8.3 / ISO 27005).
 *
 * Deadline field : acceptanceExpiryDate — the date until which the risk
 *                  acceptance is formally valid. After this date the acceptance
 *                  must be re-evaluated by the risk owner.
 * Convertible  : risks with treatmentStrategy = Accept AND acceptanceExpiryDate set
 * Terminal      : status in [Closed, Monitored] — risks that are no longer in
 *                 an active accept cycle do not need a planning action.
 */
final class RiskTreatmentAdapter implements SourceAdapter
{
    public function __construct(
        private readonly RiskRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'risk_treatment';
    }

    public function label(): string
    {
        return 'Risikoakzeptanz (Ablauf)';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return iterable<Risk> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findConvertibleForTenant($tenant);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Risk);
        return $item->getAcceptanceExpiryDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Risk);
        return $item->getTitle() ?? '#' . $item->getId();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Risk);
        return in_array($item->getStatus(), [
            RiskStatus::Closed,
            RiskStatus::Monitored,
        ], true);
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof Risk);
        return (int) $item->getId();
    }
}

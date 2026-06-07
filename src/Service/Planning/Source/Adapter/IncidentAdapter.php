<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Enum\IncidentStatus;
use App\Repository\IncidentRepository;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for Incident (ISO 27001 A.5.24–A.5.28, NIS2 Art. 23).
 *
 * Deadline field : detectedAt (the timestamp when the incident was first
 *                  observed — the most stable SLA anchor per ISO 27001 A.5.26 /
 *                  NIS2 Art. 23 72h window).
 * Terminal statuses: Resolved, Closed
 *   (InResolution is intentionally NOT terminal — the incident is still
 *    being worked; only Resolved + Closed signal that all immediate
 *    response obligations have been met)
 */
final class IncidentAdapter implements SourceAdapter
{
    public function __construct(
        private readonly IncidentRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'incident';
    }

    public function label(): string
    {
        return 'Sicherheitsvorfall';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return iterable<Incident> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy(['tenant' => $tenant]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Incident);
        return $item->getDetectedAt();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Incident);
        return $item->getTitle() ?? '#' . $item->getId();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Incident);
        return in_array($item->getStatus(), [
            IncidentStatus::Resolved,
            IncidentStatus::Closed,
        ], true);
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof Incident);
        return (int) $item->getId();
    }
}

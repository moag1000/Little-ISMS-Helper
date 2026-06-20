<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\Tenant;
use App\Entity\TrainingParticipation;
use App\Repository\TrainingParticipationRepository;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for Training Participations (ISO 27001 §7.3 — Awareness).
 *
 * Deadline field : assignedAt — the timestamp when the mandatory training was
 *                  assigned (best available SLA anchor; no explicit deadline field).
 * Terminal statuses: completed, failed, waived
 *   (pending and in_progress still require the user to complete the training)
 *
 * ownsRecurrence = true because mandatory trainings are scheduled at the
 * training level and re-assigned periodically by the auto-reaction listener —
 * the source owns the recurrence pattern.
 */
final class TrainingParticipationAdapter implements SourceAdapter
{
    public function __construct(
        private readonly TrainingParticipationRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'training';
    }

    public function label(): string
    {
        return 'Pflichtschulung';
    }

    public function requiredModule(): string
    {
        return 'training';
    }

    /** @return iterable<TrainingParticipation> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findBy(['tenant' => $tenant]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof TrainingParticipation);
        return $item->getAssignedAt();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof TrainingParticipation);
        return $item->getTraining()?->getTitle() ?? 'Training';
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof TrainingParticipation);
        return in_array($item->getStatus(), [
            TrainingParticipation::STATUS_COMPLETED,
            TrainingParticipation::STATUS_FAILED,
            TrainingParticipation::STATUS_WAIVED,
        ], true);
    }

    public function ownsRecurrence(): bool
    {
        return true;
    }

    public function refId(object $item): int
    {
        assert($item instanceof TrainingParticipation);
        return (int) $item->getId();
    }
}

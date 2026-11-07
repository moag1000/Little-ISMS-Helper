<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\EvidenceReverificationTask;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Nachweis-Reverifizierung: getDueDate() of EvidenceReverificationTask. */
final class EvidenceReverificationSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return EvidenceReverificationTask::class;
    }

    public function slug(): string
    {
        return 'evidence_reverification';
    }

    public function label(): string
    {
        return 'Nachweis-Reverifizierung';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof EvidenceReverificationTask);

        return $item->getDueDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof EvidenceReverificationTask);

        return $this->evidenceTitle($item);
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof EvidenceReverificationTask);

        return $item->getCompletedAt() !== null;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof EvidenceReverificationTask);

        return Responsibility::holders($item->getAssignedTo())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof EvidenceReverificationTask);

        return new RecordLink('app_evidence_reverification_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'evidence_reverification_due', leadDays: 14, normReference: 'ISO 27001 7.5.3');
    }

    private function evidenceTitle(EvidenceReverificationTask $item): string
    {
        return 'Evidence re-verification #' . $item->getId();
    }
}

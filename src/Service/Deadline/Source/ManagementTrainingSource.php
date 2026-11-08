<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Tenant;
use App\Repository\TrainingParticipationRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use App\Service\Responsibility\ResponsibilityResolver;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Leitungsschulung (NIS-2 Art. 20 (2), BSIG § 38 (3)): every member of top
 * management (ROLE_TOP_MGMT) completes a cybersecurity training at least once
 * a year. One item per person: due a year after their last completed training,
 * at once without one. The person is reminded, the ISB and CISO are informed.
 * Only for organisations classified under NIS-2.
 */
final class ManagementTrainingSource implements DeadlineSource
{
    public const int CADENCE_MONTHS = 12;

    public function __construct(
        private readonly ResponsibilityResolver $responsibility,
        private readonly TrainingParticipationRepository $participations,
    ) {
    }

    public function slug(): string
    {
        return 'nis2_management_training';
    }

    public function label(): string
    {
        return 'Leitungsschulung NIS-2';
    }

    public function requiredModule(): string
    {
        return 'training';
    }

    /** @return list<ManagementTrainee> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $tenant->isNis2Regulated() ? $this->trainees($tenant) : [];
    }

    /**
     * Every member of top management with their last training of the past
     * cadence; also what the compliance check of Art. 20 (2) reads.
     *
     * @return list<ManagementTrainee>
     */
    public function trainees(Tenant $tenant): array
    {
        $board = $this->responsibility->usersHolding($tenant, 'ROLE_TOP_MGMT');
        $now = new DateTimeImmutable('today');
        $last = $this->participations->lastCompletionByUser(
            $tenant,
            $board,
            $now->modify(sprintf('-%d months', self::CADENCE_MONTHS)),
        );

        $trainees = [];
        foreach ($board as $user) {
            $completed = $last[(int) $user->getId()] ?? null;
            $trainees[] = new ManagementTrainee(
                $user,
                $completed,
                $completed?->modify(sprintf('+%d months', self::CADENCE_MONTHS)) ?? $now,
            );
        }

        return $trainees;
    }

    public function dueDateOf(object $item): DateTimeInterface
    {
        assert($item instanceof ManagementTrainee);

        return $item->dueAt;
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ManagementTrainee);

        $name = trim($item->user->getFullName());

        return $name !== '' ? $name : (string) $item->user->getEmail();
    }

    public function isCompleted(object $item): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof ManagementTrainee);

        return (int) $item->user->getId();
    }

    public function supports(object $item): bool
    {
        return $item instanceof ManagementTrainee;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof ManagementTrainee);

        return Responsibility::holders($item->user)->alsoInform('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        return new RecordLink('app_training_index', []);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'management_training_due', leadDays: 30, normReference: 'NIS-2 Art. 20 (2) / BSIG § 38 (3)');
    }
}

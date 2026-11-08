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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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

    /**
     * Lazy: the resolver itself collects all deadline sources, this one included
     * (an eager reference would recurse while the container builds them).
     */
    public function __construct(
        #[Autowire(lazy: true)]
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

    /**
     * Members whose training falls due within the reminder's lead time: the
     * participants "Leitungsschulung zuweisen" prefills, and whether the
     * training list offers that button at all.
     *
     * @return list<ManagementTrainee>
     */
    public function dueSoon(Tenant $tenant): array
    {
        $horizon = new DateTimeImmutable(sprintf('+%d days', $this->profile()->leadDays ?? 30));

        return array_values(array_filter($this->trainees($tenant), static fn (ManagementTrainee $t): bool => $t->dueAt <= $horizon));
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

    /** The trainings this member takes part in; managers plan one from there ("Leitungsschulung anlegen"). */
    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof ManagementTrainee);

        return new RecordLink('app_training_index', ['participant' => (int) $item->user->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'management_training_due', leadDays: 30, normReference: 'NIS-2 Art. 20 (2) / BSIG § 38 (3)');
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\User;
use DateTimeImmutable;

/** A member of top management and when their next NIS-2 training is due. */
final readonly class ManagementTrainee
{
    public function __construct(
        public User $user,
        public ?DateTimeImmutable $lastCompleted,
        public DateTimeImmutable $dueAt,
    ) {
    }
}

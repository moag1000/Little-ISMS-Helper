<?php

declare(strict_types=1);

namespace App\Service\ManagementDecision;

use App\Entity\ManagementDecision;
use App\Enum\ManagementDecisionType;
use DateTimeImmutable;

/**
 * A standing approval top management owes: missing ($approval null) or
 * running out ($approval with its end date).
 */
final readonly class StandingApproval
{
    public function __construct(
        public ManagementDecisionType $type,
        public ?ManagementDecision $approval = null,
    ) {
    }

    public function endsAt(): ?DateTimeImmutable
    {
        return $this->approval?->getValidUntil();
    }
}

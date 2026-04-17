<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\User;
use Symfony\Contracts\EventDispatcher\Event;

final class ComplianceRequirementFulfillmentUpdatedEvent extends Event
{
    public function __construct(
        public readonly ComplianceRequirementFulfillment $fulfillment,
        public readonly int $previousPercentage,
        public readonly int $currentPercentage,
        public readonly ?User $updatedBy,
    ) {
    }

    public function hasSignificantChange(int $threshold = 5): bool
    {
        return abs($this->currentPercentage - $this->previousPercentage) >= $threshold;
    }
}

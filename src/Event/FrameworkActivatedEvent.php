<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Entity\User;
use Symfony\Contracts\EventDispatcher\Event;

final class FrameworkActivatedEvent extends Event
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly ComplianceFramework $framework,
        public readonly User $activatedBy,
    ) {
    }
}

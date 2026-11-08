<?php

declare(strict_types=1);

namespace App\Service\ManagementDecision;

use App\Entity\Tenant;

/** An organisation in which nobody holds ROLE_TOP_MGMT (duty of {@see BoardUnassignedSource}). */
final readonly class BoardUnassigned
{
    public function __construct(
        public Tenant $tenant,
    ) {
    }
}

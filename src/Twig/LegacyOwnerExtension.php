<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\DataRepair\LegacyOwnerReport;
use App\Service\TenantContext;
use Twig\Attribute\AsTwigFunction;

/**
 * `legacy_owner_open_count()` — records of the admin's organisation tree whose
 * owner is only legacy free text. Feeds the tile on the data repair page;
 * plain COUNT queries, no records loaded.
 */
final class LegacyOwnerExtension
{
    private ?int $count = null;

    public function __construct(
        private readonly LegacyOwnerReport $report,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[AsTwigFunction('legacy_owner_open_count')]
    public function openCount(): int
    {
        return $this->count ??= array_sum($this->report->countOpen(array_values($this->tenantContext->getAccessibleTenants())));
    }
}

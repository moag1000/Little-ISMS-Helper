<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Tenant;
use App\Service\TenantContext;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig Extension for DORA (Digital Operational Resilience Act) helpers.
 *
 * Available Twig Functions:
 * - is_dora_obligated(): Returns true when the current tenant is subject to DORA
 *                        (doraEntityCategory !== 'none'). Use to gate DORA-specific
 *                        UI sections, nav entries, KPI tiles, and route access.
 */
final class DoraExtension
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Returns true when the current tenant has a DORA obligation
     * (i.e. doraEntityCategory is 'financial_entity' or 'critical_ict_third_party').
     *
     * Returns false when no tenant context is available (safe default).
     */
    #[AsTwigFunction('is_dora_obligated')]
    public function isDoraObligated(): bool
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            return false;
        }

        return $tenant->isDoraObligated();
    }
}

<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Supplier;
use App\Service\Supplier\SupplierCriticalityLabels;
use App\Service\TenantContext;
use Twig\Attribute\AsTwigFunction;

/**
 * Supplier presentation helpers: the criticality pill shows the organisation's level label
 * (DE/EN) and the colour the admin picked (Admin > Lieferanten-Kritikalität).
 */
final class SupplierExtension
{
    public function __construct(
        private readonly SupplierCriticalityLabels $labels,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * The organisation's levels as label => code (list filter).
     *
     * @return array<string, string>
     */
    #[AsTwigFunction('supplier_criticality_choices')]
    public function criticalityChoices(): array
    {
        return $this->labels->choices($this->tenantContext->getCurrentTenant());
    }

    /** @return array{label: string, tone: string} */
    #[AsTwigFunction('supplier_criticality')]
    public function criticality(Supplier $supplier): array
    {
        return $this->labels->describe($supplier->getCriticality(), $supplier->getTenant());
    }
}

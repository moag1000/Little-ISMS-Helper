<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Tenant;
use App\Entity\TenantBranding;
use App\Repository\TenantBrandingRepository;
use App\Service\TenantContext;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The current organisation's branding row for report templates: `_fa_report_doc` reads the
 * report style (Admin > Report-Stil) from it when the caller passes none.
 */
final class TenantBrandingExtension implements ResetInterface
{
    /** @var array<int, TenantBranding|null> */
    private array $cache = [];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantBrandingRepository $repository,
    ) {
    }

    #[AsTwigFunction('tenant_branding')]
    public function current(): ?TenantBranding
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant || $tenant->getId() === null) {
            return null;
        }
        $id = $tenant->getId();
        if (!array_key_exists($id, $this->cache)) {
            $this->cache[$id] = $this->repository->findOneByTenant($tenant);
        }

        return $this->cache[$id];
    }

    public function reset(): void
    {
        $this->cache = [];
    }
}

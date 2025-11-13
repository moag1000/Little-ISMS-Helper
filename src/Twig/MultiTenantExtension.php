<?php

namespace App\Twig;

use App\Service\MultiTenantCheckService;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class MultiTenantExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly MultiTenantCheckService $multiTenantCheckService
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'is_multi_tenant_environment' => $this->multiTenantCheckService->isMultiTenant(),
        ];
    }
}

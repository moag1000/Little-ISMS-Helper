<?php

namespace App\Service;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\ConnectionException;
use Exception;
use App\Repository\TenantRepository;

/**
 * Service to check if multi-tenant mode is active
 * Corporate structure features should only be visible when multiple tenants exist
 */
class MultiTenantCheckService
{
    private ?int $cachedCount = null;
    private ?bool $cachedIsMultiTenant = null;

    public function __construct(
        private readonly TenantRepository $tenantRepository
    ) {
    }

    /**
     * Check if system has multiple active tenants
     */
    public function isMultiTenant(): bool
    {
        if ($this->cachedIsMultiTenant !== null) {
            return $this->cachedIsMultiTenant;
        }

        $this->cachedIsMultiTenant = $this->getActiveTenantCount() > 1;
        return $this->cachedIsMultiTenant;
    }

    /**
     * Get number of active tenants
     */
    public function getActiveTenantCount(): int
    {
        if ($this->cachedCount !== null) {
            return $this->cachedCount;
        }

        try {
            $this->cachedCount = $this->tenantRepository->count(['isActive' => true]);
        } catch (TableNotFoundException) {
            // Database tables not yet created (setup wizard not completed)
            $this->cachedCount = 0;
        } catch (ConnectionException) {
            // Database connection not available
            $this->cachedCount = 0;
        } catch (Exception) {
            // Any other database error during setup
            $this->cachedCount = 0;
        }

        return $this->cachedCount;
    }

    /**
     * Check if corporate structure features should be shown
     */
    public function showCorporateFeatures(): bool
    {
        return $this->isMultiTenant();
    }

    /**
     * Clear cache (call after creating/deleting/activating/deactivating tenants)
     */
    public function clearCache(): void
    {
        $this->cachedCount = null;
        $this->cachedIsMultiTenant = null;
    }

    /**
     * Get message for why corporate features are hidden
     */
    public function getHiddenReason(): string
    {
        $count = $this->getActiveTenantCount();

        if ($count === 0) {
            return 'No active tenants exist';
        }

        if ($count === 1) {
            return 'Corporate structure features are only available when multiple tenants exist';
        }

        return '';
    }
}

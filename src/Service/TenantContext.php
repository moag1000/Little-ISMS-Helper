<?php

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Tenant Context Service
 *
 * Manages the current tenant context for multi-tenancy support.
 * Automatically determines the active tenant based on the logged-in user.
 */
class TenantContext
{
    private ?Tenant $currentTenant = null;
    private bool $initialized = false;

    public function __construct(
        private readonly Security $security,
        private readonly TenantRepository $tenantRepository
    ) {
    }

    /**
     * Get the current tenant
     */
    public function getCurrentTenant(): ?Tenant
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return $this->currentTenant;
    }

    /**
     * Set the current tenant (useful for tenant switching)
     */
    public function setCurrentTenant(?Tenant $tenant): void
    {
        $this->currentTenant = $tenant;
        $this->initialized = true;
    }

    /**
     * Get the current tenant ID
     */
    public function getCurrentTenantId(): ?int
    {
        $tenant = $this->getCurrentTenant();
        return $tenant?->getId();
    }

    /**
     * Check if a tenant context is active
     */
    public function hasTenant(): bool
    {
        return $this->getCurrentTenant() !== null;
    }

    /**
     * Check if the current user belongs to a specific tenant
     */
    public function belongsToTenant(Tenant $tenant): bool
    {
        $currentTenant = $this->getCurrentTenant();
        return $currentTenant && $currentTenant->getId() === $tenant->getId();
    }

    /**
     * Get all active tenants
     */
    public function getActiveTenants(): array
    {
        return $this->tenantRepository->findBy(['isActive' => true], ['name' => 'ASC']);
    }

    /**
     * Initialize the tenant context from the current user
     */
    private function initialize(): void
    {
        $this->initialized = true;

        /** @var User|null $user */
        $user = $this->security->getUser();

        if (!$user) {
            $this->currentTenant = null;
            return;
        }

        $this->currentTenant = $user->getTenant();
    }

    /**
     * Reset the tenant context (useful for testing)
     */
    public function reset(): void
    {
        $this->currentTenant = null;
        $this->initialized = false;
    }
}

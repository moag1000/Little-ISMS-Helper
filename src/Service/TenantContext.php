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
        return $this->getCurrentTenant() instanceof Tenant;
    }

    /**
     * Check if the current user belongs to a specific tenant
     */
    public function belongsToTenant(Tenant $tenant): bool
    {
        $currentTenant = $this->getCurrentTenant();
        return $currentTenant instanceof Tenant && $currentTenant->getId() === $tenant->getId();
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
     * Falls back to default tenant if user has no tenant assigned
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

        // Use user's tenant if available
        $this->currentTenant = $user->getTenant();

        // Fallback if user has no tenant assigned
        if ($this->currentTenant === null) {
            $this->currentTenant = $this->resolveDefaultTenant();
        }
    }

    /**
     * Resolve default tenant when user has none assigned
     * Priority: 1) Only one tenant exists -> use it
     *           2) Multiple tenants -> use root tenant (no parent)
     *           3) Multiple roots -> use oldest one
     *           4) No tenants -> return null
     */
    private function resolveDefaultTenant(): ?Tenant
    {
        $activeTenants = $this->tenantRepository->findBy(['isActive' => true]);

        if (count($activeTenants) === 0) {
            return null;
        }

        if (count($activeTenants) === 1) {
            return $activeTenants[0];
        }

        // Find root tenants (no parent)
        $rootTenants = array_filter($activeTenants, fn(Tenant $tenant): bool => !$tenant->getParent() instanceof Tenant);

        if (count($rootTenants) === 1) {
            return reset($rootTenants);
        }

        if (count($rootTenants) > 1) {
            // Sort by createdAt ascending (oldest first)
            usort($rootTenants, fn(Tenant $a, Tenant $b): int =>
                $a->getCreatedAt() <=> $b->getCreatedAt()
            );
            return $rootTenants[0];
        }

        // No root tenants found, return oldest tenant
        usort($activeTenants, fn(Tenant $a, Tenant $b): int =>
            $a->getCreatedAt() <=> $b->getCreatedAt()
        );
        return $activeTenants[0];
    }

    /**
     * Reset the tenant context (useful for testing)
     */
    public function reset(): void
    {
        $this->currentTenant = null;
        $this->initialized = false;
    }

    /**
     * Tenants the current user should be able to read across.
     *
     * For a regular tenant user this is just their own tenant. For a
     * Holding-Tenant (isCorporateParent = true), the caller gets the
     * full subtree — intended for Group-CISO style read-only roll-ups
     * (Phase 9.P1.6). Authorization enforcement itself lives in the
     * voter layer; this service only exposes the topology.
     *
     * @return Tenant[]
     */
    public function getAccessibleTenants(): array
    {
        $tenant = $this->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        $accessible = [$tenant];
        foreach ($tenant->getAllSubsidiaries() as $subsidiary) {
            $accessible[] = $subsidiary;
        }
        return $accessible;
    }

    /**
     * Root tenant of the current user's corporate tree (or the tenant
     * itself when it has no parent).
     */
    public function getCurrentRoot(): ?Tenant
    {
        return $this->getCurrentTenant()?->getRootParent();
    }

    /**
     * True when $candidate is the current tenant or one of its descendants.
     * Use from voters / controllers before dereferencing cross-tenant
     * relationships.
     */
    public function canAccessTenant(Tenant $candidate): bool
    {
        $current = $this->getCurrentTenant();
        if (!$current instanceof Tenant) {
            return false;
        }
        if ($current === $candidate) {
            return true;
        }
        if ($current->getId() !== null && $current->getId() === $candidate->getId()) {
            return true;
        }
        return $candidate->isChildOf($current);
    }
}

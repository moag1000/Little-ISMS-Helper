<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

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
        private readonly TenantRepository $tenantRepository,
        private readonly EntityManagerInterface $entityManager,
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

        // Re-initialize if we cached "no tenant" but a user is now logged in.
        // Tests with `disableReboot()` boot the container BEFORE login_user; the
        // first call to initialize() runs with security->getUser()===null and
        // memoizes currentTenant=null. Without this re-check the controller
        // would 403 every multi-tenant route in that flow.
        if ($this->currentTenant === null && $this->security->getUser() !== null) {
            $this->initialize();
        }

        // Re-fetch if cached tenant became detached from EM (e.g. after EM clear
        // between requests in long-running processes / tests with disableReboot).
        if ($this->currentTenant !== null
            && $this->currentTenant->getId() !== null
            && !$this->entityManager->contains($this->currentTenant)
        ) {
            $this->currentTenant = $this->tenantRepository->find($this->currentTenant->getId())
                ?? $this->currentTenant;
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

        // Use user's tenant if available — re-fetch via repository so we always
        // hold a managed entity (User session-deserialization yields a detached
        // tenant association, which would cascade-persist-fail on flush).
        $userTenant = $user->getTenant();
        if ($userTenant !== null && $userTenant->getId() !== null) {
            $this->currentTenant = $this->tenantRepository->find($userTenant->getId()) ?? $userTenant;
        } else {
            $this->currentTenant = $userTenant;
        }

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

    /**
     * Resolve the tenant scope for an admin operation.
     *
     * Consolidates the inline "SUPER vs ADMIN-with-tenant-check" branches
     * that are duplicated across ~20 admin controllers
     * (see `AdminBackupController::resolveTenantScopeForBackup()` for the
     * reference shape).
     *
     * Behaviour:
     *  - `null`, `''`, `'global'`  → ROLE_SUPER_ADMIN gets `null` (global
     *                                scope); ROLE_ADMIN falls back to their
     *                                own current tenant. Any other caller
     *                                triggers AccessDeniedException.
     *  - Tenant instance           → SUPER_ADMIN: returned as-is.
     *                                ROLE_ADMIN: returned when
     *                                `canAccessTenant()` is true, else
     *                                AccessDeniedException.
     *  - int / numeric-string id   → resolved via TenantRepository; same
     *                                rules as Tenant-instance once resolved.
     *                                Unknown id throws AccessDeniedException
     *                                (treat as cross-tenant attempt).
     *
     * @param Tenant|int|string|null $requested
     *
     * @throws AccessDeniedException on cross-tenant or unauthenticated access
     */
    public function resolveAdminScope(mixed $requested): ?Tenant
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Authenticated user required to resolve admin scope.');
        }

        // Global / no-scope request
        if ($requested === null || $requested === '' || $requested === 'global') {
            if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
                return null;
            }
            if ($this->security->isGranted('ROLE_ADMIN')) {
                $current = $this->getCurrentTenant();
                if (!$current instanceof Tenant) {
                    throw new AccessDeniedException(
                        'No active tenant context for admin scope fallback.'
                    );
                }
                return $current;
            }
            throw new AccessDeniedException('Role is not permitted to request global scope.');
        }

        // Specific tenant requested — resolve to a Tenant instance
        $tenant = $this->coerceToTenant($requested);
        if (!$tenant instanceof Tenant) {
            throw new AccessDeniedException('Requested tenant could not be resolved.');
        }

        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $tenant;
        }
        if ($this->security->isGranted('ROLE_ADMIN') && $this->canAccessTenant($tenant)) {
            return $tenant;
        }

        throw new AccessDeniedException('Cross-tenant admin operation is not permitted.');
    }

    /**
     * True when the current user may administer $target.
     *
     * - ROLE_SUPER_ADMIN: always true.
     * - ROLE_ADMIN: true only when $target is within
     *   {@see getAccessibleTenants()} (own tenant + descendants).
     * - All other roles: false.
     *
     * `$target = null` means "no specific tenant" — true for SUPER_ADMIN
     * and for ROLE_ADMIN when an active tenant context exists.
     */
    public function canAdminister(?Tenant $target): bool
    {
        if (!$this->security->getUser() instanceof User) {
            return false;
        }
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return false;
        }
        if ($target === null) {
            return $this->hasTenant();
        }
        return $this->canAccessTenant($target);
    }

    /**
     * Coerce a Tenant|int|string into a managed Tenant instance.
     */
    private function coerceToTenant(mixed $value): ?Tenant
    {
        if ($value instanceof Tenant) {
            return $value;
        }
        if (is_int($value) && $value > 0) {
            return $this->tenantRepository->find($value);
        }
        if (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;
            return $id > 0 ? $this->tenantRepository->find($id) : null;
        }
        return null;
    }
}

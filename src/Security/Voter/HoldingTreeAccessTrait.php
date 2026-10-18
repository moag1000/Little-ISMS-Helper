<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\User;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Shared tenant-scope guard for voters.
 *
 * The one place that decides whether a user may act on a record that
 * belongs to a tenant. Every tenant-scoped voter goes through
 * {@see decideTenantScope()} before its own role rules:
 *
 *  - ROLE_SUPER_ADMIN is the only instance-wide role → always granted.
 *  - Otherwise the record must be in scope: same tenant (compared by
 *    identity or id), or — for read attributes only — a descendant tenant
 *    when the user holds ROLE_GROUP_CISO or ROLE_KONZERN_AUDITOR
 *    (Phase 9.P1.6 holding read; lateral/sibling access is never granted).
 *  - ROLE_ADMIN administers its own organisation only, so it passes
 *    inside that scope and nowhere else.
 *  - A missing user tenant or record tenant fails closed.
 *
 * Role-hierarchy-aware: roles are expanded via the consumer's injected
 * {@see RoleHierarchyInterface} (exposed through {@see getRoleHierarchy()}).
 */
trait HoldingTreeAccessTrait
{
    public const string ROLE_GROUP_CISO = 'ROLE_GROUP_CISO';
    public const string ROLE_KONZERN_AUDITOR = 'ROLE_KONZERN_AUDITOR';

    /**
     * Consumers must expose their injected RoleHierarchyInterface so the
     * trait can expand the user's roles via Symfony's role_hierarchy.
     */
    abstract protected function getRoleHierarchy(): RoleHierarchyInterface;

    protected function hasReachableRole(User $user, string $role): bool
    {
        return in_array($role, $this->getRoleHierarchy()->getReachableRoleNames($user->getRoles()), true);
    }

    /**
     * Tenant-scope pre-decision for a record owned by $targetTenant.
     *
     * @return bool|null true = grant (SUPER_ADMIN, or ROLE_ADMIN in scope),
     *                   false = deny (out of scope / tenant missing),
     *                   null = in scope, apply the voter's own role rules
     */
    protected function decideTenantScope(User $user, ?Tenant $targetTenant, bool $isRead): ?bool
    {
        if ($this->hasReachableRole($user, 'ROLE_SUPER_ADMIN')) {
            return true;
        }
        if (!$this->isInTenantScope($user, $targetTenant, $isRead)) {
            return false;
        }

        return $this->hasReachableRole($user, 'ROLE_ADMIN') ? true : null;
    }

    /**
     * Same tenant, or (read only) holding-tree descendant. Fails closed.
     */
    protected function isInTenantScope(User $user, ?Tenant $targetTenant, bool $isRead): bool
    {
        return $this->isSameTenant($user, $targetTenant)
            || ($isRead && $this->canReadAcrossHoldingTree($user, $targetTenant));
    }

    protected function isSameTenant(User $user, ?Tenant $targetTenant): bool
    {
        $userTenant = $user->getTenant();
        if (!$targetTenant instanceof Tenant || !$userTenant instanceof Tenant) {
            return false;
        }
        if ($targetTenant === $userTenant) {
            return true;
        }

        return $userTenant->getId() !== null && $targetTenant->getId() === $userTenant->getId();
    }

    /**
     * Holding read: ROLE_GROUP_CISO / ROLE_KONZERN_AUDITOR may read their
     * own tenant and every descendant tenant — never write.
     */
    protected function canReadAcrossHoldingTree(User $user, ?Tenant $targetTenant): bool
    {
        if (!$targetTenant instanceof Tenant) {
            return false;
        }
        $userTenant = $user->getTenant();
        if (!$userTenant instanceof Tenant) {
            return false;
        }
        if (!$this->hasReachableRole($user, self::ROLE_GROUP_CISO)
            && !$this->hasReachableRole($user, self::ROLE_KONZERN_AUDITOR)) {
            return false;
        }

        return $this->isSameTenant($user, $targetTenant) || $targetTenant->isChildOf($userTenant);
    }
}

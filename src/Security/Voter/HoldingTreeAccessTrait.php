<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\User;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Phase 9.P1.6 — Konzern-ISB / Group-CISO read-across-holding-tree.
 *
 * A user with ROLE_GROUP_CISO (and only for read attributes — never edit
 * or delete) may look into any tenant that lives below their own tenant
 * in the corporate hierarchy. Lateral access (siblings) is not granted;
 * only descendants of the user's tenant count. The role is therefore a
 * modifier on top of the normal role chain: ROLE_GROUP_CISO alone does
 * not grant write on the user's own tenant — that still comes from
 * ROLE_MANAGER / ROLE_ADMIN.
 *
 * Role-hierarchy-aware: the trait calls {@see getRoleHierarchy()} on the
 * consumer to expand `User::getRoles()` into all reachable roles before
 * matching ROLE_GROUP_CISO. This ensures a user holding only
 * ROLE_SUPER_ADMIN (which inherits ROLE_GROUP_CISO via security.yaml)
 * passes the holding-tree check too. Consumers MUST inject a
 * {@see RoleHierarchyInterface} and implement {@see getRoleHierarchy()}.
 */
trait HoldingTreeAccessTrait
{
    public const string ROLE_GROUP_CISO = 'ROLE_GROUP_CISO';

    /**
     * Consumers must expose their injected RoleHierarchyInterface so the
     * trait can expand the user's roles via Symfony's role_hierarchy.
     */
    abstract protected function getRoleHierarchy(): RoleHierarchyInterface;

    protected function canReadAcrossHoldingTree(User $user, ?Tenant $targetTenant): bool
    {
        if (!$targetTenant instanceof Tenant) {
            return false;
        }
        $userTenant = $user->getTenant();
        if (!$userTenant instanceof Tenant) {
            return false;
        }
        $reachable = $this->getRoleHierarchy()->getReachableRoleNames($user->getRoles());
        if (!in_array(self::ROLE_GROUP_CISO, $reachable, true)) {
            return false;
        }
        if ($targetTenant === $userTenant) {
            return true;
        }
        if ($userTenant->getId() !== null && $targetTenant->getId() === $userTenant->getId()) {
            return true;
        }
        return $targetTenant->isChildOf($userTenant);
    }
}

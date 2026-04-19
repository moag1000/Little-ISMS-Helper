<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\User;

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
 * Pragmatic shape: no service DI so the trait can be mixed into the
 * existing voters (which don't take constructor args) without changing
 * their signatures.
 */
trait HoldingTreeAccessTrait
{
    public const string ROLE_GROUP_CISO = 'ROLE_GROUP_CISO';

    protected function canReadAcrossHoldingTree(User $user, ?Tenant $targetTenant): bool
    {
        if (!$targetTenant instanceof Tenant) {
            return false;
        }
        $userTenant = $user->getTenant();
        if (!$userTenant instanceof Tenant) {
            return false;
        }
        if (!in_array(self::ROLE_GROUP_CISO, $user->getRoles(), true)) {
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

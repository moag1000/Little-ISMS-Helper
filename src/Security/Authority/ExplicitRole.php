<?php

declare(strict_types=1);

namespace App\Security\Authority;

use App\Entity\User;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * S27: "does this person hold the role for THIS decision?" — the role
 * hierarchy answers "may read like X", which is too wide for some decisions:
 *
 * - audit work (verdicts, audit report, verifying findings and corrective
 *   actions, AUDITOR approval steps) needs ROLE_AUDITOR reached through an
 *   auditor or manager role, not through the read-only reviewer roles
 *   ({@see self::REVIEWER_ONLY}: top management, group CISO, group auditor);
 * - a step of the data protection officer or of top management needs the
 *   assigned role, not the one ROLE_ADMIN inherits ({@see self::ADMIN}).
 *
 * The check drops the excluded roles from the person's assigned roles and
 * resolves the rest through the hierarchy, so a person who holds the role
 * directly (or through another path) still passes.
 */
final class ExplicitRole
{
    /** Read-only reviewer roles: they reach ROLE_AUDITOR for reading, never for audit work. */
    public const array REVIEWER_ONLY = ['ROLE_TOP_MGMT', 'ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR'];

    /** Administration roles: they inherit ROLE_DPO / ROLE_CISO … for operating the tool, not for the decision. */
    public const array ADMIN = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    /** Step roles whose decision needs the assigned role (no admin path, no admin override). */
    public const array ASSIGNED_ONLY_STEP_ROLES = ['ROLE_TOP_MGMT', 'ROLE_DPO'];

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * @param list<string>|User $assigned the assigned roles (token role names or the user)
     * @param list<string>      $notVia   roles whose inheritance does not count
     */
    public function reaches(array|User $assigned, string $role, array $notVia = []): bool
    {
        $roles = $assigned instanceof User ? $assigned->getRoles() : $assigned;
        $roots = array_values(array_diff($roles, $notVia));

        return in_array($role, $this->roleHierarchy->getReachableRoleNames($roots), true);
    }

    /** Audit authority by role: ROLE_AUDITOR reached through an auditor or manager role. */
    public function doesAuditWork(array|User $assigned): bool
    {
        return $this->reaches($assigned, 'ROLE_AUDITOR', self::REVIEWER_ONLY);
    }

    /** The step role held as assigned role — ROLE_ADMIN's inheritance does not count. */
    public function holdsAssigned(array|User $assigned, string $role): bool
    {
        return $this->reaches($assigned, $role, self::ADMIN);
    }
}

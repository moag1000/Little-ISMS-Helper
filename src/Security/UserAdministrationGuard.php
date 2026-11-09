<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Server-side guard for user administration (security hotfix, admin scope).
 *
 * Single source of truth for:
 *  - which roles the actor may grant or revoke: the role ceiling of
 *    {@see RoleCeiling} (privileged roles only ROLE_SUPER_ADMIN, otherwise
 *    only roles the actor holds itself, plus top management / function owner
 *    for admins; ROLE_TOP_MGMT never to oneself), and
 *  - which tenants an actor may assign to a user (SUPER_ADMIN: any;
 *    everyone else: own tenant + descendants via TenantContext).
 *
 * The per-target rule (tenant scope + "never edit a SUPER_ADMIN") lives in
 * {@see \App\Security\Voter\UserVoter}; this guard covers the *values* an
 * actor submits (roles, tenant).
 */
final class UserAdministrationGuard
{
    /**
     * Instance operator, switch_user and holding-level roles. Only
     * ROLE_SUPER_ADMIN may grant or revoke them. Defined in {@see RoleNames}.
     */
    public const array PRIVILEGED_ROLES = RoleNames::PRIVILEGED;

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly TenantRepository $tenantRepository,
        private readonly LoggerInterface $logger,
        private readonly RoleCeiling $roleCeiling,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    private function actor(): ?User
    {
        $actor = $this->security->getUser();

        return $actor instanceof User ? $actor : null;
    }

    public function canManagePrivilegedRoles(): bool
    {
        return $this->security->isGranted('ROLE_SUPER_ADMIN');
    }

    /**
     * May the current actor grant/revoke a role with this name? Covers custom
     * Role entities whose name equals a privileged role (User::getRoles()
     * merges custom role names into the effective roles).
     */
    public function mayAssignRoleName(string $roleName, ?User $target = null): bool
    {
        $actor = $this->actor();
        if (!$actor instanceof User) {
            return !RoleNames::isPrivileged($roleName) && !RoleNames::isReserved($roleName);
        }
        if ($target instanceof User && RoleCeiling::isSameUser($actor, $target)
            && RoleCeiling::isSelfGrantForbidden($roleName)) {
            return false;
        }

        return $this->roleCeiling->mayChange($actor, $roleName);
    }

    /**
     * The system roles of $candidates the user form offers to the actor for
     * $target (null = new account). Roles the target holds but the actor may
     * not change are carried over on submit ({@see enforceSubmittedValues()}).
     *
     * @param array<array-key, string> $candidates
     * @return array<array-key, string>
     */
    public function grantableRoles(array $candidates, ?User $target = null): array
    {
        $actor = $this->actor();
        if (!$actor instanceof User) {
            return [];
        }

        return $this->roleCeiling->grantable($actor, $candidates, $target);
    }

    /**
     * Roles an SSO claim mapping may hand out: reserved, non-privileged roles
     * within the configuring actor's ceiling. Never free text (S14).
     *
     * @return list<string>
     */
    public function ssoMappableRoles(): array
    {
        $candidates = array_values(array_filter(
            RoleNames::RESERVED,
            static fn (string $role): bool => !RoleNames::isPrivileged($role),
        ));

        return array_values($this->grantableRoles($candidates));
    }

    /**
     * May the user form offer this custom Role to the current actor?
     * SUPER_ADMIN: every role. Others: not if it is named like a privileged
     * role, nor if it is named like a reserved role without granting it
     * (a non-system role such as "ROLE_ADMIN" is inert and only misleads).
     * Hidden roles a target already holds are carried over on submit
     * ({@see enforceSubmittedValues()}).
     */
    public function mayOfferCustomRole(Role $role): bool
    {
        if ($this->canManagePrivilegedRoles()) {
            return true;
        }

        return $this->mayAssignRoleName((string) $role->getName())
            && RoleNames::isGrantedByCustomRole($role->getName(), $role->isSystemRole());
    }

    /**
     * The privileged roles contained in $roles (sorted, unique).
     *
     * @param array<array-key, mixed> $roles
     * @return list<string>
     */
    public static function privilegedRolesIn(array $roles): array
    {
        $privileged = array_values(array_unique(array_intersect(
            array_map(static fn (mixed $r): string => is_scalar($r) ? (string) $r : '', $roles),
            self::PRIVILEGED_ROLES,
        )));
        sort($privileged);

        return $privileged;
    }

    /**
     * True when the privileged part of two role sets differs (grant or revoke).
     *
     * @param array<array-key, mixed> $before
     * @param array<array-key, mixed> $after
     */
    public static function privilegedRolesChanged(array $before, array $after): bool
    {
        return self::privilegedRolesIn($before) !== self::privilegedRolesIn($after);
    }

    /**
     * IDs of the tenants inside the current actor's accessible tree, using the
     * same rule as the voter (TenantContext::canAccessTenant — own +
     * descendants). Meant for non-SUPER_ADMIN actors; a SUPER_ADMIN is
     * unrestricted, so callers branch on {@see canManagePrivilegedRoles()}
     * rather than on this list.
     *
     * @return list<int>
     */
    public function assignableTenantIds(): array
    {
        $ids = [];
        foreach ($this->tenantRepository->findAll() as $tenant) {
            if ($tenant->getId() !== null && $this->tenantContext->canAccessTenant($tenant)) {
                $ids[] = $tenant->getId();
            }
        }

        return $ids;
    }

    /**
     * May the current actor place a user into $tenant?
     * SUPER_ADMIN: always (including "no tenant"). Others: only a tenant in
     * their accessible tree; never "no tenant".
     */
    public function canAssignTenant(?Tenant $tenant): bool
    {
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }
        if (!$tenant instanceof Tenant) {
            return false;
        }

        return $this->tenantContext->canAccessTenant($tenant);
    }

    public function getActorTenant(): ?Tenant
    {
        return $this->tenantContext->getCurrentTenant();
    }

    /**
     * State of $user before a form submit, for {@see enforceSubmittedValues()}.
     *
     * @return array{roles: list<string>, storedRoles: list<string>, customRoles: list<Role>, tenant: ?Tenant}
     */
    public function snapshot(User $user): array
    {
        return [
            'roles' => $user->getRoles(),
            'storedRoles' => $user->getStoredRoles(),
            'customRoles' => array_values($user->getCustomRoles()->toArray()),
            'tenant' => $user->getTenant(),
        ];
    }

    /**
     * Server-side enforcement on submitted user data:
     *  - roles beyond the actor's ceiling ({@see RoleCeiling}) cannot be
     *    granted or revoked; held ones the (filtered) form does not expose
     *    are carried over, not dropped;
     *  - nobody grants ROLE_TOP_MGMT to themselves;
     *  - a new user without tenant gets the actor's tenant;
     *  - the tenant may only be set to one inside the actor's tree
     *    (SUPER_ADMIN: any).
     * On a violation the managed entity is restored, so no later flush can
     * persist the tampered values. The caller must reject the submit.
     *
     * @param array{roles: list<string>, storedRoles: list<string>, customRoles: list<Role>, tenant: ?Tenant} $before
     * @return list<string> translation keys (domain `user`) of the violations
     */
    public function enforceSubmittedValues(User $user, array $before, bool $isNew): array
    {
        $actor = $this->actor();
        if (!$actor instanceof User) {
            return ['user.validation.privileged_role_forbidden'];
        }
        $violations = [];
        $mayChange = fn (string $role): bool => $this->roleCeiling->mayChange($actor, $role);

        // Roles above the actor's ceiling the (filtered) form does not expose
        // are carried over, not dropped.
        $storedRoles = $user->getStoredRoles();
        foreach ($before['storedRoles'] as $heldRole) {
            if (!$mayChange($heldRole) && !in_array($heldRole, $storedRoles, true)) {
                $storedRoles[] = $heldRole;
            }
        }
        $user->setRoles($storedRoles);
        // Same for custom roles the (filtered) form does not offer.
        foreach ($before['customRoles'] as $customRole) {
            if (!$this->mayOfferCustomRole($customRole) && !$user->getCustomRoles()->contains($customRole)) {
                $user->addCustomRole($customRole);
            }
        }

        // Compare effective roles plus (normalised) custom role names, so a
        // custom Role named like a reserved role cannot be (un)assigned beyond
        // the ceiling even where User::getRoles() would not let it grant anything.
        $beforeNames = self::roleNameSet($before['roles'], $before['customRoles']);
        $afterNames = self::roleNameSet($user->getRoles(), $user->getCustomRoles()->toArray());
        $changed = array_merge(array_diff($beforeNames, $afterNames), array_diff($afterNames, $beforeNames));
        $granted = array_diff($afterNames, $beforeNames);

        $beyondCeiling = array_filter($changed, static fn (string $role): bool => !$mayChange($role));
        $selfGrant = !$isNew && RoleCeiling::isSameUser($actor, $user)
            && array_filter($granted, RoleCeiling::isSelfGrantForbidden(...)) !== [];

        if ($beyondCeiling !== [] || $selfGrant) {
            $user->setRoles($before['storedRoles']);
            foreach ($user->getCustomRoles()->toArray() as $customRole) {
                $user->removeCustomRole($customRole);
            }
            foreach ($before['customRoles'] as $customRole) {
                $user->addCustomRole($customRole);
            }
            $violations[] = $selfGrant && $beyondCeiling === []
                ? 'user.validation.self_grant_forbidden'
                : 'user.validation.privileged_role_forbidden';
        }

        if ($isNew && $user->getTenant() === null) {
            $user->setTenant($this->getActorTenant());
        }
        // Identity comparison: Doctrine's identity map yields one instance per
        // tenant, and an unchanged tenant is always assignable anyway.
        $tenantChanged = $isNew || $user->getTenant() !== $before['tenant'];
        if ($tenantChanged && !$this->canAssignTenant($user->getTenant())) {
            $user->setTenant($before['tenant']);
            $violations[] = 'user.validation.tenant_not_assignable';
        }

        if ($violations !== []) {
            $this->logger->warning('User administration scope violation rejected', [
                'violations' => $violations,
                'target_user_id' => $user->getId(),
                'actor' => $this->security->getUser()?->getUserIdentifier(),
            ]);
        }

        return $violations;
    }

    /**
     * Writes one audit event per grant or revoke of a role in
     * {@see RoleNames::AUDITED_GRANTS} (top management, admin, instance and
     * holding roles) — call after the flush that stored $user.
     *
     * @param array{roles: list<string>, storedRoles: list<string>, customRoles: list<Role>, tenant: ?Tenant}|null $before null for a new account
     */
    public function auditSensitiveRoleChanges(User $user, ?array $before): void
    {
        $beforeNames = $before === null ? [] : self::roleNameSet($before['roles'], $before['customRoles']);
        $afterNames = self::roleNameSet($user->getRoles(), $user->getCustomRoles()->toArray());

        foreach (RoleNames::AUDITED_GRANTS as $role) {
            $had = in_array($role, $beforeNames, true);
            $has = in_array($role, $afterNames, true);
            if ($had === $has) {
                continue;
            }
            $this->auditLogger->logCustom(
                'user.sensitive_role_changed',
                'User',
                $user->getId(),
                ['role' => $role, 'held' => $had],
                ['role' => $role, 'held' => $has],
                sprintf(
                    'Role %s %s %s by %s',
                    $role,
                    $has ? 'granted to' : 'revoked from',
                    $user->getUserIdentifier(),
                    $this->actor()?->getUserIdentifier() ?? 'system',
                ),
            );
        }
    }

    /**
     * @param array<array-key, mixed> $roles
     * @param iterable<Role> $customRoles
     * @return list<string> normalised, unique
     */
    private static function roleNameSet(array $roles, iterable $customRoles): array
    {
        $names = array_map(static fn (mixed $r): string => RoleNames::normalise(is_scalar($r) ? (string) $r : ''), $roles);
        foreach ($customRoles as $customRole) {
            $names[] = RoleNames::normalise($customRole->getName());
        }

        return array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
    }
}

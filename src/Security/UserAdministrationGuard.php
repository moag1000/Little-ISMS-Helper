<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Service\TenantContext;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Server-side guard for user administration (security hotfix, admin scope).
 *
 * Single source of truth for:
 *  - which roles count as "privileged" (only a ROLE_SUPER_ADMIN actor may
 *    grant or revoke them), and
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
    ) {
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
    public function mayAssignRoleName(string $roleName): bool
    {
        return !in_array(strtoupper(trim($roleName)), self::PRIVILEGED_ROLES, true)
            || $this->canManagePrivilegedRoles();
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
     * Server-side enforcement on submitted user data (non-SUPER_ADMIN actors):
     *  - privileged roles cannot be granted or revoked; stored privileged roles
     *    the (filtered) form does not expose are carried over, not dropped;
     *  - a new user without tenant gets the actor's tenant;
     *  - the tenant may only be set to one inside the actor's tree.
     * On a violation the managed entity is restored, so no later flush can
     * persist the tampered values. The caller must reject the submit.
     *
     * @param array{roles: list<string>, storedRoles: list<string>, customRoles: list<Role>, tenant: ?Tenant} $before
     * @return list<string> translation keys (domain `user`) of the violations
     */
    public function enforceSubmittedValues(User $user, array $before, bool $isNew): array
    {
        if ($this->canManagePrivilegedRoles()) {
            return [];
        }
        $violations = [];

        $storedRoles = $user->getStoredRoles();
        foreach (self::privilegedRolesIn($before['storedRoles']) as $privilegedRole) {
            if (!in_array($privilegedRole, $storedRoles, true)) {
                $storedRoles[] = $privilegedRole;
            }
        }
        $user->setRoles($storedRoles);

        // Compare effective roles plus (normalised) custom role names, so a
        // custom Role named like a privileged role cannot be (un)assigned even
        // where User::getRoles() would not let it grant anything.
        $customNames = static fn (array $customRoles): array => array_map(
            static fn (Role $r): string => strtoupper(trim((string) $r->getName())),
            $customRoles,
        );
        if (self::privilegedRolesChanged(
            [...$before['roles'], ...$customNames($before['customRoles'])],
            [...$user->getRoles(), ...$customNames($user->getCustomRoles()->toArray())],
        )) {
            $user->setRoles($before['storedRoles']);
            foreach ($user->getCustomRoles()->toArray() as $customRole) {
                $user->removeCustomRole($customRole);
            }
            foreach ($before['customRoles'] as $customRole) {
                $user->addCustomRole($customRole);
            }
            $violations[] = 'user.validation.privileged_role_forbidden';
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
}

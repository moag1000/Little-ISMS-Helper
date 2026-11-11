<?php

declare(strict_types=1);

namespace App\Lifecycle\Config;

use App\Security\RoleNames;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * What an organisation may change about a lifecycle transition (S15).
 *
 * `/admin/lifecycle-overrides` lets an org admin override four metadata keys of a
 * transition. The rule is "tighten, never loosen a norm duty":
 *
 *  - `roles`: only real roles (`RoleNames::RESERVED`) that may write — never ROLE_USER
 *    (every signed-in user), never the read-only reviewer roles, never the switch-user
 *    marker — and only a narrower circle than the YAML (S28, F-05): every named role must
 *    reach one of the YAML roles through the role hierarchy ([ROLE_CISO] may replace
 *    [ROLE_MANAGER], never the other way round). A service-only transition (`roles: []`)
 *    gets no roles at all. A transition whose YAML names ROLE_TOP_MGMT (Leitung, ISO 5.1/9.3) or
 *    ROLE_DPO (privacy approvals, GDPR Art. 38/39) keeps its YAML roles: an override would
 *    only let somebody else decide (e.g. ROLE_ADMIN approving a management decision).
 *  - `four_eyes`, `reason_required`: may be switched on, not off where the YAML has them
 *    (four eyes on asset disposal, ISO A.5.3/A.7.14; reasons are audit trail, ISO 7.5.3).
 *  - `module`: may add a gate where the YAML has none, never replace one (a privacy
 *    transition must stay behind the privacy module).
 *
 * The admin form validates with {@see violations()}; {@see LifecycleConfigResolver}
 * applies only what {@see effective()} keeps, so a row written before S15 (free-text roles)
 * cannot loosen anything either.
 */
final class LifecycleOverridePolicy
{
    /** The keys the admin page writes; each one has a consumer (see LifecycleOverrideConsumersTest). */
    public const array KEYS = ['roles', 'reason_required', 'four_eyes', 'module'];

    /** YAML roles that lock a transition's roles against overrides. */
    public const array LOCKING_ROLES = ['ROLE_TOP_MGMT', 'ROLE_DPO'];

    /** Never assignable to a transition: everybody, read-only reviewers, the switch-user marker. */
    private const array NOT_ASSIGNABLE = [
        'ROLE_USER',
        'ROLE_AUDITOR',
        'ROLE_GROUP_CISO',
        'ROLE_KONZERN_AUDITOR',
        'ROLE_ALLOWED_TO_SWITCH',
    ];

    /** @return list<string> roles an override may name */
    public static function assignableRoles(): array
    {
        return array_values(array_diff(RoleNames::RESERVED, self::NOT_ASSIGNABLE));
    }

    /**
     * Problems of one override value against the YAML metadata of its transition.
     *
     * @param array<string, mixed> $yaml YAML metadata of the transition
     * @return list<string> translation keys (domain `admin`), empty = allowed
     */
    public static function violations(array $yaml, string $key, mixed $value, ?RoleHierarchyInterface $hierarchy = null): array
    {
        return match ($key) {
            'roles' => self::roleViolations($yaml, $value, $hierarchy),
            'four_eyes', 'reason_required' => (($yaml[$key] ?? false) === true && $value !== true)
                ? ['admin.lifecycle_overrides.violation.' . $key . '_locked']
                : [],
            'module' => self::moduleViolations($yaml, $value),
            default => ['admin.lifecycle_overrides.violation.unknown_key'],
        };
    }

    /**
     * The overrides that survive the policy — what the resolver merges over the YAML.
     *
     * @param array<string, mixed> $yaml
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function effective(array $yaml, array $overrides, ?RoleHierarchyInterface $hierarchy = null): array
    {
        $kept = [];
        foreach ($overrides as $key => $value) {
            if (self::violations($yaml, (string) $key, $value, $hierarchy) === []) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    /** True when the transition's roles cannot be overridden at all. */
    public static function rolesLocked(array $yaml): bool
    {
        $roles = is_array($yaml['roles'] ?? null) ? $yaml['roles'] : [];

        return array_intersect($roles, self::LOCKING_ROLES) !== [];
    }

    /**
     * Whether the role only narrows the YAML circle: it reaches one of the YAML roles
     * through the hierarchy. Without a hierarchy (unit fixtures) only a YAML role itself.
     *
     * @param list<string> $yamlRoles
     */
    public static function narrows(string $role, array $yamlRoles, ?RoleHierarchyInterface $hierarchy = null): bool
    {
        $reached = $hierarchy?->getReachableRoleNames([$role]) ?? [$role];

        return array_intersect($reached, $yamlRoles) !== [];
    }

    /** @return list<string> */
    private static function roleViolations(array $yaml, mixed $value, ?RoleHierarchyInterface $hierarchy): array
    {
        if (self::rolesLocked($yaml)) {
            return ['admin.lifecycle_overrides.violation.roles_locked'];
        }
        if (!is_array($value) || $value === []) {
            return ['admin.lifecycle_overrides.violation.roles_invalid'];
        }
        $assignable = self::assignableRoles();
        foreach ($value as $role) {
            if (!is_string($role) || !in_array($role, $assignable, true)) {
                return ['admin.lifecycle_overrides.violation.roles_invalid'];
            }
        }
        $yamlRoles = array_values(array_filter(
            is_array($yaml['roles'] ?? null) ? $yaml['roles'] : [],
            'is_string',
        ));
        foreach ($value as $role) {
            if (!self::narrows($role, $yamlRoles, $hierarchy)) {
                return ['admin.lifecycle_overrides.violation.roles_wider'];
            }
        }

        return [];
    }

    /** @return list<string> */
    private static function moduleViolations(array $yaml, mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return ['admin.lifecycle_overrides.violation.module_invalid'];
        }
        $yamlModule = $yaml['module'] ?? null;
        if (is_string($yamlModule) && $yamlModule !== '' && $yamlModule !== $value) {
            return ['admin.lifecycle_overrides.violation.module_locked'];
        }

        return [];
    }
}

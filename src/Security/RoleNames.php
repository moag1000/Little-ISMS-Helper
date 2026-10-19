<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Single source of truth for reserved / privileged Symfony role names
 * (security hotfix, admin scope).
 *
 * `User::getRoles()` merges custom `Role` entity names into the effective
 * Symfony roles, and the entity cannot reach the container — so this list is
 * hard-coded here, ONCE. `tests/Security/RoleNamesTest.php` fails if it drifts
 * from security.yaml `role_hierarchy` (keys + values), the switch_user role or
 * the system roles offered by `UserType`.
 */
final class RoleNames
{
    /**
     * Every Symfony role the app knows: role_hierarchy keys + values,
     * the switch_user role and the UserType system roles.
     */
    public const array RESERVED = [
        'ROLE_USER',
        'ROLE_AUDITOR',
        'ROLE_MANAGER',
        'ROLE_CISO',
        'ROLE_RISK_MANAGER',
        'ROLE_DPO',
        'ROLE_COMPLIANCE_MANAGER',
        'ROLE_ISB',
        'ROLE_BCM_OFFICER',
        'ROLE_ADMIN',
        'ROLE_SUPER_ADMIN',
        'ROLE_ALLOWED_TO_SWITCH',
        'ROLE_GROUP_CISO',
        'ROLE_KONZERN_AUDITOR',
        'ROLE_GROUP_BCM_OFFICER',
        'ROLE_FUNCTION_OWNER',
    ];

    /**
     * Roles only a ROLE_SUPER_ADMIN actor may grant or revoke.
     */
    public const array PRIVILEGED = [
        'ROLE_SUPER_ADMIN',
        'ROLE_GROUP_CISO',
        'ROLE_KONZERN_AUDITOR',
        'ROLE_ALLOWED_TO_SWITCH',
    ];

    /**
     * Never granted through a custom Role entity, not even a system one:
     * no seeder creates them as Role rows, so any such row is forged.
     */
    private const array NEVER_VIA_CUSTOM_ROLE = [
        'ROLE_SUPER_ADMIN',
        'ROLE_ALLOWED_TO_SWITCH',
    ];

    private function __construct()
    {
    }

    /**
     * Case- and whitespace-insensitive, so "role_super_admin " counts too.
     */
    public static function isReserved(?string $name): bool
    {
        return in_array(strtoupper(trim((string) $name)), self::RESERVED, true);
    }

    /**
     * Case- and whitespace-insensitive membership in {@see PRIVILEGED}.
     */
    public static function isPrivileged(?string $name): bool
    {
        return in_array(strtoupper(trim((string) $name)), self::PRIVILEGED, true);
    }

    /**
     * May a custom Role with this name contribute the name to
     * User::getRoles()? Ordinary names: yes (unchanged behaviour). Reserved
     * names: only for seeded system roles (holding / persona roles such as
     * ROLE_GROUP_CISO are assigned this way) and never for the
     * instance-operator roles.
     */
    public static function isGrantedByCustomRole(?string $name, bool $isSystemRole): bool
    {
        if (!self::isReserved($name)) {
            return true;
        }
        if (!$isSystemRole) {
            return false;
        }

        return !in_array(strtoupper(trim((string) $name)), self::NEVER_VIA_CUSTOM_ROLE, true);
    }
}

<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permission Voter for granular access control
 *
 * Checks if users have specific permissions through their roles.
 * Integrates with Symfony's security system for fine-grained authorization.
 *
 * Usage in controllers:
 * $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
 * $this->denyAccessUnlessGranted('USER_DELETE');
 *
 * Usage in templates:
 * {% if is_granted('TENANT_EDIT', tenant) %}
 *
 * ISO 27001: A.9.2.2 (User access provisioning)
 */
class PermissionVoter extends Voter
{
    // Admin permissions
    public const ADMIN_ACCESS = 'ADMIN_ACCESS';
    public const ADMIN_SETTINGS = 'ADMIN_SETTINGS';

    // User management permissions
    public const USER_VIEW = 'USER_VIEW';
    public const USER_CREATE = 'USER_CREATE';
    public const USER_EDIT = 'USER_EDIT';
    public const USER_DELETE = 'USER_DELETE';
    public const USER_MANAGE_ROLES = 'USER_MANAGE_ROLES';

    // Tenant management permissions
    public const TENANT_VIEW = 'TENANT_VIEW';
    public const TENANT_CREATE = 'TENANT_CREATE';
    public const TENANT_EDIT = 'TENANT_EDIT';
    public const TENANT_DELETE = 'TENANT_DELETE';

    // Session management permissions
    public const SESSION_VIEW = 'SESSION_VIEW';
    public const SESSION_TERMINATE = 'SESSION_TERMINATE';

    // MFA management permissions
    public const MFA_VIEW = 'MFA_VIEW';
    public const MFA_MANAGE = 'MFA_MANAGE';
    public const MFA_SETUP = 'MFA_SETUP';
    public const MFA_DELETE = 'MFA_DELETE';

    // Module management permissions
    public const MODULE_VIEW = 'MODULE_VIEW';
    public const MODULE_CONFIGURE = 'MODULE_CONFIGURE';

    // Role & Permission management
    public const ROLE_VIEW = 'ROLE_VIEW';
    public const ROLE_CREATE = 'ROLE_CREATE';
    public const ROLE_EDIT = 'ROLE_EDIT';
    public const ROLE_DELETE = 'ROLE_DELETE';

    // Audit log permissions
    public const AUDIT_VIEW = 'AUDIT_VIEW';
    public const AUDIT_EXPORT = 'AUDIT_EXPORT';

    // System monitoring permissions
    public const MONITORING_VIEW = 'MONITORING_VIEW';
    public const MONITORING_EXPORT = 'MONITORING_EXPORT';

    // Backup permissions
    public const BACKUP_CREATE = 'BACKUP_CREATE';
    public const BACKUP_RESTORE = 'BACKUP_RESTORE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Check if this voter supports the permission
        return in_array($attribute, [
            self::ADMIN_ACCESS,
            self::ADMIN_SETTINGS,
            self::USER_VIEW,
            self::USER_CREATE,
            self::USER_EDIT,
            self::USER_DELETE,
            self::USER_MANAGE_ROLES,
            self::TENANT_VIEW,
            self::TENANT_CREATE,
            self::TENANT_EDIT,
            self::TENANT_DELETE,
            self::SESSION_VIEW,
            self::SESSION_TERMINATE,
            self::MFA_VIEW,
            self::MFA_MANAGE,
            self::MFA_SETUP,
            self::MFA_DELETE,
            self::MODULE_VIEW,
            self::MODULE_CONFIGURE,
            self::ROLE_VIEW,
            self::ROLE_CREATE,
            self::ROLE_EDIT,
            self::ROLE_DELETE,
            self::AUDIT_VIEW,
            self::AUDIT_EXPORT,
            self::MONITORING_VIEW,
            self::MONITORING_EXPORT,
            self::BACKUP_CREATE,
            self::BACKUP_RESTORE,
        ]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // User must be logged in
        if (!$user instanceof User) {
            return false;
        }

        // Super admins have all permissions
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Check if user has the permission through their roles
        return $this->hasPermission($user, $attribute);
    }

    private function hasPermission(User $user, string $permissionName): bool
    {
        // Get all custom roles for the user
        $customRoles = $user->getCustomRoles();

        foreach ($customRoles as $role) {
            $permissions = $role->getPermissions();

            foreach ($permissions as $permission) {
                if ($permission->getName() === $permissionName) {
                    return true;
                }
            }
        }

        // Fallback: ROLE_ADMIN has broad permissions for backward compatibility
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            // ROLE_ADMIN can access most things except sensitive operations
            $restrictedPermissions = [
                self::BACKUP_RESTORE,  // Only super admin can restore
            ];

            if (!in_array($permissionName, $restrictedPermissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all available permissions grouped by category
     */
    public static function getAllPermissions(): array
    {
        return [
            'admin' => [
                self::ADMIN_ACCESS => 'Access admin panel',
                self::ADMIN_SETTINGS => 'Manage system settings',
            ],
            'user' => [
                self::USER_VIEW => 'View users',
                self::USER_CREATE => 'Create users',
                self::USER_EDIT => 'Edit users',
                self::USER_DELETE => 'Delete users',
                self::USER_MANAGE_ROLES => 'Manage user roles',
            ],
            'tenant' => [
                self::TENANT_VIEW => 'View tenants',
                self::TENANT_CREATE => 'Create tenants',
                self::TENANT_EDIT => 'Edit tenants',
                self::TENANT_DELETE => 'Delete tenants',
            ],
            'session' => [
                self::SESSION_VIEW => 'View active sessions',
                self::SESSION_TERMINATE => 'Terminate user sessions',
            ],
            'mfa' => [
                self::MFA_VIEW => 'View MFA tokens',
                self::MFA_MANAGE => 'Manage MFA tokens',
                self::MFA_SETUP => 'Setup MFA for users',
                self::MFA_DELETE => 'Delete MFA tokens',
            ],
            'module' => [
                self::MODULE_VIEW => 'View modules',
                self::MODULE_CONFIGURE => 'Configure modules',
            ],
            'role' => [
                self::ROLE_VIEW => 'View roles',
                self::ROLE_CREATE => 'Create roles',
                self::ROLE_EDIT => 'Edit roles',
                self::ROLE_DELETE => 'Delete roles',
            ],
            'audit' => [
                self::AUDIT_VIEW => 'View audit logs',
                self::AUDIT_EXPORT => 'Export audit logs',
            ],
            'monitoring' => [
                self::MONITORING_VIEW => 'View monitoring data',
                self::MONITORING_EXPORT => 'Export monitoring data',
            ],
            'backup' => [
                self::BACKUP_CREATE => 'Create backups',
                self::BACKUP_RESTORE => 'Restore from backups',
            ],
        ];
    }
}

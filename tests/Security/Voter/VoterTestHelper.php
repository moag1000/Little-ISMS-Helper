<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Shared helper for voter unit tests.
 *
 * Provides a real {@see RoleHierarchy} instance matching the application's
 * `security.yaml` role_hierarchy. Use a real instance (not a mock) because
 * voters call `getReachableRoleNames()` and rely on the actual expansion
 * logic — mocking would require duplicating that logic in every test.
 */
final class VoterTestHelper
{
    /**
     * Builds a role hierarchy that mirrors the production
     * `config/packages/security.yaml` role_hierarchy section.
     */
    public static function createRoleHierarchy(): RoleHierarchyInterface
    {
        return new RoleHierarchy([
            'ROLE_AUDITOR'            => ['ROLE_USER'],
            'ROLE_MANAGER'            => ['ROLE_USER', 'ROLE_AUDITOR'],
            'ROLE_CISO'               => ['ROLE_MANAGER'],
            'ROLE_RISK_MANAGER'       => ['ROLE_MANAGER'],
            'ROLE_DPO'                => ['ROLE_MANAGER'],
            'ROLE_COMPLIANCE_MANAGER' => ['ROLE_MANAGER'],
            'ROLE_ADMIN'              => [
                'ROLE_USER',
                'ROLE_AUDITOR',
                'ROLE_MANAGER',
                'ROLE_CISO',
                'ROLE_RISK_MANAGER',
                'ROLE_DPO',
                'ROLE_COMPLIANCE_MANAGER',
            ],
            'ROLE_SUPER_ADMIN' => [
                'ROLE_ADMIN',
                'ROLE_ALLOWED_TO_SWITCH',
                'ROLE_GROUP_CISO',
                'ROLE_KONZERN_AUDITOR',
            ],
            'ROLE_GROUP_CISO'      => ['ROLE_USER'],
            'ROLE_KONZERN_AUDITOR' => ['ROLE_USER'],
        ]);
    }
}

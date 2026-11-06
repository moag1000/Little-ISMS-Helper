<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Where a user lands after login.
 *
 * Users who open the tool to act on what is waiting for them land on My Day:
 * risk owners and other plain users, and auditors. Managers (and through the
 * role hierarchy every persona role: CISO, risk manager, DPO, ISB, …) and the
 * group roles keep the dashboard, which links to My Day in the sidebar.
 */
final readonly class LandingRoute
{
    private const DASHBOARD_ROLES = ['ROLE_MANAGER', 'ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR'];

    public function __construct(private RoleHierarchyInterface $roleHierarchy)
    {
    }

    public function routeFor(?UserInterface $user): string
    {
        if ($user === null) {
            return 'app_dashboard';
        }
        $roles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        return array_intersect(self::DASHBOARD_ROLES, $roles) === [] ? 'app_my_day' : 'app_dashboard';
    }
}

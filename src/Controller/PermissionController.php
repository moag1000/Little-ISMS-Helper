<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\PermissionRepository;
use App\Repository\UserRepository;
use App\Security\Voter\UserVoter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PermissionController extends AbstractController
{
    #[Route('/admin/permissions', name: 'permission_index', methods: ['GET'])]
    #[IsGranted('ROLE_VIEW')]
    public function index(
        PermissionRepository $permissionRepository,
        Request $request
    ): Response {
        $categoryFilter = $request->query->get('category');

        if ($categoryFilter) {
            $permissions = $permissionRepository->findByCategory($categoryFilter);
            $groupedPermissions = [$categoryFilter => $permissions];
        } else {
            $groupedPermissions = $permissionRepository->findAllGroupedByCategory();
        }

        $categories = $permissionRepository->getCategories();
        $actions = $permissionRepository->getActions();

        // Calculate statistics
        $totalPermissions = $permissionRepository->count([]);
        $systemPermissions = $permissionRepository->count(['isSystemPermission' => true]);
        $customPermissions = $totalPermissions - $systemPermissions;

        return $this->render('permission/index.html.twig', [
            'groupedPermissions' => $groupedPermissions,
            'categories' => $categories,
            'actions' => $actions,
            'currentCategory' => $categoryFilter,
            'statistics' => [
                'total' => $totalPermissions,
                'system' => $systemPermissions,
                'custom' => $customPermissions,
                'categories_count' => count($categories),
            ],
        ]);
    }
    #[Route('/admin/permissions/{id}', name: 'permission_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_VIEW')]
    public function show(Permission $permission, UserRepository $userRepository, TenantContext $tenantContext): Response
    {
        // Load roles that have this permission
        $roles = $permission->getRoles();

        $affectedUsersCount = $this->countAffectedUsers($roles->toArray(), $userRepository, $tenantContext);

        return $this->render('permission/show.html.twig', [
            'permission' => $permission,
            'roles' => $roles,
            'affected_users_count' => $affectedUsersCount,
        ]);
    }

    /**
     * Distinct holders of any of $roles. Roles are global, so a non-SUPER_ADMIN
     * actor counts only the holders it may VIEW (UserVoter scope) — never a
     * cross-organisation total (#1059). Users holding several of the roles are
     * counted once.
     *
     * @param list<Role> $roles
     */
    private function countAffectedUsers(array $roles, UserRepository $userRepository, TenantContext $tenantContext): int
    {
        if ($roles === []) {
            return 0;
        }

        $queryBuilder = $userRepository->createQueryBuilder('u')
            ->innerJoin('u.customRoles', 'r')
            ->where('r IN (:roles)')
            ->setParameter('roles', $roles);

        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return (int) $queryBuilder->select('COUNT(DISTINCT u.id)')->getQuery()->getSingleScalarResult();
        }

        // Pre-filter to the actor's tenant tree (and itself) so only candidate
        // holders are hydrated; the voter then decides exactly.
        $tenants = $tenantContext->getAccessibleTenants();
        $queryBuilder->andWhere($tenants !== [] ? '(u.tenant IN (:tenants) OR u = :self)' : 'u = :self')
            ->setParameter('self', $this->getUser());
        if ($tenants !== []) {
            $queryBuilder->setParameter('tenants', $tenants);
        }

        /** @var list<User> $holders */
        $holders = $queryBuilder->select('DISTINCT u')->getQuery()->getResult();

        return count(array_filter($holders, fn (User $holder): bool => $this->isGranted(UserVoter::VIEW, $holder)));
    }
}

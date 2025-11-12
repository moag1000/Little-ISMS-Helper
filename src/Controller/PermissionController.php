<?php

namespace App\Controller;

use App\Entity\Permission;
use App\Repository\PermissionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/permissions')]
#[IsGranted('ROLE_ADMIN')]
class PermissionController extends AbstractController
{
    #[Route('', name: 'permission_index', methods: ['GET'])]
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

    #[Route('/{id}', name: 'permission_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Permission $permission): Response
    {
        // Load roles that have this permission
        $roles = $permission->getRoles();

        // Count users indirectly through roles (would need UserRepository method)
        $affectedUsersCount = 0;
        foreach ($roles as $role) {
            $affectedUsersCount += $role->getUsers()->count();
        }

        return $this->render('permission/show.html.twig', [
            'permission' => $permission,
            'roles' => $roles,
            'affected_users_count' => $affectedUsersCount,
        ]);
    }
}

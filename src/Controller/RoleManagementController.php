<?php

namespace App\Controller;

use App\Entity\Role;
use App\Repository\RoleRepository;
use App\Repository\PermissionRepository;
use App\Security\Voter\RoleVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/roles')]
#[IsGranted('ROLE_ADMIN')]
class RoleManagementController extends AbstractController
{
    #[Route('', name: 'role_management_index')]
    public function index(RoleRepository $roleRepository): Response
    {
        $this->denyAccessUnlessGranted(RoleVoter::VIEW, new Role());

        $roles = $roleRepository->getRolesWithUserCount();

        return $this->render('role_management/index.html.twig', [
            'roles' => $roles,
        ]);
    }

    #[Route('/new', name: 'role_management_new')]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        PermissionRepository $permissionRepository,
        TranslatorInterface $translator
    ): Response {
        $this->denyAccessUnlessGranted(RoleVoter::CREATE);

        $role = new Role();
        $permissions = $permissionRepository->findAllGroupedByCategory();

        if ($request->isMethod('POST')) {
            $role->setName($request->request->get('name'));
            $role->setDescription($request->request->get('description'));
            $role->setIsSystemRole($request->request->get('isSystemRole', false) === '1');

            // Add permissions
            $permissionIds = $request->request->all('permissions') ?? [];
            foreach ($permissionIds as $permissionId) {
                $permission = $permissionRepository->find($permissionId);
                if ($permission) {
                    $role->addPermission($permission);
                }
            }

            $entityManager->persist($role);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('role.success.created'));

            return $this->redirectToRoute('role_management_index');
        }

        return $this->render('role_management/new.html.twig', [
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }

    #[Route('/{id}', name: 'role_management_show', requirements: ['id' => '\d+'])]
    public function show(Role $role, RoleRepository $roleRepository): Response
    {
        $this->denyAccessUnlessGranted(RoleVoter::VIEW, $role);

        $role = $roleRepository->findWithPermissions($role->getId());

        return $this->render('role_management/show.html.twig', [
            'role' => $role,
        ]);
    }

    #[Route('/{id}/edit', name: 'role_management_edit', requirements: ['id' => '\d+'])]
    public function edit(
        Role $role,
        Request $request,
        EntityManagerInterface $entityManager,
        PermissionRepository $permissionRepository,
        TranslatorInterface $translator
    ): Response {
        $this->denyAccessUnlessGranted(RoleVoter::EDIT, $role);

        $permissions = $permissionRepository->findAllGroupedByCategory();

        if ($request->isMethod('POST')) {
            $role->setName($request->request->get('name'));
            $role->setDescription($request->request->get('description'));

            // Only allow changing system role flag for non-system roles
            if (!$role->isSystemRole()) {
                $role->setIsSystemRole($request->request->get('isSystemRole', false) === '1');
            }

            // Update permissions
            $role->getPermissions()->clear();
            $permissionIds = $request->request->all('permissions') ?? [];
            foreach ($permissionIds as $permissionId) {
                $permission = $permissionRepository->find($permissionId);
                if ($permission) {
                    $role->addPermission($permission);
                }
            }

            $role->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('role.success.updated'));

            return $this->redirectToRoute('role_management_show', ['id' => $role->getId()]);
        }

        return $this->render('role_management/edit.html.twig', [
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }

    #[Route('/{id}/delete', name: 'role_management_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        Role $role,
        Request $request,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response {
        $this->denyAccessUnlessGranted(RoleVoter::DELETE, $role);

        if ($this->isCsrfTokenValid('delete' . $role->getId(), $request->request->get('_token'))) {
            $entityManager->remove($role);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('role.success.deleted'));
        }

        return $this->redirectToRoute('role_management_index');
    }
}

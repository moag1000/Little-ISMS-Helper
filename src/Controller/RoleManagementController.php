<?php

namespace App\Controller;

use App\Entity\Role;
use App\Form\RoleType;
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
        $form = $this->createForm(RoleType::class, $role);
        $permissions = $permissionRepository->findAllGroupedByCategory();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($role);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('role.success.created'));

            return $this->redirectToRoute('role_management_index');
        }

        return $this->render('role_management/new.html.twig', [
            'role' => $role,
            'form' => $form,
            'permissions' => $permissions,
        ]);
    }

    #[Route('/compare', name: 'role_management_compare')]
    public function compare(
        Request $request,
        RoleRepository $roleRepository,
        PermissionRepository $permissionRepository
    ): Response {
        $this->denyAccessUnlessGranted(RoleVoter::VIEW, new Role());

        $roleIds = $request->query->all('roles') ?? [];
        $roles = [];

        if (!empty($roleIds)) {
            foreach ($roleIds as $roleId) {
                $role = $roleRepository->findWithPermissions((int) $roleId);
                if ($role) {
                    $roles[] = $role;
                }
            }
        }

        // Get all permissions grouped by category for the comparison matrix
        $allPermissions = $permissionRepository->findAllGroupedByCategory();
        $allRoles = $roleRepository->findAll();

        // Build comparison matrix
        $comparisonMatrix = [];
        foreach ($allPermissions as $category => $permissions) {
            foreach ($permissions as $permission) {
                $permissionId = $permission->getId();
                $comparisonMatrix[$permissionId] = [
                    'permission' => $permission,
                    'category' => $category,
                    'roles' => [],
                ];

                foreach ($roles as $role) {
                    $hasPermission = false;
                    foreach ($role->getPermissions() as $rolePermission) {
                        if ($rolePermission->getId() === $permissionId) {
                            $hasPermission = true;
                            break;
                        }
                    }
                    $comparisonMatrix[$permissionId]['roles'][$role->getId()] = $hasPermission;
                }
            }
        }

        return $this->render('role_management/compare.html.twig', [
            'roles' => $roles,
            'all_roles' => $allRoles,
            'comparison_matrix' => $comparisonMatrix,
            'selected_role_ids' => $roleIds,
        ]);
    }


    #[Route('/templates', name: 'role_management_templates')]
    public function templates(
        Request $request,
        EntityManagerInterface $entityManager,
        PermissionRepository $permissionRepository,
        TranslatorInterface $translator
    ): Response {
        $this->denyAccessUnlessGranted(RoleVoter::CREATE);

        // Define role templates
        $templates = $this->getRoleTemplates();

        // Handle template application
        if ($request->isMethod('POST')) {
            $templateKey = $request->request->get('template');
            $customName = $request->request->get('custom_name');

            if (isset($templates[$templateKey])) {
                $template = $templates[$templateKey];

                $role = new Role();
                $role->setName($customName ?: $template['name']);
                $role->setDescription($template['description']);
                $role->setIsSystemRole(false);

                // Add permissions based on template
                foreach ($template['permissions'] as $permissionName) {
                    $permission = $permissionRepository->findByName($permissionName);
                    if ($permission) {
                        $role->addPermission($permission);
                    }
                }

                $entityManager->persist($role);
                $entityManager->flush();

                $this->addFlash('success', $translator->trans('role.success.created_from_template', [
                    'role' => $role->getName(),
                ]));

                return $this->redirectToRoute('role_management_show', ['id' => $role->getId()]);
            }
        }

        return $this->render('role_management/templates.html.twig', [
            'templates' => $templates,
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

        $form = $this->createForm(RoleType::class, $role);
        $permissions = $permissionRepository->findAllGroupedByCategory();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Only allow changing system role flag for non-system roles
            if ($role->isSystemRole() && $form->get('isSystemRole')->getData() === false) {
                $role->setIsSystemRole(true);
            }

            $role->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('role.success.updated'));

            return $this->redirectToRoute('role_management_show', ['id' => $role->getId()]);
        }

        return $this->render('role_management/edit.html.twig', [
            'role' => $role,
            'form' => $form,
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

    /**
     * Define role templates with predefined permissions
     */
    private function getRoleTemplates(): array
    {
        return [
            'auditor' => [
                'name' => 'Auditor',
                'description' => 'Read-only access to all compliance and audit data',
                'permissions' => [
                    'risk.view',
                    'asset.view',
                    'control.view',
                    'audit.view',
                    'incident.view',
                    'document.view',
                    'report.view',
                    'compliance.view',
                ],
            ],
            'risk_manager' => [
                'name' => 'Risk Manager',
                'description' => 'Full access to risk management features',
                'permissions' => [
                    'risk.view',
                    'risk.create',
                    'risk.edit',
                    'risk.delete',
                    'asset.view',
                    'asset.create',
                    'asset.edit',
                    'control.view',
                    'control.create',
                    'control.edit',
                    'report.view',
                    'report.export',
                ],
            ],
            'compliance_officer' => [
                'name' => 'Compliance Officer',
                'description' => 'Manage compliance requirements and audits',
                'permissions' => [
                    'compliance.view',
                    'compliance.create',
                    'compliance.edit',
                    'audit.view',
                    'audit.create',
                    'audit.edit',
                    'control.view',
                    'control.create',
                    'control.edit',
                    'document.view',
                    'document.create',
                    'document.edit',
                    'report.view',
                    'report.export',
                ],
            ],
            'incident_manager' => [
                'name' => 'Incident Manager',
                'description' => 'Manage security incidents and responses',
                'permissions' => [
                    'incident.view',
                    'incident.create',
                    'incident.edit',
                    'incident.delete',
                    'asset.view',
                    'control.view',
                    'report.view',
                    'report.export',
                ],
            ],
            'asset_manager' => [
                'name' => 'Asset Manager',
                'description' => 'Manage IT assets and inventory',
                'permissions' => [
                    'asset.view',
                    'asset.create',
                    'asset.edit',
                    'asset.delete',
                    'license.view',
                    'license.create',
                    'license.edit',
                    'document.view',
                    'report.view',
                ],
            ],
            'readonly' => [
                'name' => 'Read-Only User',
                'description' => 'View-only access to basic features',
                'permissions' => [
                    'dashboard.view',
                    'risk.view',
                    'asset.view',
                    'incident.view',
                    'document.view',
                ],
            ],
        ];
    }
}

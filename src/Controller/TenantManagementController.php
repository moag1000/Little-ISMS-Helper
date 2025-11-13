<?php

namespace App\Controller;

use App\Entity\CorporateGovernance;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Form\TenantType;
use App\Repository\CorporateGovernanceRepository;
use App\Repository\TenantRepository;
use App\Service\CorporateStructureService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/tenants')]
#[IsGranted('ROLE_ADMIN')]
class TenantManagementController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly CorporateGovernanceRepository $governanceRepository,
        private readonly LoggerInterface $logger,
        private readonly CorporateStructureService $corporateStructureService,
    ) {
    }

    #[Route('', name: 'tenant_management_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = $request->query->get('filter', 'all'); // all, active, inactive

        $queryBuilder = $this->tenantRepository->createQueryBuilder('t');

        if ($filter === 'active') {
            $queryBuilder->where('t.isActive = :active')
                ->setParameter('active', true);
        } elseif ($filter === 'inactive') {
            $queryBuilder->where('t.isActive = :active')
                ->setParameter('active', false);
        }

        $queryBuilder->orderBy('t.createdAt', 'DESC');

        $tenants = $queryBuilder->getQuery()->getResult();

        // Calculate statistics for each tenant
        $tenantsWithStats = [];
        foreach ($tenants as $tenant) {
            $tenantsWithStats[] = [
                'tenant' => $tenant,
                'userCount' => $tenant->getUsers()->count(),
            ];
        }

        return $this->render('admin/tenants/index.html.twig', [
            'tenants' => $tenantsWithStats,
            'filter' => $filter,
            'totalCount' => count($this->tenantRepository->findAll()),
            'activeCount' => count($this->tenantRepository->findBy(['isActive' => true])),
            'inactiveCount' => count($this->tenantRepository->findBy(['isActive' => false])),
        ]);
    }

    #[Route('/new', name: 'tenant_management_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $tenant = new Tenant();
        $form = $this->createForm(TenantType::class, $tenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->entityManager->persist($tenant);
                $this->entityManager->flush();

                $this->logger->info('Tenant created', [
                    'tenant_id' => $tenant->getId(),
                    'tenant_code' => $tenant->getCode(),
                    'user' => $this->getUser()?->getUserIdentifier(),
                ]);

                $this->addFlash('success', 'tenant.flash.created');

                return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to create tenant', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $this->addFlash('danger', 'tenant.flash.error');
            }
        }

        return $this->render('admin/tenants/form.html.twig', [
            'tenant' => $tenant,
            'form' => $form,
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}', name: 'tenant_management_show', methods: ['GET'])]
    public function show(Tenant $tenant): Response
    {
        // Get user statistics
        $users = $tenant->getUsers();
        $activeUsers = $users->filter(fn($user) => $user->isActive())->count();

        // Get recent activity from users
        $recentActivity = [];
        foreach ($users->slice(0, 10) as $user) {
            $recentActivity[] = [
                'user' => $user,
                'lastLogin' => $user->getLastLoginAt(),
            ];
        }

        // Get default governance model
        $defaultGovernance = null;
        if ($tenant->getParent()) {
            $defaultGovernance = $this->governanceRepository->findDefaultGovernance($tenant);
        }

        return $this->render('admin/tenants/show.html.twig', [
            'tenant' => $tenant,
            'userCount' => $users->count(),
            'activeUsers' => $activeUsers,
            'inactiveUsers' => $users->count() - $activeUsers,
            'recentActivity' => $recentActivity,
            'defaultGovernance' => $defaultGovernance,
        ]);
    }

    #[Route('/{id}/edit', name: 'tenant_management_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Tenant $tenant): Response
    {
        $form = $this->createForm(TenantType::class, $tenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->entityManager->flush();

                $this->logger->info('Tenant updated', [
                    'tenant_id' => $tenant->getId(),
                    'tenant_code' => $tenant->getCode(),
                    'user' => $this->getUser()?->getUserIdentifier(),
                ]);

                $this->addFlash('success', 'tenant.flash.updated');

                return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to update tenant', [
                    'tenant_id' => $tenant->getId(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $this->addFlash('danger', 'tenant.flash.error');
            }
        }

        return $this->render('admin/tenants/form.html.twig', [
            'tenant' => $tenant,
            'form' => $form,
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}/toggle', name: 'tenant_management_toggle', methods: ['POST'])]
    public function toggle(Tenant $tenant): Response
    {
        try {
            $previousStatus = $tenant->isActive();
            $tenant->setIsActive(!$previousStatus);
            $this->entityManager->flush();

            $this->logger->info('Tenant status toggled', [
                'tenant_id' => $tenant->getId(),
                'tenant_code' => $tenant->getCode(),
                'previous_status' => $previousStatus,
                'new_status' => $tenant->isActive(),
                'user' => $this->getUser()?->getUserIdentifier(),
            ]);

            $message = $tenant->isActive() ? 'tenant.flash.activated' : 'tenant.flash.deactivated';
            $this->addFlash('success', $message);
        } catch (\Exception $e) {
            $this->logger->error('Failed to toggle tenant status', [
                'tenant_id' => $tenant->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addFlash('danger', 'tenant.flash.error');
        }

        return $this->redirectToRoute('tenant_management_index');
    }

    #[Route('/{id}/delete', name: 'tenant_management_delete', methods: ['POST'])]
    public function delete(Request $request, Tenant $tenant): Response
    {
        // Security: Verify CSRF token
        if (!$this->isCsrfTokenValid('delete_tenant_' . $tenant->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'common.csrf_error');
            return $this->redirectToRoute('tenant_management_index');
        }

        // Business rule: Cannot delete tenant with active users
        if ($tenant->getUsers()->count() > 0) {
            $this->addFlash('warning', 'tenant.flash.has_users');
            return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
        }

        try {
            $tenantCode = $tenant->getCode();

            $this->entityManager->remove($tenant);
            $this->entityManager->flush();

            $this->logger->warning('Tenant deleted', [
                'tenant_id' => $tenant->getId(),
                'tenant_code' => $tenantCode,
                'user' => $this->getUser()?->getUserIdentifier(),
            ]);

            $this->addFlash('success', 'tenant.flash.deleted');
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete tenant', [
                'tenant_id' => $tenant->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addFlash('danger', 'tenant.flash.error');
        }

        return $this->redirectToRoute('tenant_management_index');
    }

    /**
     * Corporate Structure Management Routes
     */
    #[Route('/corporate-structure', name: 'tenant_management_corporate_structure', methods: ['GET'])]
    public function corporateStructure(): Response
    {
        // Get all root tenants (corporate parents without parents)
        $allTenants = $this->tenantRepository->findBy(['isActive' => true], ['name' => 'ASC']);
        $corporateGroups = [];

        foreach ($allTenants as $tenant) {
            if ($tenant->isCorporateParent() && $tenant->getParent() === null) {
                $tree = $this->corporateStructureService->getStructureTree($tenant);
                $corporateGroups[] = $tree;
            }
        }

        // Get standalone tenants (not part of any corporate structure)
        $standaloneTenants = array_filter($allTenants, function ($tenant) {
            return !$tenant->isPartOfCorporateStructure();
        });

        return $this->render('admin/tenants/corporate_structure.html.twig', [
            'corporateGroups' => $corporateGroups,
            'standaloneTenants' => $standaloneTenants,
            'allTenants' => $allTenants,
            'governanceModels' => GovernanceModel::cases(),
        ]);
    }

    #[Route('/{id}/set-parent', name: 'tenant_management_set_parent', methods: ['POST'])]
    public function setParent(Request $request, Tenant $tenant): Response
    {
        $parentId = $request->request->get('parent_id');
        $governanceModel = $request->request->get('governance_model');

        try {
            if ($parentId) {
                $parent = $this->tenantRepository->find($parentId);
                if (!$parent) {
                    $this->addFlash('danger', 'corporate.flash.parent_not_found');
                    return $this->redirectToRoute('tenant_management_corporate_structure');
                }

                if (!$governanceModel || !in_array($governanceModel, ['hierarchical', 'shared', 'independent'])) {
                    $this->addFlash('danger', 'corporate.flash.invalid_governance');
                    return $this->redirectToRoute('tenant_management_corporate_structure');
                }

                $tenant->setParent($parent);
                $parent->setIsCorporateParent(true);

                // Create default governance rule
                $governance = $this->governanceRepository->findDefaultGovernance($tenant);
                if (!$governance) {
                    $governance = new CorporateGovernance();
                    $governance->setTenant($tenant);
                    $governance->setParent($parent);
                    $governance->setScope('default');
                    $governance->setScopeId(null);
                    $governance->setCreatedBy($this->getUser());
                }
                $governance->setGovernanceModel(GovernanceModel::from($governanceModel));
                $this->entityManager->persist($governance);

                // Validate structure
                $errors = $this->corporateStructureService->validateStructure($tenant);
                if (!empty($errors)) {
                    $this->addFlash('danger', implode(', ', $errors));
                    return $this->redirectToRoute('tenant_management_corporate_structure');
                }

                $this->logger->info('Tenant parent set', [
                    'tenant_id' => $tenant->getId(),
                    'parent_id' => $parent->getId(),
                    'governance_model' => $governanceModel,
                    'user' => $this->getUser()?->getUserIdentifier(),
                ]);

                $this->addFlash('success', 'corporate.flash.parent_set');
            } else {
                // Remove parent and all governance rules
                $tenant->setParent(null);

                // Delete all governance rules for this tenant
                $this->entityManager->createQueryBuilder()
                    ->delete(CorporateGovernance::class, 'cg')
                    ->where('cg.tenant = :tenant')
                    ->setParameter('tenant', $tenant)
                    ->getQuery()
                    ->execute();

                $this->logger->info('Tenant parent removed', [
                    'tenant_id' => $tenant->getId(),
                    'user' => $this->getUser()?->getUserIdentifier(),
                ]);

                $this->addFlash('success', 'corporate.flash.parent_removed');
            }

            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->error('Failed to set tenant parent', [
                'tenant_id' => $tenant->getId(),
                'error' => $e->getMessage(),
            ]);

            $this->addFlash('danger', 'corporate.flash.error');
        }

        return $this->redirectToRoute('tenant_management_corporate_structure');
    }

    #[Route('/{id}/update-governance', name: 'tenant_management_update_governance', methods: ['POST'])]
    public function updateGovernance(Request $request, Tenant $tenant): Response
    {
        $governanceModel = $request->request->get('governance_model');

        if (!$governanceModel || !in_array($governanceModel, ['hierarchical', 'shared', 'independent'])) {
            $this->addFlash('danger', 'corporate.flash.invalid_governance');
            return $this->redirectToRoute('tenant_management_corporate_structure');
        }

        try {
            // Update default governance rule
            $governance = $this->governanceRepository->findDefaultGovernance($tenant);
            if ($governance) {
                $governance->setGovernanceModel(GovernanceModel::from($governanceModel));
                $this->entityManager->flush();

                $this->logger->info('Tenant governance model updated', [
                    'tenant_id' => $tenant->getId(),
                    'governance_model' => $governanceModel,
                    'user' => $this->getUser()?->getUserIdentifier(),
                ]);

                $this->addFlash('success', 'corporate.flash.governance_updated');
            } else {
                $this->addFlash('warning', 'corporate.flash.no_governance_found');
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to update governance model', [
                'tenant_id' => $tenant->getId(),
                'error' => $e->getMessage(),
            ]);

            $this->addFlash('danger', 'corporate.flash.error');
        }

        return $this->redirectToRoute('tenant_management_corporate_structure');
    }
}

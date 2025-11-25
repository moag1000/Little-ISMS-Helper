<?php

namespace App\Controller;

use App\Entity\CorporateGovernance;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Form\TenantType;
use App\Repository\CorporateGovernanceRepository;
use App\Repository\TenantRepository;
use App\Service\AuditLogger;
use App\Service\FileUploadSecurityService;
use App\Service\CorporateStructureService;
use App\Service\MultiTenantCheckService;
use App\Service\ISMSContextService;
use App\Repository\ISMSContextRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/tenants')]
class TenantManagementController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly CorporateGovernanceRepository $governanceRepository,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $auditLogger,
        private readonly FileUploadSecurityService $fileUploadService,
        private readonly SluggerInterface $slugger,
        private readonly CorporateStructureService $corporateStructureService,
        private readonly MultiTenantCheckService $multiTenantCheckService,
        private readonly ISMSContextService $ismsContextService,
        private readonly ISMSContextRepository $ismsContextRepository,
        private readonly string $uploadsDirectory = 'uploads/tenants',
    ) {
    }

    #[Route('', name: 'tenant_management_index', methods: ['GET'])]
    #[IsGranted('TENANT_VIEW')]
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
    #[IsGranted('TENANT_CREATE')]
    public function new(Request $request): Response
    {
        $tenant = new Tenant();
        $form = $this->createForm(TenantType::class, $tenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Handle logo upload
                /** @var UploadedFile|null $logoFile */
                $logoFile = $form->get('logoFile')->getData();
                if ($logoFile) {
                    $logoPath = $this->handleLogoUpload($logoFile, $tenant);
                    if ($logoPath) {
                        $tenant->setLogoPath($logoPath);
                    }
                }

                // Handle settings JSON
                $settingsJson = $form->get('settings')->getData();
                if ($settingsJson) {
                    $tenant->setSettings(json_decode($settingsJson, true));
                }

                $this->entityManager->persist($tenant);
                $this->entityManager->flush();

                $this->logger->info('Tenant created', [
                    'tenant_id' => $tenant->getId(),
                    'tenant_code' => $tenant->getCode(),
                    'has_logo' => $tenant->getLogoPath() !== null,
                    'user' => $this->getUser()?->getUserIdentifier(),
                ]);

                // Audit log
                $this->auditLogger->logCustom(
                    'tenant_created',
                    'Tenant',
                    $tenant->getId(),
                    null,
                    [
                        'code' => $tenant->getCode(),
                        'name' => $tenant->getName(),
                        'is_active' => $tenant->isActive(),
                        'has_logo' => $tenant->getLogoPath() !== null,
                    ],
                    sprintf('Tenant "%s" created', $tenant->getName())
                );

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

    #[Route('/corporate-structure', name: 'tenant_management_corporate_structure', methods: ['GET'])]
    #[IsGranted('TENANT_VIEW')]
    public function corporateStructure(): Response
    {
        // Check if corporate structure features should be available
        $isMultiTenant = $this->multiTenantCheckService->isMultiTenant();

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
            'isMultiTenant' => $isMultiTenant,
            'activeTenantCount' => $this->multiTenantCheckService->getActiveTenantCount(),
        ]);
    }


    #[Route('/{id}', name: 'tenant_management_show', methods: ['GET'])]
    #[IsGranted('TENANT_VIEW')]
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
    #[IsGranted('TENANT_EDIT')]
    public function edit(Request $request, Tenant $tenant): Response
    {
        // Capture old values for audit log
        $oldValues = [
            'code' => $tenant->getCode(),
            'name' => $tenant->getName(),
            'description' => $tenant->getDescription(),
            'is_active' => $tenant->isActive(),
            'azure_tenant_id' => $tenant->getAzureTenantId(),
            'has_logo' => $tenant->getLogoPath() !== null,
        ];

        $oldLogoPath = $tenant->getLogoPath();
        $form = $this->createForm(TenantType::class, $tenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // Handle logo upload
                /** @var UploadedFile|null $logoFile */
                $logoFile = $form->get('logoFile')->getData();
                if ($logoFile) {
                    // Delete old logo if exists
                    if ($oldLogoPath) {
                        $this->deleteOldLogo($oldLogoPath);
                    }

                    $logoPath = $this->handleLogoUpload($logoFile, $tenant);
                    if ($logoPath) {
                        $tenant->setLogoPath($logoPath);
                    }
                }

                // Handle settings JSON
                $settingsJson = $form->get('settings')->getData();
                if ($settingsJson) {
                    $tenant->setSettings(json_decode($settingsJson, true));
                }

                $this->entityManager->flush();

                $this->logger->info('Tenant updated', [
                    'tenant_id' => $tenant->getId(),
                    'tenant_code' => $tenant->getCode(),
                    'logo_updated' => $logoFile !== null,
                    'user' => $this->getUser()?->getUserIdentifier(),
                ]);

                // Audit log with before/after values
                $newValues = [
                    'code' => $tenant->getCode(),
                    'name' => $tenant->getName(),
                    'description' => $tenant->getDescription(),
                    'is_active' => $tenant->isActive(),
                    'azure_tenant_id' => $tenant->getAzureTenantId(),
                    'has_logo' => $tenant->getLogoPath() !== null,
                ];

                $this->auditLogger->logCustom(
                    'tenant_updated',
                    'Tenant',
                    $tenant->getId(),
                    $oldValues,
                    $newValues,
                    sprintf('Tenant "%s" updated', $tenant->getName())
                );

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
    #[IsGranted('TENANT_EDIT')]
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

            // Audit log
            $this->auditLogger->logCustom(
                'tenant_status_toggled',
                'Tenant',
                $tenant->getId(),
                ['is_active' => $previousStatus],
                ['is_active' => $tenant->isActive()],
                sprintf('Tenant "%s" %s', $tenant->getName(), $tenant->isActive() ? 'activated' : 'deactivated')
            );

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
    #[IsGranted('TENANT_DELETE')]
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
            $tenantId = $tenant->getId();
            $tenantCode = $tenant->getCode();
            $tenantName = $tenant->getName();

            // Capture tenant data for audit log before deletion
            $oldValues = [
                'code' => $tenantCode,
                'name' => $tenantName,
                'is_active' => $tenant->isActive(),
            ];

            $this->entityManager->remove($tenant);
            $this->entityManager->flush();

            $this->logger->warning('Tenant deleted', [
                'tenant_id' => $tenantId,
                'tenant_code' => $tenantCode,
                'user' => $this->getUser()?->getUserIdentifier(),
            ]);

            // Audit log
            $this->auditLogger->logCustom(
                'tenant_deleted',
                'Tenant',
                $tenantId,
                $oldValues,
                null,
                sprintf('Tenant "%s" (code: %s) deleted', $tenantName, $tenantCode)
            );

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
     * Handle logo upload with security validation
     */
    private function handleLogoUpload(UploadedFile $file, Tenant $tenant): ?string
    {
        try {
            // Security validation using FileUploadSecurityService
            $validation = $this->fileUploadService->validateUpload($file);

            if (!$validation['valid']) {
                $this->addFlash('warning', 'Logo upload failed: ' . $validation['error']);
                $this->logger->warning('Logo upload validation failed', [
                    'tenant_code' => $tenant->getCode(),
                    'error' => $validation['error'],
                ]);
                return null;
            }

            // Generate safe filename
            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $newFilename = sprintf(
                '%s-%s.%s',
                $tenant->getCode(),
                uniqid(),
                $file->guessExtension()
            );

            // Move file to uploads directory
            $uploadsPath = $this->getParameter('kernel.project_dir') . '/public/' . $this->uploadsDirectory;
            $file->move($uploadsPath, $newFilename);

            $this->logger->info('Logo uploaded successfully', [
                'tenant_code' => $tenant->getCode(),
                'filename' => $newFilename,
            ]);

            return $this->uploadsDirectory . '/' . $newFilename;

        } catch (\Exception $e) {
            $this->logger->error('Logo upload failed', [
                'tenant_code' => $tenant->getCode(),
                'error' => $e->getMessage(),
            ]);
            $this->addFlash('warning', 'Logo upload failed. Please try again.');
            return null;
        }
    }

    /**
     * Delete old logo file
     */
    private function deleteOldLogo(string $logoPath): void
    {
        try {
            $fullPath = $this->getParameter('kernel.project_dir') . '/public/' . $logoPath;
            if (file_exists($fullPath)) {
                unlink($fullPath);
                $this->logger->info('Old logo deleted', ['path' => $logoPath]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete old logo', [
                'path' => $logoPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    #[Route('/{id}/set-parent', name: 'tenant_management_set_parent', methods: ['POST'])]
    #[IsGranted('TENANT_EDIT')]
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
    #[IsGranted('TENANT_EDIT')]
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

    /**
     * Add a user to the tenant
     */
    #[Route('/{id}/add-user', name: 'tenant_management_add_user', methods: ['POST'])]
    #[IsGranted('TENANT_EDIT')]
    public function addUser(Request $request, Tenant $tenant): Response
    {
        // Verify CSRF token
        if (!$this->isCsrfTokenValid('add_user_' . $tenant->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'common.csrf_error');
            return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
        }

        $userEmail = $request->request->get('user_email');

        if (!$userEmail) {
            $this->addFlash('warning', 'tenant.users.flash.email_required');
            return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
        }

        try {
            // Find user by email
            $user = $this->entityManager->getRepository(\App\Entity\User::class)->findOneBy(['email' => $userEmail]);

            if (!$user) {
                $this->addFlash('warning', 'tenant.users.flash.user_not_found');
                return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
            }

            // Check if user already assigned to this tenant
            if ($user->getTenant() && $user->getTenant()->getId() === $tenant->getId()) {
                $this->addFlash('info', 'tenant.users.flash.already_assigned');
                return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
            }

            // Assign user to tenant
            $user->setTenant($tenant);
            $this->entityManager->flush();

            $this->logger->info('User added to tenant', [
                'tenant_id' => $tenant->getId(),
                'user_id' => $user->getId(),
                'user_email' => $user->getEmail(),
                'admin' => $this->getUser()?->getUserIdentifier(),
            ]);

            // Audit log
            $this->auditLogger->logCustom(
                'tenant_user_added',
                'Tenant',
                $tenant->getId(),
                null,
                ['user_email' => $user->getEmail()],
                sprintf('User "%s" added to tenant "%s"', $user->getEmail(), $tenant->getName())
            );

            $this->addFlash('success', 'tenant.users.flash.user_added');
        } catch (\Exception $e) {
            $this->logger->error('Failed to add user to tenant', [
                'tenant_id' => $tenant->getId(),
                'user_email' => $userEmail,
                'error' => $e->getMessage(),
            ]);

            $this->addFlash('danger', 'tenant.users.flash.error');
        }

        return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
    }

    /**
     * Remove a user from the tenant
     */
    #[Route('/{id}/remove-user/{userId}', name: 'tenant_management_remove_user', methods: ['POST'])]
    #[IsGranted('TENANT_EDIT')]
    public function removeUser(Request $request, Tenant $tenant, int $userId): Response
    {
        // Verify CSRF token
        if (!$this->isCsrfTokenValid('remove_user_' . $tenant->getId() . '_' . $userId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'common.csrf_error');
            return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
        }

        try {
            $user = $this->entityManager->getRepository(\App\Entity\User::class)->find($userId);

            if (!$user) {
                $this->addFlash('warning', 'tenant.users.flash.user_not_found');
                return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
            }

            // Check if user belongs to this tenant
            if (!$user->getTenant() || $user->getTenant()->getId() !== $tenant->getId()) {
                $this->addFlash('warning', 'tenant.users.flash.not_assigned');
                return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
            }

            $userEmail = $user->getEmail();

            // Remove user from tenant (set to null)
            $user->setTenant(null);
            $this->entityManager->flush();

            $this->logger->info('User removed from tenant', [
                'tenant_id' => $tenant->getId(),
                'user_id' => $user->getId(),
                'user_email' => $userEmail,
                'admin' => $this->getUser()?->getUserIdentifier(),
            ]);

            // Audit log
            $this->auditLogger->logCustom(
                'tenant_user_removed',
                'Tenant',
                $tenant->getId(),
                ['user_email' => $userEmail],
                null,
                sprintf('User "%s" removed from tenant "%s"', $userEmail, $tenant->getName())
            );

            $this->addFlash('success', 'tenant.users.flash.user_removed');
        } catch (\Exception $e) {
            $this->logger->error('Failed to remove user from tenant', [
                'tenant_id' => $tenant->getId(),
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $this->addFlash('danger', 'tenant.users.flash.error');
        }

        return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
    }

    /**
     * API endpoint to get ISMS Context for a tenant
     */
    #[Route('/api/tenants/{id}/isms-context', name: 'api_tenant_isms_context', methods: ['GET'])]
    #[IsGranted('TENANT_VIEW')]
    public function getISMSContext(Tenant $tenant): JsonResponse
    {
        try {
            // Get ISMS Context for this tenant
            $context = $this->ismsContextRepository->findOneBy(['tenant' => $tenant]);

            if (!$context) {
                return new JsonResponse([
                    'error' => true,
                    'message' => 'No ISMS context found for this tenant',
                    'editUrl' => $this->generateUrl('app_context_edit'),
                ]);
            }

            // Sync organization name from tenant
            $this->ismsContextService->syncOrganizationNameFromTenant($context);
            $this->entityManager->flush();

            // Get effective context (considering corporate inheritance)
            $effectiveContext = $this->ismsContextService->getEffectiveContext($context);
            $inheritanceInfo = $this->ismsContextService->getContextInheritanceInfo($context);
            $completeness = $this->ismsContextService->calculateCompleteness($effectiveContext);
            $canEdit = $this->ismsContextService->canEditContext($context);

            // Serialize context data
            $contextData = [
                'id' => $effectiveContext->getId(),
                'organizationName' => $effectiveContext->getOrganizationName(),
                'ismsScope' => $effectiveContext->getIsmsScope(),
                'scopeExclusions' => $effectiveContext->getScopeExclusions(),
                'externalIssues' => $effectiveContext->getExternalIssues(),
                'internalIssues' => $effectiveContext->getInternalIssues(),
                'interestedParties' => $effectiveContext->getInterestedParties(),
                'interestedPartiesRequirements' => $effectiveContext->getInterestedPartiesRequirements(),
                'legalRequirements' => $effectiveContext->getLegalRequirements(),
                'regulatoryRequirements' => $effectiveContext->getRegulatoryRequirements(),
                'contractualObligations' => $effectiveContext->getContractualObligations(),
                'ismsPolicy' => $effectiveContext->getIsmsPolicy(),
                'rolesAndResponsibilities' => $effectiveContext->getRolesAndResponsibilities(),
                'lastReviewDate' => $effectiveContext->getLastReviewDate()?->format('Y-m-d'),
                'nextReviewDate' => $effectiveContext->getNextReviewDate()?->format('Y-m-d'),
            ];

            // Serialize inheritance info
            $inheritanceData = [
                'isInherited' => $inheritanceInfo['isInherited'],
                'hasParent' => $inheritanceInfo['hasParent'],
            ];

            if ($inheritanceInfo['inheritedFrom']) {
                $inheritanceData['inheritedFrom'] = [
                    'id' => $inheritanceInfo['inheritedFrom']->getId(),
                    'name' => $inheritanceInfo['inheritedFrom']->getName(),
                    'code' => $inheritanceInfo['inheritedFrom']->getCode(),
                ];
            }

            return new JsonResponse([
                'context' => $contextData,
                'inheritanceInfo' => $inheritanceData,
                'completeness' => $completeness,
                'canEdit' => $canEdit,
                'editUrl' => $this->generateUrl('app_context_edit'),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get ISMS context for tenant', [
                'tenant_id' => $tenant->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'error' => true,
                'message' => 'Failed to load ISMS context',
            ], 500);
        }
    }

    /**
     * Edit organisation context settings (industries, size, country)
     */
    #[Route('/{id}/organisation-context', name: 'tenant_management_organisation_context', methods: ['GET', 'POST'])]
    #[IsGranted('TENANT_EDIT')]
    public function editOrganisationContext(Request $request, Tenant $tenant): Response
    {
        $settings = $tenant->getSettings() ?? [];
        $orgSettings = $settings['organisation'] ?? [];

        // Prepare form data
        $formData = [
            'industries' => $orgSettings['industries'] ?? ['other'],
            'employee_count' => $orgSettings['employee_count'] ?? '1-10',
            'country' => $orgSettings['country'] ?? 'DE',
            'description' => $orgSettings['description'] ?? '',
        ];

        $form = $this->createForm(\App\Form\OrganisationInfoType::class, $formData, [
            'include_name' => false, // Name is managed via Tenant entity
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            try {
                // Store old values for audit
                $oldOrgSettings = $orgSettings;

                // Update organisation settings
                $settings['organisation'] = [
                    'industries' => $data['industries'] ?? ['other'],
                    'employee_count' => $data['employee_count'],
                    'country' => $data['country'],
                    'description' => $data['description'] ?? '',
                    'selected_modules' => $orgSettings['selected_modules'] ?? [],
                    'selected_frameworks' => $orgSettings['selected_frameworks'] ?? [],
                    'setup_completed_at' => $orgSettings['setup_completed_at'] ?? null,
                    'last_modified_at' => (new \DateTimeImmutable())->format('c'),
                ];

                $tenant->setSettings($settings);
                $this->entityManager->flush();

                // Audit log
                $this->auditLogger->logCustom(
                    'organisation_context_updated',
                    'Tenant',
                    $tenant->getId(),
                    ['organisation' => $oldOrgSettings],
                    ['organisation' => $settings['organisation']],
                    sprintf('Organisation context updated for tenant "%s"', $tenant->getName())
                );

                $this->addFlash('success', 'tenant.flash.organisation_context_updated');

                return $this->redirectToRoute('tenant_management_show', ['id' => $tenant->getId()]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to update organisation context', [
                    'tenant_id' => $tenant->getId(),
                    'error' => $e->getMessage(),
                ]);
                $this->addFlash('danger', 'tenant.flash.organisation_context_error');
            }
        }

        return $this->render('admin/tenants/organisation_context.html.twig', [
            'tenant' => $tenant,
            'form' => $form,
            'current_settings' => $orgSettings,
        ]);
    }
}

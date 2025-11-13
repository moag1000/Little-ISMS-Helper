<?php

namespace App\Controller;

use App\Entity\Tenant;
use App\Form\TenantType;
use App\Repository\TenantRepository;
use App\Service\FileUploadSecurityService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/tenants')]
#[IsGranted('ROLE_ADMIN')]
class TenantManagementController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly LoggerInterface $logger,
        private readonly FileUploadSecurityService $fileUploadService,
        private readonly SluggerInterface $slugger,
        private readonly string $uploadsDirectory = 'uploads/tenants',
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

        return $this->render('admin/tenants/show.html.twig', [
            'tenant' => $tenant,
            'userCount' => $users->count(),
            'activeUsers' => $activeUsers,
            'inactiveUsers' => $users->count() - $activeUsers,
            'recentActivity' => $recentActivity,
        ]);
    }

    #[Route('/{id}/edit', name: 'tenant_management_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Tenant $tenant): Response
    {
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
}

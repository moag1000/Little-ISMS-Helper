<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BulkDeleteService;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Controller\Trait\BulkActionTrait;
use App\Controller\Trait\InPageFormTrait;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Patch;
use App\Enum\PatchStatus;
use App\Form\PatchType;
use App\Repository\PatchRepository;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use App\Util\ListPage;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class PatchController extends AbstractController
{
    use BulkActionTrait;
    use InPageFormTrait;
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly PatchRepository $patchRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
        private readonly ?AuditLogger $auditLogger = null,
    ) {}

    #[Route('/patch', name: 'app_patch_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Patch Management — patches module (NIS2 Art. 21.2(e) + ISO 27001 A.8.8)
        if ($redirect = $this->checkModuleActive('patches')) {
            return $redirect;
        }

        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get view filter parameter
        $view = $request->query->get('view', 'own'); // Default: inherited

        // Get patches based on view filter
        if ($tenant) {
            $patches = match ($view) {
                'own' => $this->patchRepository->findByTenant($tenant),
                'subsidiaries' => $this->patchRepository->findByTenantIncludingSubsidiaries($tenant),
                default => $this->patchRepository->findByTenantIncludingParent($tenant),
            };
            $inheritanceInfo = [
                'hasParent' => $tenant->getParent() !== null,
                'hasSubsidiaries' => $tenant->getSubsidiaries()->count() > 0,
                'currentView' => $view
            ];
        } else {
            $patches = $this->patchRepository->findAll();
            $inheritanceInfo = [
                'hasParent' => false,
                'hasSubsidiaries' => false,
                'currentView' => 'own'
            ];
        }

        // Summary chips count the list as shown (view applied).
        $pendingCount = count(array_filter($patches, static fn(Patch $p): bool => in_array($p->getStatus(), ['available', 'tested', 'approved'], true)));
        $deployedCount = count(array_filter($patches, static fn(Patch $p): bool => $p->getStatus() === PatchStatus::Deployed->value));

        return $this->render('patch/index.html.twig', [
            'page' => ListPage::fromRequest(
                $patches,
                $request,
                static fn(Patch $p): array => [$p->getPatchId(), $p->getTitle()],
            ),
            'pending_count' => $pendingCount,
            'deployed_count' => $deployedCount,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
        ]);
    }

    #[Route('/patch/new', name: 'app_patch_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('patches')) {
            return $redirect;
        }

        $patch = new Patch();

        // Set tenant from current user
        $user = $this->security->getUser();
        if ($user instanceof UserInterface && $user->getTenant()) {
            $patch->setTenant($user->getTenant());
        }

        $form = $this->createForm(PatchType::class, $patch);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($patch);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('patch.success.created', [], 'messages'));

            if ($this->isTurboFrameRequest($request)) {
                return $this->patchStreamSave($patch, isNew: true);
            }
            return $this->redirectToRoute('app_patch_show', ['id' => $patch->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('patch/_form_modal.html.twig', [
                'patch' => $patch,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('patch/new.html.twig', [
            'patch' => $patch,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/patch/{id}', name: 'app_patch_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, Patch $patch): Response
    {
        if ($redirect = $this->checkModuleActive('patches')) {
            return $redirect;
        }

        // In-modal → condensed read-only detail; direct URL → full page (fallback).
        if ($this->isTurboFrameRequest($request)) {
            return $this->render('patch/_detail_modal.html.twig', [
                'patch' => $patch,
            ]);
        }

        return $this->render('patch/show.html.twig', [
            'patch' => $patch,
        ]);
    }

    #[Route('/patch/{id}/edit', name: 'app_patch_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Patch $patch): Response
    {
        if ($redirect = $this->checkModuleActive('patches')) {
            return $redirect;
        }

        $form = $this->createForm(PatchType::class, $patch);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('patch.success.updated', [], 'messages'));

            if ($this->isTurboFrameRequest($request)) {
                return $this->patchStreamSave($patch, isNew: false);
            }
            return $this->redirectToRoute('app_patch_show', ['id' => $patch->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('patch/_form_modal.html.twig', [
                'patch' => $patch,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('patch/edit.html.twig', [
            'patch' => $patch,
            'form' => $form,
        ], new Response(status: $status));
    }

    /** Turbo Stream after a successful in-modal Patch save (row replace/append). */
    private function patchStreamSave(Patch $patch, bool $isNew): Response
    {
        return $this->render('patch/_stream_save.html.twig', [
            'patch' => $patch,
            'is_new' => $isNew,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    #[Route('/patch/{id}/delete', name: 'app_patch_delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, Patch $patch): Response
    {
        if ($redirect = $this->checkModuleActive('patches')) {
            return $redirect;
        }

        if ($this->isCsrfTokenValid('delete'.$patch->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($patch);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('patch.success.deleted', [], 'messages'));
        }

        return $this->redirectToRoute('app_patch_index');
    }

    /**
     * Dependency-check endpoint for the Aurora bulk-delete-confirmation modal.
     * Patches have no blocking FK relations — returns empty dependencies.
     */
    // @csrf-exempt: read-only dependency check for the bulk-delete confirmation modal
    #[Route('/patch/bulk-delete-check', name: 'app_patch_bulk_delete_check', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDeleteCheck(Request $request): JsonResponse
    {
        if (!$this->moduleService->isModuleActive('patches')) {
            return new JsonResponse(['error' => 'Module not active'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $ids = (array) ($data['ids'] ?? []);
        return new JsonResponse(['dependencies' => [], 'checked_count' => count($ids)]);
    }

    #[Route('/patch/bulk-delete', name: 'app_patch_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDelete(Request $request, BulkDeleteService $bulkDeleteService): JsonResponse
    {
        if (!$this->moduleService->isModuleActive('patches')) {
            return new JsonResponse(['error' => 'Module not active'], Response::HTTP_FORBIDDEN);
        }

        return $this->handleBulkDelete(
            $request,
            $bulkDeleteService,
            'Patch',
            $this->security->getUser(),
            fn (int $id): ?object => $this->patchRepository->find($id),
        );
    }

    /**
     * Bulk CSV export of selected patches.
     * ISO 27001 Cl. 7.5.3 — audit-logged via BulkActionTrait.
     */
    #[Route('/patch/bulk-export', name: 'app_patch_bulk_export', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulkExport(Request $request, TenantContext $tenantContext): StreamedResponse|Response
    {
        if (!$this->moduleService->isModuleActive('patches')) {
            return $this->json(['error' => 'Module not active'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('bulk_action', (string) ($data['_token'] ?? ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        $ids  = $data['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $patches = [];
        foreach ($ids as $rawId) {
            $patch = $this->patchRepository->find((int) $rawId);
            if ($patch === null) {
                continue;
            }
            if (!$this->isInBulkContextScope($patch, $tenantContext)) {
                continue;
            }
            $patches[] = $patch;
        }

        if ($patches === []) {
            return $this->json(['error' => 'No exportable patches'], 404);
        }

        $headers = ['ID', 'Title', 'Status', 'Priority', 'Version', 'Vendor', 'Product', 'Release Date'];

        return $this->streamCsvExport(
            $patches,
            $headers,
            static function (Patch $p): array {
                return [
                    (string) $p->getId(),
                    (string) $p->getTitle(),
                    (string) $p->getStatus(),
                    (string) $p->getPriority(),
                    (string) $p->getVersion(),
                    (string) $p->getVendor(),
                    (string) $p->getProduct(),
                    $p->getReleaseDate()?->format('Y-m-d') ?? '',
                ];
            },
            'patches-export',
            'Patch',
            $this->auditLogger,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use App\Controller\Trait\BulkActionTrait;
use App\Entity\Patch;
use App\Form\PatchType;
use App\Repository\PatchRepository;
use App\Service\AuditLogger;
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

    public function __construct(
        private readonly PatchRepository $patchRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ?AuditLogger $auditLogger = null,
    ) {}

    #[Route('/patch', name: 'app_patch_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
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

        // Statistics
        $deploymentStats = $this->patchRepository->getDeploymentStatistics();
        $pendingPatches = array_filter($patches, fn(Patch $patch): bool => in_array($patch->getStatus(), ['available', 'tested', 'approved']));

        // Calculate detailed statistics based on origin
        if ($tenant) {
            $detailedStats = $this->calculateDetailedStats($patches, $tenant);
        } else {
            $detailedStats = ['own' => count($patches), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($patches)];
        }

        return $this->render('patch/index.html.twig', [
            'patches' => $patches,
            'deployment_stats' => $deploymentStats,
            'pending_count' => count($pendingPatches),
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
        ]);
    }

    #[Route('/patch/new', name: 'app_patch_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
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

            $this->addFlash('success', $this->translator->trans('patch.success.created')); // @todo H-06 flash-domain
            return $this->redirectToRoute('app_patch_show', ['id' => $patch->getId()]);
        }

        return $this->render('patch/new.html.twig', [
            'patch' => $patch,
            'form' => $form,
        ]);
    }

    #[Route('/patch/{id}', name: 'app_patch_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Patch $patch): Response
    {
        return $this->render('patch/show.html.twig', [
            'patch' => $patch,
        ]);
    }

    #[Route('/patch/{id}/edit', name: 'app_patch_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Patch $patch): Response
    {
        $form = $this->createForm(PatchType::class, $patch);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('patch.success.updated')); // @todo H-06 flash-domain
            return $this->redirectToRoute('app_patch_show', ['id' => $patch->getId()]);
        }

        return $this->render('patch/edit.html.twig', [
            'patch' => $patch,
            'form' => $form,
        ]);
    }

    #[Route('/patch/{id}/delete', name: 'app_patch_delete', methods: ['POST'])]
    public function delete(Request $request, Patch $patch): Response
    {
        if ($this->isCsrfTokenValid('delete'.$patch->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($patch);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('patch.success.deleted')); // @todo H-06 flash-domain
        }

        return $this->redirectToRoute('app_patch_index');
    }

    /**
     * Calculate detailed statistics showing breakdown by origin
     */
    private function calculateDetailedStats(array $items, $currentTenant): array
    {
        $ownCount = 0;
        $inheritedCount = 0;
        $subsidiariesCount = 0;

        // Get ancestors and subsidiaries for comparison
        $ancestors = $currentTenant->getAllAncestors();
        $ancestorIds = array_map(fn($t) => $t->getId(), $ancestors);

        $subsidiaries = $currentTenant->getAllSubsidiaries();
        $subsidiaryIds = array_map(fn($t) => $t->getId(), $subsidiaries);

        foreach ($items as $item) {
            $itemTenant = $item->getTenant();
            if (!$itemTenant) {
                continue;
            }

            $itemTenantId = $itemTenant->getId();
            $currentTenantId = $currentTenant->getId();

            if ($itemTenantId === $currentTenantId) {
                $ownCount++;
            } elseif (in_array($itemTenantId, $ancestorIds)) {
                $inheritedCount++;
            } elseif (in_array($itemTenantId, $subsidiaryIds)) {
                $subsidiariesCount++;
            }
        }

        return [
            'own' => $ownCount,
            'inherited' => $inheritedCount,
            'subsidiaries' => $subsidiariesCount,
            'total' => $ownCount + $inheritedCount + $subsidiariesCount
        ];
    }

    #[Route('/patch/bulk-delete', name: 'app_patch_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $tenant = $this->security->getUser()?->getTenant();
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $patch = $this->patchRepository->find($id);
                if (!$patch) {
                    $errors[] = "Patch ID $id not found";
                    continue;
                }
                if ($tenant && $patch->getTenant() !== $tenant) {
                    $errors[] = "Patch ID $id does not belong to your organization";
                    continue;
                }
                $this->entityManager->remove($patch);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting Patch ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        return $this->json([
            'success' => $deleted > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => "$deleted patches deleted successfully",
        ]);
    }

    /**
     * Bulk CSV export of selected patches.
     * ISO 27001 Cl. 7.5.3 — audit-logged via BulkActionTrait.
     */
    #[Route('/patch/bulk-export', name: 'app_patch_bulk_export', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulkExport(Request $request): StreamedResponse|Response
    {
        $data = json_decode($request->getContent(), true);
        $ids  = $data['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $user   = $this->security->getUser();
        $tenant = $user?->getTenant();

        $patches = [];
        foreach ($ids as $rawId) {
            $patch = $this->patchRepository->find((int) $rawId);
            if ($patch === null) {
                continue;
            }
            if ($tenant !== null && $patch->getTenant() !== $tenant) {
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

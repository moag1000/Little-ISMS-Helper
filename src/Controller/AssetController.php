<?php

namespace App\Controller;

use Exception;
use App\Entity\Asset;
use App\Form\AssetType;
use App\Repository\AssetRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\RiskRepository;
use App\Service\AssetService;
use App\Service\ProtectionRequirementService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class AssetController extends AbstractController
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AssetService $assetService,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly ProtectionRequirementService $protectionRequirementService,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly RiskRepository $riskRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly TenantContext $tenantContext
    ) {}
    #[Route('/asset/', name: 'app_asset_index')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get current tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get filter parameters
        $type = $request->query->get('type');
        $classification = $request->query->get('classification');
        $owner = $request->query->get('owner');
        $status = $request->query->get('status');
        $view = $request->query->get('view', 'inherited'); // Default: inherited

        // Get assets based on view filter
        if ($tenant) {
            // Determine which assets to load based on view parameter
            $assets = match ($view) {
                // Only own assets
                'own' => $this->assetRepository->findByTenant($tenant),
                // Own + from all subsidiaries (for parent companies)
                'subsidiaries' => $this->assetRepository->findByTenantIncludingSubsidiaries($tenant),
                // Own + inherited from parents (default behavior)
                default => $this->assetService->getAssetsForTenant($tenant),
            };
            $inheritanceInfo = $this->assetService->getAssetInheritanceInfo($tenant);
            $inheritanceInfo['hasSubsidiaries'] = $tenant->getSubsidiaries()->count() > 0;
            $inheritanceInfo['currentView'] = $view;
        } else {
            $assets = $this->assetRepository->findAll();
            $inheritanceInfo = [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
                'hasSubsidiaries' => false,
                'currentView' => 'own'
            ];
        }

        // Filter to active only first
        $assets = array_filter($assets, fn(Asset $asset): bool => $asset->getStatus() === 'active');

        if ($type) {
            $assets = array_filter($assets, fn(Asset $asset): bool => $asset->getAssetType() === $type);
        }

        if ($classification) {
            $assets = array_filter($assets, fn(Asset $asset): bool => $asset->getDataClassification() === $classification);
        }

        if ($owner) {
            $assets = array_filter($assets, fn(Asset $asset): bool => stripos((string) $asset->getOwner(), $owner) !== false);
        }

        if ($status) {
            $assets = array_filter($assets, fn(Asset $asset): bool => $asset->getStatus() === $status);
        }

        // Re-index array after filtering to avoid gaps in keys
        $assets = array_values($assets);

        $typeStats = $this->assetRepository->countByType();

        // Calculate BCM-based suggestions and risk count for each asset
        $assetRecommendations = [];
        foreach ($assets as $asset) {
            $analysis = $this->protectionRequirementService->getCompleteProtectionRequirementAnalysis($asset);
            $processes = $this->businessProcessRepository->findByAsset($asset->getId());
            $risks = $this->riskRepository->findBy(['asset' => $asset]);

            $assetRecommendations[$asset->getId()] = [
                'availability_analysis' => $analysis['availability'],
                'has_bcm_data' => $processes !== [],
                'process_count' => count($processes),
                'processes' => $processes,
                'risk_count' => count($risks),
            ];
        }

        // Calculate detailed statistics based on origin
        if ($tenant) {
            $detailedStats = $this->calculateDetailedStats($assets, $tenant);
        } else {
            $detailedStats = ['own' => count($assets), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($assets)];
        }

        return $this->render('asset/index.html.twig', [
            'assets' => $assets,
            'typeStats' => $typeStats,
            'recommendations' => $assetRecommendations,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
        ]);
    }
    #[Route('/asset/new', name: 'app_asset_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $asset = new Asset();
        $asset->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(AssetType::class, $asset);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($asset);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('asset.success.created'));
            return $this->redirectToRoute('app_asset_show', ['id' => $asset->getId()]);
        }

        return $this->render('asset/new.html.twig', [
            'asset' => $asset,
            'form' => $form,
        ]);
    }
    #[Route('/asset/bulk-delete', name: 'app_asset_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkDelete(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $asset = $this->assetRepository->find($id);

                if (!$asset) {
                    $errors[] = "Asset ID $id not found";
                    continue;
                }

                // Security check: only allow deletion of own tenant's assets
                if ($asset->getTenant() !== $tenant) {
                    $errors[] = "Asset ID $id does not belong to your organization";
                    continue;
                }

                // Check for dependencies (optional warning, but still allow delete)
                $risks = $asset->getRisks();
                if ($risks->count() > 0) {
                    // Log dependency info but continue
                }

                $this->entityManager->remove($asset);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting asset ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        $message = $deleted > 0
            ? $this->translator->trans('asset.bulk_delete.success', ['count' => $deleted])
            : $this->translator->trans('asset.bulk_delete.no_items');

        if ($errors !== []) {
            return $this->json([
                'success' => $deleted > 0,
                'deleted' => $deleted,
                'errors' => $errors,
                'message' => $message
            ], $deleted > 0 ? 200 : 400);
        }

        return $this->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => $message
        ]);
    }
    #[Route('/asset/{id}', name: 'app_asset_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Asset $asset): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        $analysis = $this->protectionRequirementService->getCompleteProtectionRequirementAnalysis($asset);
        $processes = $this->businessProcessRepository->findByAsset($asset->getId());

        // Get audit log history for this asset (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('Asset', $asset->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        // Check if asset is inherited and can be edited (only if user has tenant)
        if ($tenant) {
            $isInherited = $this->assetService->isInheritedAsset($asset, $tenant);
            $canEdit = $this->assetService->canEditAsset($asset, $tenant);
        } else {
            $isInherited = false;
            $canEdit = true;
        }

        return $this->render('asset/show.html.twig', [
            'asset' => $asset,
            'analysis' => $analysis,
            'processes' => $processes,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
            'isInherited' => $isInherited,
            'canEdit' => $canEdit,
            'currentTenant' => $tenant,
        ]);
    }
    #[Route('/asset/{id}/edit', name: 'app_asset_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Asset $asset): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if asset can be edited (not inherited) - only if user has tenant
        if ($tenant && !$this->assetService->canEditAsset($asset, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_edit_inherited'));
            return $this->redirectToRoute('app_asset_show', ['id' => $asset->getId()]);
        }

        $form = $this->createForm(AssetType::class, $asset);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('asset.success.updated'));
            return $this->redirectToRoute('app_asset_show', ['id' => $asset->getId()]);
        }

        return $this->render('asset/edit.html.twig', [
            'asset' => $asset,
            'form' => $form,
        ]);
    }
    #[Route('/asset/{id}/delete', name: 'app_asset_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Asset $asset): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if asset can be deleted (not inherited) - only if user has tenant
        if ($tenant && !$this->assetService->canEditAsset($asset, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_delete_inherited'));
            return $this->redirectToRoute('app_asset_index');
        }

        if ($this->isCsrfTokenValid('delete'.$asset->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($asset);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('asset.success.deleted'));
        }

        return $this->redirectToRoute('app_asset_index');
    }
    #[Route('/asset/{id}/bcm-insights', name: 'app_asset_bcm_insights', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function bcmInsights(int $id): Response
    {
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        $asset = $this->assetRepository->find($id);

        if (!$asset) {
            throw $this->createNotFoundException('Asset not found');
        }

        $analysis = $this->protectionRequirementService->getCompleteProtectionRequirementAnalysis($asset);
        $processes = $this->businessProcessRepository->findByAsset($id);

        // Check if asset is inherited (only if user has tenant)
        if ($tenant) {
            $isInherited = $this->assetService->isInheritedAsset($asset, $tenant);
            $canEdit = $this->assetService->canEditAsset($asset, $tenant);
        } else {
            $isInherited = false;
            $canEdit = true;
        }

        return $this->render('asset/bcm_insights.html.twig', [
            'asset' => $asset,
            'analysis' => $analysis,
            'processes' => $processes,
            'isInherited' => $isInherited,
            'canEdit' => $canEdit,
            'currentTenant' => $tenant,
        ]);
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
}

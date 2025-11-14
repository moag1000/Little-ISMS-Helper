<?php

namespace App\Controller;

use App\Entity\Asset;
use App\Form\AssetType;
use App\Repository\AssetRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BusinessProcessRepository;
use App\Service\AssetService;
use App\Service\ProtectionRequirementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/asset')]
class AssetController extends AbstractController
{
    public function __construct(
        private AssetRepository $assetRepository,
        private AssetService $assetService,
        private AuditLogRepository $auditLogRepository,
        private ProtectionRequirementService $protectionRequirementService,
        private BusinessProcessRepository $businessProcessRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private Security $security
    ) {}

    #[Route('/', name: 'app_asset_index')]
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
            switch ($view) {
                case 'own':
                    // Only own assets
                    $assets = $this->assetRepository->findByTenant($tenant);
                    break;
                case 'subsidiaries':
                    // Own + from all subsidiaries (for parent companies)
                    $assets = $this->assetRepository->findByTenantIncludingSubsidiaries($tenant);
                    break;
                case 'inherited':
                default:
                    // Own + inherited from parents (default behavior)
                    $assets = $this->assetService->getAssetsForTenant($tenant);
                    break;
            }

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
        $assets = array_filter($assets, fn($asset) => $asset->getStatus() === 'active');

        if ($type) {
            $assets = array_filter($assets, fn($asset) => $asset->getAssetType() === $type);
        }

        if ($classification) {
            $assets = array_filter($assets, fn($asset) => $asset->getDataClassification() === $classification);
        }

        if ($owner) {
            $assets = array_filter($assets, fn($asset) => stripos($asset->getOwner(), $owner) !== false);
        }

        if ($status) {
            $assets = array_filter($assets, fn($asset) => $asset->getStatus() === $status);
        }

        // Re-index array after filtering to avoid gaps in keys
        $assets = array_values($assets);

        $typeStats = $this->assetRepository->countByType();

        // Calculate BCM-based suggestions for each asset
        $assetRecommendations = [];
        foreach ($assets as $asset) {
            $analysis = $this->protectionRequirementService->getCompleteProtectionRequirementAnalysis($asset);
            $processes = $this->businessProcessRepository->findByAsset($asset->getId());

            $assetRecommendations[$asset->getId()] = [
                'availability_analysis' => $analysis['availability'],
                'has_bcm_data' => !empty($processes),
                'process_count' => count($processes),
                'processes' => $processes,
            ];
        }

        return $this->render('asset/index.html.twig', [
            'assets' => $assets,
            'typeStats' => $typeStats,
            'recommendations' => $assetRecommendations,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
        ]);
    }

    #[Route('/new', name: 'app_asset_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $asset = new Asset();
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

    #[Route('/{id}', name: 'app_asset_show', requirements: ['id' => '\d+'])]
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

    #[Route('/{id}/edit', name: 'app_asset_edit', requirements: ['id' => '\d+'])]
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

    #[Route('/{id}/delete', name: 'app_asset_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
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

    #[Route('/{id}/bcm-insights', name: 'app_asset_bcm_insights', requirements: ['id' => '\d+'])]
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
}

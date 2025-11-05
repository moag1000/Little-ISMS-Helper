<?php

namespace App\Controller;

use App\Repository\AssetRepository;
use App\Repository\BusinessProcessRepository;
use App\Service\ProtectionRequirementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/asset')]
class AssetController extends AbstractController
{
    public function __construct(
        private AssetRepository $assetRepository,
        private ProtectionRequirementService $protectionRequirementService,
        private BusinessProcessRepository $businessProcessRepository
    ) {}

    #[Route('/', name: 'app_asset_index')]
    public function index(): Response
    {
        $assets = $this->assetRepository->findActiveAssets();
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
        ]);
    }

    #[Route('/{id}/bcm-insights', name: 'app_asset_bcm_insights', requirements: ['id' => '\d+'])]
    public function bcmInsights(int $id): Response
    {
        $asset = $this->assetRepository->find($id);

        if (!$asset) {
            throw $this->createNotFoundException('Asset not found');
        }

        $analysis = $this->protectionRequirementService->getCompleteProtectionRequirementAnalysis($asset);
        $processes = $this->businessProcessRepository->findByAsset($id);

        return $this->render('asset/bcm_insights.html.twig', [
            'asset' => $asset,
            'analysis' => $analysis,
            'processes' => $processes,
        ]);
    }
}

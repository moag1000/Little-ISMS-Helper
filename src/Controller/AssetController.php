<?php

namespace App\Controller;

use App\Repository\AssetRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/asset')]
class AssetController extends AbstractController
{
    public function __construct(private AssetRepository $assetRepository) {}

    #[Route('/', name: 'app_asset_index')]
    public function index(): Response
    {
        $assets = $this->assetRepository->findActiveAssets();
        $typeStats = $this->assetRepository->countByType();

        return $this->render('asset/index.html.twig', [
            'assets' => $assets,
            'typeStats' => $typeStats,
        ]);
    }
}

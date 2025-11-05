<?php

namespace App\Controller;

use App\Repository\BusinessProcessRepository;
use App\Service\ProtectionRequirementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/bcm')]
class BCMController extends AbstractController
{
    public function __construct(
        private BusinessProcessRepository $businessProcessRepository,
        private ProtectionRequirementService $protectionRequirementService
    ) {}

    #[Route('/', name: 'app_bcm_index')]
    public function index(): Response
    {
        $processes = $this->businessProcessRepository->findAll();

        // Statistiken
        $stats = [
            'total' => count($processes),
            'critical' => count(array_filter($processes, fn($p) => $p->getCriticality() === 'critical')),
            'high' => count(array_filter($processes, fn($p) => $p->getCriticality() === 'high')),
            'avg_rto' => $this->calculateAverageRTO($processes),
            'avg_mtpd' => $this->calculateAverageMTPD($processes)
        ];

        return $this->render('bcm/index.html.twig', [
            'processes' => $processes,
            'stats' => $stats,
        ]);
    }

    #[Route('/data-reuse-insights', name: 'app_bcm_data_reuse')]
    public function dataReuseInsights(): Response
    {
        $processes = $this->businessProcessRepository->findAll();

        $insights = [];
        $assetsInfluenced = [];

        foreach ($processes as $process) {
            $assets = $process->getSupportingAssets();

            foreach ($assets as $asset) {
                $assetId = $asset->getId();

                // Prevent duplicate asset analysis
                if (isset($assetsInfluenced[$assetId])) {
                    continue;
                }

                $analysis = $this->protectionRequirementService->getCompleteProtectionRequirementAnalysis($asset);

                if ($analysis['availability']['recommendation'] !== null) {
                    $insights[] = [
                        'process' => $process,
                        'asset' => $asset,
                        'analysis' => $analysis,
                        'current_availability' => $asset->getAvailability(),
                        'suggested_availability' => $analysis['availability']['value']
                    ];

                    $assetsInfluenced[$assetId] = true;
                }
            }
        }

        return $this->render('bcm/data_reuse_insights.html.twig', [
            'insights' => $insights,
            'total_processes' => count($processes),
            'assets_influenced' => count($assetsInfluenced),
        ]);
    }

    #[Route('/critical', name: 'app_bcm_critical')]
    public function criticalProcesses(): Response
    {
        $processes = $this->businessProcessRepository->findCriticalProcesses();

        return $this->render('bcm/critical.html.twig', [
            'processes' => $processes,
        ]);
    }

    private function calculateAverageRTO(array $processes): float
    {
        if (empty($processes)) return 0;

        $total = array_reduce($processes, fn($carry, $p) => $carry + $p->getRto(), 0);
        return round($total / count($processes), 1);
    }

    private function calculateAverageMTPD(array $processes): float
    {
        if (empty($processes)) return 0;

        $total = array_reduce($processes, fn($carry, $p) => $carry + $p->getMtpd(), 0);
        return round($total / count($processes), 1);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\BusinessProcess;
use App\Repository\BusinessProcessRepository;
use App\Service\ModuleConfigurationService;
use App\Service\ProtectionRequirementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
#[RequiresModule('bcm')]
class BCMController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly ProtectionRequirementService $protectionRequirementService,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {}
    /** One business-process list: /bcm forwards to it (filters kept). */
    #[Route('/bcm', name: 'app_bcm_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->redirectToRoute('app_business_process_index', $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/bcm/data-reuse-insights', name: 'app_bcm_data_reuse', methods: ['GET'])]
    public function dataReuseInsights(): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

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
                        'current_availability' => $asset->getAvailabilityValue(),
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
    /** Former "critical processes" page = the process list filtered by criticality. */
    #[Route('/bcm/critical', name: 'app_bcm_critical', methods: ['GET'])]
    public function criticalProcesses(): Response
    {
        return $this->redirectToRoute('app_business_process_index', ['criticality' => 'critical'], Response::HTTP_MOVED_PERMANENTLY);
    }
}

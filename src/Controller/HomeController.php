<?php

namespace App\Controller;

use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private AssetRepository $assetRepository,
        private RiskRepository $riskRepository,
        private IncidentRepository $incidentRepository,
        private ControlRepository $controlRepository
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig');
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        $assetCount = count($this->assetRepository->findActiveAssets());
        $riskCount = count($this->riskRepository->findAll());
        $openIncidentCount = count($this->incidentRepository->findOpenIncidents());

        $applicableControls = $this->controlRepository->findApplicableControls();
        $implementedControls = array_filter($applicableControls, fn($c) => $c->getImplementationStatus() === 'implemented');
        $compliancePercentage = count($applicableControls) > 0
            ? round((count($implementedControls) / count($applicableControls)) * 100)
            : 0;

        $kpis = [
            [
                'name' => 'Erfasste Assets',
                'value' => $assetCount,
                'unit' => 'Stück',
                'icon' => '🖥️',
            ],
            [
                'name' => 'Identifizierte Risiken',
                'value' => $riskCount,
                'unit' => 'Stück',
                'icon' => '⚠️',
            ],
            [
                'name' => 'Offene Vorfälle',
                'value' => $openIncidentCount,
                'unit' => 'Stück',
                'icon' => '🚨',
            ],
            [
                'name' => 'Compliance-Status',
                'value' => $compliancePercentage,
                'unit' => '%',
                'icon' => '✅',
            ],
        ];

        return $this->render('home/dashboard.html.twig', [
            'kpis' => $kpis,
        ]);
    }
}

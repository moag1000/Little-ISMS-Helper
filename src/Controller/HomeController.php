<?php

namespace App\Controller;

use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class HomeController extends AbstractController
{
    public function __construct(
        private AssetRepository $assetRepository,
        private RiskRepository $riskRepository,
        private IncidentRepository $incidentRepository,
        private ControlRepository $controlRepository,
        private TranslatorInterface $translator
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
                'name' => $this->translator->trans('kpi.registered_assets'),
                'value' => $assetCount,
                'unit' => $this->translator->trans('kpi.unit_pieces'),
                'icon' => '🖥️',
            ],
            [
                'name' => $this->translator->trans('kpi.identified_risks'),
                'value' => $riskCount,
                'unit' => $this->translator->trans('kpi.unit_pieces'),
                'icon' => '⚠️',
            ],
            [
                'name' => $this->translator->trans('kpi.open_incidents'),
                'value' => $openIncidentCount,
                'unit' => $this->translator->trans('kpi.unit_pieces'),
                'icon' => '🚨',
            ],
            [
                'name' => $this->translator->trans('kpi.compliance_status'),
                'value' => $compliancePercentage,
                'unit' => $this->translator->trans('kpi.unit_percent'),
                'icon' => '✅',
            ],
        ];

        return $this->render('home/dashboard.html.twig', [
            'kpis' => $kpis,
        ]);
    }
}

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
        // Statistiken für Hero Section
        $stats = [
            'assets' => count($this->assetRepository->findActiveAssets()),
            'risks' => count($this->riskRepository->findAll()),
            'controls' => count($this->controlRepository->findAll()),
            'incidents' => count($this->incidentRepository->findAll()),
        ];

        return $this->render('home/index_modern.html.twig', [
            'stats' => $stats,
        ]);
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

        // Statistiken für moderne Widgets
        $stats = [
            'assets_total' => $assetCount,
            'assets_critical' => count(array_filter($this->assetRepository->findActiveAssets(), fn($a) => $a->getConfidentiality() === 'high')),
            'risks_total' => $riskCount,
            'risks_high' => count(array_filter($this->riskRepository->findAll(), fn($r) => $r->getRiskLevel() >= 12)),
            'controls_total' => count($applicableControls),
            'controls_implemented' => count($implementedControls),
            'incidents_open' => $openIncidentCount,
            'compliance_percentage' => $compliancePercentage,
        ];

        // Activity Feed Daten
        $activities = $this->getRecentActivities();

        return $this->render('home/dashboard_modern.html.twig', [
            'kpis' => $kpis,
            'stats' => $stats,
            'activities' => $activities,
        ]);
    }

    private function getRecentActivities(): array
    {
        $activities = [];

        // Beispiel-Aktivitäten (später durch echte Daten ersetzen)
        $recentAssets = array_slice($this->assetRepository->findActiveAssets(), 0, 3);
        foreach ($recentAssets as $asset) {
            $activities[] = [
                'icon' => 'bi-server',
                'color' => 'primary',
                'title' => 'Asset hinzugefügt',
                'description' => $asset->getName(),
                'time' => $asset->getCreatedAt() ? $asset->getCreatedAt()->diff(new \DateTime())->format('%i Minuten') : 'kürzlich',
                'user' => $asset->getOwner() ?? 'System',
            ];
        }

        $recentRisks = array_slice($this->riskRepository->findAll(), 0, 3);
        foreach ($recentRisks as $risk) {
            $activities[] = [
                'icon' => 'bi-exclamation-triangle',
                'color' => 'warning',
                'title' => 'Risiko identifiziert',
                'description' => $risk->getDescription() ?? 'Neues Risiko',
                'time' => $risk->getCreatedAt() ? $risk->getCreatedAt()->diff(new \DateTime())->format('%i Minuten') : 'kürzlich',
                'user' => 'Security Team',
            ];
        }

        // Nach Zeit sortieren (neueste zuerst)
        usort($activities, function($a, $b) {
            return strcmp($b['time'], $a['time']);
        });

        return array_slice($activities, 0, 10);
    }
}

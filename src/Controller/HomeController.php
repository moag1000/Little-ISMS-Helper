<?php

namespace App\Controller;

use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\ISOComplianceIntelligenceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class HomeController extends AbstractController
{
    public function __construct(
        private AssetRepository $assetRepository,
        private RiskRepository $riskRepository,
        private IncidentRepository $incidentRepository,
        private ControlRepository $controlRepository,
        private TranslatorInterface $translator,
        private ISOComplianceIntelligenceService $isoComplianceService
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(Request $request): Response
    {
        // Get preferred locale from session, browser preference, or default to 'de'
        $locale = $request->getSession()->get('_locale')
            ?? $request->getPreferredLanguage(['de', 'en'])
            ?? 'de';

        // Not authenticated → redirect to login (without locale)
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        // Authenticated → redirect to dashboard (with locale)
        return $this->redirectToRoute('app_dashboard', ['_locale' => $locale]);
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    #[IsGranted('ROLE_USER')]
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
            'assets_critical' => count(array_filter($this->assetRepository->findActiveAssets(), fn($a) => $a->getConfidentialityValue() >= 4)),
            'risks_total' => $riskCount,
            'risks_high' => count(array_filter($this->riskRepository->findAll(), fn($r) => $r->getInherentRiskLevel() >= 12)),
            'controls_total' => count($applicableControls),
            'controls_implemented' => count($implementedControls),
            'incidents_open' => $openIncidentCount,
            'compliance_percentage' => $compliancePercentage,
        ];

        // Activity Feed Daten
        $activities = $this->getRecentActivities();

        // ISO Compliance Dashboard
        $isoCompliance = $this->isoComplianceService->getComplianceDashboard();

        return $this->render('home/dashboard_modern.html.twig', [
            'kpis' => $kpis,
            'stats' => $stats,
            'activities' => $activities,
            'iso_compliance' => $isoCompliance,
        ]);
    }

    private function getRecentActivities(): array
    {
        $activities = [];

        // Beispiel-Aktivitäten (später durch echte Daten ersetzen)
        $recentAssets = array_slice($this->assetRepository->findActiveAssets(), 0, 3);
        foreach ($recentAssets as $asset) {
            $createdAt = $asset->getCreatedAt();
            $timeAgo = $createdAt ? sprintf($this->translator->trans('activity.minutes_ago'), $createdAt->diff(new \DateTime())->i) : $this->translator->trans('activity.recently');

            $activities[] = [
                'icon' => 'bi-server',
                'color' => 'primary',
                'title' => $this->translator->trans('activity.asset_added'),
                'description' => $asset->getName(),
                'time' => $timeAgo,
                'user' => $asset->getOwner() ?? $this->translator->trans('activity.system'),
            ];
        }

        $recentRisks = array_slice($this->riskRepository->findAll(), 0, 3);
        foreach ($recentRisks as $risk) {
            $createdAt = $risk->getCreatedAt();
            $timeAgo = $createdAt ? sprintf($this->translator->trans('activity.minutes_ago'), $createdAt->diff(new \DateTime())->i) : $this->translator->trans('activity.recently');

            $activities[] = [
                'icon' => 'bi-exclamation-triangle',
                'color' => 'warning',
                'title' => $this->translator->trans('activity.risk_identified'),
                'description' => $risk->getDescription() ?? $this->translator->trans('activity.new_risk'),
                'time' => $timeAgo,
                'user' => $this->translator->trans('activity.security_team'),
            ];
        }

        // Nach Zeit sortieren (neueste zuerst)
        usort($activities, function($a, $b) {
            return strcmp($b['time'], $a['time']);
        });

        return array_slice($activities, 0, 10);
    }
}

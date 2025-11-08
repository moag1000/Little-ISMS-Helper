<?php

namespace App\Controller;

use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Service\DashboardStatisticsService;
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
        private readonly DashboardStatisticsService $statisticsService,
        private readonly ISOComplianceIntelligenceService $isoComplianceService,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly TranslatorInterface $translator
    ) {}

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

    #[IsGranted('ROLE_USER')]
    public function dashboard(): Response
    {
        // Get all statistics from service (better separation of concerns)
        $stats = $this->statisticsService->getDashboardStatistics();

        // Build KPIs array for template
        $kpis = [
            [
                'name' => $this->translator->trans('kpi.registered_assets'),
                'value' => $stats['assetCount'],
                'unit' => $this->translator->trans('kpi.unit_pieces'),
                'icon' => '🖥️',
            ],
            [
                'name' => $this->translator->trans('kpi.identified_risks'),
                'value' => $stats['riskCount'],
                'unit' => $this->translator->trans('kpi.unit_pieces'),
                'icon' => '⚠️',
            ],
            [
                'name' => $this->translator->trans('kpi.open_incidents'),
                'value' => $stats['openIncidentCount'],
                'unit' => $this->translator->trans('kpi.unit_pieces'),
                'icon' => '🚨',
            ],
            [
                'name' => $this->translator->trans('kpi.compliance_status'),
                'value' => $stats['compliancePercentage'],
                'unit' => $this->translator->trans('kpi.unit_percent'),
                'icon' => '✅',
            ],
        ];

        // Activity Feed Daten
        $activities = $this->getRecentActivities();

        // ISO Compliance Dashboard - zusätzliche Compliance-Informationen
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

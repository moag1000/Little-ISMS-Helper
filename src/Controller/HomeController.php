<?php

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\Tenant;
use DateTimeImmutable;
use DateTime;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\DashboardStatisticsService;
use App\Service\ISOComplianceIntelligenceService;
use App\Service\RiskReviewService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly DashboardStatisticsService $dashboardStatisticsService,
        private readonly ISOComplianceIntelligenceService $isoComplianceIntelligenceService,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly RiskReviewService $riskReviewService,
        private readonly RiskTreatmentPlanRepository $riskTreatmentPlanRepository,
        private readonly WorkflowInstanceRepository $workflowInstanceRepository,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator
    ) {}

    public function index(Request $request): Response
    {
        // Get preferred locale from session, browser preference, or default to 'de'
        $locale = $request->getSession()->get('_locale')
            ?? $request->getPreferredLanguage(['de', 'en'])
            ?? 'de';

        // Not authenticated → redirect to login (without locale)
        if (!$this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('app_login');
        }

        // Authenticated → redirect to dashboard (with locale)
        return $this->redirectToRoute('app_dashboard', ['_locale' => $locale]);
    }

    #[IsGranted('ROLE_USER')]
    public function dashboard(): Response
    {
        // Get all statistics from service (better separation of concerns)
        $stats = $this->dashboardStatisticsService->getDashboardStatistics();

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
        $isoCompliance = $this->isoComplianceIntelligenceService->getComplianceDashboard();

        // Risk Review Data (ISO 27001:2022 Clause 6.1.3.d)
        $tenant = $this->tenantContext->getCurrentTenant();

        // Guard: If user has no tenant assigned, only allow ADMIN
        if (!$tenant && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('No tenant assigned to user. Please contact administrator.');
        }

        // If no tenant (SUPER_ADMIN case), set review data to empty
        $overdueReviews = $tenant instanceof Tenant ? $this->riskReviewService->getOverdueReviews($tenant) : [];
        $upcomingReviews = $tenant instanceof Tenant ? $this->riskReviewService->getUpcomingReviews($tenant, 30) : [];

        // Treatment Plan Monitoring (ISO 27001:2022 Clause 6.1.3 - Priority 2.4)
        $overdueTreatmentPlans = $tenant instanceof Tenant ? $this->riskTreatmentPlanRepository->findOverdueForTenant($tenant) : [];
        $approachingTreatmentPlans = $tenant instanceof Tenant ? $this->riskTreatmentPlanRepository->findDueWithinDays(7, $tenant) : [];

        // Workflow Approvals (UX High Priority #1 - Single-pane visibility)
        $user = $this->getUser();
        $pendingWorkflows = $user instanceof UserInterface ? $this->workflowInstanceRepository->findPendingForUser($user) : [];
        $overdueWorkflows = $this->workflowInstanceRepository->findOverdue();
        $upcomingDeadlines = $this->workflowInstanceRepository->findUpcomingDeadlines(
            new DateTimeImmutable('+24 hours')
        );

        return $this->render('home/dashboard.html.twig', [
            'kpis' => $kpis,
            'stats' => $stats,
            'activities' => $activities,
            'iso_compliance' => $isoCompliance,
            'overdue_reviews' => $overdueReviews,
            'upcoming_reviews' => $upcomingReviews,
            'overdue_treatment_plans' => $overdueTreatmentPlans,
            'approaching_treatment_plans' => $approachingTreatmentPlans,
            'pending_workflows' => $pendingWorkflows,
            'overdue_workflows' => $overdueWorkflows,
            'upcoming_workflow_deadlines' => $upcomingDeadlines,
        ]);
    }

    private function getRecentActivities(): array
    {
        $activities = [];

        // Beispiel-Aktivitäten (später durch echte Daten ersetzen)
        $recentAssets = array_slice($this->assetRepository->findActiveAssets(), 0, 3);
        foreach ($recentAssets as $recentAsset) {
            $createdAt = $recentAsset->getCreatedAt();
            if ($createdAt) {
                $diff = $createdAt->diff(new DateTime());
                $minutes = $diff->i;
                $hours = $diff->h;
                $days = $diff->d;

                if ($days > 0) {
                    $timeAgo = $this->translator->trans('dashboard.activity.days_ago', ['count' => $days], 'dashboard');
                } elseif ($hours > 0) {
                    $timeAgo = $this->translator->trans('dashboard.activity.hours_ago', ['count' => $hours], 'dashboard');
                } else {
                    $timeAgo = $this->translator->trans('dashboard.activity.minutes_ago', ['count' => $minutes], 'dashboard');
                }
            } else {
                $timeAgo = $this->translator->trans('dashboard.activity.recently', [], 'dashboard');
            }

            $activities[] = [
                'icon' => 'bi-server',
                'color' => 'primary',
                'title' => $this->translator->trans('dashboard.activity.asset_added', [], 'dashboard'),
                'description' => $recentAsset->getName(),
                'time' => $timeAgo,
                'user' => $recentAsset->getOwner() ?? $this->translator->trans('dashboard.activity.system', [], 'dashboard'),
            ];
        }

        $recentRisks = array_slice($this->riskRepository->findAll(), 0, 3);
        foreach ($recentRisks as $recentRisk) {
            $createdAt = $recentRisk->getCreatedAt();
            if ($createdAt) {
                $diff = $createdAt->diff(new DateTime());
                $minutes = $diff->i;
                $hours = $diff->h;
                $days = $diff->d;

                if ($days > 0) {
                    $timeAgo = $this->translator->trans('dashboard.activity.days_ago', ['count' => $days], 'dashboard');
                } elseif ($hours > 0) {
                    $timeAgo = $this->translator->trans('dashboard.activity.hours_ago', ['count' => $hours], 'dashboard');
                } else {
                    $timeAgo = $this->translator->trans('dashboard.activity.minutes_ago', ['count' => $minutes], 'dashboard');
                }
            } else {
                $timeAgo = $this->translator->trans('dashboard.activity.recently', [], 'dashboard');
            }

            $activities[] = [
                'icon' => 'bi-exclamation-triangle',
                'color' => 'warning',
                'title' => $this->translator->trans('dashboard.activity.risk_identified', [], 'dashboard'),
                'description' => $recentRisk->getDescription() ?? $this->translator->trans('dashboard.activity.new_risk', [], 'dashboard'),
                'time' => $timeAgo,
                'user' => $this->translator->trans('dashboard.activity.security_team', [], 'dashboard'),
            ];
        }

        // Nach Zeit sortieren (neueste zuerst)
        usort($activities, fn(array $a, array $b): int => strcmp((string) $b['time'], (string) $a['time']));

        return array_slice($activities, 0, 10);
    }
}

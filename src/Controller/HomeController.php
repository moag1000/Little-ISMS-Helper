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
use App\Service\ComplianceWizardService;
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
        private readonly ComplianceWizardService $complianceWizardService,
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

        // Check user preference: skip welcome page?
        $skipWelcome = $request->getSession()->get('skip_welcome_page', false);

        if ($skipWelcome) {
            // User prefers to go directly to dashboard
            return $this->redirectToRoute('app_dashboard', ['_locale' => $locale]);
        }

        // Show welcome page
        return $this->redirectToRoute('app_welcome', ['_locale' => $locale]);
    }

    #[IsGranted('ROLE_USER')]
    public function dashboard(Request $request): Response
    {
        // Get all statistics from service (better separation of concerns)
        $stats = $this->dashboardStatisticsService->getDashboardStatistics();

        // Get module-aware management KPIs for expanded dashboard
        $managementKpis = $this->dashboardStatisticsService->getManagementKPIs();

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

        // Compliance Wizard Status (quick overview for dashboard)
        $complianceSummary = $this->complianceWizardService->getOverallComplianceSummary($tenant);

        // Risk distribution by inherent risk level (real data for chart)
        $risksByLevel = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $allRisks = $tenant instanceof Tenant
            ? $this->riskRepository->findByTenant($tenant)
            : [];
        foreach ($allRisks as $risk) {
            $level = $risk->getInherentRiskLevel();
            if ($level >= 20) {
                $risksByLevel['critical']++;
            } elseif ($level >= 12) {
                $risksByLevel['high']++;
            } elseif ($level >= 6) {
                $risksByLevel['medium']++;
            } else {
                $risksByLevel['low']++;
            }
        }

        // Assets grouped by type (real data for chart)
        $assetsByType = [];
        $allAssets = $tenant instanceof Tenant
            ? $this->assetRepository->findActiveAssets($tenant)
            : [];
        foreach ($allAssets as $asset) {
            $type = $asset->getAssetType() ?? 'unknown';
            $assetsByType[$type] = ($assetsByType[$type] ?? 0) + 1;
        }

        // "My Tasks Today" Widget - Personal task list for current user
        $myTasks = $this->getMyTasks($user, $overdueTreatmentPlans, $pendingWorkflows, $overdueReviews);

        // Urgent Tasks (ported from WelcomeController)
        $urgentTasks = $this->getUrgentTasks($tenant, $user, $overdueReviews, $overdueTreatmentPlans, $approachingTreatmentPlans, $pendingWorkflows, $overdueWorkflows);
        $totalUrgentCount = array_sum(array_column($urgentTasks, 'count'));

        // First Steps visibility (session-based dismiss)
        $showFirstSteps = !$request->getSession()->get('first_steps_dismissed', false);

        return $this->render('home/dashboard.html.twig', [
            'stats' => $stats,
            'management_kpis' => $managementKpis,
            'activities' => $activities,
            'iso_compliance' => $isoCompliance,
            'overdue_reviews' => $overdueReviews,
            'upcoming_reviews' => $upcomingReviews,
            'overdue_treatment_plans' => $overdueTreatmentPlans,
            'approaching_treatment_plans' => $approachingTreatmentPlans,
            'pending_workflows' => $pendingWorkflows,
            'overdue_workflows' => $overdueWorkflows,
            'upcoming_workflow_deadlines' => $upcomingDeadlines,
            'compliance_summary' => $complianceSummary,
            'risks_by_level' => $risksByLevel,
            'assets_by_type' => $assetsByType,
            'my_tasks' => $myTasks,
            'urgent_tasks' => $urgentTasks,
            'total_urgent_count' => $totalUrgentCount,
            'show_first_steps' => $showFirstSteps,
        ]);
    }

    #[IsGranted('ROLE_USER')]
    public function dismissFirstSteps(Request $request): Response
    {
        if ($this->isCsrfTokenValid('dismiss_first_steps', $request->request->get('_token'))) {
            $request->getSession()->set('first_steps_dismissed', true);
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[IsGranted('ROLE_USER')]
    public function restoreFirstSteps(Request $request): Response
    {
        $request->getSession()->remove('first_steps_dismissed');

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Build a personal task list for the current user.
     * Aggregates from data already loaded in the dashboard method.
     *
     * @return array<array{type: string, title: string, url: string, priority: string, overdue_days: int|null}>
     */
    private function getMyTasks(
        ?UserInterface $user,
        array $overdueTreatmentPlans,
        array $pendingWorkflows,
        array $overdueReviews
    ): array {
        $myTasks = [];

        if (!$user) {
            return $myTasks;
        }

        // My overdue treatment plans (where I am the responsible person)
        foreach ($overdueTreatmentPlans as $plan) {
            if ($plan->getResponsiblePerson() === $user) {
                $overdueDays = abs($plan->getDaysUntilTarget());
                $myTasks[] = [
                    'type' => 'treatment_plan',
                    'title' => $plan->getTitle(),
                    'url' => $this->generateUrl('app_risk_treatment_plan_show', ['id' => $plan->getId()]),
                    'priority' => $overdueDays > 14 ? 'danger' : 'warning',
                    'overdue_days' => $overdueDays,
                    'icon' => 'bi-exclamation-triangle-fill',
                ];
            }
        }

        // My pending workflow approvals
        foreach ($pendingWorkflows as $workflow) {
            $myTasks[] = [
                'type' => 'workflow',
                'title' => $workflow->getWorkflow()?->getName() ?? $this->translator->trans('dashboard.my_tasks.workflow_approval', [], 'dashboard'),
                'url' => $this->generateUrl('app_workflow_pending'),
                'priority' => 'info',
                'overdue_days' => null,
                'icon' => 'bi-hourglass-split',
            ];
        }

        // My risks needing review (risk owner = me, review date past)
        foreach ($overdueReviews as $risk) {
            if ($risk->getRiskOwner() === $user) {
                $overdueDays = $risk->getReviewDate()
                    ? (new DateTime())->diff($risk->getReviewDate())->days
                    : null;
                $myTasks[] = [
                    'type' => 'risk_review',
                    'title' => $risk->getTitle(),
                    'url' => $this->generateUrl('app_risk_show', ['id' => $risk->getId()]),
                    'priority' => 'warning',
                    'overdue_days' => $overdueDays,
                    'icon' => 'bi-calendar-x',
                ];
            }
        }

        // Sort: danger first, then warning, then info
        $priorityOrder = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($myTasks, function (array $a, array $b) use ($priorityOrder): int {
            $aPriority = $priorityOrder[$a['priority']] ?? 3;
            $bPriority = $priorityOrder[$b['priority']] ?? 3;
            return $aPriority <=> $bPriority;
        });

        return $myTasks;
    }

    /**
     * Get urgent tasks for the dashboard (ported from WelcomeController).
     * Reuses data already loaded in the dashboard method to avoid duplicate queries.
     *
     * @return array<array{type: string, icon: string, color: string, label: string, count: int, url: string, priority: int}>
     */
    private function getUrgentTasks(
        ?Tenant $tenant,
        ?UserInterface $user,
        array $overdueReviews,
        array $overdueTreatmentPlans,
        array $approachingTreatmentPlans,
        array $pendingWorkflows,
        array $overdueWorkflows
    ): array {
        $tasks = [];

        if (!$tenant) {
            return $tasks;
        }

        // Overdue risk reviews
        if (count($overdueReviews) > 0) {
            $tasks[] = [
                'type' => 'overdue_reviews',
                'icon' => 'bi-calendar-x',
                'color' => 'warning',
                'label' => $this->translator->trans('dashboard.urgent.overdue_reviews', [], 'dashboard'),
                'count' => count($overdueReviews),
                'url' => $this->generateUrl('app_risk_index'),
                'priority' => 2,
            ];
        }

        // Overdue treatment plans
        if (count($overdueTreatmentPlans) > 0) {
            $tasks[] = [
                'type' => 'overdue_treatment_plans',
                'icon' => 'bi-exclamation-triangle-fill',
                'color' => 'danger',
                'label' => $this->translator->trans('dashboard.urgent.overdue_treatment_plans', [], 'dashboard'),
                'count' => count($overdueTreatmentPlans),
                'url' => $this->generateUrl('app_risk_treatment_plan_index'),
                'priority' => 1,
            ];
        }

        // Approaching treatment plan deadlines
        if (count($approachingTreatmentPlans) > 0) {
            $tasks[] = [
                'type' => 'approaching_deadlines',
                'icon' => 'bi-clock-history',
                'color' => 'warning',
                'label' => $this->translator->trans('dashboard.urgent.approaching_deadlines', [], 'dashboard'),
                'count' => count($approachingTreatmentPlans),
                'url' => $this->generateUrl('app_risk_treatment_plan_index'),
                'priority' => 3,
            ];
        }

        // Pending workflow approvals
        if ($user && count($pendingWorkflows) > 0) {
            $tasks[] = [
                'type' => 'pending_workflows',
                'icon' => 'bi-hourglass-split',
                'color' => 'info',
                'label' => $this->translator->trans('dashboard.urgent.pending_workflows', [], 'dashboard'),
                'count' => count($pendingWorkflows),
                'url' => $this->generateUrl('app_workflow_pending'),
                'priority' => 2,
            ];
        }

        // Overdue workflows
        if (count($overdueWorkflows) > 0) {
            $tasks[] = [
                'type' => 'overdue_workflows',
                'icon' => 'bi-exclamation-circle-fill',
                'color' => 'danger',
                'label' => $this->translator->trans('dashboard.urgent.overdue_workflows', [], 'dashboard'),
                'count' => count($overdueWorkflows),
                'url' => $this->generateUrl('app_workflow_overdue'),
                'priority' => 1,
            ];
        }

        // Sort by priority (lower = more urgent)
        usort($tasks, fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $tasks;
    }

    private function getRecentActivities(): array
    {
        $activities = [];

        // Beispiel-Aktivitäten (später durch echte Daten ersetzen)
        $tenant = $this->getUser()?->getTenant();
        $recentAssets = $tenant
            ? array_slice($this->assetRepository->findActiveAssets($tenant), 0, 3)
            : [];
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
                'timestamp' => $createdAt ? $createdAt->getTimestamp() : 0,
                'user' => $recentAsset->getOwner() ?? $this->translator->trans('dashboard.activity.system', [], 'dashboard'),
            ];
        }

        $recentRisks = $tenant
            ? array_slice($this->riskRepository->findByTenant($tenant), 0, 3)
            : [];
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
                'timestamp' => $createdAt ? $createdAt->getTimestamp() : 0,
                'user' => $this->translator->trans('dashboard.activity.security_team', [], 'dashboard'),
            ];
        }

        // Sort by timestamp descending (newest first)
        usort($activities, fn(array $a, array $b): int => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));

        return array_slice($activities, 0, 10);
    }
}

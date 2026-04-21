<?php

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use DateTime;
use App\Repository\AssetRepository;
use App\Repository\AuditLogRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\ComplianceAnalyticsService;
use App\Service\ComplianceWizardService;
use App\Service\DashboardStatisticsService;
use App\Service\GuidedTourService;
use App\Service\InheritanceMetricsService;
use App\Service\ISOComplianceIntelligenceService;
use App\Service\RiskReviewService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly TranslatorInterface $translator,
        private readonly ?ComplianceAnalyticsService $complianceAnalyticsService = null,
        private readonly ?ComplianceRequirementRepository $complianceRequirementRepository = null,
        private readonly ?AuditLogRepository $auditLogRepository = null,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?ControlRepository $controlRepository = null,
        private readonly ?DocumentRepository $documentRepository = null,
        private readonly ?ISMSContextRepository $ismsContextRepository = null,
        private readonly ?InheritanceMetricsService $inheritanceMetricsService = null,
        private readonly ?GuidedTourService $guidedTourService = null,
    ) {}

    public function index(Request $request): Response
    {
        // Get preferred locale from session, browser preference, or default to 'de'
        $locale = $request->getSession()->get('_locale')
            ?? $request->getPreferredLanguage(['de', 'en'])
            ?? 'de';

        // Not authenticated → redirect to login (without locale)
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            return $this->redirectToRoute('app_login');
        }

        // Check user preference: skip welcome page (entity-persisted, session fallback)
        $skipWelcome = ($user instanceof User && $user->isSkipWelcomePage())
            || $request->getSession()->get('skip_welcome_page', false);

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

        // Cross-Framework Control Mapping Data
        $crossFrameworkData = ['shared_controls' => 0, 'reuse_ratio' => 0, 'top_shared' => []];
        if ($this->complianceRequirementRepository !== null && $tenant instanceof Tenant) {
            try {
                $allRequirements = $this->complianceRequirementRepository->findAll();
                $controlFrameworkMap = [];
                foreach ($allRequirements as $req) {
                    foreach ($req->getMappedControls() as $control) {
                        $fw = $req->getFramework();
                        if ($fw !== null) {
                            $controlId = $control->getId();
                            if (!isset($controlFrameworkMap[$controlId])) {
                                $controlFrameworkMap[$controlId] = [
                                    'count' => 0,
                                    'control' => $control,
                                    'frameworks' => [],
                                ];
                            }
                            $fwCode = $fw->getCode();
                            if (!isset($controlFrameworkMap[$controlId]['frameworks'][$fwCode])) {
                                $controlFrameworkMap[$controlId]['frameworks'][$fwCode] = true;
                                $controlFrameworkMap[$controlId]['count'] = count($controlFrameworkMap[$controlId]['frameworks']);
                            }
                        }
                    }
                }

                $shared = array_filter($controlFrameworkMap, fn($c) => ($c['count'] ?? 0) >= 2);
                usort($shared, fn($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));

                $crossFrameworkData = [
                    'shared_controls' => count($shared),
                    'reuse_ratio' => count($controlFrameworkMap) > 0
                        ? round(count($shared) / count($controlFrameworkMap) * 100)
                        : 0,
                    'top_shared' => array_map(fn($c) => [
                        'id' => $c['control']->getControlId(),
                        'name' => $c['control']->getName(),
                        'framework_count' => count($c['frameworks'] ?? []),
                    ], array_slice($shared, 0, 5)),
                ];
            } catch (\Throwable) {
                // Compliance module may not be fully set up
            }
        }

        // Add trend data to management KPIs (from KPI snapshots)
        $managementKpis = $this->dashboardStatisticsService->addTrendData($managementKpis, $tenant);

        // R2 Ein-Zahl-KPI — Data-Reuse in FTE-Tagen (Board-taugliche Einzelzahl)
        $reuseFteSaved = null;
        if ($this->inheritanceMetricsService !== null && $tenant instanceof Tenant) {
            try {
                $reuseFteSaved = $this->inheritanceMetricsService->fteSavedForTenant($tenant);
            } catch (\Throwable) {
                $reuseFteSaved = null;
            }
        }

        // Sprint 13: Guided-Tour-Banner-Vorschlag. Zeigt pro Nutzer die
        // noch nicht abgeschlossene, rollen-passende Tour. Null = kein Banner.
        $suggestedTour = null;
        $autoRole = null;
        if ($this->guidedTourService !== null && $user instanceof User) {
            $autoRole = $this->guidedTourService->autoDetectTour($user);
            if (!$user->hasCompletedTour($autoRole)) {
                $suggestedTour = $this->guidedTourService->metaFor($autoRole);
            }
        }

        return $this->render('home/dashboard.html.twig', [
            'stats' => $stats,
            'management_kpis' => $managementKpis,
            'reuse_fte_saved' => $reuseFteSaved,
            'suggested_tour' => $suggestedTour,
            'tour_role' => $autoRole,
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
            'cross_framework_data' => $crossFrameworkData,

            // S5 / Junior-Finding #4: 5-Step-Onboarding-Checklist counters.
            'context_defined' => $this->hasContextDefined($tenant),
            'asset_count' => $tenant ? $this->assetRepository->count(['tenant' => $tenant]) : 0,
            'risk_count' => $tenant ? $this->riskRepository->count(['tenant' => $tenant]) : 0,
            'applicable_control_count' => $tenant && $this->controlRepository
                ? $this->controlRepository->count(['tenant' => $tenant, 'applicable' => true])
                : 0,
            'document_count' => $tenant && $this->documentRepository
                ? $this->documentRepository->count(['tenant' => $tenant])
                : 0,
        ]);
    }

    private function hasContextDefined(?\App\Entity\Tenant $tenant): bool
    {
        if ($tenant === null || $this->ismsContextRepository === null) {
            return false;
        }
        $context = $this->ismsContextRepository->findOneBy(['tenant' => $tenant]);
        if ($context === null) {
            return false;
        }
        return trim((string) $context->getOrganizationName()) !== ''
            && trim((string) $context->getIsmsScope()) !== '';
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
        $request->getSession()->remove('skip_welcome_page');

        // Also reset entity-persisted preference
        $user = $this->getUser();
        if ($user instanceof User && $this->entityManager !== null) {
            $user->setSkipWelcomePage(false);
            $this->entityManager->flush();
        }

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

    /**
     * Get recent activities from the audit log.
     *
     * Queries real audit log entries instead of generating fake activities
     * from entity creation dates.
     *
     * @return array<array{icon: string, color: string, title: string, description: string, time: string, timestamp: int, user: string}>
     */
    private function getRecentActivities(): array
    {
        if ($this->auditLogRepository === null) {
            return [];
        }

        $recentLogs = $this->auditLogRepository->findAllOrdered(10);

        $activities = [];
        foreach ($recentLogs as $log) {
            $createdAt = $log->getCreatedAt();
            $timeAgo = $createdAt
                ? $this->getTimeAgo($createdAt)
                : $this->translator->trans('dashboard.activity.recently', [], 'dashboard');

            $action = $log->getAction() ?? 'update';
            [$icon, $color] = match ($action) {
                'create' => ['bi-plus-circle', 'success'],
                'update' => ['bi-pencil', 'primary'],
                'delete' => ['bi-trash', 'danger'],
                'login' => ['bi-box-arrow-in-right', 'info'],
                'logout' => ['bi-box-arrow-right', 'secondary'],
                default => ['bi-clock', 'muted'],
            };

            $activities[] = [
                'icon' => $icon,
                'color' => $color,
                'title' => $log->getEntityType() . ': ' . $action,
                'description' => $log->getDescription()
                    ?? ($log->getEntityType() . ' #' . $log->getEntityId()),
                'time' => $timeAgo,
                'timestamp' => $createdAt ? $createdAt->getTimestamp() : 0,
                'user' => $log->getUserName() ?? $this->translator->trans('dashboard.activity.system', [], 'dashboard'),
            ];
        }

        return $activities;
    }

    /**
     * Convert a datetime to a human-readable "time ago" string.
     */
    private function getTimeAgo(\DateTimeInterface $dateTime): string
    {
        $now = new DateTime();
        $diff = $dateTime->diff($now);
        $days = (int) $diff->days;
        $hours = $diff->h;
        $minutes = $diff->i;

        if ($days > 0) {
            return $this->translator->trans('dashboard.activity.days_ago', ['count' => $days], 'dashboard');
        }
        if ($hours > 0) {
            return $this->translator->trans('dashboard.activity.hours_ago', ['count' => $hours], 'dashboard');
        }

        return $this->translator->trans('dashboard.activity.minutes_ago', ['count' => $minutes], 'dashboard');
    }
}

<?php

namespace App\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\ComplianceWizardService;
use App\Service\ModuleConfigurationService;
use App\Service\RiskReviewService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class WelcomeController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly ComplianceWizardService $complianceWizardService,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly ControlRepository $controlRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly RiskReviewService $riskReviewService,
        private readonly RiskTreatmentPlanRepository $riskTreatmentPlanRepository,
        private readonly WorkflowInstanceRepository $workflowInstanceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/welcome', name: 'app_welcome')]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $user = $this->getUser();

        // Get active modules with counts
        $activeModules = $this->getActiveModulesWithStats($tenant);

        // Get urgent tasks
        $urgentTasks = $this->getUrgentTasks($tenant, $user);

        // Get pending workflows for current user
        $pendingWorkflows = $user ? $this->workflowInstanceRepository->findPendingForUser($user) : [];

        // Check if user prefers to skip welcome page (entity-persisted, session fallback)
        $skipWelcome = ($user instanceof User && $user->isSkipWelcomePage())
            || $request->getSession()->get('skip_welcome_page', false);

        // Get compliance wizard status for incomplete wizards
        $complianceWizards = $this->complianceWizardService->getQuickStatus($tenant);
        $incompleteWizards = array_filter($complianceWizards, fn($w) => $w['score'] < 100);

        // Compute overall compliance score from all wizard scores
        $overallCompliance = 0;
        if (!empty($complianceWizards)) {
            $overallCompliance = (int) round(
                array_sum(array_column($complianceWizards, 'score')) / count($complianceWizards)
            );
        }

        // Count total risks for the hero status line
        $risksTotal = $tenant ? $this->riskRepository->count(['tenant' => $tenant]) : 0;

        return $this->render('home/welcome.html.twig', [
            'active_modules' => $activeModules,
            'urgent_tasks' => $urgentTasks,
            'pending_workflows' => $pendingWorkflows,
            'total_urgent_count' => $this->countUrgentTasks($urgentTasks),
            'skip_welcome' => $skipWelcome,
            'tenant' => $tenant,
            'compliance_wizards' => $incompleteWizards,
            'overall_compliance' => $overallCompliance,
            'risks_total' => $risksTotal,
        ]);
    }

    #[Route('/welcome/preference', name: 'app_welcome_preference', methods: ['POST'])]
    #[IsCsrfTokenValid('welcome_preference', tokenKey: '_token')]
    public function setPreference(Request $request): Response
    {
        $skipWelcome = $request->request->getBoolean('skip_welcome');
        $request->getSession()->set('skip_welcome_page', $skipWelcome);

        // Persist preference to User entity for cross-session persistence
        $user = $this->getUser();
        if ($user instanceof User) {
            $user->setSkipWelcomePage($skipWelcome);
            $this->entityManager->flush();
        }

        // Redirect to dashboard if user wants to skip
        if ($skipWelcome) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->redirectToRoute('app_welcome');
    }

    private function getActiveModulesWithStats(?Tenant $tenant): array
    {
        $modules = [];

        // Core ISMS - always active
        $modules[] = [
            'key' => 'core',
            'name_key' => 'welcome.module.core',
            'icon' => 'bi-shield-check',
            'color' => 'primary',
            'count' => null,
            'route' => 'app_context_index',
            'active' => true,
        ];

        // Assets
        if ($this->moduleConfigurationService->isModuleActive('assets')) {
            $count = $tenant ? $this->assetRepository->count(['tenant' => $tenant]) : 0;
            $modules[] = [
                'key' => 'assets',
                'name_key' => 'welcome.module.assets',
                'icon' => 'bi-server',
                'color' => 'info',
                'count' => $count,
                'route' => 'app_asset_index',
                'create_route' => 'app_asset_new',
                'active' => true,
            ];
        }

        // Risks
        if ($this->moduleConfigurationService->isModuleActive('risks')) {
            $count = $tenant ? $this->riskRepository->count(['tenant' => $tenant]) : 0;
            $modules[] = [
                'key' => 'risks',
                'name_key' => 'welcome.module.risks',
                'icon' => 'bi-exclamation-triangle',
                'color' => 'warning',
                'count' => $count,
                'route' => 'app_risk_index',
                'create_route' => 'app_risk_new',
                'active' => true,
            ];
        }

        // Controls
        if ($this->moduleConfigurationService->isModuleActive('controls')) {
            $total = $tenant ? $this->controlRepository->count(['tenant' => $tenant]) : 0;
            $implemented = $tenant ? $this->controlRepository->count(['tenant' => $tenant, 'implementationStatus' => 'implemented']) : 0;
            $modules[] = [
                'key' => 'controls',
                'name_key' => 'welcome.module.controls',
                'icon' => 'bi-list-check',
                'color' => 'success',
                'count' => $implemented . '/' . $total,
                'route' => 'app_soa_index',
                'active' => true,
            ];
        }

        // Incidents
        if ($this->moduleConfigurationService->isModuleActive('incidents')) {
            $count = $tenant ? $this->incidentRepository->count(['tenant' => $tenant, 'status' => 'open']) : 0;
            $modules[] = [
                'key' => 'incidents',
                'name_key' => 'welcome.module.incidents',
                'icon' => 'bi-exclamation-circle',
                'color' => 'danger',
                'count' => $count,
                'route' => 'app_incident_index',
                'create_route' => 'app_incident_new',
                'active' => true,
            ];
        }

        // BCM
        if ($this->moduleConfigurationService->isModuleActive('bcm')) {
            $modules[] = [
                'key' => 'bcm',
                'name_key' => 'welcome.module.bcm',
                'icon' => 'bi-arrow-repeat',
                'color' => 'secondary',
                'count' => null,
                'route' => 'app_bcm_index',
                'active' => true,
            ];
        }

        // Compliance
        if ($this->moduleConfigurationService->isModuleActive('compliance')) {
            $modules[] = [
                'key' => 'compliance',
                'name_key' => 'welcome.module.compliance',
                'icon' => 'bi-patch-check',
                'color' => 'purple',
                'count' => null,
                'route' => 'app_compliance_index',
                'active' => true,
            ];
        }

        // Audits
        if ($this->moduleConfigurationService->isModuleActive('audits')) {
            $modules[] = [
                'key' => 'audits',
                'name_key' => 'welcome.module.audits',
                'icon' => 'bi-clipboard-check',
                'color' => 'dark',
                'count' => null,
                'route' => 'app_audit_index',
                'active' => true,
            ];
        }

        return $modules;
    }

    private function getUrgentTasks(?Tenant $tenant, ?UserInterface $user): array
    {
        $tasks = [];

        if (!$tenant) {
            return $tasks;
        }

        // Overdue risk reviews
        $overdueReviews = $this->riskReviewService->getOverdueReviews($tenant);
        if (count($overdueReviews) > 0) {
            // Sort by review date ascending (most overdue first)
            usort($overdueReviews, fn($a, $b) => ($a->getReviewDate() ?? new \DateTime('1970-01-01')) <=> ($b->getReviewDate() ?? new \DateTime('1970-01-01')));
            $topReview = $overdueReviews[0];
            $reviewDate = $topReview->getReviewDate();
            $daysOverdue = $reviewDate ? (new \DateTime())->diff($reviewDate)->days : null;

            $task = [
                'type' => 'overdue_reviews',
                'icon' => 'bi-calendar-x',
                'color' => 'warning',
                'title' => 'welcome.tasks.overdue_reviews',
                'count' => count($overdueReviews),
                'route' => 'app_risk_index',
                'route_params' => ['review_overdue' => '1'],
                'priority' => 2,
                'top_item_name' => $topReview->getTitle(),
            ];
            if ($daysOverdue !== null) {
                $task['top_item_overdue_days'] = $daysOverdue;
            }
            $tasks[] = $task;
        }

        // Overdue treatment plans
        $overduePlans = $this->riskTreatmentPlanRepository->findOverdueForTenant($tenant);
        if (count($overduePlans) > 0) {
            // Already sorted by targetCompletionDate ASC from repository (most overdue first)
            $topPlan = $overduePlans[0];
            $targetDate = $topPlan->getTargetCompletionDate();
            $daysOverdue = $targetDate ? (new \DateTime())->diff($targetDate)->days : null;

            $task = [
                'type' => 'overdue_treatment_plans',
                'icon' => 'bi-exclamation-triangle-fill',
                'color' => 'danger',
                'title' => 'welcome.tasks.overdue_treatment_plans',
                'count' => count($overduePlans),
                'route' => 'app_risk_treatment_plan_index',
                'route_params' => ['overdue_only' => '1'],
                'priority' => 1,
                'top_item_name' => $topPlan->getTitle(),
            ];
            if ($daysOverdue !== null) {
                $task['top_item_overdue_days'] = $daysOverdue;
            }
            $tasks[] = $task;
        }

        // Approaching treatment plan deadlines
        $approachingPlans = $this->riskTreatmentPlanRepository->findDueWithinDays(7, $tenant);
        if (count($approachingPlans) > 0) {
            // Already sorted by targetCompletionDate ASC (soonest deadline first)
            $topApproaching = $approachingPlans[0];
            $targetDate = $topApproaching->getTargetCompletionDate();
            $daysLeft = $targetDate ? (new \DateTime())->diff($targetDate)->days : null;

            $task = [
                'type' => 'approaching_deadlines',
                'icon' => 'bi-clock-history',
                'color' => 'warning',
                'title' => 'welcome.tasks.approaching_deadlines',
                'count' => count($approachingPlans),
                'route' => 'app_risk_treatment_plan_index',
                'route_params' => ['approaching' => '7'],
                'priority' => 3,
                'top_item_name' => $topApproaching->getTitle(),
            ];
            if ($daysLeft !== null) {
                $task['top_item_days_left'] = $daysLeft;
            }
            $tasks[] = $task;
        }

        // Pending workflow approvals
        if ($user) {
            $pendingWorkflows = $this->workflowInstanceRepository->findPendingForUser($user);
            if (count($pendingWorkflows) > 0) {
                $topWorkflow = $pendingWorkflows[0];
                $workflowName = $topWorkflow->getWorkflow()?->getName();

                $tasks[] = [
                    'type' => 'pending_workflows',
                    'icon' => 'bi-hourglass-split',
                    'color' => 'info',
                    'title' => 'welcome.tasks.pending_workflows',
                    'count' => count($pendingWorkflows),
                    'route' => 'app_workflow_pending',
                    'route_params' => [],
                    'priority' => 2,
                    'top_item_name' => $workflowName,
                ];
            }
        }

        // Overdue workflows
        $overdueWorkflows = $this->workflowInstanceRepository->findOverdue();
        if (count($overdueWorkflows) > 0) {
            $topOverdueWf = $overdueWorkflows[0];
            $wfName = $topOverdueWf->getWorkflow()?->getName();
            $wfDueDate = $topOverdueWf->getDueDate();
            $wfDaysOverdue = $wfDueDate ? (new \DateTime())->diff($wfDueDate)->days : null;

            $task = [
                'type' => 'overdue_workflows',
                'icon' => 'bi-exclamation-circle-fill',
                'color' => 'danger',
                'title' => 'welcome.tasks.overdue_workflows',
                'count' => count($overdueWorkflows),
                'route' => 'app_workflow_overdue',
                'route_params' => [],
                'priority' => 1,
                'top_item_name' => $wfName,
            ];
            if ($wfDaysOverdue !== null) {
                $task['top_item_overdue_days'] = $wfDaysOverdue;
            }
            $tasks[] = $task;
        }

        // Sort by priority (lower = more urgent)
        usort($tasks, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return $tasks;
    }

    private function countUrgentTasks(array $tasks): int
    {
        return array_sum(array_column($tasks, 'count'));
    }
}

<?php

namespace App\Controller;

use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\ModuleConfigurationService;
use App\Service\RiskReviewService;
use App\Service\TenantContext;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class WelcomeController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly ControlRepository $controlRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly RiskReviewService $riskReviewService,
        private readonly RiskTreatmentPlanRepository $riskTreatmentPlanRepository,
        private readonly WorkflowInstanceRepository $workflowInstanceRepository,
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

        // Check if user prefers to skip welcome page
        $skipWelcome = $request->getSession()->get('skip_welcome_page', false);

        return $this->render('home/welcome.html.twig', [
            'active_modules' => $activeModules,
            'urgent_tasks' => $urgentTasks,
            'pending_workflows' => $pendingWorkflows,
            'total_urgent_count' => $this->countUrgentTasks($urgentTasks),
            'skip_welcome' => $skipWelcome,
            'tenant' => $tenant,
        ]);
    }

    #[Route('/welcome/preference', name: 'app_welcome_preference', methods: ['POST'])]
    public function setPreference(Request $request): Response
    {
        // Validate CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('welcome_preference', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $skipWelcome = $request->request->getBoolean('skip_welcome');
        $request->getSession()->set('skip_welcome_page', $skipWelcome);

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
            'name' => 'Core ISMS',
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
                'name' => 'Assets',
                'icon' => 'bi-server',
                'color' => 'info',
                'count' => $count,
                'route' => 'app_asset_index',
                'active' => true,
            ];
        }

        // Risks
        if ($this->moduleConfigurationService->isModuleActive('risks')) {
            $count = $tenant ? $this->riskRepository->count(['tenant' => $tenant]) : 0;
            $modules[] = [
                'key' => 'risks',
                'name' => 'Risiken',
                'icon' => 'bi-exclamation-triangle',
                'color' => 'warning',
                'count' => $count,
                'route' => 'app_risk_index',
                'active' => true,
            ];
        }

        // Controls
        if ($this->moduleConfigurationService->isModuleActive('controls')) {
            $total = $tenant ? $this->controlRepository->count(['tenant' => $tenant]) : 0;
            $implemented = $tenant ? $this->controlRepository->count(['tenant' => $tenant, 'implementationStatus' => 'implemented']) : 0;
            $modules[] = [
                'key' => 'controls',
                'name' => 'Controls',
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
                'name' => 'Incidents',
                'icon' => 'bi-exclamation-circle',
                'color' => 'danger',
                'count' => $count,
                'route' => 'app_incident_index',
                'active' => true,
            ];
        }

        // BCM
        if ($this->moduleConfigurationService->isModuleActive('bcm')) {
            $modules[] = [
                'key' => 'bcm',
                'name' => 'BCM',
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
                'name' => 'Compliance',
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
                'name' => 'Audits',
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
            $tasks[] = [
                'type' => 'overdue_reviews',
                'icon' => 'bi-calendar-x',
                'color' => 'warning',
                'title' => 'welcome.tasks.overdue_reviews',
                'count' => count($overdueReviews),
                'route' => 'app_risk_index',
                'priority' => 2,
            ];
        }

        // Overdue treatment plans
        $overduePlans = $this->riskTreatmentPlanRepository->findOverdueForTenant($tenant);
        if (count($overduePlans) > 0) {
            $tasks[] = [
                'type' => 'overdue_treatment_plans',
                'icon' => 'bi-exclamation-triangle-fill',
                'color' => 'danger',
                'title' => 'welcome.tasks.overdue_treatment_plans',
                'count' => count($overduePlans),
                'route' => 'app_risk_treatment_plan_index',
                'priority' => 1,
            ];
        }

        // Approaching treatment plan deadlines
        $approachingPlans = $this->riskTreatmentPlanRepository->findDueWithinDays(7, $tenant);
        if (count($approachingPlans) > 0) {
            $tasks[] = [
                'type' => 'approaching_deadlines',
                'icon' => 'bi-clock-history',
                'color' => 'warning',
                'title' => 'welcome.tasks.approaching_deadlines',
                'count' => count($approachingPlans),
                'route' => 'app_risk_treatment_plan_index',
                'priority' => 3,
            ];
        }

        // Pending workflow approvals
        if ($user) {
            $pendingWorkflows = $this->workflowInstanceRepository->findPendingForUser($user);
            if (count($pendingWorkflows) > 0) {
                $tasks[] = [
                    'type' => 'pending_workflows',
                    'icon' => 'bi-hourglass-split',
                    'color' => 'info',
                    'title' => 'welcome.tasks.pending_workflows',
                    'count' => count($pendingWorkflows),
                    'route' => 'app_workflow_pending',
                    'priority' => 2,
                ];
            }
        }

        // Overdue workflows
        $overdueWorkflows = $this->workflowInstanceRepository->findOverdue();
        if (count($overdueWorkflows) > 0) {
            $tasks[] = [
                'type' => 'overdue_workflows',
                'icon' => 'bi-exclamation-circle-fill',
                'color' => 'danger',
                'title' => 'welcome.tasks.overdue_workflows',
                'count' => count($overdueWorkflows),
                'route' => 'app_workflow_overdue',
                'priority' => 1,
            ];
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

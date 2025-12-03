<?php

namespace App\Controller;

use App\Entity\WorkflowStep;
use App\Form\WorkflowType;
use DateTimeImmutable;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Repository\WorkflowRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\TenantContext;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class WorkflowController extends AbstractController
{
    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly WorkflowInstanceRepository $workflowInstanceRepository,
        private readonly WorkflowService $workflowService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Smart redirect logic: Return to referrer page (entity show page) if available,
     * otherwise redirect to workflow instance page.
     *
     * This allows inline workflow actions to return users to the entity page
     * they came from, instead of forcing them to the workflow page.
     */
    private function getSmartRedirect(Request $request, WorkflowInstance $workflowInstance): Response
    {
        $referer = $request->headers->get('referer');

        // If referer exists and is NOT the workflow instance page itself, redirect back
        if ($referer && !str_contains($referer, '/workflow/instance/' . $workflowInstance->getId())) {
            return $this->redirect($referer);
        }

        // Default: redirect to workflow instance page
        return $this->redirectToRoute('app_workflow_instance_show', ['id' => $workflowInstance->getId()]);
    }

    #[Route('/workflow/', name: 'app_workflow_index')]
    public function index(): Response
    {
        $workflows = $this->workflowRepository->findAllActive();
        $statistics = $this->workflowInstanceRepository->getStatistics();
        $instances = $this->workflowInstanceRepository->findBy([], ['startedAt' => 'DESC'], 10);

        // Check approval permissions and eligibility for each instance
        $currentUser = $this->getUser();
        $userCanApprove = [];
        $hasEligibleApprovers = [];
        foreach ($instances as $instance) {
            $currentStep = $instance->getCurrentStep();
            $userCanApprove[$instance->getId()] = $currentStep && $this->workflowService->canUserApprove($currentUser, $currentStep);
            // Check if step has eligible approvers (for warning display)
            if ($currentStep && $instance->getStatus() === 'in_progress') {
                $hasEligibleApprovers[$instance->getId()] = $this->workflowService->hasEligibleApprovers($currentStep);
            } else {
                $hasEligibleApprovers[$instance->getId()] = true;
            }
        }

        return $this->render('workflow/index.html.twig', [
            'workflows' => $workflows,
            'statistics' => $statistics,
            'instances' => $instances,
            'userCanApprove' => $userCanApprove,
            'hasEligibleApprovers' => $hasEligibleApprovers,
        ]);
    }

    #[Route('/workflow/definitions', name: 'app_workflow_definitions')]
    #[IsGranted('ROLE_ADMIN')]
    public function definitions(): Response
    {
        $workflows = $this->workflowRepository->findAll();

        return $this->render('workflow/definitions.html.twig', [
            'workflows' => $workflows,
        ]);
    }

    #[Route('/workflow/pending', name: 'app_workflow_pending')]
    public function pending(): Response
    {
        $user = $this->getUser();
        $pendingApprovals = $this->workflowService->getPendingApprovals($user);

        // Check approval permissions for each instance
        $userCanApprove = [];
        foreach ($pendingApprovals as $instance) {
            $currentStep = $instance->getCurrentStep();
            $userCanApprove[$instance->getId()] = $currentStep && $this->workflowService->canUserApprove($user, $currentStep);
        }

        return $this->render('workflow/pending.html.twig', [
            'pending_approvals' => $pendingApprovals,
            'userCanApprove' => $userCanApprove,
        ]);
    }

    #[Route('/workflow/instance/{id}', name: 'app_workflow_instance_show', requirements: ['id' => '\d+'])]
    public function showInstance(WorkflowInstance $workflowInstance): Response
    {
        $currentUser = $this->getUser();
        $currentStep = $workflowInstance->getCurrentStep();
        $canApprove = $currentStep instanceof WorkflowStep && $this->workflowService->canUserApprove($currentUser, $currentStep);

        // Check if the current step has eligible approvers (for warning display)
        $hasEligibleApprovers = true;
        $eligibleApprovers = [];
        if ($currentStep instanceof WorkflowStep && $workflowInstance->getStatus() === 'in_progress') {
            $hasEligibleApprovers = $this->workflowService->hasEligibleApprovers($currentStep);
            if (!$hasEligibleApprovers) {
                $eligibleApprovers = $this->workflowService->getEligibleApprovers($currentStep);
            }
        }

        return $this->render('workflow/instance_show.html.twig', [
            'instance' => $workflowInstance,
            'can_approve' => $canApprove,
            'has_eligible_approvers' => $hasEligibleApprovers,
            'eligible_approvers' => $eligibleApprovers,
        ]);
    }

    #[Route('/workflow/instance/{id}/approve', name: 'app_workflow_instance_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approveInstance(Request $request, WorkflowInstance $workflowInstance): Response
    {
        if (!$this->isCsrfTokenValid('approve'.$workflowInstance->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid'));
            return $this->getSmartRedirect($request, $workflowInstance);
        }

        $comments = $request->request->get('comments');
        $success = $this->workflowService->approveStep($workflowInstance, $this->getUser(), $comments);

        if ($success) {
            $this->addFlash('success', $this->translator->trans('workflow.success.approved'));
        } else {
            $this->addFlash('error', $this->translator->trans('workflow.error.not_authorized_approve'));
        }

        return $this->getSmartRedirect($request, $workflowInstance);
    }

    #[Route('/workflow/instance/{id}/reject', name: 'app_workflow_instance_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rejectInstance(Request $request, WorkflowInstance $workflowInstance): Response
    {
        if (!$this->isCsrfTokenValid('reject'.$workflowInstance->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid'));
            return $this->getSmartRedirect($request, $workflowInstance);
        }

        $comments = $request->request->get('comments');

        if (empty($comments)) {
            $this->addFlash('error', $this->translator->trans('workflow.error.comments_required'));
            return $this->getSmartRedirect($request, $workflowInstance);
        }

        $success = $this->workflowService->rejectStep($workflowInstance, $this->getUser(), $comments);

        if ($success) {
            $this->addFlash('warning', $this->translator->trans('workflow.warning.rejected'));
        } else {
            $this->addFlash('error', $this->translator->trans('workflow.error.not_authorized_reject'));
        }

        return $this->getSmartRedirect($request, $workflowInstance);
    }

    #[Route('/workflow/instance/{id}/cancel', name: 'app_workflow_instance_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function cancelInstance(Request $request, WorkflowInstance $workflowInstance): Response
    {
        if (!$this->isCsrfTokenValid('cancel'.$workflowInstance->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid'));
            return $this->redirectToRoute('app_workflow_instance_show', ['id' => $workflowInstance->getId()]);
        }

        $reason = $request->request->get('reason', 'Cancelled by administrator');
        $this->workflowService->cancelWorkflow($workflowInstance, $reason);

        $this->addFlash('info', $this->translator->trans('workflow.info.cancelled'));

        return $this->redirectToRoute('app_workflow_index');
    }

    #[Route('/workflow/active', name: 'app_workflow_active')]
    public function active(): Response
    {
        $activeWorkflows = $this->workflowService->getActiveWorkflows();

        // Check approval permissions for each instance
        $currentUser = $this->getUser();
        $userCanApprove = [];
        foreach ($activeWorkflows as $instance) {
            $currentStep = $instance->getCurrentStep();
            $userCanApprove[$instance->getId()] = $currentStep && $this->workflowService->canUserApprove($currentUser, $currentStep);
        }

        return $this->render('workflow/active.html.twig', [
            'active_workflows' => $activeWorkflows,
            'userCanApprove' => $userCanApprove,
        ]);
    }

    #[Route('/workflow/overdue', name: 'app_workflow_overdue')]
    #[IsGranted('ROLE_ADMIN')]
    public function overdue(): Response
    {
        $overdueWorkflows = $this->workflowService->getOverdueWorkflows();

        // Check approval permissions for each instance
        $currentUser = $this->getUser();
        $userCanApprove = [];
        foreach ($overdueWorkflows as $instance) {
            $currentStep = $instance->getCurrentStep();
            $userCanApprove[$instance->getId()] = $currentStep && $this->workflowService->canUserApprove($currentUser, $currentStep);
        }

        return $this->render('workflow/overdue.html.twig', [
            'overdue_workflows' => $overdueWorkflows,
            'userCanApprove' => $userCanApprove,
        ]);
    }

    #[Route('/workflow/by-entity/{entityType}/{entityId}', name: 'app_workflow_by_entity', requirements: ['entityId' => '\d+'])]
    public function byEntity(string $entityType, int $entityId): Response
    {
        $instance = $this->workflowService->getWorkflowInstance($entityType, $entityId);

        if (!$instance instanceof WorkflowInstance) {
            $this->addFlash('info', $this->translator->trans('workflow.info.no_active_workflow'));
            return $this->redirectToRoute('app_workflow_index');
        }

        return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
    }

    #[Route('/workflow/start/{entityType}/{entityId}', name: 'app_workflow_start', requirements: ['entityId' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function start(Request $request, string $entityType, int $entityId): Response
    {
        $workflowName = $request->query->get('workflow');

        $instance = $this->workflowService->startWorkflow($entityType, $entityId, $workflowName);

        if ($instance instanceof WorkflowInstance) {
            $this->addFlash('success', $this->translator->trans('workflow.success.started'));
            return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
        }
        $this->addFlash('error', $this->translator->trans('workflow.error.not_found'));
        return $this->redirectToRoute('app_workflow_index');
    }

    // ===========================================
    // Workflow Definition CRUD
    // ===========================================

    #[Route('/workflow/definition/{id}', name: 'app_workflow_definition_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function showDefinition(Workflow $workflow): Response
    {
        $instances = $this->workflowInstanceRepository->findBy(
            ['workflow' => $workflow],
            ['startedAt' => 'DESC'],
            10
        );

        return $this->render('workflow/definition_show.html.twig', [
            'workflow' => $workflow,
            'recent_instances' => $instances,
        ]);
    }

    #[Route('/workflow/definition/{id}/builder', name: 'app_workflow_definition_builder', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function builder(Workflow $workflow): Response
    {
        return $this->render('workflow/builder.html.twig', [
            'workflow' => $workflow,
            'api_base_url' => '/api/workflow',
        ]);
    }

    #[Route('/workflow/definition/new', name: 'app_workflow_definition_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function newDefinition(Request $request): Response
    {
        $workflow = new Workflow();
        $workflow->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(WorkflowType::class, $workflow);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($workflow);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('workflow.success.definition_created'));

            return $this->redirectToRoute('app_workflow_definition_show', ['id' => $workflow->getId()]);
        }

        return $this->render('workflow/definition_form.html.twig', [
            'workflow' => $workflow,
            'form' => $form,
            'is_edit' => false,
        ]);
    }

    #[Route('/workflow/definition/{id}/edit', name: 'app_workflow_definition_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function editDefinition(Request $request, Workflow $workflow): Response
    {
        $form = $this->createForm(WorkflowType::class, $workflow);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $workflow->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('workflow.success.definition_updated'));

            return $this->redirectToRoute('app_workflow_definition_show', ['id' => $workflow->getId()]);
        }

        return $this->render('workflow/definition_form.html.twig', [
            'workflow' => $workflow,
            'form' => $form,
            'is_edit' => true,
        ]);
    }

    #[Route('/workflow/definition/{id}/delete', name: 'app_workflow_definition_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteDefinition(Request $request, Workflow $workflow): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$workflow->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid'));
            return $this->redirectToRoute('app_workflow_definitions');
        }

        // Check if there are active instances
        $activeInstances = $this->workflowInstanceRepository->count([
            'workflow' => $workflow,
            'status' => ['pending', 'in_progress']
        ]);

        if ($activeInstances > 0) {
            $this->addFlash('error', $this->translator->trans('workflow.error.has_active_instances', ['count' => $activeInstances]));
            return $this->redirectToRoute('app_workflow_definition_show', ['id' => $workflow->getId()]);
        }

        $this->entityManager->remove($workflow);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('workflow.success.definition_deleted'));

        return $this->redirectToRoute('app_workflow_definitions');
    }

    #[Route('/workflow/definition/{id}/toggle', name: 'app_workflow_definition_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleDefinition(Request $request, Workflow $workflow): Response
    {
        if (!$this->isCsrfTokenValid('toggle'.$workflow->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid'));
            return $this->redirectToRoute('app_workflow_definitions');
        }

        $workflow->setIsActive(!$workflow->isActive());
        $workflow->setUpdatedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $status = $workflow->isActive() ? 'activated' : 'deactivated';
        $this->addFlash('success', $this->translator->trans('workflow.success.definition_' . $status));

        return $this->redirectToRoute('app_workflow_definition_show', ['id' => $workflow->getId()]);
    }
}

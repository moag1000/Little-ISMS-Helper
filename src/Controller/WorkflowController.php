<?php

namespace App\Controller;

use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Repository\WorkflowRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/workflow')]
#[IsGranted('ROLE_USER')]
class WorkflowController extends AbstractController
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private WorkflowInstanceRepository $workflowInstanceRepository,
        private WorkflowService $workflowService,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_workflow_index')]
    public function index(): Response
    {
        $workflows = $this->workflowRepository->findAllActive();
        $statistics = $this->workflowInstanceRepository->getStatistics();

        return $this->render('workflow/index.html.twig', [
            'workflows' => $workflows,
            'statistics' => $statistics,
        ]);
    }

    #[Route('/definitions', name: 'app_workflow_definitions')]
    #[IsGranted('ROLE_ADMIN')]
    public function definitions(): Response
    {
        $workflows = $this->workflowRepository->findAll();

        return $this->render('workflow/definitions.html.twig', [
            'workflows' => $workflows,
        ]);
    }

    #[Route('/pending', name: 'app_workflow_pending')]
    public function pending(): Response
    {
        $user = $this->getUser();
        $pendingApprovals = $this->workflowService->getPendingApprovals($user);

        return $this->render('workflow/pending.html.twig', [
            'pending_approvals' => $pendingApprovals,
        ]);
    }

    #[Route('/instance/{id}', name: 'app_workflow_instance_show', requirements: ['id' => '\d+'])]
    public function showInstance(WorkflowInstance $instance): Response
    {
        $currentUser = $this->getUser();
        $currentStep = $instance->getCurrentStep();
        $canApprove = $currentStep && $this->workflowService->canUserApprove($currentUser, $currentStep);

        return $this->render('workflow/instance_show.html.twig', [
            'instance' => $instance,
            'can_approve' => $canApprove,
        ]);
    }

    #[Route('/instance/{id}/approve', name: 'app_workflow_instance_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approveInstance(Request $request, WorkflowInstance $instance): Response
    {
        if (!$this->isCsrfTokenValid('approve'.$instance->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid'));
            return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
        }

        $comments = $request->request->get('comments');
        $success = $this->workflowService->approveStep($instance, $this->getUser(), $comments);

        if ($success) {
            $this->addFlash('success', $this->translator->trans('workflow.success.approved'));
        } else {
            $this->addFlash('error', $this->translator->trans('workflow.error.not_authorized_approve'));
        }

        return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
    }

    #[Route('/instance/{id}/reject', name: 'app_workflow_instance_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rejectInstance(Request $request, WorkflowInstance $instance): Response
    {
        if (!$this->isCsrfTokenValid('reject'.$instance->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid'));
            return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
        }

        $comments = $request->request->get('comments');

        if (empty($comments)) {
            $this->addFlash('error', $this->translator->trans('workflow.error.comments_required'));
            return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
        }

        $success = $this->workflowService->rejectStep($instance, $this->getUser(), $comments);

        if ($success) {
            $this->addFlash('warning', $this->translator->trans('workflow.warning.rejected'));
        } else {
            $this->addFlash('error', $this->translator->trans('workflow.error.not_authorized_reject'));
        }

        return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
    }

    #[Route('/instance/{id}/cancel', name: 'app_workflow_instance_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function cancelInstance(Request $request, WorkflowInstance $instance): Response
    {
        if (!$this->isCsrfTokenValid('cancel'.$instance->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid'));
            return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
        }

        $reason = $request->request->get('reason', 'Cancelled by administrator');
        $this->workflowService->cancelWorkflow($instance, $reason);

        $this->addFlash('info', $this->translator->trans('workflow.info.cancelled'));

        return $this->redirectToRoute('app_workflow_index');
    }

    #[Route('/active', name: 'app_workflow_active')]
    public function active(): Response
    {
        $activeWorkflows = $this->workflowService->getActiveWorkflows();

        return $this->render('workflow/active.html.twig', [
            'active_workflows' => $activeWorkflows,
        ]);
    }

    #[Route('/overdue', name: 'app_workflow_overdue')]
    #[IsGranted('ROLE_ADMIN')]
    public function overdue(): Response
    {
        $overdueWorkflows = $this->workflowService->getOverdueWorkflows();

        return $this->render('workflow/overdue.html.twig', [
            'overdue_workflows' => $overdueWorkflows,
        ]);
    }

    #[Route('/by-entity/{entityType}/{entityId}', name: 'app_workflow_by_entity', requirements: ['entityId' => '\d+'])]
    public function byEntity(string $entityType, int $entityId): Response
    {
        $instance = $this->workflowService->getWorkflowInstance($entityType, $entityId);

        if (!$instance) {
            $this->addFlash('info', $this->translator->trans('workflow.info.no_active_workflow'));
            return $this->redirectToRoute('app_workflow_index');
        }

        return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
    }

    #[Route('/start/{entityType}/{entityId}', name: 'app_workflow_start', requirements: ['entityId' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function start(Request $request, string $entityType, int $entityId): Response
    {
        $workflowName = $request->query->get('workflow');

        $instance = $this->workflowService->startWorkflow($entityType, $entityId, $workflowName);

        if ($instance) {
            $this->addFlash('success', $this->translator->trans('workflow.success.started'));
            return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
        } else {
            $this->addFlash('error', $this->translator->trans('workflow.error.not_found'));
            return $this->redirectToRoute('app_workflow_index');
        }
    }
}

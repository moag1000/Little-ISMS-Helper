<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\WorkflowStep;
use DateTimeImmutable;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Enum\WorkflowInstanceStatus;
use App\Repository\WorkflowRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\AuditLogger;
use App\Service\EmailNotificationService;
use App\Service\TenantContext;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
        private readonly TenantContext $tenantContext,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?EmailNotificationService $emailNotificationService = null,
        private readonly LoggerInterface $logger = new NullLogger(),
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

        // If referer exists, is on the same host, and is NOT the workflow instance page itself, redirect back
        $expectedHost = $request->getSchemeAndHttpHost();
        if ($referer
            && str_starts_with($referer, $expectedHost . '/')
            && !str_contains($referer, '/workflow/instance/' . $workflowInstance->getId())
        ) {
            return $this->redirect($referer);
        }

        // Default: redirect to workflow instance page
        return $this->redirectToRoute('app_workflow_instance_show', ['id' => $workflowInstance->getId()]);
    }

    #[Route('/workflow/', name: 'app_workflow_index', methods: ['GET'])]
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
            if ($currentStep && $instance->getStatus() === WorkflowInstanceStatus::InProgress->value) {
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

    #[Route('/workflow/definitions', name: 'app_workflow_definitions', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function definitions(): Response
    {
        // Y.4: YAML workflows are now the source of truth. The Admin Workflow
        // Overlay Editor at /admin/workflows is the canonical UI for inspecting
        // and overriding workflow steps. Keep this route as a permanent redirect
        // for bookmarks; DB Workflow rows remain in place for audit-trail.
        return $this->redirectToRoute('admin_workflow_overlay_index');
    }

    #[Route('/workflow/pending', name: 'app_workflow_pending', methods: ['GET'])]
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

    #[Route('/workflow/instance/{id}', name: 'app_workflow_instance_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showInstance(WorkflowInstance $workflowInstance): Response
    {
        $currentUser = $this->getUser();
        $currentStep = $workflowInstance->getCurrentStep();
        $canApprove = $currentStep instanceof WorkflowStep && $this->workflowService->canUserApprove($currentUser, $currentStep);

        // Check if the current step has eligible approvers (for warning display)
        $hasEligibleApprovers = true;
        $eligibleApprovers = [];
        if ($currentStep instanceof WorkflowStep && $workflowInstance->getStatus() === WorkflowInstanceStatus::InProgress->value) {
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
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid', [], 'messages'));
            return $this->getSmartRedirect($request, $workflowInstance);
        }

        $comments = $request->request->get('comments');
        $success = $this->workflowService->approveStep($workflowInstance, $this->getUser(), $comments);

        if ($success) {
            $this->addFlash('success', $this->translator->trans('workflow.success.approved', [], 'messages'));
        } else {
            $this->addFlash('error', $this->translator->trans('workflow.error.not_authorized_approve', [], 'messages'));
        }

        return $this->getSmartRedirect($request, $workflowInstance);
    }

    #[Route('/workflow/instance/{id}/reject', name: 'app_workflow_instance_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rejectInstance(Request $request, WorkflowInstance $workflowInstance): Response
    {
        if (!$this->isCsrfTokenValid('reject'.$workflowInstance->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid', [], 'messages'));
            return $this->getSmartRedirect($request, $workflowInstance);
        }

        $comments = $request->request->get('comments');

        if (empty($comments)) {
            $this->addFlash('error', $this->translator->trans('workflow.error.comments_required', [], 'messages'));
            return $this->getSmartRedirect($request, $workflowInstance);
        }

        $success = $this->workflowService->rejectStep($workflowInstance, $this->getUser(), $comments);

        if ($success) {
            $this->addFlash('warning', $this->translator->trans('workflow.warning.rejected', [], 'messages'));
        } else {
            $this->addFlash('error', $this->translator->trans('workflow.error.not_authorized_reject', [], 'messages'));
        }

        return $this->getSmartRedirect($request, $workflowInstance);
    }

    /**
     * Persona-Walkthrough Risk-Owner-Business (Task #124, KRITISCH).
     *
     * Allows the current approver to send a question to the workflow's
     * initiator (or the ISO if no initiator is set) WITHOUT rejecting or
     * approving the instance. The workflow stays in its current state and
     * the question is appended to the approval-history + audit-log under
     * action `workflow_clarification_requested`.
     *
     * Why: a Fachbereichsleiter:in (business owner) often has 1 specific
     * question about the policy ("Ab wann gilt das für Bestandsverträge?")
     * and would otherwise REJECT the document just to surface that question
     * — blocking the entire workflow for a question that takes 5 minutes
     * to answer. This route gives that question a dedicated channel.
     */
    #[Route('/workflow/instance/{id}/clarify', name: 'app_workflow_instance_clarify', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function clarifyInstance(Request $request, WorkflowInstance $workflowInstance): Response
    {
        if (!$this->isCsrfTokenValid('clarify' . $workflowInstance->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid', [], 'messages'));
            return $this->getSmartRedirect($request, $workflowInstance);
        }

        $currentUser = $this->getUser();
        $currentStep = $workflowInstance->getCurrentStep();
        if (!$currentStep instanceof WorkflowStep || !$this->workflowService->canUserApprove($currentUser, $currentStep)) {
            $this->addFlash('error', $this->translator->trans('workflow.error.not_authorized_approve', [], 'messages'));
            return $this->getSmartRedirect($request, $workflowInstance);
        }

        $question = trim((string) $request->request->get('question', ''));
        if ($question === '') {
            $this->addFlash('error', $this->translator->trans('approval.error.clarification_empty', [], 'workflows'));
            return $this->getSmartRedirect($request, $workflowInstance);
        }

        // Append to approval-history (instance is NOT advanced).
        $entry = [
            'action'         => 'clarification_requested',
            'event'          => 'clarification_requested',
            'step_name'      => $currentStep->getName(),
            'asker_user_id'  => method_exists($currentUser, 'getId') ? $currentUser->getId() : null,
            'asker_name'     => method_exists($currentUser, 'getFirstName')
                ? trim(((string) $currentUser->getFirstName()) . ' ' . ((string) $currentUser->getLastName()))
                : (string) $currentUser->getUserIdentifier(),
            'question'       => $question,
            'comments'       => $question,
            'timestamp'      => (new \DateTimeImmutable())->format(DATE_ATOM),
            'at'             => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $workflowInstance->addApprovalHistoryEntry($entry);
        $this->entityManager->flush();

        // Audit-log — required for ISO 27001 Clause 7.5.3 (documented information).
        if ($this->auditLogger !== null) {
            $this->auditLogger->logCustom(
                action: 'workflow_clarification_requested',
                entityType: 'WorkflowInstance',
                entityId: $workflowInstance->getId(),
                oldValues: null,
                newValues: [
                    'workflow_instance_id' => $workflowInstance->getId(),
                    'step_name'            => $currentStep->getName(),
                    'asker_user_id'        => $entry['asker_user_id'],
                    'question'             => $question,
                ],
                description: sprintf(
                    'Approver requested clarification on WorkflowInstance #%d at step "%s"',
                    $workflowInstance->getId() ?? 0,
                    $currentStep->getName() ?? '',
                ),
            );
        }

        // Email the initiator (or skip silently if no initiator / no email service).
        $initiator = $workflowInstance->getInitiatedBy();
        if ($initiator !== null && $this->emailNotificationService !== null && $initiator->getEmail() !== null) {
            try {
                $this->emailNotificationService->sendGenericNotification(
                    sprintf(
                        '%s — %s #%d',
                        $this->translator->trans('approval.clarify_modal.title', [], 'workflows'),
                        $workflowInstance->getEntityType() ?? 'Workflow',
                        $workflowInstance->getEntityId() ?? 0,
                    ),
                    'emails/workflow_clarification_request.html.twig',
                    [
                        'instance'      => $workflowInstance,
                        'step'          => $currentStep,
                        'asker_name'    => $entry['asker_name'],
                        'question'      => $question,
                        'recipient'     => $initiator,
                    ],
                    [$initiator],
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to send clarification email to initiator', [
                    'workflow_instance_id' => $workflowInstance->getId(),
                    'error'                => $e->getMessage(),
                ]);
            }
        }

        $this->addFlash('success', $this->translator->trans('approval.success.clarification_sent', [], 'workflows'));
        return $this->getSmartRedirect($request, $workflowInstance);
    }

    #[Route('/workflow/instance/{id}/cancel', name: 'app_workflow_instance_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function cancelInstance(Request $request, WorkflowInstance $workflowInstance): Response
    {
        if (!$this->isCsrfTokenValid('cancel'.$workflowInstance->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid', [], 'messages'));
            return $this->redirectToRoute('app_workflow_instance_show', ['id' => $workflowInstance->getId()]);
        }

        $reason = $request->request->get('reason', 'Cancelled by administrator');
        $this->workflowService->cancelWorkflow($workflowInstance, $reason);

        $this->addFlash('info', $this->translator->trans('workflow.info.cancelled', [], 'messages'));

        return $this->redirectToRoute('app_workflow_index');
    }

    #[Route('/workflow/active', name: 'app_workflow_active', methods: ['GET'])]
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

    #[Route('/workflow/overdue', name: 'app_workflow_overdue', methods: ['GET'])]
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

    #[Route('/workflow/by-entity/{entityType}/{entityId}', name: 'app_workflow_by_entity', requirements: ['entityId' => '\d+'], methods: ['GET'])]
    public function byEntity(string $entityType, int $entityId): Response
    {
        $instance = $this->workflowService->getWorkflowInstance($entityType, $entityId);

        if (!$instance instanceof WorkflowInstance) {
            $this->addFlash('info', $this->translator->trans('workflow.info.no_active_workflow', [], 'messages'));
            return $this->redirectToRoute('app_workflow_index');
        }

        return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
    }

    #[Route('/workflow/start/{entityType}/{entityId}', name: 'app_workflow_start', requirements: ['entityId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function start(Request $request, string $entityType, int $entityId): Response
    {
        $workflowName = $request->query->get('workflow');

        $instance = $this->workflowService->startWorkflow($entityType, $entityId, $workflowName);

        if ($instance instanceof WorkflowInstance) {
            $this->addFlash('success', $this->translator->trans('workflow.success.started', [], 'messages'));
            return $this->redirectToRoute('app_workflow_instance_show', ['id' => $instance->getId()]);
        }
        $this->addFlash('error', $this->translator->trans('workflow.error.not_found', [], 'messages'));
        return $this->redirectToRoute('app_workflow_index');
    }

    // ===========================================
    // Workflow Definition CRUD
    // ===========================================

    #[Route('/workflow/definition/{id}', name: 'app_workflow_definition_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function showDefinition(Workflow $workflow): Response
    {
        $instances = $this->workflowInstanceRepository->findBy(
            ['workflow' => $workflow],
            ['startedAt' => 'DESC'],
            10
        );

        // Y.4: Show YAML-registered workflow data side-by-side with DB historical data.
        // New workflows are defined in config/workflows/regulatory/*.yaml.
        // DB rows are preserved read-only for historical display.
        return $this->render('workflow/definition_show.html.twig', [
            'workflow' => $workflow,
            'recent_instances' => $instances,
            'yaml_is_canonical' => true, // signals template to show YAML-source notice
        ]);
    }

    /**
     * Intentionally restricted to ROLE_ADMIN: deleting a workflow definition is
     * irreversible and cascades to all historical step records. ISB personas
     * (ROLE_MANAGER) can deactivate/archive definitions via toggleDefinition instead.
     */
    #[Route('/workflow/definition/{id}/delete', name: 'app_workflow_definition_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteDefinition(Request $request, Workflow $workflow): Response
    {
        if (!$this->isCsrfTokenValid('delete'.$workflow->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid', [], 'messages'));
            return $this->redirectToRoute('app_workflow_definitions');
        }

        // Check if there are active instances
        $activeInstances = $this->workflowInstanceRepository->count([
            'workflow' => $workflow,
            'status' => ['pending', 'in_progress']
        ]);

        if ($activeInstances > 0) {
            $this->addFlash('error', $this->translator->trans('workflow.error.has_active_instances', ['count' => $activeInstances], 'messages'));
            return $this->redirectToRoute('app_workflow_definition_show', ['id' => $workflow->getId()]);
        }

        $this->entityManager->remove($workflow);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('workflow.success.definition_deleted', [], 'messages'));

        return $this->redirectToRoute('app_workflow_definitions');
    }

    #[Route('/workflow/definition/{id}/toggle', name: 'app_workflow_definition_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function toggleDefinition(Request $request, Workflow $workflow): Response
    {
        if (!$this->isCsrfTokenValid('toggle'.$workflow->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('error.csrf_invalid', [], 'messages'));
            return $this->redirectToRoute('app_workflow_definitions');
        }

        $workflow->setIsActive(!$workflow->isActive());
        $workflow->setUpdatedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $status = $workflow->isActive() ? 'activated' : 'deactivated';
        $this->addFlash('success', $this->translator->trans('workflow.success.definition_' . $status, [], 'messages'));

        return $this->redirectToRoute('app_workflow_definition_show', ['id' => $workflow->getId()]);
    }
}

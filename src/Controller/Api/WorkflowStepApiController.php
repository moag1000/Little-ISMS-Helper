<?php

namespace App\Controller\Api;

use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Repository\WorkflowRepository;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class WorkflowStepApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowRepository $workflowRepository,
        private readonly CsrfTokenManagerInterface $csrfTokenManager
    ) {}

    /**
     * Validate CSRF token from request header
     */
    private function validateCsrfToken(Request $request, string $tokenId = 'workflow_api'): bool
    {
        $token = $request->headers->get('X-CSRF-Token');
        if (!$token) {
            return false;
        }
        return $this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $token));
    }

    #[Route('/api/workflow/{id}/steps', name: 'api_workflow_steps_list', methods: ['GET'])]
    public function listSteps(Workflow $workflow): JsonResponse
    {
        $steps = [];
        foreach ($workflow->getSteps() as $step) {
            $steps[] = $this->serializeStep($step);
        }

        return $this->json([
            'success' => true,
            'steps' => $steps
        ]);
    }

    #[Route('/api/workflow/{id}/steps', name: 'api_workflow_steps_add', methods: ['POST'])]
    public function addStep(Request $request, Workflow $workflow): JsonResponse
    {
        if (!$this->validateCsrfToken($request)) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($data) || $data === []) {
            return $this->json(['success' => false, 'error' => 'Empty or invalid request body'], Response::HTTP_BAD_REQUEST);
        }

        $workflowStep = new WorkflowStep();
        $workflowStep->setWorkflow($workflow);

        // Set step order to be the last
        $maxOrder = 0;
        foreach ($workflow->getSteps() as $existingStep) {
            if ($existingStep->getStepOrder() > $maxOrder) {
                $maxOrder = $existingStep->getStepOrder();
            }
        }
        $workflowStep->setStepOrder($maxOrder + 1);

        // Apply data from request
        $this->applyStepData($workflowStep, $data);

        // Validate
        $errors = $this->validateStep($workflowStep);
        if ($errors !== []) {
            return $this->json(['success' => false, 'errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->entityManager->beginTransaction();

            $workflow->addStep($workflowStep);
            $this->entityManager->persist($workflowStep);
            $this->entityManager->flush();

            $this->entityManager->commit();

            return $this->json([
                'success' => true,
                'step' => $this->serializeStep($workflowStep),
                'message' => 'Step added successfully'
            ], Response::HTTP_CREATED);
        } catch (DBALException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/workflow/step/{id}', name: 'api_workflow_step_update', methods: ['PUT', 'PATCH'])]
    public function updateStep(Request $request, WorkflowStep $workflowStep): JsonResponse
    {
        if (!$this->validateCsrfToken($request)) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($data) || $data === []) {
            return $this->json(['success' => false, 'error' => 'Empty or invalid request body'], Response::HTTP_BAD_REQUEST);
        }

        $this->applyStepData($workflowStep, $data);

        // Validate
        $errors = $this->validateStep($workflowStep);
        if ($errors !== []) {
            return $this->json(['success' => false, 'errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->entityManager->beginTransaction();
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->json([
                'success' => true,
                'step' => $this->serializeStep($workflowStep),
                'message' => 'Step updated successfully'
            ]);
        } catch (DBALException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/workflow/step/{id}', name: 'api_workflow_step_delete', methods: ['DELETE'])]
    public function deleteStep(Request $request, WorkflowStep $workflowStep): JsonResponse
    {
        if (!$this->validateCsrfToken($request)) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $workflow = $workflowStep->getWorkflow();

        if (!$workflow instanceof Workflow) {
            return $this->json(['success' => false, 'error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        $deletedOrder = $workflowStep->getStepOrder();

        try {
            $this->entityManager->beginTransaction();

            $workflow->removeStep($workflowStep);
            $this->entityManager->remove($workflowStep);

            // Reorder remaining steps
            foreach ($workflow->getSteps() as $remainingStep) {
                if ($remainingStep->getStepOrder() > $deletedOrder) {
                    $remainingStep->setStepOrder($remainingStep->getStepOrder() - 1);
                }
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->json([
                'success' => true,
                'message' => 'Step deleted successfully'
            ]);
        } catch (DBALException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/workflow/{id}/steps/reorder', name: 'api_workflow_steps_reorder', methods: ['POST'])]
    public function reorderSteps(Request $request, Workflow $workflow): JsonResponse
    {
        if (!$this->validateCsrfToken($request)) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['stepIds']) || !is_array($data['stepIds'])) {
            return $this->json(['success' => false, 'error' => 'stepIds array required'], Response::HTTP_BAD_REQUEST);
        }

        $stepIds = $data['stepIds'];
        $steps = [];

        // Build a map of steps
        foreach ($workflow->getSteps() as $step) {
            $steps[$step->getId()] = $step;
        }

        // Validate all step IDs belong to this workflow
        foreach ($stepIds as $stepId) {
            $stepIdInt = (int) $stepId;
            if (!isset($steps[$stepIdInt])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid step ID: ' . $stepId
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $this->entityManager->beginTransaction();

            // Apply new order (starting from 1, not 0)
            foreach ($stepIds as $index => $stepId) {
                $stepIdInt = (int) $stepId;
                $steps[$stepIdInt]->setStepOrder($index + 1);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->json([
                'success' => true,
                'message' => 'Steps reordered successfully'
            ]);
        } catch (DBALException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/workflow/step/{id}/duplicate', name: 'api_workflow_step_duplicate', methods: ['POST'])]
    public function duplicateStep(Request $request, WorkflowStep $workflowStep): JsonResponse
    {
        if (!$this->validateCsrfToken($request)) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $workflow = $workflowStep->getWorkflow();

        if (!$workflow instanceof Workflow) {
            return $this->json(['success' => false, 'error' => 'Workflow not found'], Response::HTTP_NOT_FOUND);
        }

        $newStep = new WorkflowStep();
        $newStep->setWorkflow($workflow);
        $newStep->setName($workflowStep->getName() . ' (Copy)');
        $newStep->setDescription($workflowStep->getDescription());
        $newStep->setStepType($workflowStep->getStepType());
        $newStep->setApproverRole($workflowStep->getApproverRole());
        $newStep->setApproverUsers($workflowStep->getApproverUsers());
        $newStep->setIsRequired($workflowStep->isRequired());
        $newStep->setDaysToComplete($workflowStep->getDaysToComplete());

        // Insert after the original step
        $newOrder = $workflowStep->getStepOrder() + 1;

        try {
            $this->entityManager->beginTransaction();

            // Shift all steps after the original
            foreach ($workflow->getSteps() as $existingStep) {
                if ($existingStep->getStepOrder() >= $newOrder) {
                    $existingStep->setStepOrder($existingStep->getStepOrder() + 1);
                }
            }

            $newStep->setStepOrder($newOrder);
            $workflow->addStep($newStep);

            $this->entityManager->persist($newStep);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->json([
                'success' => true,
                'step' => $this->serializeStep($newStep),
                'message' => 'Step duplicated successfully'
            ], Response::HTTP_CREATED);
        } catch (DBALException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/workflow/templates', name: 'api_workflow_templates', methods: ['GET'])]
    public function getTemplates(): JsonResponse
    {
        $templates = [
            'risk_assessment' => [
                'name' => 'Risk Assessment Workflow',
                'description' => 'Standard workflow for risk assessment and approval',
                'entityType' => 'Risk',
                'steps' => [
                    ['name' => 'Risk Identification', 'stepType' => 'approval', 'approverRole' => 'ROLE_USER', 'daysToComplete' => 3],
                    ['name' => 'Risk Analysis', 'stepType' => 'approval', 'approverRole' => 'ROLE_RISK_MANAGER', 'daysToComplete' => 5],
                    ['name' => 'Treatment Plan Review', 'stepType' => 'approval', 'approverRole' => 'ROLE_MANAGER', 'daysToComplete' => 5],
                    ['name' => 'Final Approval', 'stepType' => 'approval', 'approverRole' => 'ROLE_ISO_OFFICER', 'daysToComplete' => 3],
                ]
            ],
            'control_implementation' => [
                'name' => 'Control Implementation Workflow',
                'description' => 'Approval workflow for implementing security controls',
                'entityType' => 'Control',
                'steps' => [
                    ['name' => 'Implementation Planning', 'stepType' => 'approval', 'approverRole' => 'ROLE_USER', 'daysToComplete' => 5],
                    ['name' => 'Technical Review', 'stepType' => 'approval', 'approverRole' => 'ROLE_ADMIN', 'daysToComplete' => 3],
                    ['name' => 'Security Assessment', 'stepType' => 'approval', 'approverRole' => 'ROLE_ISO_OFFICER', 'daysToComplete' => 5],
                    ['name' => 'Management Approval', 'stepType' => 'approval', 'approverRole' => 'ROLE_MANAGER', 'daysToComplete' => 3],
                ]
            ],
            'incident_response' => [
                'name' => 'Incident Response Workflow',
                'description' => 'Workflow for security incident handling',
                'entityType' => 'Incident',
                'steps' => [
                    ['name' => 'Initial Classification', 'stepType' => 'approval', 'approverRole' => 'ROLE_USER', 'daysToComplete' => 1],
                    ['name' => 'Investigation', 'stepType' => 'approval', 'approverRole' => 'ROLE_ISO_OFFICER', 'daysToComplete' => 3],
                    ['name' => 'Containment Approval', 'stepType' => 'approval', 'approverRole' => 'ROLE_ADMIN', 'daysToComplete' => 1],
                    ['name' => 'Resolution Review', 'stepType' => 'approval', 'approverRole' => 'ROLE_MANAGER', 'daysToComplete' => 5],
                    ['name' => 'Lessons Learned', 'stepType' => 'notification', 'approverRole' => 'ROLE_USER', 'daysToComplete' => 10],
                ]
            ],
            'document_review' => [
                'name' => 'Document Review Workflow',
                'description' => 'Standard document review and approval process',
                'entityType' => 'Document',
                'steps' => [
                    ['name' => 'Initial Review', 'stepType' => 'approval', 'approverRole' => 'ROLE_USER', 'daysToComplete' => 3],
                    ['name' => 'Technical Review', 'stepType' => 'approval', 'approverRole' => 'ROLE_AUDITOR', 'daysToComplete' => 5],
                    ['name' => 'Final Approval', 'stepType' => 'approval', 'approverRole' => 'ROLE_MANAGER', 'daysToComplete' => 3],
                ]
            ],
            'change_request' => [
                'name' => 'Change Request Workflow',
                'description' => 'Approval process for system changes',
                'entityType' => 'ChangeRequest',
                'steps' => [
                    ['name' => 'Impact Assessment', 'stepType' => 'approval', 'approverRole' => 'ROLE_USER', 'daysToComplete' => 3],
                    ['name' => 'Security Review', 'stepType' => 'approval', 'approverRole' => 'ROLE_ISO_OFFICER', 'daysToComplete' => 5],
                    ['name' => 'CAB Approval', 'stepType' => 'approval', 'approverRole' => 'ROLE_MANAGER', 'daysToComplete' => 7],
                    ['name' => 'Implementation Sign-off', 'stepType' => 'approval', 'approverRole' => 'ROLE_ADMIN', 'daysToComplete' => 3],
                ]
            ],
            'training' => [
                'name' => 'Training Workflow',
                'description' => 'Workflow for planning, executing and verifying training sessions',
                'entityType' => 'Training',
                'steps' => [
                    ['name' => 'Training Scheduled', 'stepType' => 'notification', 'approverRole' => 'ROLE_USER', 'daysToComplete' => 2],
                    ['name' => 'Participants Confirmed', 'stepType' => 'approval', 'approverRole' => 'ROLE_MANAGER', 'daysToComplete' => 5],
                    ['name' => 'Training Completed', 'stepType' => 'notification', 'approverRole' => 'ROLE_USER', 'daysToComplete' => 1],
                    ['name' => 'Completion Verified', 'stepType' => 'approval', 'approverRole' => 'ROLE_ISO_OFFICER', 'daysToComplete' => 3],
                    ['name' => 'Certificates Issued', 'stepType' => 'notification', 'approverRole' => 'ROLE_ADMIN', 'daysToComplete' => 5],
                ]
            ],
        ];

        return $this->json([
            'success' => true,
            'templates' => $templates
        ]);
    }

    #[Route('/api/workflow/{id}/apply-template', name: 'api_workflow_apply_template', methods: ['POST'])]
    public function applyTemplate(Request $request, Workflow $workflow): JsonResponse
    {
        if (!$this->validateCsrfToken($request)) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['templateKey'])) {
            return $this->json(['success' => false, 'error' => 'templateKey required'], Response::HTTP_BAD_REQUEST);
        }

        $jsonResponse = $this->getTemplates();
        $content = $jsonResponse->getContent();

        if (in_array($content, ['', '0', false], true)) {
            return $this->json(['success' => false, 'error' => 'Template data unavailable'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $templatesData = json_decode($content, true);

        if ($templatesData === null || !isset($templatesData['templates'])) {
            return $this->json(['success' => false, 'error' => 'Invalid template data'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $templates = $templatesData['templates'];

        if (!isset($templates[$data['templateKey']])) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $template = $templates[$data['templateKey']];

        try {
            $this->entityManager->beginTransaction();

            // Clear existing steps if requested
            if (isset($data['clearExisting']) && $data['clearExisting']) {
                foreach ($workflow->getSteps()->toArray() as $workflowStep) {
                    $workflow->removeStep($workflowStep);
                    $this->entityManager->remove($workflowStep);
                }
            }

            // Apply template steps (starting from 1)
            $startOrder = $workflow->getSteps()->count() + 1;
            foreach ($template['steps'] as $index => $stepData) {
                $workflowStep = new WorkflowStep();
                $workflowStep->setWorkflow($workflow);
                $workflowStep->setStepOrder($startOrder + $index);
                $workflowStep->setName($stepData['name']);
                $workflowStep->setStepType($stepData['stepType']);
                $workflowStep->setApproverRole($stepData['approverRole']);
                $workflowStep->setDaysToComplete($stepData['daysToComplete']);
                $workflowStep->setIsRequired(true);

                $workflow->addStep($workflowStep);
                $this->entityManager->persist($workflowStep);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->json([
                'success' => true,
                'message' => 'Template applied successfully',
                'stepsAdded' => count($template['steps'])
            ]);
        } catch (DBALException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            return $this->json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function serializeStep(WorkflowStep $workflowStep): array
    {
        return [
            'id' => $workflowStep->getId(),
            'name' => $workflowStep->getName(),
            'description' => $workflowStep->getDescription(),
            'stepOrder' => $workflowStep->getStepOrder(),
            'stepType' => $workflowStep->getStepType(),
            'approverRole' => $workflowStep->getApproverRole(),
            'approverUsers' => $workflowStep->getApproverUsers() ?? [],
            'isRequired' => $workflowStep->isRequired(),
            'daysToComplete' => $workflowStep->getDaysToComplete(),
        ];
    }

    private function applyStepData(WorkflowStep $workflowStep, array $data): void
    {
        if (isset($data['name'])) {
            $workflowStep->setName($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $workflowStep->setDescription($data['description']);
        }
        if (isset($data['stepType'])) {
            $workflowStep->setStepType($data['stepType']);
        }
        if (array_key_exists('approverRole', $data)) {
            $workflowStep->setApproverRole($data['approverRole']);
        }
        if (array_key_exists('approverUsers', $data)) {
            $workflowStep->setApproverUsers($data['approverUsers']);
        }
        if (isset($data['isRequired'])) {
            $workflowStep->setIsRequired((bool) $data['isRequired']);
        }
        if (array_key_exists('daysToComplete', $data)) {
            $workflowStep->setDaysToComplete($data['daysToComplete'] !== null && $data['daysToComplete'] !== '' ? (int) $data['daysToComplete'] : null);
        }
    }

    private function validateStep(WorkflowStep $workflowStep): array
    {
        $errors = [];

        if (in_array($workflowStep->getName(), [null, '', '0'], true)) {
            $errors[] = 'Step name is required';
        }

        if (strlen((string) $workflowStep->getName()) > 255) {
            $errors[] = 'Step name must be 255 characters or less';
        }

        if (!in_array($workflowStep->getStepType(), ['approval', 'notification', 'auto_action'])) {
            $errors[] = 'Invalid step type';
        }

        if ($workflowStep->getDaysToComplete() !== null && $workflowStep->getDaysToComplete() <= 0) {
            $errors[] = 'Days to complete must be positive';
        }

        if ($workflowStep->getDaysToComplete() !== null && $workflowStep->getDaysToComplete() > 365) {
            $errors[] = 'Days to complete cannot exceed 365';
        }

        return $errors;
    }
}

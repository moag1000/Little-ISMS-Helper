<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only view of the legacy DB workflow steps.
 *
 * Since the canonical workflow definitions are
 * `config/workflows/regulatory/*.yaml`; the `workflows` / `workflow_steps`
 * rows stay read-only for historical WorkflowInstance display. Per-organisation
 * changes (approver role, SLA days, auto-progress conditions, module gate) go
 * through the YAML overlay editor at /admin/workflows
 * (WorkflowOverlayController).
 *
 * The former drag-drop builder write endpoints answer 410 Gone with a pointer
 * to the overlay editor, so stale clients fail loudly instead of silently
 * mutating deprecated rows.
 *
 * @see docs/decisions/2026-05-17-workflow-yaml-unification.md
 */
#[IsGranted('ROLE_MANAGER')]
class WorkflowStepApiController extends AbstractController
{
    #[Route('/api/workflow/{id}/steps', name: 'api_workflow_steps_list', methods: ['GET'])]
    public function listSteps(Workflow $workflow): JsonResponse
    {
        $steps = [];
        foreach ($workflow->getSteps() as $step) {
            $steps[] = $this->serializeStep($step);
        }

        return $this->json([
            'success' => true,
            'read_only' => true,
            'steps' => $steps,
        ]);
    }

    // @csrf-exempt: retired write API — always answers 410 Gone and changes nothing
    #[Route('/api/workflow/{id}/steps', name: 'api_workflow_steps_add', methods: ['POST'])]
    #[Route('/api/workflow/step/{id}', name: 'api_workflow_step_update', methods: ['PUT', 'PATCH'])]
    #[Route('/api/workflow/step/{id}', name: 'api_workflow_step_delete', methods: ['DELETE'])]
    #[Route('/api/workflow/{id}/steps/reorder', name: 'api_workflow_steps_reorder', methods: ['POST'])]
    #[Route('/api/workflow/step/{id}/duplicate', name: 'api_workflow_step_duplicate', methods: ['POST'])]
    #[Route('/api/workflow/{id}/apply-template', name: 'api_workflow_apply_template', methods: ['POST'])]
    #[Route('/api/workflow/templates', name: 'api_workflow_templates', methods: ['GET'])]
    public function gone(): JsonResponse
    {
        return $this->json([
            'success' => false,
            'error' => 'gone',
            'message' => 'Workflow steps are defined in config/workflows/regulatory/*.yaml and are read-only '
                . 'in the database. Use the workflow overlay editor for per-organisation changes.',
            'overlay_url' => $this->generateUrl('admin_workflow_overlay_index'),
        ], Response::HTTP_GONE);
    }

    /** @return array<string, mixed> */
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
}

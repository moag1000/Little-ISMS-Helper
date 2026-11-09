<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tenant;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\TenantContext;
use App\Workflow\Loader\RegulatoryWorkflowLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only view of the approval chains (S15).
 *
 * Reads the canonical step definitions only through RegulatoryWorkflowLoader (metadata.steps of
 * config/workflows/regulatory/*.yaml). Lifecycle workflows (config/workflows/*.yaml) have no steps
 * and link to the transition overrides (/admin/lifecycle-overrides), which ARE applied.
 *
 * Until S15 this page also saved per-organisation step overrides (approver role, approvers, days,
 * auto-progress conditions, reason, four eyes, module) into lifecycle_config. Nothing ever read them:
 * the running approval reads its approver and deadline from the WorkflowStep row, which is written
 * only by {@see \App\Workflow\Mirror\RegulatoryWorkflowMirror} from the YAML. The page now says what
 * applies instead of offering settings without effect: who approves is whoever holds the step's role
 * in the organisation (user management), `owner` is the record's responsible person.
 * Version20261109020000_DropWorkflowStepOverlays removed the stored rows.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/workflows')]
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
final class WorkflowOverlayController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RegulatoryWorkflowLoader $workflowLoader,
    ) {}

    /**
     * List all known workflows (lifecycle + regulatory) with their step counts.
     */
    #[Route('', name: 'admin_workflow_overlay_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->resolveTenant($request);
        if (!$tenant instanceof Tenant) {
            return $this->render('admin/lifecycle_overrides/no_tenant.html.twig');
        }

        $lifecycleWorkflows = $this->listLifecycleWorkflows();
        $regulatoryWorkflows = $this->listRegulatoryWorkflows();

        $rows = [];
        foreach ($lifecycleWorkflows as $name) {
            $rows[] = [
                'name' => $name,
                'type' => 'lifecycle',
                'step_count' => 0,
                'has_steps' => false,
            ];
        }
        foreach ($regulatoryWorkflows as $name) {
            $steps = $this->loadStepsForWorkflow($name);
            $rows[] = [
                'name' => $name,
                'type' => 'regulatory',
                'step_count' => count($steps),
                'has_steps' => count($steps) > 0,
            ];
        }

        return $this->render('admin/workflows/index.html.twig', [
            'workflows' => $rows,
        ]);
    }

    /**
     * Show the step structure of a workflow (read-only).
     */
    #[Route('/{name}', name: 'admin_workflow_overlay_show', methods: ['GET'], requirements: ['name' => '[a-z][a-z0-9_]*'])]
    public function show(Request $request, string $name): Response
    {
        $tenant = $this->resolveTenant($request);
        if (!$tenant instanceof Tenant) {
            return $this->render('admin/lifecycle_overrides/no_tenant.html.twig');
        }

        $this->assertWorkflowKnown($name);

        $stepRows = [];
        foreach ($this->loadStepsForWorkflow($name) as $idx => $step) {
            $stepRows[] = ['index' => $idx, 'yaml' => $step];
        }

        return $this->render('admin/workflows/show.html.twig', [
            'workflowName' => $name,
            'steps' => $stepRows,
            'isRegulatory' => in_array($name, $this->listRegulatoryWorkflows(), true),
        ]);
    }

    private function resolveTenant(Request $request): ?Tenant
    {
        $requested = $request->request->get('tenant_id') ?? $request->query->get('tenant_id');
        return $this->tenantContext->resolveAdminScope($requested);
    }

    /**
     * Steps of a workflow, from the one reader of config/workflows/regulatory (S17:
     * `metadata.steps`, {@see RegulatoryWorkflowLoader::stepsOf()}). Lifecycle workflows have none.
     *
     * @return list<array<string, mixed>>
     */
    private function loadStepsForWorkflow(string $name): array
    {
        return $this->workflowLoader->getStepsForWorkflow($name) ?? [];
    }

    /**
     * List known lifecycle workflow names (from config/workflows/*.yaml).
     *
     * @return list<string>
     */
    private function listLifecycleWorkflows(): array
    {
        $names = [];
        $pattern = \dirname(__DIR__, 3) . '/config/workflows/*.yaml';
        foreach (glob($pattern) ?: [] as $f) {
            $base = basename($f, '.yaml');
            if (!str_ends_with($base, '_lifecycle')) {
                $base .= '_lifecycle';
            }
            // Only workflows whose transitions an organisation may override (the link target).
            if (!\App\Lifecycle\EntityTypeRegistry::acceptsTenantOverrides($base)) {
                continue;
            }
            $names[] = $base;
        }
        sort($names);
        return $names;
    }

    /**
     * List known regulatory workflow names (from config/workflows/regulatory/*.yaml or known fallback list).
     *
     * @return list<string>
     */
    private function listRegulatoryWorkflows(): array
    {
        $regulatoryDir = \dirname(__DIR__, 3) . '/config/workflows/regulatory/';
        if (is_dir($regulatoryDir)) {
            $names = [];
            foreach (glob($regulatoryDir . '*.yaml') ?: [] as $f) {
                $names[] = basename($f, '.yaml');
            }
            sort($names);
            return $names;
        }

        // Fallback: return the known 5 critical regulatory workflow names
        // so the index page is non-empty without the loader service.
        return [
            'gdpr_data_breach',
            'incident_high_severity',
            'incident_low_severity',
            'risk_treatment',
            'dpia',
        ];
    }

    /**
     * Assert that the given workflow name is in the known set.
     */
    private function assertWorkflowKnown(string $name): void
    {
        $all = array_merge($this->listLifecycleWorkflows(), $this->listRegulatoryWorkflows());
        if (!in_array($name, $all, true)) {
            throw $this->createNotFoundException(sprintf('Unknown workflow "%s".', $name));
        }
    }
}

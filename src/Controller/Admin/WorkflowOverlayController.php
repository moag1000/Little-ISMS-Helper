<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LifecycleConfig;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\Admin\WorkflowStepOverlayType;
use App\Repository\LifecycleConfigRepository;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use App\Workflow\Loader\RegulatoryWorkflowLoader;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

/**
 * Admin UI for workflow step-level overlay editor.
 *
 * Reads canonical step definitions from:
 *   1. RegulatoryWorkflowLoader::getStepsForWorkflow() (the service may not exist)
 *   2. Fallback: parse metadata.steps from config/workflows/regulatory/*.yaml directly
 *   3. Lifecycle workflows: config/workflows/*.yaml (no steps, but listed for cross-link)
 *
 * Overwrideable step fields per tenant are persisted into lifecycle_config with:
 *   workflowName  = 'workflow:{name}'
 *   transitionName = 'step:{index}'
 *   configKey     = approverRole | approverUsers | daysToComplete | autoProgressConditions
 *                   | reasonRequired | fourEyes | module
 *
 * Cross-link: links to admin_lifecycle_overrides_index for transition-level overrides.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/workflows')]
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
final class WorkflowOverlayController extends AbstractController
{
    public function __construct(
        private readonly LifecycleConfigRepository $overrideRepo,
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly RegulatoryWorkflowLoader $workflowLoader,
    ) {}

    /**
     * List all known workflows (lifecycle + regulatory) with their step-override counts.
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
                'override_count' => $this->overrideRepo->countStepOverridesForWorkflow($tenant, $name),
                'has_steps' => false,
            ];
        }
        foreach ($regulatoryWorkflows as $name) {
            $steps = $this->loadStepsForWorkflow($name);
            $rows[] = [
                'name' => $name,
                'type' => 'regulatory',
                'step_count' => count($steps),
                'override_count' => $this->overrideRepo->countStepOverridesForWorkflow($tenant, $name),
                'has_steps' => count($steps) > 0,
            ];
        }

        return $this->render('admin/workflows/index.html.twig', [
            'workflows' => $rows,
        ]);
    }

    /**
     * Show YAML structure (read-only) + tenant step overrides for a workflow.
     */
    #[Route('/{name}', name: 'admin_workflow_overlay_show', methods: ['GET'], requirements: ['name' => '[a-z][a-z0-9_]*'])]
    public function show(Request $request, string $name): Response
    {
        $tenant = $this->resolveTenant($request);
        if (!$tenant instanceof Tenant) {
            return $this->render('admin/lifecycle_overrides/no_tenant.html.twig');
        }

        $this->assertWorkflowKnown($name);

        $steps = $this->loadStepsForWorkflow($name);
        $allOverrides = $this->overrideRepo->findAllStepOverridesForWorkflow($tenant, $name);

        // Build per-step merged view
        $stepRows = [];
        foreach ($steps as $idx => $step) {
            $stepOverrides = $allOverrides[$idx] ?? [];
            $stepRows[] = [
                'index' => $idx,
                'yaml' => $step,
                'overrides' => $stepOverrides,
                'effective' => array_replace($step, $stepOverrides),
                'has_overrides' => !empty($stepOverrides),
            ];
        }

        return $this->render('admin/workflows/show.html.twig', [
            'workflowName' => $name,
            'steps' => $stepRows,
            'isRegulatory' => in_array($name, $this->listRegulatoryWorkflows(), true),
        ]);
    }

    /**
     * Edit step-level overrides for a specific step in a workflow.
     */
    #[Route('/{name}/{stepIndex}/edit', name: 'admin_workflow_overlay_edit', methods: ['GET', 'POST'], requirements: ['name' => '[a-z][a-z0-9_]*', 'stepIndex' => '\d+'])]
    public function edit(Request $request, string $name, int $stepIndex): Response
    {
        $tenant = $this->resolveTenant($request);
        if (!$tenant instanceof Tenant) {
            return $this->render('admin/lifecycle_overrides/no_tenant.html.twig');
        }

        $this->assertWorkflowKnown($name);

        $steps = $this->loadStepsForWorkflow($name);
        if (!isset($steps[$stepIndex])) {
            throw $this->createNotFoundException(
                sprintf('Step %d not found in workflow "%s".', $stepIndex, $name)
            );
        }

        $yamlStep = $steps[$stepIndex];
        $existing = $this->overrideRepo->findByWorkflowAndStep($tenant, $name, $stepIndex);

        // Build form data from existing overrides
        $formData = [
            'approverRole' => $existing['approverRole'] ?? null,
            'approverUsers' => array_values(array_map('intval', array_filter(
                (array) ($existing['approverUsers'] ?? []),
                static fn (mixed $id): bool => is_numeric($id),
            ))),
            'daysToComplete' => $existing['daysToComplete'] ?? null,
            'autoProgressConditions' => is_array($existing['autoProgressConditions'] ?? null)
                ? $existing['autoProgressConditions']
                : null,
            'reasonRequired' => (bool) ($existing['reasonRequired'] ?? false),
            'fourEyes' => (bool) ($existing['fourEyes'] ?? false),
            'module' => $existing['module'] ?? null,
        ];

        $form = $this->createForm(WorkflowStepOverlayType::class, $formData, [
            'user_choices' => $this->userChoices($tenant),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $now = new DateTimeImmutable();
            $user = $this->getUser();
            $oldValues = $existing;
            $newValues = [];

            // approverRole
            $approverRole = trim((string) ($data['approverRole'] ?? ''));
            if ($approverRole !== '') {
                $this->upsertStepOverride($tenant, $name, $stepIndex, 'approverRole', $approverRole, $user, $now);
                $newValues['approverRole'] = $approverRole;
            } else {
                $this->deleteStepOverrideKey($tenant, $name, $stepIndex, 'approverRole');
            }

            // approverUsers (list of tenant user IDs)
            $value = array_values(array_map('intval', (array) ($data['approverUsers'] ?? [])));
            if ($value !== []) {
                $this->upsertStepOverride($tenant, $name, $stepIndex, 'approverUsers', $value, $user, $now);
                $newValues['approverUsers'] = $value;
            } else {
                $this->deleteStepOverrideKey($tenant, $name, $stepIndex, 'approverUsers');
            }

            // daysToComplete
            $days = $data['daysToComplete'] ?? null;
            if ($days !== null && $days >= 0) {
                $this->upsertStepOverride($tenant, $name, $stepIndex, 'daysToComplete', $days, $user, $now);
                $newValues['daysToComplete'] = $days;
            } else {
                $this->deleteStepOverrideKey($tenant, $name, $stepIndex, 'daysToComplete');
            }

            // autoProgressConditions (structured builder → array)
            $value = $data['autoProgressConditions'] ?? null;
            if (is_array($value) && $value !== []) {
                $this->upsertStepOverride($tenant, $name, $stepIndex, 'autoProgressConditions', $value, $user, $now);
                $newValues['autoProgressConditions'] = $value;
            } else {
                $this->deleteStepOverrideKey($tenant, $name, $stepIndex, 'autoProgressConditions');
            }

            // reasonRequired (tri-state via override checkbox)
            $reasonOverrideEnabled = $form->get('reasonRequiredOverride')->getData();
            if ($reasonOverrideEnabled) {
                $val = (bool) ($data['reasonRequired'] ?? false);
                $this->upsertStepOverride($tenant, $name, $stepIndex, 'reasonRequired', $val, $user, $now);
                $newValues['reasonRequired'] = $val;
            } else {
                $this->deleteStepOverrideKey($tenant, $name, $stepIndex, 'reasonRequired');
            }

            // fourEyes (tri-state via override checkbox)
            $fourEyesOverrideEnabled = $form->get('fourEyesOverride')->getData();
            if ($fourEyesOverrideEnabled) {
                $val = (bool) ($data['fourEyes'] ?? false);
                $this->upsertStepOverride($tenant, $name, $stepIndex, 'fourEyes', $val, $user, $now);
                $newValues['fourEyes'] = $val;
            } else {
                $this->deleteStepOverrideKey($tenant, $name, $stepIndex, 'fourEyes');
            }

            // module
            $module = trim((string) ($data['module'] ?? ''));
            if ($module !== '') {
                $this->upsertStepOverride($tenant, $name, $stepIndex, 'module', $module, $user, $now);
                $newValues['module'] = $module;
            } else {
                $this->deleteStepOverrideKey($tenant, $name, $stepIndex, 'module');
            }

            $this->em->flush();

            $this->auditLogger->logUpdate(
                'WorkflowStepOverlay',
                null, // composite key (workflow/step) lives in the description; entityId is ?int
                $oldValues,
                $newValues,
                sprintf('Workflow-Step-Override für %s / Step %d gespeichert.', $name, $stepIndex),
            );

            $this->addFlash('success', 'admin.workflows.save_success');

            return $this->redirectToRoute('admin_workflow_overlay_show', ['name' => $name]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/workflows/step_edit.html.twig', [
            'form' => $form,
            'workflowName' => $name,
            'stepIndex' => $stepIndex,
            'yamlStep' => $yamlStep,
            'existing' => $existing,
        ], new Response(status: $status));
    }

    /**
     * Reset (delete) all step-level overrides for a specific step, restoring YAML baseline.
     */
    #[Route('/{name}/{stepIndex}/reset', name: 'admin_workflow_overlay_reset', methods: ['POST'], requirements: ['name' => '[a-z][a-z0-9_]*', 'stepIndex' => '\d+'])]
    public function reset(Request $request, string $name, int $stepIndex): Response
    {
        $csrfToken = sprintf('workflow_step_reset_%s_%d', $name, $stepIndex);
        if (!$this->isCsrfTokenValid($csrfToken, $request->request->get('_token'))) {
            $this->addFlash('error', 'common.csrf_invalid');
            return $this->redirectToRoute('admin_workflow_overlay_show', ['name' => $name]);
        }

        $tenant = $this->resolveTenant($request);
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }

        $deleted = $this->overrideRepo->deleteStepOverrides($tenant, $name, $stepIndex);

        if ($deleted > 0) {
            // logDelete, not logUpdate: logUpdate only records keys present in
            // the new values, so an "everything removed" update logged nothing.
            $this->auditLogger->logDelete(
                'WorkflowStepOverlay',
                null, // composite key (workflow/step) lives in the description; entityId is ?int
                ['workflow' => $name, 'step' => $stepIndex, 'deleted_count' => $deleted],
                sprintf('Workflow-Step-Overrides für %s / Step %d auf YAML-Baseline zurückgesetzt.', $name, $stepIndex),
            );
        }

        $this->addFlash('success', 'admin.workflows.reset_success');
        return $this->redirectToRoute('admin_workflow_overlay_show', ['name' => $name]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the tenant for the current admin operation.
     *
     * delegates to {@see TenantContext::resolveAdminScope()}
     * so SUPER_ADMIN can pass an explicit `tenant_id` (query or POST) to
     * target another tenant. ROLE_ADMIN falls back to their own active
     * tenant. Throws AccessDeniedException for cross-tenant attempts.
     */
    /**
     * Active users of the tenant as ChoiceType choices (label => id).
     *
     * @return array<string, int>
     */
    private function userChoices(Tenant $tenant): array
    {
        $users = $this->em->getRepository(User::class)->findBy(
            ['tenant' => $tenant, 'isActive' => true],
            ['lastName' => 'ASC', 'firstName' => 'ASC'],
        );
        $choices = [];
        foreach ($users as $u) {
            $label = trim(sprintf('%s %s', $u->getFirstName() ?? '', $u->getLastName() ?? ''));
            $choices[sprintf('%s (%s)', $label !== '' ? $label : (string) $u->getEmail(), (string) $u->getEmail())] = (int) $u->getId();
        }

        return $choices;
    }

    private function resolveTenant(Request $request): ?Tenant
    {
        $requested = $request->request->get('tenant_id') ?? $request->query->get('tenant_id');
        return $this->tenantContext->resolveAdminScope($requested);
    }

    /**
     * Load steps for a workflow name.
     *
     * Uses RegulatoryWorkflowLoader (injected via constructor) as the primary source.
     * Falls back to parsing the YAML metadata.steps block directly when the loader
     * returns null (workflow not registered in the Symfony Workflow Registry).
     *
     * @return list<array<string, mixed>>
     */
    private function loadStepsForWorkflow(string $name): array
    {
        $steps = $this->workflowLoader->getStepsForWorkflow($name);
        if (!empty($steps)) {
            return $steps;
        }

        return $this->parseStepsFromYaml($name);
    }

    /**
     * Parse metadata.steps from a regulatory YAML file as fallback.
     *
     * @return list<array<string, mixed>>
     */
    private function parseStepsFromYaml(string $name): array
    {
        $yamlFile = $this->findYamlFile($name);
        if ($yamlFile === null || !file_exists($yamlFile)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($yamlFile);
        } catch (\Throwable) {
            return [];
        }

        // Regulatory YAML: framework.workflows.{name}.metadata.steps
        $steps = $data['framework']['workflows'][$name]['metadata']['steps'] ?? [];
        if (!is_array($steps)) {
            return [];
        }

        return array_values($steps);
    }

    /**
     * Find the YAML file for a given workflow name.
     * Checks config/workflows/regulatory/ first, then config/workflows/.
     */
    private function findYamlFile(string $name): ?string
    {
        $base = \dirname(__DIR__, 3) . '/config/workflows/';

        // Regulatory workflows may be stored under regulatory/ subdirectory
        $regulatory = $base . 'regulatory/' . $name . '.yaml';
        if (file_exists($regulatory)) {
            return $regulatory;
        }

        // Lifecycle workflows: name may end in _lifecycle or be stripped
        $plain = $base . $name . '.yaml';
        if (file_exists($plain)) {
            return $plain;
        }

        // Try stripping _lifecycle suffix for files like document.yaml
        $stripped = str_replace('_lifecycle', '', $name);
        $strippedFile = $base . $stripped . '.yaml';
        if (file_exists($strippedFile)) {
            return $strippedFile;
        }

        return null;
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

    private function upsertStepOverride(
        Tenant $tenant,
        string $workflowName,
        int $stepIndex,
        string $configKey,
        mixed $configValue,
        ?object $user,
        DateTimeImmutable $now,
    ): void {
        $row = $this->overrideRepo->findOneStepOverrideByKey($tenant, $workflowName, $stepIndex, $configKey);
        if ($row === null) {
            $row = (new LifecycleConfig())
                ->setTenant($tenant)
                ->setWorkflowName('workflow:' . $workflowName)
                ->setTransitionName('step:' . $stepIndex)
                ->setConfigKey($configKey);
            $this->em->persist($row);
        }
        $row->setConfigValue($configValue)
            ->setUpdatedAt($now);

        if ($user instanceof User) {
            $row->setUpdatedByUser($user);
        }
    }

    private function deleteStepOverrideKey(
        Tenant $tenant,
        string $workflowName,
        int $stepIndex,
        string $configKey,
    ): void {
        $row = $this->overrideRepo->findOneStepOverrideByKey($tenant, $workflowName, $stepIndex, $configKey);
        if ($row !== null) {
            $this->em->remove($row);
        }
    }
}

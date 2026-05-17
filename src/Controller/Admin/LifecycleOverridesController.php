<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LifecycleConfig;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\Admin\LifecycleOverrideType;
use App\Repository\LifecycleConfigRepository;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\Registry;

/**
 * Per-tenant admin UI for lifecycle_config overrides.
 *
 * Allows ROLE_ADMIN to override workflow transition metadata (roles,
 * reason_required, four_eyes, module) without changing YAML source files.
 * All changes are tenant-scoped and audit-logged.
 */
#[Route('/admin/lifecycle-overrides')]
#[IsGranted('ROLE_ADMIN')]
final class LifecycleOverridesController extends AbstractController
{
    /** Keys that can be overridden via this UI. */
    private const OVERRIDEABLE_KEYS = ['roles', 'reason_required', 'four_eyes', 'module'];

    public function __construct(
        private readonly Registry $workflowRegistry,
        private readonly LifecycleConfigRepository $overrideRepo,
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    #[Route('', name: 'admin_lifecycle_overrides_index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return $this->render('admin/lifecycle_overrides/no_tenant.html.twig');
        }

        $workflowNames = $this->listKnownWorkflowNames();
        $rows = [];
        foreach ($workflowNames as $name) {
            $rows[] = [
                'name' => $name,
                'override_count' => $this->overrideRepo->countForWorkflow($tenant, $name),
            ];
        }

        return $this->render('admin/lifecycle_overrides/index.html.twig', [
            'workflows' => $rows,
        ]);
    }

    #[Route('/{workflowName}', name: 'admin_lifecycle_overrides_show', methods: ['GET'], requirements: ['workflowName' => '[a-z][a-z0-9_]*_lifecycle'])]
    public function show(string $workflowName): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return $this->render('admin/lifecycle_overrides/no_tenant.html.twig');
        }

        $known = $this->listKnownWorkflowNames();
        if (!in_array($workflowName, $known, true)) {
            throw $this->createNotFoundException(sprintf('Unknown workflow "%s".', $workflowName));
        }

        $transitions = $this->getTransitionsForWorkflow($workflowName);
        $overrides = $this->overrideRepo->findForWorkflow($tenant, $workflowName);

        // Build a quick-lookup map: transition => [key => value]
        $overrideMap = [];
        foreach ($overrides as $override) {
            $overrideMap[$override->getTransitionName()][$override->getConfigKey()] = $override->getConfigValue();
        }

        return $this->render('admin/lifecycle_overrides/show.html.twig', [
            'workflowName' => $workflowName,
            'transitions' => $transitions,
            'overrideMap' => $overrideMap,
        ]);
    }

    #[Route('/{workflowName}/{transitionName}/edit', name: 'admin_lifecycle_overrides_edit', methods: ['GET', 'POST'], requirements: ['workflowName' => '[a-z][a-z0-9_]*_lifecycle', 'transitionName' => '[a-z][a-z0-9_]*'])]
    public function edit(Request $request, string $workflowName, string $transitionName): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return $this->render('admin/lifecycle_overrides/no_tenant.html.twig');
        }

        $known = $this->listKnownWorkflowNames();
        if (!in_array($workflowName, $known, true)) {
            throw $this->createNotFoundException(sprintf('Unknown workflow "%s".', $workflowName));
        }

        $transitions = $this->getTransitionsForWorkflow($workflowName);
        if (!in_array($transitionName, $transitions, true)) {
            throw $this->createNotFoundException(sprintf('Unknown transition "%s" in workflow "%s".', $transitionName, $workflowName));
        }

        // Load existing overrides for this transition into form-ready data
        $existing = $this->overrideRepo->findOverridesForTransition($tenant, $workflowName, $transitionName);

        $formData = [
            'roles_raw' => isset($existing['roles']) && is_array($existing['roles'])
                ? implode(', ', $existing['roles'])
                : ($existing['roles'] ?? null),
            'reason_required' => $existing['reason_required'] ?? false,
            'four_eyes' => $existing['four_eyes'] ?? false,
            'module' => $existing['module'] ?? null,
        ];

        $form = $this->createForm(LifecycleOverrideType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $user = $this->getUser();
            $now = new DateTimeImmutable();

            $oldValues = $existing;
            $newValues = [];

            // -- roles --
            $rolesRaw = trim((string) ($data['roles_raw'] ?? ''));
            if ($rolesRaw !== '') {
                $roles = array_values(array_filter(array_map('trim', explode(',', $rolesRaw))));
                $this->upsertOverride($tenant, $workflowName, $transitionName, 'roles', $roles, $user, $now);
                $newValues['roles'] = $roles;
            } else {
                $this->deleteOverrideKey($tenant, $workflowName, $transitionName, 'roles');
            }

            // -- reason_required (only if toggle enabled) --
            $reasonOverrideEnabled = $form->get('reason_required_override')->getData();
            if ($reasonOverrideEnabled) {
                $val = (bool) ($data['reason_required'] ?? false);
                $this->upsertOverride($tenant, $workflowName, $transitionName, 'reason_required', $val, $user, $now);
                $newValues['reason_required'] = $val;
            } else {
                $this->deleteOverrideKey($tenant, $workflowName, $transitionName, 'reason_required');
            }

            // -- four_eyes (only if toggle enabled) --
            $fourEyesOverrideEnabled = $form->get('four_eyes_override')->getData();
            if ($fourEyesOverrideEnabled) {
                $val = (bool) ($data['four_eyes'] ?? false);
                $this->upsertOverride($tenant, $workflowName, $transitionName, 'four_eyes', $val, $user, $now);
                $newValues['four_eyes'] = $val;
            } else {
                $this->deleteOverrideKey($tenant, $workflowName, $transitionName, 'four_eyes');
            }

            // -- module --
            $module = trim((string) ($data['module'] ?? ''));
            if ($module !== '') {
                $this->upsertOverride($tenant, $workflowName, $transitionName, 'module', $module, $user, $now);
                $newValues['module'] = $module;
            } else {
                $this->deleteOverrideKey($tenant, $workflowName, $transitionName, 'module');
            }

            $this->em->flush();

            $this->auditLogger->logUpdate(
                'LifecycleConfig',
                sprintf('%s/%s', $workflowName, $transitionName),
                $oldValues,
                $newValues,
                sprintf('Lifecycle-Override für %s / %s gespeichert.', $workflowName, $transitionName),
            );

            $this->addFlash('success', 'admin.lifecycle_overrides.save_success');

            return $this->redirectToRoute('admin_lifecycle_overrides_show', ['workflowName' => $workflowName]);
        }

        return $this->render('admin/lifecycle_overrides/edit.html.twig', [
            'form' => $form,
            'workflowName' => $workflowName,
            'transitionName' => $transitionName,
            'existing' => $existing,
        ]);
    }

    #[Route('/{workflowName}/{transitionName}/reset', name: 'admin_lifecycle_overrides_reset', methods: ['POST'], requirements: ['workflowName' => '[a-z][a-z0-9_]*_lifecycle', 'transitionName' => '[a-z][a-z0-9_]*'])]
    public function reset(Request $request, string $workflowName, string $transitionName): Response
    {
        if (!$this->isCsrfTokenValid('lifecycle_reset_' . $transitionName, $request->request->get('_token'))) {
            $this->addFlash('error', 'common.csrf_invalid');
            return $this->redirectToRoute('admin_lifecycle_overrides_show', ['workflowName' => $workflowName]);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }

        $deleted = $this->overrideRepo->deleteForTransition($tenant, $workflowName, $transitionName);

        if ($deleted > 0) {
            $this->auditLogger->logUpdate(
                'LifecycleConfig',
                sprintf('%s/%s', $workflowName, $transitionName),
                ['deleted_count' => $deleted],
                [],
                sprintf('Lifecycle-Overrides für %s / %s auf YAML-Baseline zurückgesetzt.', $workflowName, $transitionName),
            );
        }

        $this->addFlash('success', 'admin.lifecycle_overrides.reset_success');
        return $this->redirectToRoute('admin_lifecycle_overrides_show', ['workflowName' => $workflowName]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function upsertOverride(
        Tenant $tenant,
        string $workflowName,
        string $transitionName,
        string $configKey,
        mixed $configValue,
        ?object $user,
        DateTimeImmutable $now,
    ): void {
        $row = $this->overrideRepo->findOneByKey($tenant, $workflowName, $transitionName, $configKey);
        if ($row === null) {
            $row = (new LifecycleConfig())
                ->setTenant($tenant)
                ->setWorkflowName($workflowName)
                ->setTransitionName($transitionName)
                ->setConfigKey($configKey);
            $this->em->persist($row);
        }
        $row->setConfigValue($configValue)
            ->setUpdatedAt($now);

        if ($user instanceof User) {
            $row->setUpdatedByUser($user);
        }
    }

    private function deleteOverrideKey(
        Tenant $tenant,
        string $workflowName,
        string $transitionName,
        string $configKey,
    ): void {
        $row = $this->overrideRepo->findOneByKey($tenant, $workflowName, $transitionName, $configKey);
        if ($row !== null) {
            $this->em->remove($row);
        }
    }

    /**
     * Returns the list of transition names defined in a workflow's YAML.
     *
     * Uses the Symfony Workflow Registry. Requires a dummy subject instance
     * matching the workflow's `supports` definition. Falls back to empty
     * array if the workflow cannot be introspected.
     *
     * @return list<string>
     */
    private function getTransitionsForWorkflow(string $workflowName): array
    {
        try {
            $all = $this->workflowRegistry->all(new \stdClass());
        } catch (\Throwable) {
            $all = [];
        }

        // workflowRegistry->all() needs a subject that matches. Instead we
        // parse the YAML directly — fastest and most reliable.
        $yamlFile = \dirname(__DIR__, 3) . '/config/workflows/' . str_replace('_lifecycle', '', $workflowName) . '.yaml';
        if (!file_exists($yamlFile)) {
            return [];
        }

        $content = file_get_contents($yamlFile);
        if ($content === false) {
            return [];
        }

        // Minimal YAML parse: extract transition keys from "transitions:" block
        $transitions = [];
        if (preg_match('/\btransitions:\s*\n(.*?)(?=\n\s{4}\w|\z)/s', $content, $m)) {
            preg_match_all('/^\s{16}(\w+):\s*$/m', $m[1], $tm);
            $transitions = $tm[1] ?? [];
        }

        return array_values($transitions);
    }

    /**
     * @return list<string>
     */
    private function listKnownWorkflowNames(): array
    {
        $names = [];
        $pattern = \dirname(__DIR__, 3) . '/config/workflows/*.yaml';
        foreach (glob($pattern) as $f) {
            $base = basename($f, '.yaml');
            // Normalise: document → document_lifecycle, already _lifecycle → keep
            if (!str_ends_with($base, '_lifecycle')) {
                $base .= '_lifecycle';
            }
            $names[] = $base;
        }
        sort($names);
        return $names;
    }
}

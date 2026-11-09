<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\LifecycleConfig;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\Admin\LifecycleOverrideType;
use App\Lifecycle\Config\LifecycleOverridePolicy;
use App\Repository\LifecycleConfigRepository;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-tenant admin UI for lifecycle_config overrides.
 *
 * Allows ROLE_ADMIN to override workflow transition metadata (roles,
 * reason_required, four_eyes, module) without changing YAML source files.
 * All changes are tenant-scoped and audit-logged. What may change is decided by
 * {@see LifecycleOverridePolicy} (S15: roles from the role list only, four eyes /
 * reasons / module gates only tightened, Leitung and DSB transitions keep their roles).
 *
 * ROLE_ADMIN configures own tenant,
 * SUPER_ADMIN any. Tenant scope resolves via
 * {@see TenantContext::resolveAdminScope()}.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/lifecycle-overrides')]
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
final class LifecycleOverridesController extends AbstractController
{
    public function __construct(
        private readonly LifecycleConfigRepository $overrideRepo,
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'admin_lifecycle_overrides_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->resolveTenant($request);
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
    public function show(Request $request, string $workflowName): Response
    {
        $tenant = $this->resolveTenant($request);
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
        $tenant = $this->resolveTenant($request);
        if (!$tenant instanceof Tenant) {
            return $this->render('admin/lifecycle_overrides/no_tenant.html.twig');
        }

        $known = $this->listKnownWorkflowNames();
        if (!in_array($workflowName, $known, true)) {
            throw $this->createNotFoundException(sprintf('Unknown workflow "%s".', $workflowName));
        }

        $transitionMeta = $this->transitionMetadata($workflowName);
        if (!array_key_exists($transitionName, $transitionMeta)) {
            throw $this->createNotFoundException(sprintf('Unknown transition "%s" in workflow "%s".', $transitionName, $workflowName));
        }
        $yaml = $transitionMeta[$transitionName];
        $rolesLocked = LifecycleOverridePolicy::rolesLocked($yaml);

        // Load existing overrides for this transition into form-ready data
        $existing = $this->overrideRepo->findOverridesForTransition($tenant, $workflowName, $transitionName);

        $formData = [
            'roles' => array_values(array_intersect(
                is_array($existing['roles'] ?? null) ? $existing['roles'] : [],
                LifecycleOverridePolicy::assignableRoles(),
            )),
            'reason_required' => $existing['reason_required'] ?? false,
            'four_eyes' => $existing['four_eyes'] ?? false,
            'module' => $existing['module'] ?? null,
        ];

        $form = $this->createForm(LifecycleOverrideType::class, $formData, ['roles_locked' => $rolesLocked]);
        $form->handleRequest($request);

        // S15: the policy decides, not the form alone — tighten, never loosen a norm duty.
        $values = [];
        if ($form->isSubmitted()) {
            $data = $form->getData();
            $roles = $rolesLocked ? [] : array_values((array) ($data['roles'] ?? []));
            $values = [
                'roles' => $roles === [] ? null : $roles,
                'reason_required' => $form->get('reason_required_override')->getData()
                    ? (bool) ($data['reason_required'] ?? false) : null,
                'four_eyes' => $form->get('four_eyes_override')->getData()
                    ? (bool) ($data['four_eyes'] ?? false) : null,
                'module' => trim((string) ($data['module'] ?? '')) === '' ? null : trim((string) $data['module']),
            ];
            foreach ($values as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $field = $key === 'reason_required' || $key === 'four_eyes' ? $key . '_override' : $key;
                foreach (LifecycleOverridePolicy::violations($yaml, $key, $value) as $violation) {
                    $form->get($field)->addError(new FormError($this->translator->trans($violation, [], 'admin')));
                }
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            $now = new DateTimeImmutable();

            $oldValues = $existing;
            $newValues = [];
            foreach ($values as $key => $value) {
                if ($value === null) {
                    $this->deleteOverrideKey($tenant, $workflowName, $transitionName, $key);
                    continue;
                }
                $this->upsertOverride($tenant, $workflowName, $transitionName, $key, $value, $user, $now);
                $newValues[$key] = $value;
            }

            $this->em->flush();

            $this->auditLogger->logUpdate(
                'LifecycleConfig',
                null, // composite key (workflow/transition) lives in the description; entityId is ?int

                $oldValues,
                $newValues,
                sprintf('Lifecycle-Override für %s / %s gespeichert.', $workflowName, $transitionName),
            );

            $this->addFlash('success', 'admin.lifecycle_overrides.save_success');

            return $this->redirectToRoute('admin_lifecycle_overrides_show', ['workflowName' => $workflowName]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/lifecycle_overrides/edit.html.twig', [
            'form' => $form,
            'workflowName' => $workflowName,
            'transitionName' => $transitionName,
            'existing' => $existing,
            'yaml' => $yaml,
            'rolesLocked' => $rolesLocked,
        ], new Response(status: $status));
    }

    #[Route('/{workflowName}/{transitionName}/reset', name: 'admin_lifecycle_overrides_reset', methods: ['POST'], requirements: ['workflowName' => '[a-z][a-z0-9_]*_lifecycle', 'transitionName' => '[a-z][a-z0-9_]*'])]
    public function reset(Request $request, string $workflowName, string $transitionName): Response
    {
        if (!$this->isCsrfTokenValid('lifecycle_reset_' . $transitionName, $request->request->get('_token'))) {
            $this->addFlash('error', 'common.csrf_invalid');
            return $this->redirectToRoute('admin_lifecycle_overrides_show', ['workflowName' => $workflowName]);
        }

        $tenant = $this->resolveTenant($request);
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }

        $deleted = $this->overrideRepo->deleteForTransition($tenant, $workflowName, $transitionName);

        if ($deleted > 0) {
            // logDelete, not logUpdate: logUpdate only records keys present in
            // the new values, so an "everything removed" update logged nothing.
            $this->auditLogger->logDelete(
                'LifecycleConfig',
                null, // composite key (workflow/transition) lives in the description; entityId is ?int
                ['workflow' => $workflowName, 'transition' => $transitionName, 'deleted_count' => $deleted],
                sprintf('Lifecycle-Overrides für %s / %s auf YAML-Baseline zurückgesetzt.', $workflowName, $transitionName),
            );
        }

        $this->addFlash('success', 'admin.lifecycle_overrides.reset_success');
        return $this->redirectToRoute('admin_lifecycle_overrides_show', ['workflowName' => $workflowName]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the tenant for the current admin operation.
     *
     * delegates to {@see TenantContext::resolveAdminScope()}
     * so SUPER_ADMIN can pass an explicit `tenant_id` (POST or query) to
     * target another tenant. ROLE_ADMIN falls back to their own active
     * tenant. Throws AccessDeniedException for cross-tenant attempts.
     */
    private function resolveTenant(Request $request): ?Tenant
    {
        $requested = $request->request->get('tenant_id') ?? $request->query->get('tenant_id');
        return $this->tenantContext->resolveAdminScope($requested);
    }

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
     * Transition names of a workflow with their YAML metadata, read from config/workflows/<name>.yaml.
     *
     * @return array<string, array<string, mixed>>
     */
    private function transitionMetadata(string $workflowName): array
    {
        $yamlFile = \dirname(__DIR__, 3) . '/config/workflows/' . str_replace('_lifecycle', '', $workflowName) . '.yaml';
        if (!is_file($yamlFile)) {
            return [];
        }
        $parsed = Yaml::parseFile($yamlFile);
        $transitions = $parsed['framework']['workflows'][$workflowName]['transitions'] ?? [];
        $meta = [];
        foreach (is_array($transitions) ? $transitions : [] as $name => $transition) {
            $meta[(string) $name] = is_array($transition['metadata'] ?? null) ? $transition['metadata'] : [];
        }

        return $meta;
    }

    /** @return list<string> */
    private function getTransitionsForWorkflow(string $workflowName): array
    {
        return array_keys($this->transitionMetadata($workflowName));
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
            // Instance workflows (tenant, role, permission, …) are never overridable.
            if (!\App\Lifecycle\EntityTypeRegistry::acceptsTenantOverrides($base)) {
                continue;
            }
            $names[] = $base;
        }
        sort($names);
        return $names;
    }
}

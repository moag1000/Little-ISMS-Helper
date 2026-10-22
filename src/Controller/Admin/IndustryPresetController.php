<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Trait\LocalizedFlashTrait;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\Setup\IndustryPresetApplier;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin UI for Industry-Preset application.
 *
 * Surfaces the 4 curated presets from `fixtures/library/presets/` so
 * tenant-admins can apply them without dropping to the CLI. Each preset
 * activates modules (active_modules.yaml), flips frameworks active=true,
 * and persists initial Control / Document skeletons — via the
 * {@see IndustryPresetApplier} shared with app:load-industry-preset.
 *
 * ROLE_ADMIN applies presets in their own
 * tenant, SUPER_ADMIN may target any via the `tenant_id` POST param.
 * Tenant scope resolves via {@see TenantContext::resolveAdminScope()}.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/industry-presets', name: 'app_admin_industry_preset_')]
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
class IndustryPresetController extends AbstractController
{
    use LocalizedFlashTrait;

    private const PRESET_DIR = __DIR__ . '/../../../fixtures/library/presets';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
        private readonly IndustryPresetApplier $presetApplier,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    protected function getFlashDomain(): string
    {
        return 'admin';
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $presets = $this->loadPresets();
        $activeModules = $this->moduleService->getActiveModules();

        return $this->render('admin/industry_preset/index.html.twig', [
            'presets' => $presets,
            'active_modules' => $activeModules,
        ]);
    }

    #[Route('/{preset}/preview', name: 'preview', methods: ['GET'], requirements: ['preset' => '[a-z0-9-]+'])]
    public function preview(string $preset): Response
    {
        $config = $this->loadPreset($preset);
        if ($config === null) {
            $this->flashError('admin.industry_preset.error.not_found');
            return $this->redirectToRoute('app_admin_industry_preset_index');
        }

        return $this->render('admin/industry_preset/preview.html.twig', [
            'preset' => $preset,
            'config' => $config,
            'active_modules' => $this->moduleService->getActiveModules(),
        ]);
    }

    #[Route('/{preset}/apply', name: 'apply', methods: ['POST'], requirements: ['preset' => '[a-z0-9-]+'])]
    public function apply(string $preset, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('industry_preset_' . $preset, (string) $request->request->get('_token'))) {
            $this->flashError('admin.industry_preset.error.invalid_csrf');
            return $this->redirectToRoute('app_admin_industry_preset_index');
        }

        $config = $this->loadPreset($preset);
        if ($config === null) {
            $this->flashError('admin.industry_preset.error.not_found');
            return $this->redirectToRoute('app_admin_industry_preset_index');
        }

        $tenant = $this->tenantContext->resolveAdminScope($request->request->get('tenant_id'));
        if ($tenant === null) {
            $this->flashError('admin.industry_preset.error.tenant_required');
            return $this->redirectToRoute('app_admin_industry_preset_index');
        }

        $result = $this->presetApplier->apply($preset, $config, $tenant);
        $modulesActivated = $result['modules_activated'];
        $frameworksActivated = $result['frameworks_activated'];
        $controlsCreated = $result['controls_created'];
        $documentsCreated = $result['documents_created'];

        if ($this->auditLogger !== null) {
            $this->auditLogger->logCustom(
                'industry_preset.applied',
                'IndustryPreset',
                null,
                null,
                [
                    'preset' => $preset,
                    'modules_activated' => $modulesActivated,
                    'frameworks_activated' => $frameworksActivated,
                    'controls_created' => $controlsCreated,
                    'documents_created' => $documentsCreated,
                ],
                sprintf('Industry-preset "%s" applied to tenant #%d', $preset, $tenant->getId()),
            );
        }

        $this->addFlash('success', sprintf(
            'Preset "%s" applied: %d modules, %d frameworks, %d controls, %d documents.',
            $config['name'] ?? $preset,
            count($modulesActivated),
            $frameworksActivated,
            $controlsCreated,
            $documentsCreated,
        ));

        return $this->redirectToRoute('app_admin_industry_preset_index');
    }

    /**
     * @return array<int, array{id: string, name: string, description: string, modules_count: int, frameworks_count: int}>
     */
    private function loadPresets(): array
    {
        $files = glob(self::PRESET_DIR . '/*.yaml') ?: [];
        $out = [];
        foreach ($files as $path) {
            $config = Yaml::parseFile($path);
            $id = basename($path, '.yaml');
            $out[] = [
                'id' => $id,
                'name' => (string) ($config['name'] ?? $id),
                'description' => (string) ($config['description'] ?? ''),
                'locale' => (string) ($config['locale'] ?? 'en'),
                'modules_count' => count($config['modules'] ?? []),
                'frameworks_count' => count(array_filter($config['frameworks'] ?? [], static fn(array $f) => $f['activate'] ?? false)),
                'controls_count' => count($config['initial_controls'] ?? []),
                'documents_count' => count($config['initial_documents'] ?? []),
            ];
        }
        return $out;
    }

    private function loadPreset(string $preset): ?array
    {
        // Defensive: allow only [a-z0-9-] to avoid path traversal
        if (preg_match('/^[a-z0-9-]+$/', $preset) !== 1) {
            return null;
        }
        $path = self::PRESET_DIR . '/' . $preset . '.yaml';
        if (!is_file($path)) {
            return null;
        }
        return Yaml::parseFile($path);
    }
}

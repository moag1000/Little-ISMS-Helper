<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ComplianceFramework;
use App\Entity\Control;
use App\Entity\Document;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

/**
 * V3 W2-M3 — Admin UI for Industry-Preset application.
 *
 * Surfaces the 4 curated presets from `fixtures/library/presets/` so
 * tenant-admins can apply them without dropping to the CLI. Each preset
 * activates modules (active_modules.yaml), flips frameworks active=true,
 * and persists initial Control / Document skeletons.
 */
#[Route('/admin/industry-presets', name: 'app_admin_industry_preset_')]
#[IsGranted('ROLE_ADMIN')]
class IndustryPresetController extends AbstractController
{
    private const PRESET_DIR = __DIR__ . '/../../../fixtures/library/presets';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleService,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
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
            $this->addFlash('error', 'Preset not found.');
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
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_industry_preset_index');
        }

        $config = $this->loadPreset($preset);
        if ($config === null) {
            $this->addFlash('error', 'Preset not found.');
            return $this->redirectToRoute('app_admin_industry_preset_index');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('error', 'Tenant context required.');
            return $this->redirectToRoute('app_admin_industry_preset_index');
        }

        // 1. Modules
        $modulesActivated = [];
        $current = $this->moduleService->getActiveModules();
        $merged = array_values(array_unique(array_merge($current, $config['modules'] ?? [])));
        if (count($merged) !== count($current)) {
            $modulesActivated = array_values(array_diff($merged, $current));
            $this->moduleService->saveActiveModules($merged);
        }

        // 2. Frameworks
        $frameworksActivated = 0;
        foreach ($config['frameworks'] ?? [] as $framework) {
            if (!($framework['activate'] ?? false)) {
                continue;
            }
            $code = (string) ($framework['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $fwRepo = $this->entityManager->getRepository(ComplianceFramework::class);
            $existing = $fwRepo->findOneBy(['code' => $code]);
            if ($existing instanceof ComplianceFramework) {
                if ($existing->isActive() !== true) {
                    $existing->setActive(true);
                    $frameworksActivated++;
                }
            } else {
                $fw = new ComplianceFramework();
                $fw->setCode($code);
                $fw->setName($code);
                $fw->setVersion('latest');
                $fw->setApplicableIndustry($config['name'] ?? 'all');
                $fw->setRegulatoryBody('—');
                $fw->setMandatory(($framework['priority'] ?? null) === 'primary');
                $fw->setActive(true);
                $this->entityManager->persist($fw);
                $frameworksActivated++;
            }
        }

        // 3. Controls
        $controlsCreated = 0;
        $controlRepo = $this->entityManager->getRepository(Control::class);
        foreach ($config['initial_controls'] ?? [] as $cdef) {
            $existing = $controlRepo->findOneBy([
                'tenant' => $tenant,
                'controlId' => $cdef['identifier'] ?? null,
            ]);
            if ($existing instanceof Control) {
                continue;
            }
            $control = new Control();
            if (method_exists($control, 'setTenant')) {
                $control->setTenant($tenant);
            }
            if (method_exists($control, 'setControlId')) {
                $control->setControlId((string) ($cdef['identifier'] ?? ''));
            }
            if (method_exists($control, 'setName')) {
                $control->setName((string) ($cdef['name'] ?? ''));
            }
            if (method_exists($control, 'setImplementationStatus')) {
                $control->setImplementationStatus((string) ($cdef['implementation_status'] ?? 'not_started'));
            }
            if (method_exists($control, 'setApplicable')) {
                $control->setApplicable(true);
            }
            $this->entityManager->persist($control);
            $controlsCreated++;
        }

        // 4. Documents
        $documentsCreated = 0;
        $docRepo = $this->entityManager->getRepository(Document::class);
        foreach ($config['initial_documents'] ?? [] as $ddef) {
            $existing = $docRepo->findOneBy([
                'tenant' => $tenant,
                'title' => $ddef['title'] ?? null,
            ]);
            if ($existing instanceof Document) {
                continue;
            }
            $doc = new Document();
            if (method_exists($doc, 'setTenant')) {
                $doc->setTenant($tenant);
            }
            if (method_exists($doc, 'setTitle')) {
                $doc->setTitle((string) ($ddef['title'] ?? ''));
            }
            if (method_exists($doc, 'setDocumentType')) {
                $doc->setDocumentType((string) ($ddef['type'] ?? 'policy'));
            }
            if (method_exists($doc, 'setStatus')) {
                $doc->setStatus((string) ($ddef['status'] ?? 'draft'));
            }
            $this->entityManager->persist($doc);
            $documentsCreated++;
        }

        $this->entityManager->flush();

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

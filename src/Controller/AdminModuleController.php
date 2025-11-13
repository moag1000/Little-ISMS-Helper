<?php

namespace App\Controller;

use App\Service\DataImportService;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin wrapper for Module Management
 * Integrates existing /modules functionality into admin panel
 */
#[Route('/admin/modules')]
class AdminModuleController extends AbstractController
{
    public function __construct(
        private readonly ModuleConfigurationService $moduleConfigService,
        private readonly DataImportService $dataImportService,
        private readonly TranslatorInterface $translator
    ) {
    }

    /**
     * Module Overview - Admin Panel
     */
    #[Route('', name: 'admin_modules_index', methods: ['GET'])]
    #[IsGranted('MODULE_VIEW')]
    public function index(): Response
    {
        $allModules = $this->moduleConfigService->getAllModules();
        $activeModules = $this->moduleConfigService->getActiveModules();
        $statistics = $this->moduleConfigService->getStatistics();
        $dependencyGraph = $this->moduleConfigService->getDependencyGraph();

        return $this->render('admin/modules/index.html.twig', [
            'all_modules' => $allModules,
            'active_modules' => $activeModules,
            'statistics' => $statistics,
            'dependency_graph' => $dependencyGraph,
        ]);
    }

    /**
     * Module Activation
     */
    #[Route('/{moduleKey}/activate', name: 'admin_modules_activate', methods: ['POST'])]
    #[IsGranted('MODULE_MANAGE')]
    public function activate(string $moduleKey, Request $request): Response
    {
        $result = $this->moduleConfigService->activateModule($moduleKey);

        if ($result['success']) {
            $this->addFlash('success', $result['message']);

            // Show added dependencies
            foreach ($result['added_modules'] ?? [] as $addedKey) {
                $module = $this->moduleConfigService->getModule($addedKey);
                if ($module && $addedKey !== $moduleKey) {
                    $this->addFlash('info', $this->translator->trans('module.info.added_as_dependency', ['name' => $module['name']]));
                }
            }
        } else {
            $this->addFlash('error', $result['error']);
        }

        return $this->redirectToRoute('admin_modules_index');
    }

    /**
     * Module Deactivation
     */
    #[Route('/{moduleKey}/deactivate', name: 'admin_modules_deactivate', methods: ['POST'])]
    #[IsGranted('MODULE_MANAGE')]
    public function deactivate(string $moduleKey): Response
    {
        $result = $this->moduleConfigService->deactivateModule($moduleKey);

        if ($result['success']) {
            $this->addFlash('success', $result['message']);
        } else {
            $this->addFlash('error', $result['error']);

            if (isset($result['dependents'])) {
                $this->addFlash('warning', $this->translator->trans('module.warning.disable_dependents_first', ['dependents' => implode(', ', $result['dependents'])]));
            }
        }

        return $this->redirectToRoute('admin_modules_index');
    }

    /**
     * Module Details
     */
    #[Route('/{moduleKey}/details', name: 'admin_modules_details', methods: ['GET'])]
    #[IsGranted('MODULE_VIEW')]
    public function details(string $moduleKey): Response
    {
        $module = $this->moduleConfigService->getModule($moduleKey);

        if (!$module) {
            $this->addFlash('error', $this->translator->trans('module.error.not_found'));
            return $this->redirectToRoute('admin_modules_index');
        }

        $isActive = $this->moduleConfigService->isModuleActive($moduleKey);
        $dependencyGraph = $this->moduleConfigService->getDependencyGraph();
        $moduleGraph = $dependencyGraph[$moduleKey] ?? null;

        // Get available sample data for this module
        $availableSampleData = [];
        foreach ($this->moduleConfigService->getSampleData() as $key => $sampleData) {
            if (in_array($moduleKey, $sampleData['required_modules'] ?? [])) {
                $availableSampleData[$key] = $sampleData;
            }
        }

        return $this->render('admin/modules/details.html.twig', [
            'module_key' => $moduleKey,
            'module' => $module,
            'is_active' => $isActive,
            'graph' => $moduleGraph,
            'sample_data' => $availableSampleData,
        ]);
    }

    /**
     * Import Module Sample Data
     */
    #[Route('/{moduleKey}/import-data', name: 'admin_modules_import_data', methods: ['POST'])]
    #[IsGranted('MODULE_MANAGE')]
    public function importData(string $moduleKey, Request $request): Response
    {
        $module = $this->moduleConfigService->getModule($moduleKey);

        if (!$module) {
            $this->addFlash('error', $this->translator->trans('module.error.not_found'));
            return $this->redirectToRoute('admin_modules_index');
        }

        if (!$this->moduleConfigService->isModuleActive($moduleKey)) {
            $this->addFlash('error', $this->translator->trans('module.error.not_active'));
            return $this->redirectToRoute('admin_modules_details', ['moduleKey' => $moduleKey]);
        }

        // Get selected sample data
        $selectedSamples = $request->request->all('samples') ?? [];
        $activeModules = $this->moduleConfigService->getActiveModules();

        $result = $this->dataImportService->importSampleData($selectedSamples, $activeModules);

        foreach ($result['results'] as $importResult) {
            if ($importResult['status'] === 'success') {
                $this->addFlash('success', $importResult['name'] . ': ' . $importResult['message']);
            } elseif ($importResult['status'] === 'error') {
                $this->addFlash('error', $importResult['name'] . ': ' . $importResult['message']);
            }
        }

        return $this->redirectToRoute('admin_modules_details', ['moduleKey' => $moduleKey]);
    }

    /**
     * Export Module Data
     */
    #[Route('/{moduleKey}/export', name: 'admin_modules_export', methods: ['GET'])]
    #[IsGranted('MODULE_VIEW')]
    public function export(string $moduleKey): Response
    {
        $result = $this->dataImportService->exportModuleData($moduleKey);

        if (!$result['success']) {
            $this->addFlash('error', $result['error']);
            return $this->redirectToRoute('admin_modules_details', ['moduleKey' => $moduleKey]);
        }

        // Create YAML download
        $yaml = \Symfony\Component\Yaml\Yaml::dump($result['data'], 6, 2);

        $response = new Response($yaml);
        $response->headers->set('Content-Type', 'application/x-yaml');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="module_%s_export_%s.yaml"', $moduleKey, date('Y-m-d')));

        return $response;
    }

    /**
     * Dependency Graph Visualization
     */
    #[Route('/dependency-graph', name: 'admin_modules_graph', methods: ['GET'])]
    #[IsGranted('MODULE_VIEW')]
    public function dependencyGraph(): Response
    {
        $dependencyGraph = $this->moduleConfigService->getDependencyGraph();
        $activeModules = $this->moduleConfigService->getActiveModules();

        return $this->render('admin/modules/graph.html.twig', [
            'dependency_graph' => $dependencyGraph,
            'active_modules' => $activeModules,
        ]);
    }
}

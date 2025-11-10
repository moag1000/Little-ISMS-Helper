<?php

namespace App\Controller;

use App\Service\DataImportService;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/modules')]
class ModuleManagementController extends AbstractController
{
    public function __construct(
        private readonly ModuleConfigurationService $moduleConfigService,
        private readonly DataImportService $dataImportService,
        private readonly TranslatorInterface $translator
    ) {
    }

    /**
     * Module-Übersicht
     */
    #[Route('/', name: 'module_management_index')]
    public function index(): Response
    {
        $allModules = $this->moduleConfigService->getAllModules();
        $activeModules = $this->moduleConfigService->getActiveModules();
        $statistics = $this->moduleConfigService->getStatistics();
        $dependencyGraph = $this->moduleConfigService->getDependencyGraph();

        return $this->render('module_management/index.html.twig', [
            'all_modules' => $allModules,
            'active_modules' => $activeModules,
            'statistics' => $statistics,
            'dependency_graph' => $dependencyGraph,
        ]);
    }

    /**
     * Modul aktivieren
     */
    #[Route('/{moduleKey}/activate', name: 'module_management_activate', methods: ['POST'])]
    public function activate(string $moduleKey, Request $request): Response
    {
        $result = $this->moduleConfigService->activateModule($moduleKey);

        if ($result['success']) {
            $this->addFlash('success', $result['message']);

            // Zeige hinzugefügte Abhängigkeiten
            foreach ($result['added_modules'] ?? [] as $addedKey) {
                $module = $this->moduleConfigService->getModule($addedKey);
                if ($module && $addedKey !== $moduleKey) {
                    $this->addFlash('info', $this->translator->trans('module.info.added_as_dependency', ['name' => $module['name']]));
                }
            }
        } else {
            $this->addFlash('error', $result['error']);
        }

        return $this->redirectToRoute('module_management_index');
    }

    /**
     * Modul deaktivieren
     */
    #[Route('/{moduleKey}/deactivate', name: 'module_management_deactivate', methods: ['POST'])]
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

        return $this->redirectToRoute('module_management_index');
    }

    /**
     * Modul-Details
     */
    #[Route('/{moduleKey}/details', name: 'module_management_details')]
    public function details(string $moduleKey): Response
    {
        $module = $this->moduleConfigService->getModule($moduleKey);

        if (!$module) {
            $this->addFlash('error', $this->translator->trans('module.error.not_found'));
            return $this->redirectToRoute('module_management_index');
        }

        $isActive = $this->moduleConfigService->isModuleActive($moduleKey);
        $dependencyGraph = $this->moduleConfigService->getDependencyGraph();
        $moduleGraph = $dependencyGraph[$moduleKey] ?? null;

        // Hole verfügbare Beispiel-Daten für dieses Modul
        $availableSampleData = [];
        foreach ($this->moduleConfigService->getSampleData() as $key => $sampleData) {
            if (in_array($moduleKey, $sampleData['required_modules'] ?? [])) {
                $availableSampleData[$key] = $sampleData;
            }
        }

        return $this->render('module_management/details.html.twig', [
            'module_key' => $moduleKey,
            'module' => $module,
            'is_active' => $isActive,
            'graph' => $moduleGraph,
            'sample_data' => $availableSampleData,
        ]);
    }

    /**
     * Modul-Daten importieren (nachträglich)
     */
    #[Route('/{moduleKey}/import-data', name: 'module_management_import_data', methods: ['POST'])]
    public function importData(string $moduleKey, Request $request): Response
    {
        $module = $this->moduleConfigService->getModule($moduleKey);

        if (!$module) {
            $this->addFlash('error', $this->translator->trans('module.error.not_found'));
            return $this->redirectToRoute('module_management_index');
        }

        if (!$this->moduleConfigService->isModuleActive($moduleKey)) {
            $this->addFlash('error', $this->translator->trans('module.error.not_active'));
            return $this->redirectToRoute('module_management_details', ['moduleKey' => $moduleKey]);
        }

        // Hole ausgewählte Beispiel-Daten
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

        return $this->redirectToRoute('module_management_details', ['moduleKey' => $moduleKey]);
    }

    /**
     * Modul-Daten exportieren
     */
    #[Route('/{moduleKey}/export', name: 'module_management_export')]
    public function export(string $moduleKey): Response
    {
        $result = $this->dataImportService->exportModuleData($moduleKey);

        if (!$result['success']) {
            $this->addFlash('error', $result['error']);
            return $this->redirectToRoute('module_management_details', ['moduleKey' => $moduleKey]);
        }

        // Erstelle YAML-Download
        $yaml = \Symfony\Component\Yaml\Yaml::dump($result['data'], 6, 2);

        $response = new Response($yaml);
        $response->headers->set('Content-Type', 'application/x-yaml');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="module_%s_export_%s.yaml"', $moduleKey, date('Y-m-d')));

        return $response;
    }

    /**
     * Dependency-Graph visualisieren
     */
    #[Route('/dependency-graph', name: 'module_management_graph')]
    public function dependencyGraph(): Response
    {
        $dependencyGraph = $this->moduleConfigService->getDependencyGraph();
        $activeModules = $this->moduleConfigService->getActiveModules();

        return $this->render('module_management/graph.html.twig', [
            'dependency_graph' => $dependencyGraph,
            'active_modules' => $activeModules,
        ]);
    }
}

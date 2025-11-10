<?php

namespace App\Controller;

use App\Service\DataImportService;
use App\Service\ModuleConfigurationService;
use App\Service\SystemRequirementsChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/setup')]
class DeploymentWizardController extends AbstractController
{
    public function __construct(
        private readonly SystemRequirementsChecker $requirementsChecker,
        private readonly ModuleConfigurationService $moduleConfigService,
        private readonly DataImportService $dataImportService,
        private readonly TranslatorInterface $translator
    ) {
    }

    /**
     * Wizard Start / Übersicht
     */
    #[Route('/', name: 'setup_wizard_index')]
    public function index(): Response
    {
        // Prüfe ob Setup bereits durchgeführt wurde
        $setupComplete = $this->isSetupComplete();

        return $this->render('setup/index.html.twig', [
            'setup_complete' => $setupComplete,
        ]);
    }

    /**
     * Step 1: System-Anforderungen prüfen
     */
    #[Route('/step1-requirements', name: 'setup_step1_requirements')]
    public function step1Requirements(): Response
    {
        $results = $this->requirementsChecker->checkAll();

        return $this->render('setup/step1_requirements.html.twig', [
            'results' => $results,
            'can_proceed' => $results['overall']['can_proceed'],
        ]);
    }

    /**
     * Step 2: Module auswählen
     */
    #[Route('/step2-modules', name: 'setup_step2_modules')]
    public function step2Modules(SessionInterface $session): Response
    {
        // Prüfe ob Step 1 bestanden
        if (!$this->requirementsChecker->isSystemReady()) {
            $this->addFlash('error', $this->translator->trans('deployment.error.fix_requirements'));
            return $this->redirectToRoute('setup_step1_requirements');
        }

        $allModules = $this->moduleConfigService->getAllModules();
        $requiredModules = array_keys($this->moduleConfigService->getRequiredModules());
        $optionalModules = $this->moduleConfigService->getOptionalModules();

        // Lade vorherige Auswahl aus Session
        $selectedModules = $session->get('setup_selected_modules', $requiredModules);

        return $this->render('setup/step2_modules.html.twig', [
            'all_modules' => $allModules,
            'required_modules' => $requiredModules,
            'optional_modules' => $optionalModules,
            'selected_modules' => $selectedModules,
            'dependency_graph' => $this->moduleConfigService->getDependencyGraph(),
        ]);
    }

    /**
     * Step 2: Module speichern
     */
    #[Route('/step2-modules/save', name: 'setup_step2_modules_save', methods: ['POST'])]
    public function step2ModulesSave(Request $request, SessionInterface $session): Response
    {
        $selectedModules = $request->request->all('modules') ?? [];

        // Validiere und löse Abhängigkeiten auf
        $validation = $this->moduleConfigService->validateModuleSelection($selectedModules);

        if (!$validation['valid']) {
            foreach ($validation['errors'] as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('setup_step2_modules');
        }

        // Löse Abhängigkeiten auf
        $resolved = $this->moduleConfigService->resolveModuleDependencies($selectedModules);

        // Speichere in Session
        $session->set('setup_selected_modules', $resolved['modules']);

        // Zeige hinzugefügte Module
        foreach ($resolved['added'] as $addedModule) {
            $module = $this->moduleConfigService->getModule($addedModule);
            $this->addFlash('info', $this->translator->trans('deployment.info.module_added_auto', ['name' => $module['name']]));
        }

        foreach ($validation['warnings'] as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('setup_step3_database');
    }

    /**
     * Step 3: Datenbank initialisieren
     */
    #[Route('/step3-database', name: 'setup_step3_database')]
    public function step3Database(SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        if (empty($selectedModules)) {
            $this->addFlash('error', $this->translator->trans('deployment.error.select_modules'));
            return $this->redirectToRoute('setup_step2_modules');
        }

        // Prüfe Datenbank-Status
        $dbStatus = $this->dataImportService->checkDatabaseStatus();

        return $this->render('setup/step3_database.html.twig', [
            'selected_modules' => $selectedModules,
            'db_status' => $dbStatus,
        ]);
    }

    /**
     * Step 3: Datenbank-Migrationen ausführen
     */
    #[Route('/step3-database/migrate', name: 'setup_step3_database_migrate', methods: ['POST'])]
    public function step3DatabaseMigrate(): Response
    {
        $result = $this->dataImportService->runMigrations();

        if ($result['success']) {
            $this->addFlash('success', $result['message']);
            return $this->redirectToRoute('setup_step4_base_data');
        } else {
            $this->addFlash('error', $result['message']);
            return $this->redirectToRoute('setup_step3_database');
        }
    }

    /**
     * Step 4: Basis-Daten importieren
     */
    #[Route('/step4-base-data', name: 'setup_step4_base_data')]
    public function step4BaseData(SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        if (empty($selectedModules)) {
            $this->addFlash('error', $this->translator->trans('deployment.error.select_modules'));
            return $this->redirectToRoute('setup_step2_modules');
        }

        $baseData = $this->moduleConfigService->getBaseData();

        return $this->render('setup/step4_base_data.html.twig', [
            'selected_modules' => $selectedModules,
            'base_data' => $baseData,
        ]);
    }

    /**
     * Step 4: Basis-Daten importieren (Ausführen)
     */
    #[Route('/step4-base-data/import', name: 'setup_step4_base_data_import', methods: ['POST'])]
    public function step4BaseDataImport(SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        $result = $this->dataImportService->importBaseData($selectedModules);

        $successCount = 0;
        $errorCount = 0;

        foreach ($result['results'] as $importResult) {
            if ($importResult['status'] === 'success') {
                $successCount++;
                $this->addFlash('success', $importResult['name'] . ': ' . $importResult['message']);
            } elseif ($importResult['status'] === 'error') {
                $errorCount++;
                $this->addFlash('error', $importResult['name'] . ': ' . $importResult['message']);
            }
        }

        $session->set('setup_base_data_imported', true);

        return $this->redirectToRoute('setup_step5_sample_data');
    }

    /**
     * Step 5: Beispiel-Daten (optional)
     */
    #[Route('/step5-sample-data', name: 'setup_step5_sample_data')]
    public function step5SampleData(SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        if (empty($selectedModules)) {
            $this->addFlash('error', $this->translator->trans('deployment.error.select_modules'));
            return $this->redirectToRoute('setup_step2_modules');
        }

        $sampleData = $this->moduleConfigService->getAvailableSampleData($selectedModules);

        return $this->render('setup/step5_sample_data.html.twig', [
            'selected_modules' => $selectedModules,
            'sample_data' => $sampleData,
        ]);
    }

    /**
     * Step 5: Beispiel-Daten importieren
     */
    #[Route('/step5-sample-data/import', name: 'setup_step5_sample_data_import', methods: ['POST'])]
    public function step5SampleDataImport(Request $request, SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);
        $selectedSamples = $request->request->all('samples') ?? [];

        $result = $this->dataImportService->importSampleData($selectedSamples, $selectedModules);

        foreach ($result['results'] as $importResult) {
            if ($importResult['status'] === 'success') {
                $this->addFlash('success', $importResult['name'] . ': ' . $importResult['message']);
            } elseif ($importResult['status'] === 'error') {
                $this->addFlash('error', $importResult['name'] . ': ' . $importResult['message']);
            }
        }

        return $this->redirectToRoute('setup_step6_complete');
    }

    /**
     * Step 5: Beispiel-Daten überspringen
     */
    #[Route('/step5-sample-data/skip', name: 'setup_step5_sample_data_skip', methods: ['POST'])]
    public function step5SampleDataSkip(): Response
    {
        $this->addFlash('info', $this->translator->trans('deployment.info.sample_data_skipped'));
        return $this->redirectToRoute('setup_step6_complete');
    }

    /**
     * Step 6: Setup abschließen
     */
    #[Route('/step6-complete', name: 'setup_step6_complete')]
    public function step6Complete(SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        if (empty($selectedModules)) {
            $this->addFlash('error', $this->translator->trans('deployment.error.select_modules'));
            return $this->redirectToRoute('setup_step2_modules');
        }

        // Speichere aktive Module
        $this->moduleConfigService->saveActiveModules($selectedModules);

        // Markiere Setup als abgeschlossen
        $this->markSetupComplete();

        $statistics = $this->moduleConfigService->getStatistics();

        return $this->render('setup/step6_complete.html.twig', [
            'selected_modules' => $selectedModules,
            'statistics' => $statistics,
        ]);
    }

    /**
     * Prüft ob Setup abgeschlossen ist
     */
    private function isSetupComplete(): bool
    {
        $setupFile = $this->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        return file_exists($setupFile);
    }

    /**
     * Markiert Setup als abgeschlossen
     */
    private function markSetupComplete(): void
    {
        $setupFile = $this->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        file_put_contents($setupFile, date('Y-m-d H:i:s'));
    }

    /**
     * Setup zurücksetzen (nur Development)
     */
    #[Route('/reset', name: 'setup_wizard_reset')]
    public function reset(SessionInterface $session): Response
    {
        // Nur in Dev-Umgebung erlauben
        if ($this->getParameter('kernel.environment') !== 'dev') {
            throw $this->createAccessDeniedException('Nur in Development-Umgebung verfügbar');
        }

        // Lösche Setup-Lock
        $setupFile = $this->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (file_exists($setupFile)) {
            unlink($setupFile);
        }

        // Lösche Session
        $session->clear();

        $this->addFlash('success', $this->translator->trans('deployment.success.reset'));
        return $this->redirectToRoute('setup_wizard_index');
    }
}

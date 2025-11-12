<?php

namespace App\Controller;

use App\Form\AdminUserType;
use App\Form\DatabaseConfigurationType;
use App\Security\SetupAccessChecker;
use App\Service\DatabaseTestService;
use App\Service\DataImportService;
use App\Service\EnvironmentWriter;
use App\Service\ModuleConfigurationService;
use App\Service\SystemRequirementsChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/setup')]
class DeploymentWizardController extends AbstractController
{
    public function __construct(
        private readonly SystemRequirementsChecker $requirementsChecker,
        private readonly ModuleConfigurationService $moduleConfigService,
        private readonly DataImportService $dataImportService,
        private readonly TranslatorInterface $translator,
        private readonly SetupAccessChecker $setupChecker,
        private readonly EnvironmentWriter $envWriter,
        private readonly DatabaseTestService $dbTestService,
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * Wizard Start / Welcome
     */
    #[Route('/', name: 'setup_wizard_index')]
    public function index(): Response
    {
        // Check if setup is already complete
        $setupComplete = $this->setupChecker->isSetupComplete();

        if ($setupComplete) {
            // Setup already complete - show admin panel message
            return $this->render('setup/index.html.twig', [
                'setup_complete' => true,
            ]);
        }

        // Setup not complete - start wizard
        return $this->redirectToRoute('setup_step0_welcome');
    }

    /**
     * Step 0: Welcome & Language Selection
     */
    #[Route('/step0-welcome', name: 'setup_step0_welcome')]
    public function step0Welcome(): Response
    {
        return $this->render('setup/step0_welcome.html.twig');
    }

    /**
     * Step 1: Database Configuration
     */
    #[Route('/step1-database-config', name: 'setup_step1_database_config')]
    public function step1DatabaseConfig(Request $request, SessionInterface $session): Response
    {
        $form = $this->createForm(DatabaseConfigurationType::class);
        $form->handleRequest($request);

        $testResult = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $config = $form->getData();

            // Test database connection
            $testResult = $this->dbTestService->testConnection($config);

            if ($testResult['success']) {
                // Test passed - save configuration
                try {
                    // Ensure APP_SECRET exists
                    $this->envWriter->ensureAppSecret();

                    // Write database configuration
                    $this->envWriter->writeDatabaseConfig($config);

                    // Create database if needed
                    if ($testResult['create_needed'] ?? false) {
                        $createResult = $this->dbTestService->createDatabaseIfNotExists($config);

                        if (!$createResult['success']) {
                            $this->addFlash('error', $createResult['message']);
                            return $this->render('setup/step1_database_config.html.twig', [
                                'form' => $form,
                                'test_result' => $testResult,
                            ]);
                        }
                    }

                    // Store in session for display
                    $session->set('setup_database_configured', true);
                    $session->set('setup_database_type', $config['type']);

                    $this->addFlash('success', $this->translator->trans('setup.database.config_saved'));

                    return $this->redirectToRoute('setup_step2_admin_user');
                } catch (\Exception $e) {
                    $this->addFlash('error', $this->translator->trans('setup.database.config_failed') . ': ' . $e->getMessage());
                }
            }
        }

        return $this->render('setup/step1_database_config.html.twig', [
            'form' => $form,
            'test_result' => $testResult,
        ]);
    }

    /**
     * Step 2: Admin User Creation
     */
    #[Route('/step2-admin-user', name: 'setup_step2_admin_user')]
    public function step2AdminUser(Request $request, SessionInterface $session): Response
    {
        // Check if database is configured
        if (!$session->get('setup_database_configured')) {
            $this->addFlash('error', $this->translator->trans('setup.error.configure_database_first'));
            return $this->redirectToRoute('setup_step1_database_config');
        }

        $form = $this->createForm(AdminUserType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            try {
                // First run migrations to create database structure
                $migrationResult = $this->runMigrationsInternal();

                if (!$migrationResult['success']) {
                    $this->addFlash('error', $this->translator->trans('setup.admin.migration_failed') . ': ' . $migrationResult['message']);
                    return $this->render('setup/step2_admin_user.html.twig', [
                        'form' => $form,
                    ]);
                }

                // Create admin user via command
                $result = $this->createAdminUserViaCommand($data);

                if ($result['success']) {
                    $session->set('setup_admin_created', true);
                    $session->set('setup_admin_email', $data['email']);

                    $this->addFlash('success', $this->translator->trans('setup.admin.user_created'));

                    return $this->redirectToRoute('setup_step3_requirements');
                } else {
                    $this->addFlash('error', $result['message']);
                }
            } catch (\Exception $e) {
                $this->addFlash('error', $this->translator->trans('setup.admin.creation_failed') . ': ' . $e->getMessage());
            }
        }

        return $this->render('setup/step2_admin_user.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Step 3: System Requirements Check
     */
    #[Route('/step3-requirements', name: 'setup_step3_requirements')]
    public function step3Requirements(SessionInterface $session): Response
    {
        // Check if admin user is created
        if (!$session->get('setup_admin_created')) {
            $this->addFlash('error', $this->translator->trans('setup.error.create_admin_first'));
            return $this->redirectToRoute('setup_step2_admin_user');
        }

        $results = $this->requirementsChecker->checkAll();

        return $this->render('setup/step3_requirements.html.twig', [
            'results' => $results,
            'can_proceed' => $results['overall']['can_proceed'],
        ]);
    }

    /**
     * Step 4: Module Selection
     */
    #[Route('/step4-modules', name: 'setup_step4_modules')]
    public function step4Modules(SessionInterface $session): Response
    {
        // Check if requirements passed
        if (!$this->requirementsChecker->isSystemReady()) {
            $this->addFlash('error', $this->translator->trans('deployment.error.fix_requirements'));
            return $this->redirectToRoute('setup_step3_requirements');
        }

        $allModules = $this->moduleConfigService->getAllModules();
        $requiredModules = array_keys($this->moduleConfigService->getRequiredModules());
        $optionalModules = $this->moduleConfigService->getOptionalModules();

        // Load previous selection from session
        $selectedModules = $session->get('setup_selected_modules', $requiredModules);

        return $this->render('setup/step4_modules.html.twig', [
            'all_modules' => $allModules,
            'required_modules' => $requiredModules,
            'optional_modules' => $optionalModules,
            'selected_modules' => $selectedModules,
            'dependency_graph' => $this->moduleConfigService->getDependencyGraph(),
        ]);
    }

    /**
     * Step 4: Save Module Selection
     */
    #[Route('/step4-modules/save', name: 'setup_step4_modules_save', methods: ['POST'])]
    public function step4ModulesSave(Request $request, SessionInterface $session): Response
    {
        $selectedModules = $request->request->all('modules') ?? [];

        // Validate and resolve dependencies
        $validation = $this->moduleConfigService->validateModuleSelection($selectedModules);

        if (!$validation['valid']) {
            foreach ($validation['errors'] as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('setup_step4_modules');
        }

        // Resolve dependencies
        $resolved = $this->moduleConfigService->resolveModuleDependencies($selectedModules);

        // Save to session
        $session->set('setup_selected_modules', $resolved['modules']);

        // Show added modules
        foreach ($resolved['added'] as $addedModule) {
            $module = $this->moduleConfigService->getModule($addedModule);
            $this->addFlash('info', $this->translator->trans('deployment.info.module_added_auto', ['name' => $module['name']]));
        }

        foreach ($validation['warnings'] as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('setup_step5_base_data');
    }

    /**
     * Step 5: Base Data Import
     */
    #[Route('/step5-base-data', name: 'setup_step5_base_data')]
    public function step5BaseData(SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        if (empty($selectedModules)) {
            $this->addFlash('error', $this->translator->trans('deployment.error.select_modules'));
            return $this->redirectToRoute('setup_step4_modules');
        }

        $baseData = $this->moduleConfigService->getBaseData();

        return $this->render('setup/step5_base_data.html.twig', [
            'selected_modules' => $selectedModules,
            'base_data' => $baseData,
        ]);
    }

    /**
     * Step 5: Import Base Data
     */
    #[Route('/step5-base-data/import', name: 'setup_step5_base_data_import', methods: ['POST'])]
    public function step5BaseDataImport(SessionInterface $session): Response
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

        return $this->redirectToRoute('setup_step6_sample_data');
    }

    /**
     * Step 6: Sample Data (Optional)
     */
    #[Route('/step6-sample-data', name: 'setup_step6_sample_data')]
    public function step6SampleData(SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        if (empty($selectedModules)) {
            $this->addFlash('error', $this->translator->trans('deployment.error.select_modules'));
            return $this->redirectToRoute('setup_step4_modules');
        }

        $sampleData = $this->moduleConfigService->getAvailableSampleData($selectedModules);

        return $this->render('setup/step6_sample_data.html.twig', [
            'selected_modules' => $selectedModules,
            'sample_data' => $sampleData,
        ]);
    }

    /**
     * Step 6: Import Sample Data
     */
    #[Route('/step6-sample-data/import', name: 'setup_step6_sample_data_import', methods: ['POST'])]
    public function step6SampleDataImport(Request $request, SessionInterface $session): Response
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

        return $this->redirectToRoute('setup_step7_complete');
    }

    /**
     * Step 6: Skip Sample Data
     */
    #[Route('/step6-sample-data/skip', name: 'setup_step6_sample_data_skip', methods: ['POST'])]
    public function step6SampleDataSkip(): Response
    {
        $this->addFlash('info', $this->translator->trans('deployment.info.sample_data_skipped'));
        return $this->redirectToRoute('setup_step7_complete');
    }

    /**
     * Step 7: Setup Complete
     */
    #[Route('/step7-complete', name: 'setup_step7_complete')]
    public function step7Complete(SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        if (empty($selectedModules)) {
            $this->addFlash('error', $this->translator->trans('deployment.error.select_modules'));
            return $this->redirectToRoute('setup_step4_modules');
        }

        // Save active modules
        $this->moduleConfigService->saveActiveModules($selectedModules);

        // Mark setup as complete
        $this->setupChecker->markSetupComplete();

        $statistics = $this->moduleConfigService->getStatistics();

        return $this->render('setup/step7_complete.html.twig', [
            'selected_modules' => $selectedModules,
            'statistics' => $statistics,
            'admin_email' => $session->get('setup_admin_email'),
        ]);
    }

    /**
     * Reset Setup (Development Only)
     */
    #[Route('/reset', name: 'setup_wizard_reset')]
    public function reset(SessionInterface $session): Response
    {
        // Only allow in dev environment
        if ($this->getParameter('kernel.environment') !== 'dev') {
            throw $this->createAccessDeniedException('Only available in development environment');
        }

        // Reset setup
        $this->setupChecker->resetSetup();

        // Clear session
        $session->clear();

        $this->addFlash('success', $this->translator->trans('deployment.success.reset'));
        return $this->redirectToRoute('setup_wizard_index');
    }

    /**
     * Helper: Run database migrations
     *
     * @return array Result with 'success' and 'message'
     */
    private function runMigrationsInternal(): array
    {
        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $input = new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
                '--quiet' => true,
            ]);

            $output = new BufferedOutput();
            $exitCode = $application->run($input, $output);

            if ($exitCode === 0) {
                return [
                    'success' => true,
                    'message' => 'Database migrations executed successfully',
                ];
            }

            return [
                'success' => false,
                'message' => 'Migration failed: ' . $output->fetch(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Helper: Create admin user via console command
     *
     * @param array $data User data (email, firstName, lastName, password)
     * @return array Result with 'success' and 'message'
     */
    private function createAdminUserViaCommand(array $data): array
    {
        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $input = new ArrayInput([
                'command' => 'app:setup-permissions',
                '--admin-email' => $data['email'],
                '--admin-password' => $data['password'],
                '--admin-firstname' => $data['firstName'],
                '--admin-lastname' => $data['lastName'],
                '--no-interaction' => true,
            ]);

            $output = new BufferedOutput();
            $exitCode = $application->run($input, $output);

            if ($exitCode === 0) {
                return [
                    'success' => true,
                    'message' => 'Admin user created successfully',
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to create admin user: ' . $output->fetch(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}

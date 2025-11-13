<?php

namespace App\Controller;

use App\Form\AdminUserType;
use App\Form\ComplianceFrameworkSelectionType;
use App\Form\DatabaseConfigurationType;
use App\Form\EmailConfigurationType;
use App\Form\OrganisationInfoType;
use App\Security\SetupAccessChecker;
use App\Service\ComplianceFrameworkLoaderService;
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
        private readonly ComplianceFrameworkLoaderService $complianceLoader,
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
        // State recovery: Check if partially completed
        $state = $this->setupChecker->detectSetupState();

        if ($state['database_configured']) {
            $this->addFlash('info', $this->translator->trans('setup.state.recovery_detected'));

            // Redirect to appropriate step
            $nextStep = $this->setupChecker->getRecommendedNextStep();
            if ($nextStep !== 'setup_wizard_index') {
                return $this->redirectToRoute($nextStep);
            }
        }

        return $this->render('setup/step0_welcome.html.twig');
    }

    /**
     * Step 1: Database Configuration
     */
    #[Route('/step1-database-config', name: 'setup_step1_database_config')]
    public function step1DatabaseConfig(Request $request, SessionInterface $session): Response
    {
        // Pre-check: Filesystem permissions
        $permCheck = $this->envWriter->checkWritePermissions();
        if (!$permCheck['writable']) {
            $this->addFlash('error', $permCheck['message']);
        }

        $form = $this->createForm(DatabaseConfigurationType::class);
        $form->handleRequest($request);

        $testResult = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $config = $form->getData();

            // Test database connection
            $testResult = $this->dbTestService->testConnection($config);

            if ($testResult['success']) {
                // Check for existing tables (warn user)
                $existingTables = $this->dbTestService->checkExistingTables($config);
                if ($existingTables['has_tables']) {
                    $this->addFlash('warning', $this->translator->trans('setup.database.existing_tables', [
                        '%count%' => $existingTables['count'],
                        '%tables%' => implode(', ', array_slice($existingTables['tables'], 0, 5)),
                    ]));
                }

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
                } catch (\RuntimeException $e) {
                    // File system errors (permissions, disk full, etc.)
                    if (str_contains($e->getMessage(), 'Failed to write') || str_contains($e->getMessage(), 'Failed to rename')) {
                        $this->addFlash('error', $this->translator->trans('setup.database.write_failed',  [
                            '%error%' => $e->getMessage(),
                            '%hint%' => 'Please check file permissions for .env.local and ensure sufficient disk space.'
                        ]));
                    } else {
                        $this->addFlash('error', $this->translator->trans('setup.database.config_failed') . ': ' . $e->getMessage());
                    }
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

            // Normalize email to lowercase for consistent lookup
            $data['email'] = strtolower($data['email']);

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

                    return $this->redirectToRoute('setup_step5_requirements');
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
     * Step 3: Email Configuration (Optional)
     */
    #[Route('/step3-email-config', name: 'setup_step3_email_config')]
    public function step3EmailConfig(Request $request, SessionInterface $session): Response
    {
        // Check if admin user is created
        if (!$session->get('setup_admin_created')) {
            $this->addFlash('error', $this->translator->trans('setup.error.create_admin_first'));
            return $this->redirectToRoute('setup_step2_admin_user');
        }

        $form = $this->createForm(EmailConfigurationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            try {
                // Build MAILER_DSN
                $transport = $data['transport'] ?? 'smtp';

                if ($transport === 'smtp') {
                    $host = $data['host'] ?? 'localhost';
                    $port = $data['port'] ?? 587;
                    $username = $data['username'] ?? '';
                    $password = $data['password'] ?? '';
                    $encryption = $data['encryption'] ?? null;

                    $mailerDsn = sprintf(
                        'smtp://%s:%s@%s:%s',
                        urlencode($username),
                        urlencode($password),
                        $host,
                        $port
                    );

                    if ($encryption) {
                        $mailerDsn .= "?encryption={$encryption}";
                    }
                } elseif ($transport === 'sendmail') {
                    $mailerDsn = 'sendmail://default';
                } else {
                    $mailerDsn = 'native://default';
                }

                // Write to .env.local
                $envVars = ['MAILER_DSN' => $mailerDsn];

                if (!empty($data['from_address'])) {
                    $envVars['MAILER_FROM_ADDRESS'] = $data['from_address'];
                }

                if (!empty($data['from_name'])) {
                    $envVars['MAILER_FROM_NAME'] = $data['from_name'];
                }

                $this->envWriter->writeEnvVariables($envVars);

                $session->set('setup_email_configured', true);
                $this->addFlash('success', $this->translator->trans('setup.email.config_saved'));

                return $this->redirectToRoute('setup_step4_organisation_info');
            } catch (\Exception $e) {
                $this->addFlash('error', $this->translator->trans('setup.email.config_failed') . ': ' . $e->getMessage());
            }
        }

        return $this->render('setup/step3_email_config.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Step 3: Skip Email Configuration
     */
    #[Route('/step3-email-config/skip', name: 'setup_step3_email_config_skip', methods: ['POST'])]
    public function step3EmailConfigSkip(SessionInterface $session): Response
    {
        $session->set('setup_email_configured', false);
        $this->addFlash('info', $this->translator->trans('setup.email.skipped'));

        return $this->redirectToRoute('setup_step4_organisation_info');
    }

    /**
     * Step 4: Organisation Information
     */
    #[Route('/step4-organisation-info', name: 'setup_step4_organisation_info')]
    public function step4OrganisationInfo(Request $request, SessionInterface $session): Response
    {
        // User can skip email config, so we don't check for it
        // But admin must be created
        if (!$session->get('setup_admin_created')) {
            $this->addFlash('error', $this->translator->trans('setup.error.create_admin_first'));
            return $this->redirectToRoute('setup_step2_admin_user');
        }

        $form = $this->createForm(OrganisationInfoType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            try {
                // Store in session for later use (will be used during base data import)
                $session->set('setup_organisation_name', $data['name']);
                $session->set('setup_organisation_industry', $data['industry']);
                $session->set('setup_organisation_employee_count', $data['employee_count']);
                $session->set('setup_organisation_country', $data['country']);
                $session->set('setup_organisation_description', $data['description'] ?? '');

                $this->addFlash('success', $this->translator->trans('setup.organisation.info_saved'));

                return $this->redirectToRoute('setup_step5_requirements');
            } catch (\Exception $e) {
                $this->addFlash('error', $this->translator->trans('setup.organisation.info_failed') . ': ' . $e->getMessage());
            }
        }

        return $this->render('setup/step4_organisation_info.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Step 5: System Requirements Check
     */
    #[Route('/step5-requirements', name: 'setup_step5_requirements')]
    public function step5Requirements(SessionInterface $session): Response
    {
        // Check if admin user is created
        if (!$session->get('setup_admin_created')) {
            $this->addFlash('error', $this->translator->trans('setup.error.create_admin_first'));
            return $this->redirectToRoute('setup_step2_admin_user');
        }

        $results = $this->requirementsChecker->checkAll();

        return $this->render('setup/step5_requirements.html.twig', [
            'results' => $results,
            'can_proceed' => $results['overall']['can_proceed'],
        ]);
    }

    /**
     * Step 6: Module Selection
     */
    #[Route('/step6-modules', name: 'setup_step6_modules')]
    public function step6Modules(SessionInterface $session): Response
    {
        // Check if requirements passed
        if (!$this->requirementsChecker->isSystemReady()) {
            $this->addFlash('error', $this->translator->trans('deployment.error.fix_requirements'));
            return $this->redirectToRoute('setup_step5_requirements');
        }

        $allModules = $this->moduleConfigService->getAllModules();
        $requiredModules = array_keys($this->moduleConfigService->getRequiredModules());
        $optionalModules = $this->moduleConfigService->getOptionalModules();

        // Load previous selection from session
        $selectedModules = $session->get('setup_selected_modules', $requiredModules);

        return $this->render('setup/step6_modules.html.twig', [
            'all_modules' => $allModules,
            'required_modules' => $requiredModules,
            'optional_modules' => $optionalModules,
            'selected_modules' => $selectedModules,
            'dependency_graph' => $this->moduleConfigService->getDependencyGraph(),
        ]);
    }

    /**
     * Step 6: Save Module Selection
     */
    #[Route('/step6-modules/save', name: 'setup_step6_modules_save', methods: ['POST'])]
    public function step6ModulesSave(Request $request, SessionInterface $session): Response
    {
        $selectedModules = $request->request->all('modules') ?? [];

        // Validate and resolve dependencies
        $validation = $this->moduleConfigService->validateModuleSelection($selectedModules);

        if (!$validation['valid']) {
            foreach ($validation['errors'] as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('setup_step6_modules');
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

        return $this->redirectToRoute('setup_step7_compliance_frameworks');
    }

    /**
     * Step 7: Compliance Frameworks Selection
     */
    #[Route('/step7-compliance-frameworks', name: 'setup_step7_compliance_frameworks')]
    public function step7ComplianceFrameworks(Request $request, SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        if (empty($selectedModules)) {
            $this->addFlash('error', $this->translator->trans('deployment.error.select_modules'));
            return $this->redirectToRoute('setup_step6_modules');
        }

        // Get available frameworks
        $availableFrameworks = $this->complianceLoader->getAvailableFrameworks();

        // Get context for recommendations
        $organisationIndustry = $session->get('setup_organisation_industry', 'other');
        $employeeCount = $session->get('setup_organisation_employee_count', '1-10');
        $country = $session->get('setup_organisation_country', 'DE');

        // Get industry-specific recommendations
        $recommendedFrameworks = $this->getRecommendedFrameworks($organisationIndustry, $employeeCount, $country);

        // Pre-select recommended frameworks (including ISO27001 which is mandatory)
        $form = $this->createForm(ComplianceFrameworkSelectionType::class, [
            'frameworks' => $recommendedFrameworks,
        ], [
            'available_frameworks' => $availableFrameworks,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $selectedFrameworks = $data['frameworks'] ?? ['ISO27001'];

            // Ensure ISO27001 is always selected
            if (!in_array('ISO27001', $selectedFrameworks, true)) {
                $selectedFrameworks[] = 'ISO27001';
            }

            // Store selection in session
            $session->set('setup_selected_frameworks', $selectedFrameworks);

            $this->addFlash('success', $this->translator->trans('setup.compliance.frameworks_saved', [
                '%count%' => count($selectedFrameworks),
            ]));

            return $this->redirectToRoute('setup_step8_base_data');
        }

        return $this->render('setup/step7_compliance_frameworks.html.twig', [
            'form' => $form,
            'available_frameworks' => $availableFrameworks,
            'recommended_frameworks' => $recommendedFrameworks,
        ]);
    }

    /**
     * Get recommended compliance frameworks based on industry, size, and location
     *
     * @param string $industry Organisation industry
     * @param string $employeeCount Employee count range (1-10, 11-50, 51-250, 251-1000, 1001+)
     * @param string $country Country code (DE, AT, CH, etc.)
     * @return array List of recommended framework codes
     */
    private function getRecommendedFrameworks(string $industry, string $employeeCount, string $country): array
    {
        $recommendations = ['ISO27001']; // Always recommend ISO 27001

        // Determine if company is large enough for NIS2 (typically 50+ employees)
        $isNis2Size = in_array($employeeCount, ['51-250', '251-1000', '1001+'], true);

        // Use ISO 27701 for DACH region (covers GDPR), otherwise recommend GDPR
        $privacyFramework = in_array($country, ['DE', 'AT', 'CH'], true) ? 'ISO27701' : 'GDPR';

        // Industry-specific recommendations
        switch ($industry) {
            case 'automotive':
                $recommendations[] = 'TISAX';
                $recommendations[] = $privacyFramework;
                break;

            case 'financial_services':
                $recommendations[] = 'DORA';
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'healthcare':
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'energy':
            case 'telecommunications':
                // Critical infrastructure - NIS2 often applies regardless of size
                $recommendations[] = 'NIS2';
                $recommendations[] = $privacyFramework;
                break;

            case 'public_sector':
                $recommendations[] = 'BSI_GRUNDSCHUTZ';
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'it_services':
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'manufacturing':
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            default:
                // Default recommendation for other industries
                $recommendations[] = $privacyFramework;
                break;
        }

        return array_unique($recommendations);
    }

    /**
     * Step 8: Base Data Import
     */
    #[Route('/step8-base-data', name: 'setup_step8_base_data')]
    public function step8BaseData(SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        if (empty($selectedModules)) {
            $this->addFlash('error', $this->translator->trans('deployment.error.select_modules'));
            return $this->redirectToRoute('setup_step6_modules');
        }

        $baseData = $this->moduleConfigService->getBaseData();

        return $this->render('setup/step8_base_data.html.twig', [
            'selected_modules' => $selectedModules,
            'base_data' => $baseData,
        ]);
    }

    /**
     * Step 8: Import Base Data
     */
    #[Route('/step8-base-data/import', name: 'setup_step8_base_data_import', methods: ['POST'])]
    public function step8BaseDataImport(SessionInterface $session): Response
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

        return $this->redirectToRoute('setup_step9_sample_data');
    }

    /**
     * Step 9: Sample Data (Optional)
     */
    #[Route('/step9-sample-data', name: 'setup_step9_sample_data')]
    public function step9SampleData(SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        if (empty($selectedModules)) {
            $this->addFlash('error', $this->translator->trans('deployment.error.select_modules'));
            return $this->redirectToRoute('setup_step6_modules');
        }

        $sampleData = $this->moduleConfigService->getAvailableSampleData($selectedModules);

        return $this->render('setup/step9_sample_data.html.twig', [
            'selected_modules' => $selectedModules,
            'sample_data' => $sampleData,
        ]);
    }

    /**
     * Step 9: Import Sample Data
     */
    #[Route('/step9-sample-data/import', name: 'setup_step9_sample_data_import', methods: ['POST'])]
    public function step9SampleDataImport(Request $request, SessionInterface $session): Response
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

        return $this->redirectToRoute('setup_step10_complete');
    }

    /**
     * Step 9: Skip Sample Data
     */
    #[Route('/step9-sample-data/skip', name: 'setup_step9_sample_data_skip', methods: ['POST'])]
    public function step9SampleDataSkip(): Response
    {
        $this->addFlash('info', $this->translator->trans('deployment.info.sample_data_skipped'));
        return $this->redirectToRoute('setup_step10_complete');
    }

    /**
     * Step 10: Setup Complete
     */
    #[Route('/step10-complete', name: 'setup_step10_complete')]
    public function step10Complete(SessionInterface $session): Response
    {
        $selectedModules = $session->get('setup_selected_modules', []);

        if (empty($selectedModules)) {
            $this->addFlash('error', $this->translator->trans('deployment.error.select_modules'));
            return $this->redirectToRoute('setup_step6_modules');
        }

        // Save active modules
        $this->moduleConfigService->saveActiveModules($selectedModules);

        // Mark setup as complete
        $this->setupChecker->markSetupComplete();

        $statistics = $this->moduleConfigService->getStatistics();

        return $this->render('setup/step10_complete.html.twig', [
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

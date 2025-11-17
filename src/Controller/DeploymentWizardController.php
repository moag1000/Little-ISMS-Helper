<?php

namespace App\Controller;

use App\Entity\Tenant;
use App\Form\AdminUserType;
use App\Form\ComplianceFrameworkSelectionType;
use App\Form\DatabaseConfigurationType;
use App\Form\EmailConfigurationType;
use App\Form\OrganisationInfoType;
use App\Repository\AssetRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\TenantRepository;
use App\Security\SetupAccessChecker;
use App\Service\BackupService;
use App\Service\ComplianceFrameworkLoaderService;
use App\Service\DatabaseTestService;
use App\Service\DataImportService;
use App\Service\EnvironmentWriter;
use App\Service\ModuleConfigurationService;
use App\Service\RestoreService;
use App\Service\SystemRequirementsChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Log\LoggerInterface;

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
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
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

        // Auto-detect Docker standalone deployment and pre-fill credentials
        $defaultData = [];
        // Use @ to suppress open_basedir warnings on non-Docker servers
        $isDockerStandalone = @file_exists('/run/mysqld/mysqld.sock') || @file_exists('/.dockerenv');

        if ($isDockerStandalone) {
            // Pre-fill with Docker internal MySQL configuration
            $defaultData = [
                'type' => 'mysql',
                'host' => 'localhost',
                'port' => 3306,
                'name' => $_ENV['MYSQL_DATABASE'] ?? 'isms',
                'user' => $_ENV['MYSQL_USER'] ?? 'isms',
                'password' => $_ENV['MYSQL_PASSWORD'] ?? $this->getDockerMysqlPassword(),
                'serverVersion' => 'mariadb-11.4.0',
            ];

            $this->addFlash('info', $this->translator->trans('setup.database.docker_detected'));
        }

        $form = $this->createForm(DatabaseConfigurationType::class, $defaultData);
        $form->handleRequest($request);

        // Retrieve test result from session (for displaying after redirect)
        $testResult = $session->get('setup_db_test_result');
        $session->remove('setup_db_test_result');

        if ($form->isSubmitted() && $form->isValid()) {
            $config = $form->getData();

            // For Docker standalone: If password is empty, use the auto-generated one
            if ($isDockerStandalone && empty($config['password'])) {
                $config['password'] = $this->getDockerMysqlPassword();
            }

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
                            $session->set('setup_db_test_result', $testResult);
                            return $this->redirectToRoute('setup_step1_database_config');
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
                    // Redirect to same page to show error (Turbo compatibility)
                    return $this->redirectToRoute('setup_step1_database_config');
                } catch (\Exception $e) {
                    $this->addFlash('error', $this->translator->trans('setup.database.config_failed') . ': ' . $e->getMessage());
                    // Redirect to same page to show error (Turbo compatibility)
                    return $this->redirectToRoute('setup_step1_database_config');
                }
            } else {
                // Test failed - store result in session and redirect (Turbo compatibility)
                $session->set('setup_db_test_result', $testResult);
                $this->addFlash('error', $testResult['message'] ?? $this->translator->trans('setup.database.test_failed'));
                return $this->redirectToRoute('setup_step1_database_config');
            }
        }

        // Return 422 status for validation errors so Turbo displays errors
        $response = $this->render('setup/step1_database_config.html.twig', [
            'form' => $form,
            'test_result' => $testResult,
        ]);

        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(422);
        }

        return $response;
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

        // Debug info as flash messages (temporary for debugging)
        $this->addFlash('info', 'DEBUG: Form submitted: ' . ($form->isSubmitted() ? 'YES' : 'NO'));
        if ($form->isSubmitted()) {
            $this->addFlash('info', 'DEBUG: Form valid: ' . ($form->isValid() ? 'YES' : 'NO'));
            if (!$form->isValid()) {
                $errors = [];
                foreach ($form->getErrors(true) as $error) {
                    $errors[] = $error->getMessage();
                }
                $this->addFlash('warning', 'DEBUG: Form errors: ' . implode(', ', $errors));
            }
        }

        // Store debug info in session to survive redirects
        if ($form->isSubmitted()) {
            $session->set('debug_form_submitted', true);
            $session->set('debug_form_valid', $form->isValid());
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $session->set('debug_processing', 'Entering form processing block');
            $this->addFlash('info', 'DEBUG: Entering form processing block');
            $data = $form->getData();

            // Normalize email to lowercase for consistent lookup
            $data['email'] = strtolower($data['email']);

            try {
                $this->addFlash('info', 'DEBUG: Starting admin user creation process');
                // Check if database already has tables (from previous failed setup attempt)
                // If so, drop the database and recreate it to ensure clean state
                $dbConfig = [
                    'type' => $session->get('setup_db_type', 'mysql'),
                    'host' => $session->get('setup_db_host', 'localhost'),
                    'port' => $session->get('setup_db_port', 3306),
                    'name' => $session->get('setup_db_name', 'little_isms_helper'),
                    'user' => $session->get('setup_db_user', 'root'),
                    'password' => $session->get('setup_db_password', ''),
                    'unixSocket' => $session->get('setup_db_socket'),
                ];

                $tableCheck = $this->databaseTestService->checkExistingTables($dbConfig);
                if ($tableCheck['has_tables'] && $tableCheck['count'] > 0) {
                    // Database has tables - drop and recreate for clean setup
                    $this->dropAndRecreateDatabase($dbConfig);
                }

                // First run migrations to create database structure
                $migrationResult = $this->runMigrationsInternal();

                if (!$migrationResult['success']) {
                    $this->addFlash('error', 'DEBUG: Migration failed - ' . $migrationResult['message']);
                    $this->addFlash('error', $this->translator->trans('setup.admin.migration_failed') . ': ' . $migrationResult['message']);
                    // Turbo requires redirect after POST
                    return $this->redirectToRoute('setup_step2_admin_user');
                }

                $this->addFlash('info', 'DEBUG: Migration successful, creating admin user');
                // Create admin user via command
                $result = $this->createAdminUserViaCommand($data);

                if ($result['success']) {
                    $this->addFlash('success', 'DEBUG: Admin user created successfully');
                    $session->set('setup_admin_created', true);
                    $session->set('setup_admin_email', $data['email']);

                    $this->addFlash('success', $this->translator->trans('setup.admin.user_created'));

                    return $this->redirectToRoute('setup_step3_email_config');
                } else {
                    $this->addFlash('error', 'DEBUG: Admin user creation failed - ' . $result['message']);
                    $this->addFlash('error', $result['message']);
                    // Turbo requires redirect after POST
                    return $this->redirectToRoute('setup_step2_admin_user');
                }
            } catch (\Exception $e) {
                $this->addFlash('error', 'DEBUG: Exception - ' . $e->getMessage());
                $this->addFlash('error', $this->translator->trans('setup.admin.creation_failed') . ': ' . $e->getMessage());
                // Turbo requires redirect after POST
                return $this->redirectToRoute('setup_step2_admin_user');
            }
        } else {
            $this->addFlash('info', 'DEBUG: Form not submitted or not valid - rendering form');
        }

        // For validation errors, return 422 status so Turbo displays the form with errors
        $response = $this->render('setup/step2_admin_user.html.twig', [
            'form' => $form,
        ]);

        // If form was submitted but invalid, set 422 status
        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(422);
        }

        return $response;
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

        // Return 422 status for validation errors so Turbo displays errors
        $response = $this->render('setup/step3_email_config.html.twig', [
            'form' => $form,
        ]);

        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(422);
        }

        return $response;
    }

    /**
     * Step 3: Skip Email Configuration
     */
    #[Route('/step3-email-config/skip', name: 'setup_step3_email_config_skip', methods: ['POST'])]
    public function step3EmailConfigSkip(Request $request, SessionInterface $session): Response
    {
        // Validate CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('setup_email_skip', $token)) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('setup_step3_email_config');
        }

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
                // Support multiple industries (for corporate structures)
                $industries = $data['industries'] ?? [];
                $session->set('setup_organisation_industries', $industries);
                // Keep backward compatibility - use first industry as primary
                $session->set('setup_organisation_industry', $industries[0] ?? 'other');
                $session->set('setup_organisation_employee_count', $data['employee_count']);
                $session->set('setup_organisation_country', $data['country']);
                $session->set('setup_organisation_description', $data['description'] ?? '');

                $this->addFlash('success', $this->translator->trans('setup.organisation.info_saved'));

                return $this->redirectToRoute('setup_step5_requirements');
            } catch (\Exception $e) {
                $this->addFlash('error', $this->translator->trans('setup.organisation.info_failed') . ': ' . $e->getMessage());
            }
        }

        // Return 422 status for validation errors so Turbo displays errors
        $response = $this->render('setup/step4_organisation_info.html.twig', [
            'form' => $form,
        ]);

        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(422);
        }

        return $response;
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

        // Get organization context for recommendations
        $organisationIndustries = $session->get('setup_organisation_industries', ['other']);
        $employeeCount = $session->get('setup_organisation_employee_count', '1-10');

        // Get recommended modules based on organization data (supports multiple industries)
        $recommendedModules = $this->getRecommendedModulesForIndustries($organisationIndustries, $employeeCount);

        // Load previous selection from session, default to required + recommended
        if (!$session->has('setup_selected_modules')) {
            $selectedModules = array_unique(array_merge($requiredModules, $recommendedModules));
        } else {
            $selectedModules = $session->get('setup_selected_modules');
        }

        return $this->render('setup/step6_modules.html.twig', [
            'all_modules' => $allModules,
            'required_modules' => $requiredModules,
            'optional_modules' => $optionalModules,
            'selected_modules' => $selectedModules,
            'recommended_modules' => $recommendedModules,
            'dependency_graph' => $this->moduleConfigService->getDependencyGraph(),
        ]);
    }

    /**
     * Step 6: Save Module Selection
     */
    #[Route('/step6-modules/save', name: 'setup_step6_modules_save', methods: ['POST'])]
    public function step6ModulesSave(Request $request, SessionInterface $session): Response
    {
        // Validate CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('setup_modules', $token)) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('setup_step6_modules');
        }

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
        $organisationIndustries = $session->get('setup_organisation_industries', ['other']);
        $employeeCount = $session->get('setup_organisation_employee_count', '1-10');
        $country = $session->get('setup_organisation_country', 'DE');

        // Get industry-specific recommendations (supports multiple industries)
        $recommendedFrameworks = $this->getRecommendedFrameworksForIndustries($organisationIndustries, $employeeCount, $country);

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

        // Return 422 status for validation errors so Turbo displays errors
        $response = $this->render('setup/step7_compliance_frameworks.html.twig', [
            'form' => $form,
            'available_frameworks' => $availableFrameworks,
            'recommended_frameworks' => $recommendedFrameworks,
        ]);

        if ($form->isSubmitted() && !$form->isValid()) {
            $response->setStatusCode(422);
        }

        return $response;
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

        // Determine company size thresholds
        $isNis2Size = in_array($employeeCount, ['51-250', '251-1000', '1001+'], true);
        $isLargeOrg = in_array($employeeCount, ['251-1000', '1001+'], true);

        // Determine country/region specific frameworks
        $isDACH = in_array($country, ['DE', 'AT', 'CH'], true);
        $isGermany = $country === 'DE';
        $isEU = in_array($country, ['DE', 'AT', 'BE', 'DK', 'FI', 'FR', 'IT', 'LU', 'NL', 'PL', 'ES', 'SE', 'CZ', 'EU_OTHER'], true);

        // Use ISO 27701 for DACH region (covers GDPR), otherwise recommend GDPR
        $privacyFramework = $isDACH ? 'ISO27701' : 'GDPR';

        // Industry-specific recommendations
        switch ($industry) {
            case 'automotive':
                $recommendations[] = 'TISAX';
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
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
                if ($isGermany) {
                    $recommendations[] = 'KRITIS-HEALTH';
                }
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'pharmaceutical':
                $recommendations[] = 'GXP';
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'digital_health':
                if ($isGermany) {
                    $recommendations[] = 'DIGAV';
                }
                $recommendations[] = $privacyFramework;
                break;

            case 'energy':
                // Critical infrastructure - NIS2 often applies regardless of size
                $recommendations[] = 'NIS2';
                if ($isGermany) {
                    $recommendations[] = 'KRITIS';
                }
                $recommendations[] = $privacyFramework;
                break;

            case 'telecommunications':
                // Critical infrastructure - NIS2 often applies regardless of size
                $recommendations[] = 'NIS2';
                if ($isGermany) {
                    $recommendations[] = 'TKG-2024';
                    $recommendations[] = 'KRITIS';
                }
                $recommendations[] = $privacyFramework;
                break;

            case 'cloud_services':
                if ($isGermany) {
                    $recommendations[] = 'BSI-C5';
                }
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'public_sector':
                if ($isGermany) {
                    $recommendations[] = 'BSI_GRUNDSCHUTZ';
                }
                $recommendations[] = $privacyFramework;
                if ($isNis2Size) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'critical_infrastructure':
                $recommendations[] = 'NIS2';
                if ($isGermany) {
                    $recommendations[] = 'KRITIS';
                    $recommendations[] = 'BSI_GRUNDSCHUTZ';
                }
                $recommendations[] = $privacyFramework;
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

            case 'retail':
                $recommendations[] = $privacyFramework;
                if ($isLargeOrg) {
                    $recommendations[] = 'NIS2';
                }
                break;

            case 'education':
                $recommendations[] = $privacyFramework;
                if ($isGermany) {
                    $recommendations[] = 'BSI_GRUNDSCHUTZ';
                }
                break;

            default:
                // Default recommendation for other industries
                $recommendations[] = $privacyFramework;
                if ($isNis2Size && $isEU) {
                    $recommendations[] = 'NIS2';
                }
                break;
        }

        return array_unique($recommendations);
    }

    /**
     * Get recommended modules based on industry and company size
     *
     * @param string $industry Organisation industry
     * @param string $employeeCount Employee count range (1-10, 11-50, 51-250, 251-1000, 1001+)
     * @return array List of recommended module keys
     */
    private function getRecommendedModules(string $industry, string $employeeCount): array
    {
        $recommendations = [];

        // Larger organizations typically need more structure
        $isLargeOrg = in_array($employeeCount, ['251-1000', '1001+'], true);
        $isMediumOrg = in_array($employeeCount, ['51-250', '251-1000', '1001+'], true);

        // Core modules recommended for all
        $recommendations[] = 'asset_management';
        $recommendations[] = 'risk_management';
        $recommendations[] = 'controls';

        // Industry-specific recommendations
        switch ($industry) {
            case 'automotive':
                // TISAX requires strong asset and risk management
                $recommendations[] = 'compliance';
                $recommendations[] = 'document_management';
                if ($isMediumOrg) {
                    $recommendations[] = 'training';
                }
                break;

            case 'financial_services':
                // DORA requires BCM and incident management
                $recommendations[] = 'bcm';
                $recommendations[] = 'incident_management';
                $recommendations[] = 'compliance';
                $recommendations[] = 'audit_management';
                break;

            case 'healthcare':
                // Healthcare needs incident tracking and compliance
                $recommendations[] = 'incident_management';
                $recommendations[] = 'compliance';
                $recommendations[] = 'training';
                if ($isMediumOrg) {
                    $recommendations[] = 'bcm';
                }
                break;

            case 'pharmaceutical':
                // GxP requires strong training and audit
                $recommendations[] = 'compliance';
                $recommendations[] = 'audit_management';
                $recommendations[] = 'training';
                $recommendations[] = 'document_management';
                break;

            case 'digital_health':
                // DiGA needs compliance and audit trail
                $recommendations[] = 'compliance';
                $recommendations[] = 'audit_management';
                break;

            case 'energy':
            case 'telecommunications':
            case 'critical_infrastructure':
                // Critical infrastructure needs BCM and incident management
                $recommendations[] = 'bcm';
                $recommendations[] = 'incident_management';
                $recommendations[] = 'compliance';
                $recommendations[] = 'audit_management';
                break;

            case 'cloud_services':
                // Cloud services need strong controls and audit
                $recommendations[] = 'compliance';
                $recommendations[] = 'audit_management';
                if ($isMediumOrg) {
                    $recommendations[] = 'bcm';
                }
                break;

            case 'public_sector':
                // Public sector has strict audit requirements
                $recommendations[] = 'audit_management';
                $recommendations[] = 'compliance';
                $recommendations[] = 'document_management';
                break;

            case 'it_services':
                // IT services need incident management
                $recommendations[] = 'incident_management';
                if ($isMediumOrg) {
                    $recommendations[] = 'bcm';
                }
                break;

            case 'manufacturing':
                // Manufacturing needs BCM
                $recommendations[] = 'bcm';
                if ($isMediumOrg) {
                    $recommendations[] = 'incident_management';
                }
                break;

            default:
                // Basic recommendations for other industries
                if ($isMediumOrg) {
                    $recommendations[] = 'incident_management';
                }
                break;
        }

        // Large organizations benefit from training and audit
        if ($isLargeOrg) {
            $recommendations[] = 'training';
            $recommendations[] = 'audit_management';
        }

        return array_unique($recommendations);
    }

    /**
     * Get recommended compliance frameworks for multiple industries (corporate structures)
     *
     * @param array $industries List of industry codes
     * @param string $employeeCount Employee count range
     * @param string $country Country code
     * @return array Aggregated list of recommended framework codes
     */
    private function getRecommendedFrameworksForIndustries(array $industries, string $employeeCount, string $country): array
    {
        $allRecommendations = [];

        foreach ($industries as $industry) {
            $industryRecommendations = $this->getRecommendedFrameworks($industry, $employeeCount, $country);
            $allRecommendations = array_merge($allRecommendations, $industryRecommendations);
        }

        return array_unique($allRecommendations);
    }

    /**
     * Get recommended modules for multiple industries (corporate structures)
     *
     * @param array $industries List of industry codes
     * @param string $employeeCount Employee count range
     * @return array Aggregated list of recommended module keys
     */
    private function getRecommendedModulesForIndustries(array $industries, string $employeeCount): array
    {
        $allRecommendations = [];

        foreach ($industries as $industry) {
            $industryRecommendations = $this->getRecommendedModules($industry, $employeeCount);
            $allRecommendations = array_merge($allRecommendations, $industryRecommendations);
        }

        return array_unique($allRecommendations);
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
    public function step8BaseDataImport(Request $request, SessionInterface $session): Response
    {
        // Validate CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('setup_base_data', $token)) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('setup_step8_base_data');
        }

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

        // Check for backup restore result from session (set after POST redirect)
        $backupRestoreResult = $session->get('backup_restore_result');
        if ($backupRestoreResult !== null) {
            // Add tenant list for orphan repair UI
            if (isset($backupRestoreResult['orphaned_entities']) && $backupRestoreResult['orphaned_entities']['total'] > 0) {
                $backupRestoreResult['tenants'] = $this->tenantRepository->findAll();
            }
            // Remove from session after reading (one-time display)
            $session->remove('backup_restore_result');
        }

        return $this->render('setup/step9_sample_data.html.twig', [
            'selected_modules' => $selectedModules,
            'sample_data' => $sampleData,
            'backup_restore_result' => $backupRestoreResult,
        ]);
    }

    /**
     * Step 9: Import Sample Data
     */
    #[Route('/step9-sample-data/import', name: 'setup_step9_sample_data_import', methods: ['POST'])]
    public function step9SampleDataImport(Request $request, SessionInterface $session): Response
    {
        // Validate CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('setup_sample_data', $token)) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('setup_step9_sample_data');
        }

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
    public function step9SampleDataSkip(Request $request): Response
    {
        // Validate CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('setup_sample_skip', $token)) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('setup_step9_sample_data');
        }

        $this->addFlash('info', $this->translator->trans('deployment.info.sample_data_skipped'));
        return $this->redirectToRoute('setup_step10_complete');
    }

    /**
     * Step 9: Restore Backup
     */
    #[Route('/step9-restore-backup', name: 'setup_step9_restore_backup', methods: ['POST'])]
    public function step9RestoreBackup(
        Request $request,
        SessionInterface $session,
        BackupService $backupService,
        RestoreService $restoreService,
        LoggerInterface $logger
    ): Response {
        $selectedModules = $session->get('setup_selected_modules', []);
        $sampleData = $this->moduleConfigService->getAvailableSampleData($selectedModules);

        // Validate CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('setup_restore_backup', $token)) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->render('setup/step9_sample_data.html.twig', [
                'selected_modules' => $selectedModules,
                'sample_data' => $sampleData,
                'backup_restore_result' => [
                    'success' => false,
                    'message' => $this->translator->trans('common.csrf_error'),
                ],
            ]);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('backup_file');

        if (!$file) {
            $this->addFlash('error', 'Keine Backup-Datei hochgeladen');
            return $this->render('setup/step9_sample_data.html.twig', [
                'selected_modules' => $selectedModules,
                'sample_data' => $sampleData,
                'backup_restore_result' => [
                    'success' => false,
                    'message' => 'Keine Backup-Datei hochgeladen',
                ],
            ]);
        }

        // Validate file extension
        $extension = $file->getClientOriginalExtension();
        if (!in_array($extension, ['json', 'gz'])) {
            $this->addFlash('error', 'Ungültiges Dateiformat. Nur .json oder .gz Dateien sind erlaubt.');
            return $this->render('setup/step9_sample_data.html.twig', [
                'selected_modules' => $selectedModules,
                'sample_data' => $sampleData,
                'backup_restore_result' => [
                    'success' => false,
                    'message' => 'Ungültiges Dateiformat',
                ],
            ]);
        }

        try {
            // Save file temporarily
            $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $filename = 'setup_restore_' . date('Y-m-d_H-i-s') . '.' . $extension;
            $filepath = $backupDir . '/' . $filename;
            $file->move($backupDir, $filename);

            $logger->info('Backup file uploaded for setup restore', [
                'filename' => $filename,
            ]);

            // Load and restore backup
            $backup = $backupService->loadBackupFromFile($filepath);

            $options = [
                'missing_field_strategy' => RestoreService::STRATEGY_USE_DEFAULT,
                'existing_data_strategy' => RestoreService::EXISTING_UPDATE,
                'dry_run' => false,
                'clear_before_restore' => $request->request->getBoolean('clear_before_restore', true),
                'admin_password' => $request->request->get('admin_password', ''),
            ];

            $result = $restoreService->restoreFromBackup($backup, $options);

            $logger->info('Backup restored during setup', [
                'statistics' => $result['statistics'],
                'warnings' => count($result['warnings']),
            ]);

            // Check for orphaned entities after restore
            $orphanedAssets = $this->assetRepository->createQueryBuilder('a')
                ->where('a.tenant IS NULL')
                ->getQuery()
                ->getResult();

            $orphanedRisks = $this->riskRepository->createQueryBuilder('r')
                ->where('r.tenant IS NULL')
                ->getQuery()
                ->getResult();

            $orphanedIncidents = $this->incidentRepository->createQueryBuilder('i')
                ->where('i.tenant IS NULL')
                ->getQuery()
                ->getResult();

            // Check for risks without asset assignment
            $risksWithoutAssets = $this->riskRepository->createQueryBuilder('r')
                ->where('r.asset IS NULL')
                ->getQuery()
                ->getResult();

            $hasOrphanedEntities = count($orphanedAssets) > 0 || count($orphanedRisks) > 0 || count($orphanedIncidents) > 0;

            if ($hasOrphanedEntities) {
                $this->addFlash('warning', sprintf(
                    'Backup wiederhergestellt, aber %d verwaiste Entitäten gefunden (Assets: %d, Risiken: %d, Vorfälle: %d). Bitte reparieren Sie diese.',
                    count($orphanedAssets) + count($orphanedRisks) + count($orphanedIncidents),
                    count($orphanedAssets),
                    count($orphanedRisks),
                    count($orphanedIncidents)
                ));
            } elseif (count($risksWithoutAssets) > 0) {
                $this->addFlash('info', sprintf(
                    'Backup wiederhergestellt! %d Risiken haben keine Asset-Zuordnung. Sie können diese im Admin Data Repair Tool korrigieren.',
                    count($risksWithoutAssets)
                ));
            } else {
                $this->addFlash('success', 'Backup erfolgreich wiederhergestellt! Sie können jetzt das Setup abschließen.');
            }

            // Store result in session for display after redirect (Turbo compatibility)
            $session->set('backup_restore_result', [
                'success' => true,
                'statistics' => $result['statistics'],
                'warnings' => $result['warnings'],
                'orphaned_entities' => [
                    'assets' => count($orphanedAssets),
                    'risks' => count($orphanedRisks),
                    'incidents' => count($orphanedIncidents),
                    'total' => count($orphanedAssets) + count($orphanedRisks) + count($orphanedIncidents),
                ],
                'risks_without_assets' => count($risksWithoutAssets),
            ]);

            return $this->redirectToRoute('setup_step9_sample_data');
        } catch (\Exception $e) {
            $logger->error('Backup restore failed during setup', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addFlash('error', 'Fehler bei der Wiederherstellung: ' . $e->getMessage());

            $session->set('backup_restore_result', [
                'success' => false,
                'message' => $e->getMessage(),
            ]);

            return $this->redirectToRoute('setup_step9_sample_data');
        }
    }

    /**
     * Step 9: Repair Orphaned Entities
     */
    #[Route('/step9-repair-orphans', name: 'setup_step9_repair_orphans', methods: ['POST'])]
    public function step9RepairOrphans(
        Request $request,
        SessionInterface $session,
        LoggerInterface $logger
    ): Response {
        $selectedModules = $session->get('setup_selected_modules', []);
        $sampleData = $this->moduleConfigService->getAvailableSampleData($selectedModules);

        // Validate CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('setup_repair_orphans', $token)) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('setup_step9_sample_data');
        }

        $targetTenantId = $request->request->get('target_tenant_id');
        if (!$targetTenantId) {
            $this->addFlash('error', 'Bitte wählen Sie einen Ziel-Mandanten aus.');
            return $this->redirectToRoute('setup_step9_sample_data');
        }

        $targetTenant = $this->tenantRepository->find($targetTenantId);
        if (!$targetTenant) {
            $this->addFlash('error', 'Ziel-Mandant nicht gefunden.');
            return $this->redirectToRoute('setup_step9_sample_data');
        }

        try {
            // Repair orphaned Assets
            $orphanedAssets = $this->assetRepository->createQueryBuilder('a')
                ->where('a.tenant IS NULL')
                ->getQuery()
                ->getResult();

            foreach ($orphanedAssets as $asset) {
                $asset->setTenant($targetTenant);
            }

            // Repair orphaned Risks
            $orphanedRisks = $this->riskRepository->createQueryBuilder('r')
                ->where('r.tenant IS NULL')
                ->getQuery()
                ->getResult();

            foreach ($orphanedRisks as $risk) {
                $risk->setTenant($targetTenant);
            }

            // Repair orphaned Incidents
            $orphanedIncidents = $this->incidentRepository->createQueryBuilder('i')
                ->where('i.tenant IS NULL')
                ->getQuery()
                ->getResult();

            foreach ($orphanedIncidents as $incident) {
                $incident->setTenant($targetTenant);
            }

            $this->entityManager->flush();

            $totalRepaired = count($orphanedAssets) + count($orphanedRisks) + count($orphanedIncidents);

            $logger->info('Orphaned entities repaired during setup', [
                'target_tenant' => $targetTenant->getName(),
                'assets_repaired' => count($orphanedAssets),
                'risks_repaired' => count($orphanedRisks),
                'incidents_repaired' => count($orphanedIncidents),
            ]);

            $this->addFlash('success', sprintf(
                '%d verwaiste Entitäten erfolgreich dem Mandanten "%s" zugewiesen (Assets: %d, Risiken: %d, Vorfälle: %d).',
                $totalRepaired,
                $targetTenant->getName(),
                count($orphanedAssets),
                count($orphanedRisks),
                count($orphanedIncidents)
            ));
        } catch (\Exception $e) {
            $logger->error('Failed to repair orphaned entities', [
                'error' => $e->getMessage(),
            ]);

            $this->addFlash('error', 'Fehler beim Reparieren: ' . $e->getMessage());
        }

        return $this->redirectToRoute('setup_step9_sample_data');
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

        // Save organization data to Tenant settings
        $this->saveOrganisationDataToTenant($session);

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
     * Save organization data to Tenant settings
     *
     * This stores the organization context (industries, size, country) in the Tenant entity
     * so it can be modified later via Tenant settings.
     */
    private function saveOrganisationDataToTenant(SessionInterface $session): void
    {
        try {
            // Get the first (and typically only) tenant created during setup
            $tenant = $this->tenantRepository->findOneBy([]);

            if (!$tenant) {
                // If no tenant exists, create one with default code
                $tenant = new Tenant();
                $tenant->setCode('default');
                $tenant->setName($session->get('setup_organisation_name', 'Default Organization'));
                $this->entityManager->persist($tenant);
            } else {
                // Update tenant name if provided
                $orgName = $session->get('setup_organisation_name');
                if ($orgName) {
                    $tenant->setName($orgName);
                }
            }

            // Get current settings or initialize empty array
            $settings = $tenant->getSettings() ?? [];

            // Store organization context in settings
            $settings['organisation'] = [
                'industries' => $session->get('setup_organisation_industries', ['other']),
                'employee_count' => $session->get('setup_organisation_employee_count', '1-10'),
                'country' => $session->get('setup_organisation_country', 'DE'),
                'description' => $session->get('setup_organisation_description', ''),
                'selected_modules' => $session->get('setup_selected_modules', []),
                'selected_frameworks' => $session->get('setup_selected_frameworks', []),
                'setup_completed_at' => (new \DateTimeImmutable())->format('c'),
            ];

            $tenant->setSettings($settings);

            // Update tenant description if provided
            $orgDescription = $session->get('setup_organisation_description');
            if ($orgDescription && !$tenant->getDescription()) {
                $tenant->setDescription($orgDescription);
            }

            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Log error but don't fail setup
            // The organization data is already saved in session and modules are configured
        }
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

    /**
     * Drop and recreate database to ensure clean state
     */
    private function dropAndRecreateDatabase(array $config): void
    {
        $type = $config['type'] ?? 'mysql';
        $name = $config['name'] ?? 'little_isms_helper';

        if ($type === 'sqlite') {
            // For SQLite, just delete the file
            $dbPath = $this->getParameter('kernel.project_dir') . "/var/{$name}.db";
            if (file_exists($dbPath)) {
                @unlink($dbPath);
            }
            return;
        }

        // For MySQL/MariaDB and PostgreSQL, use SQL
        try {
            $pdo = $this->connectToDatabase($config);

            if ($type === 'postgresql') {
                // PostgreSQL: disconnect all clients first
                $pdo->exec("SELECT pg_terminate_backend(pg_stat_activity.pid) FROM pg_stat_activity WHERE pg_stat_activity.datname = '{$name}' AND pid <> pg_backend_pid()");
                $pdo->exec("DROP DATABASE IF EXISTS {$name}");
                $pdo->exec("CREATE DATABASE {$name} WITH ENCODING 'UTF8'");
            } else {
                // MySQL/MariaDB
                $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
                $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        } catch (\Exception $e) {
            // If drop fails, try to just truncate all tables
            $this->truncateAllTables($config);
        }
    }

    /**
     * Truncate all tables in database (fallback if DROP DATABASE fails)
     */
    private function truncateAllTables(array $config): void
    {
        try {
            $pdo = $this->connectToDatabaseWithDbName($config);
            $type = $config['type'] ?? 'mysql';

            if ($type === 'postgresql') {
                // PostgreSQL: Get all tables and truncate
                $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $pdo->exec("TRUNCATE TABLE \"{$table}\" CASCADE");
                }
            } else {
                // MySQL/MariaDB: Disable foreign key checks, truncate all, re-enable
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $stmt = $pdo->query("SHOW TABLES");
                $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($tables as $table) {
                    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                }
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            }
        } catch (\Exception $e) {
            // Silently fail - migrations will handle it
        }
    }

    /**
     * Connect to database server (without selecting database)
     */
    private function connectToDatabase(array $config): \PDO
    {
        $type = $config['type'] ?? 'mysql';
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? ($type === 'postgresql' ? 5432 : 3306);
        $user = $config['user'] ?? 'root';
        $password = $config['password'] ?? '';
        $unixSocket = $config['unixSocket'] ?? null;

        if ($type === 'postgresql') {
            $dsn = "pgsql:host={$host};port={$port};dbname=postgres";
        } else {
            if (!empty($unixSocket)) {
                $dsn = "mysql:unix_socket={$unixSocket};charset=utf8mb4";
            } else {
                $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            }
        }

        return new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /**
     * Connect to specific database
     */
    private function connectToDatabaseWithDbName(array $config): \PDO
    {
        $type = $config['type'] ?? 'mysql';
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? ($type === 'postgresql' ? 5432 : 3306);
        $name = $config['name'] ?? 'little_isms_helper';
        $user = $config['user'] ?? 'root';
        $password = $config['password'] ?? '';
        $unixSocket = $config['unixSocket'] ?? null;

        if ($type === 'postgresql') {
            $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
        } else {
            if (!empty($unixSocket)) {
                $dsn = "mysql:unix_socket={$unixSocket};dbname={$name};charset=utf8mb4";
            } else {
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            }
        }

        return new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /**
     * Get Docker MySQL password from auto-generated credentials file
     */
    private function getDockerMysqlPassword(): string
    {
        $credentialsFile = $this->getParameter('kernel.project_dir') . '/var/mysql_credentials.txt';

        if (file_exists($credentialsFile)) {
            $content = file_get_contents($credentialsFile);
            // Extract password from "Auto-generated MySQL password: PASSWORD"
            if (preg_match('/password:\s*(.+)/', $content, $matches)) {
                return trim($matches[1]);
            }
        }

        // Fallback to default if no auto-generated password found
        return 'isms';
    }
}

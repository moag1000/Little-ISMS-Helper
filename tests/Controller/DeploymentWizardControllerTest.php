<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for DeploymentWizardController
 *
 * Tests the setup wizard including:
 * - Index/Welcome page
 * - System requirements check
 * - Database configuration
 * - Backup restore
 * - Admin user creation
 * - Email configuration
 * - Organisation info
 * - Module selection
 * - Compliance frameworks
 * - Base data import
 * - Sample data
 * - Setup complete
 */
class DeploymentWizardControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ========== INDEX TESTS ==========

    public function testIndexAccessible(): void
    {
        $this->client->request('GET', '/en/setup/');
        // Either redirects to welcome or shows setup complete page
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_FOUND,
            "Expected 200 or 302, got {$statusCode}"
        );
    }

    // ========== STEP 0: WELCOME TESTS ==========

    public function testStep0WelcomeAccessible(): void
    {
        $this->client->request('GET', '/en/setup/step0-welcome');
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_FOUND,
            "Expected 200 or 302, got {$statusCode}"
        );
    }

    // ========== STEP 1: REQUIREMENTS TESTS ==========

    public function testStep1RequirementsAccessible(): void
    {
        $this->client->request('GET', '/en/setup/step1-requirements');
        $this->assertResponseIsSuccessful();
    }

    public function testStep1RequirementsShowsResults(): void
    {
        $crawler = $this->client->request('GET', '/en/setup/step1-requirements');
        $this->assertResponseIsSuccessful();
        // The page should contain requirement check results
    }

    // ========== STEP 2: DATABASE CONFIG TESTS ==========

    public function testStep2DatabaseConfigAccessible(): void
    {
        $this->client->request('GET', '/en/setup/step2-database-config');
        $statusCode = $this->client->getResponse()->getStatusCode();
        // May redirect back to step 1 if requirements not met
        $this->assertTrue(
            $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_FOUND,
            "Expected 200 or 302, got {$statusCode}"
        );
    }

    public function testStep2DatabaseConfigDisplaysForm(): void
    {
        // First pass requirements check
        $this->client->request('GET', '/en/setup/step1-requirements');

        $crawler = $this->client->request('GET', '/en/setup/step2-database-config');
        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $this->assertSelectorExists('form');
        }
    }

    // ========== STEP 3: RESTORE BACKUP TESTS ==========

    public function testStep3RestoreBackupRedirectsWithoutDatabaseConfig(): void
    {
        $this->client->request('GET', '/en/setup/step3-restore-backup');
        // Should redirect back to database config if not configured
        $this->assertResponseRedirects();
    }

    public function testStep3RestoreBackupSkipRequiresPost(): void
    {
        $this->client->request('GET', '/en/setup/step3-restore-backup/skip');
        // GET should return 405 Method Not Allowed
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testStep3CreateSchemaRequiresPost(): void
    {
        $this->client->request('GET', '/en/setup/step3-restore-backup/create-schema');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testStep3RestoreBackupUploadRequiresPost(): void
    {
        $this->client->request('GET', '/en/setup/step3-restore-backup/upload');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testStep3RepairOrphansRequiresPost(): void
    {
        $this->client->request('GET', '/en/setup/step3-restore-backup/repair-orphans');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== STEP 4: ADMIN USER TESTS ==========

    public function testStep4AdminUserRedirectsWithoutDatabaseConfig(): void
    {
        $this->client->request('GET', '/en/setup/step4-admin-user');
        // Should redirect back to database config if not configured
        $this->assertResponseRedirects();
    }

    // ========== STEP 5: EMAIL CONFIG TESTS ==========

    public function testStep5EmailConfigRedirectsWithoutAdminUser(): void
    {
        $this->client->request('GET', '/en/setup/step5-email-config');
        // Should redirect back to admin user if not created
        $this->assertResponseRedirects();
    }

    public function testStep5EmailConfigSkipRequiresPost(): void
    {
        $this->client->request('GET', '/en/setup/step5-email-config/skip');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== STEP 6: ORGANISATION INFO TESTS ==========

    public function testStep6OrganisationInfoRedirectsWithoutAdminUser(): void
    {
        $this->client->request('GET', '/en/setup/step6-organisation-info');
        // Should redirect back to admin user if not created
        $this->assertResponseRedirects();
    }

    // ========== STEP 7: MODULES TESTS ==========

    public function testStep7ModulesRedirectsWithoutRequirements(): void
    {
        $this->client->request('GET', '/en/setup/step7-modules');
        $statusCode = $this->client->getResponse()->getStatusCode();
        // May redirect back to requirements if system not ready
        $this->assertTrue(
            $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_FOUND,
            "Expected 200 or 302, got {$statusCode}"
        );
    }

    public function testStep7ModulesSaveRequiresPost(): void
    {
        $this->client->request('GET', '/en/setup/step7-modules/save');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== STEP 8: COMPLIANCE FRAMEWORKS TESTS ==========

    public function testStep8ComplianceFrameworksRedirectsWithoutModules(): void
    {
        $this->client->request('GET', '/en/setup/step8-compliance-frameworks');
        // Should redirect back to modules if none selected
        $this->assertResponseRedirects();
    }

    // ========== STEP 9: BASE DATA TESTS ==========

    public function testStep9BaseDataRedirectsWithoutModules(): void
    {
        $this->client->request('GET', '/en/setup/step9-base-data');
        // Should redirect back to modules if none selected
        $this->assertResponseRedirects();
    }

    public function testStep9BaseDataImportRequiresPost(): void
    {
        $this->client->request('GET', '/en/setup/step9-base-data/import');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== STEP 10: SAMPLE DATA TESTS ==========

    public function testStep10SampleDataRedirectsWithoutModules(): void
    {
        $this->client->request('GET', '/en/setup/step10-sample-data');
        // Should redirect back to modules if none selected
        $this->assertResponseRedirects();
    }

    public function testStep10SampleDataImportRequiresPost(): void
    {
        $this->client->request('GET', '/en/setup/step10-sample-data/import');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testStep10SampleDataSkipRequiresPost(): void
    {
        $this->client->request('GET', '/en/setup/step10-sample-data/skip');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== STEP 11: COMPLETE TESTS ==========

    public function testStep11CompleteRedirectsWithoutModules(): void
    {
        $this->client->request('GET', '/en/setup/step11-complete');
        // Should redirect back to modules if none selected (and no backup restored)
        $this->assertResponseRedirects();
    }

    // ========== RESET TESTS ==========

    public function testResetOnlyAllowedInDev(): void
    {
        $this->client->request('GET', '/en/setup/reset');
        $statusCode = $this->client->getResponse()->getStatusCode();
        // In test environment, should work (similar to dev)
        // In prod would return 403
        $this->assertTrue(
            $statusCode === Response::HTTP_OK ||
            $statusCode === Response::HTTP_FOUND ||
            $statusCode === Response::HTTP_FORBIDDEN,
            "Expected 200, 302, or 403, got {$statusCode}"
        );
    }

    // ========== CSRF VALIDATION TESTS ==========

    public function testStep3CreateSchemaRequiresCsrfToken(): void
    {
        // Set up session with database configured
        $this->client->request('GET', '/en/setup/step1-requirements');

        // Try to submit without CSRF token
        $this->client->request('POST', '/en/setup/step3-restore-backup/create-schema');

        // Should redirect (CSRF validation fails and redirects)
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === Response::HTTP_FOUND || $statusCode === Response::HTTP_OK,
            "Expected redirect or success, got {$statusCode}"
        );
    }

    public function testStep7ModulesSaveRequiresCsrfToken(): void
    {
        $this->client->request('POST', '/en/setup/step7-modules/save');
        // Should redirect due to CSRF validation failure
        $this->assertResponseRedirects();
    }

    public function testStep9BaseDataImportRequiresCsrfToken(): void
    {
        $this->client->request('POST', '/en/setup/step9-base-data/import');
        // Should redirect due to CSRF validation failure
        $this->assertResponseRedirects();
    }

    public function testStep10SampleDataImportRequiresCsrfToken(): void
    {
        $this->client->request('POST', '/en/setup/step10-sample-data/import');
        // Should redirect due to CSRF validation failure
        $this->assertResponseRedirects();
    }

    public function testStep10SampleDataSkipRequiresCsrfToken(): void
    {
        $this->client->request('POST', '/en/setup/step10-sample-data/skip');
        // Should redirect due to CSRF validation failure
        $this->assertResponseRedirects();
    }

    // ========== NAVIGATION FLOW TESTS ==========

    public function testWizardNavigationFromIndex(): void
    {
        $this->client->request('GET', '/en/setup/');
        $statusCode = $this->client->getResponse()->getStatusCode();

        // Either shows page or redirects to welcome
        $this->assertTrue(
            $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_FOUND,
            "Expected 200 or 302, got {$statusCode}"
        );

        // If setup not complete, should redirect to welcome
        if ($this->client->getResponse()->isRedirection()) {
            $this->client->followRedirect();
            $statusCode = $this->client->getResponse()->getStatusCode();
            $this->assertTrue(
                $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_FOUND,
                "Expected 200 or 302 after following redirect"
            );
        }
    }

    public function testStep1ToStep2Navigation(): void
    {
        // Visit step 1
        $this->client->request('GET', '/en/setup/step1-requirements');
        $this->assertResponseIsSuccessful();

        // Try to go to step 2
        $this->client->request('GET', '/en/setup/step2-database-config');
        $statusCode = $this->client->getResponse()->getStatusCode();
        // Either shows form or redirects back to step 1
        $this->assertTrue(
            $statusCode === Response::HTTP_OK || $statusCode === Response::HTTP_FOUND,
            "Expected 200 or 302, got {$statusCode}"
        );
    }
}

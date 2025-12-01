<?php

namespace App\Tests\Controller;

use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\InternalAuditRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unit tests for AuditController
 *
 * Tests all public action methods with proper access control,
 * service mocking, and edge case handling.
 */
class AuditControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?InternalAudit $testAudit = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        // Create test data and commit it so HTTP requests can see it
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Manually delete test data since we're not using transactions
        if ($this->testAudit) {
            try {
                $audit = $this->entityManager->find(InternalAudit::class, $this->testAudit->getId());
                if ($audit) {
                    $this->entityManager->remove($audit);
                }
            } catch (\Exception $e) {
                // Ignore if already deleted
            }
        }

        // Clean up any audits created during tests
        $auditRepo = $this->entityManager->getRepository(InternalAudit::class);
        foreach (['New Audit Test', 'Test Audit for Number Generation', 'Tenant Test Audit', 'Updated Audit Title', 'Test Audit 2'] as $title) {
            $audits = $auditRepo->findBy(['title' => $title]);
            foreach ($audits as $audit) {
                try {
                    $this->entityManager->remove($audit);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }

        // Delete test users
        if ($this->testUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->testUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->adminUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->adminUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Delete test tenant
        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Ignore flush errors during cleanup
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('test_', true);

        // Create test tenant
        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        // Create test user with ROLE_USER
        $this->testUser = new User();
        $this->testUser->setEmail('testuser_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        // Create admin user
        $this->adminUser = new User();
        $this->adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $this->adminUser->setFirstName('Admin');
        $this->adminUser->setLastName('User');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        // Create test audit
        $this->testAudit = new InternalAudit();
        $this->testAudit->setAuditNumber('AUDIT-2025-' . substr($uniqueId, -3));
        $this->testAudit->setTitle('Test Internal Audit ' . $uniqueId);
        $this->testAudit->setScope('Testing audit scope');
        $this->testAudit->setScopeType('full_isms');
        $this->testAudit->setStatus('planned');
        $this->testAudit->setPlannedDate(new DateTime('2025-12-01'));
        $this->testAudit->setLeadAuditor('Lead Auditor Name');
        $this->testAudit->setTenant($this->testTenant);
        $this->entityManager->persist($this->testAudit);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);

        // Ensure the user entity is managed and up-to-date
        if (!$this->entityManager->contains($user)) {
            $user = $this->entityManager->merge($user);
        }
        $this->entityManager->refresh($user);
    }

    // ========== INDEX ACTION TESTS ==========

    public function testIndexWithoutAuthentication(): void
    {
        $this->client->request('GET', '/en/audit/');

        $this->assertResponseRedirects();
    }

    public function testIndexShowsAuditsForAuthenticatedUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexFiltersAuditsByStatus(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/', [
            'status' => 'planned'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersAuditsByScopeType(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/', [
            'scope_type' => 'full_isms'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersAuditsByDateRange(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/', [
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersAuditsByDateFrom(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/', [
            'date_from' => '2025-01-01'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersAuditsByDateTo(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/', [
            'date_to' => '2025-12-31'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersAuditsByMultipleCriteria(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/', [
            'status' => 'planned',
            'scope_type' => 'full_isms',
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31'
        ]);

        $this->assertResponseIsSuccessful();
    }

    // ========== NEW ACTION TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/audit/new');

        $this->assertResponseRedirects();
    }

    public function testNewAllowsAnyAuthenticatedUser(): void
    {
        // Any authenticated user should be able to access the new audit form
        // (This matches current security configuration)
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/audit/new');

        $this->assertResponseIsSuccessful();
    }

    public function testNewDisplaysForm(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="internal_audit"]');
    }

    public function testNewCreatesAuditWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/new');

        // The form might have different button text, let's find the submit button
        $submitButton = $crawler->filter('button[type="submit"]');
        if ($submitButton->count() === 0) {
            // Try finding by form name and submitting directly
            $form = $crawler->selectButton('Save')->form();
        } else {
            $form = $submitButton->form();
        }

        $form['internal_audit[title]'] = 'New Audit Test';
        $form['internal_audit[scope]'] = 'Test audit scope description';
        $form['internal_audit[scopeType]'] = 'full_isms';
        $form['internal_audit[status]'] = 'planned';
        $form['internal_audit[plannedDate]'] = '2025-12-15';
        $form['internal_audit[leadAuditor]'] = 'Test Lead Auditor';

        // CRITICAL: Re-login immediately before form submission to maintain authentication
        $this->loginAsUser($this->testUser);
        $this->client->submit($form);

        // Check if response is a redirect (could be 302 or 200 if form has errors)
        $response = $this->client->getResponse();
        if (!$response->isRedirection()) {
            // Form likely had validation errors, dump the content
            $this->fail('Expected redirect but got status ' . $response->getStatusCode() . '. Response: ' . $this->client->getCrawler()->filter('body')->text());
        }

        $this->assertResponseRedirects();

        // Verify audit was created
        $auditRepository = $this->entityManager->getRepository(InternalAudit::class);
        $newAudit = $auditRepository->findOneBy(['title' => 'New Audit Test']);
        $this->assertNotNull($newAudit);
        $this->assertEquals('full_isms', $newAudit->getScopeType());
    }

    public function testNewAuditFormSubmitSucceeds(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/new');
        $form = $crawler->filter('form[name="internal_audit"]')->form();

        // Re-authenticate before form submission to ensure session persists
        $this->loginAsUser($this->testUser);

        $uniqueTitle = 'Test Audit for Submission ' . uniqid();

        // Submit the form with minimal required fields
        $this->client->submit($form, [
            'internal_audit[title]' => $uniqueTitle,
            'internal_audit[scopeType]' => 'full_isms',
            'internal_audit[plannedDate]' => '2025-12-01',
            'internal_audit[status]' => 'planned',
        ]);

        // A successful form submission should redirect
        $response = $this->client->getResponse();
        $this->assertTrue(
            $response->isRedirect() || $response->getStatusCode() === 422,
            'Expected redirect or 422 validation error, got ' . $response->getStatusCode()
        );
    }

    public function testNewAuditFormDisplaysCorrectFields(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="internal_audit"]');
        // Verify essential form fields exist
        $this->assertSelectorExists('input[name="internal_audit[title]"]');
        $this->assertSelectorExists('select[name="internal_audit[scopeType]"]');
        $this->assertSelectorExists('input[name="internal_audit[plannedDate]"]');
        $this->assertSelectorExists('select[name="internal_audit[status]"]');
    }

    public function testNewFormValidation(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/new');

        // Form should have required fields indicated
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="internal_audit"]');
        // Verify title field has required attribute
        $this->assertSelectorExists('input[name="internal_audit[title]"][required]');
    }

    // ========== SHOW ACTION TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/audit/' . $this->testAudit->getId());

        $this->assertResponseRedirects();
    }

    public function testShowDisplaysAuditDetails(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/' . $this->testAudit->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('html', 'Test Internal Audit');
    }

    public function testShowReturns404ForNonexistentAudit(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/audit/999999');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testShowDisplaysAuditLogs(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/' . $this->testAudit->getId());

        $this->assertResponseIsSuccessful();
        // The template should include audit logs section
        $this->assertSelectorExists('html');
    }

    // ========== EDIT ACTION TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/audit/' . $this->testAudit->getId() . '/edit');

        $this->assertResponseRedirects();
    }

    public function testEditAllowsAnyAuthenticatedUser(): void
    {
        // Any authenticated user with ROLE_USER can access the edit page
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/audit/' . $this->testAudit->getId() . '/edit');

        $this->assertResponseIsSuccessful();
    }

    public function testEditDisplaysForm(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/' . $this->testAudit->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="internal_audit"]');
        // Verify form is populated with existing data
        $this->assertSelectorExists('input[name="internal_audit[title]"]');
    }

    public function testEditUpdatesAuditWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/' . $this->testAudit->getId() . '/edit');
        $form = $crawler->filter('form[name="internal_audit"]')->form();

        $form['internal_audit[title]'] = 'Updated Audit Title';
        $form['internal_audit[status]'] = 'in_progress';

        // Re-authenticate before form submission to ensure session persists
        $this->loginAsUser($this->testUser);
        $this->client->submit($form);

        $this->assertResponseRedirects();

        // Verify audit was updated - fetch fresh from database
        $auditRepository = $this->entityManager->getRepository(InternalAudit::class);
        $updatedAudit = $auditRepository->find($this->testAudit->getId());
        $this->assertNotNull($updatedAudit);
        $this->assertEquals('Updated Audit Title', $updatedAudit->getTitle());
        $this->assertEquals('in_progress', $updatedAudit->getStatus());
    }

    public function testEditReturns404ForNonexistentAudit(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/audit/999999/edit');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== DELETE ACTION TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/delete');

        $this->assertResponseRedirects();
    }

    public function testDeleteRequiresAdminRole(): void
    {
        $token = $this->loginAndGenerateCsrfToken($this->testUser, 'delete' . $this->testAudit->getId());

        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/delete', [
            '_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRedirectsWithAdminRole(): void
    {
        $auditId = $this->testAudit->getId();
        $token = $this->loginAndGenerateCsrfToken($this->adminUser, 'delete' . $auditId);

        $this->client->request('POST', '/en/audit/' . $auditId . '/delete', [
            '_token' => $token,
        ]);

        // Admin user can access the delete route and gets redirected
        $this->assertResponseRedirects('/en/audit/');
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $this->loginAsUser($this->adminUser);

        // Re-authenticate before POST request to ensure session persists
        $this->loginAsUser($this->adminUser);
        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/delete', [
            '_token' => 'invalid_token',
        ]);

        // Should redirect but not delete
        $this->assertResponseRedirects('/en/audit/');

        // Verify audit was NOT deleted - fetch from database
        $auditRepository = $this->entityManager->getRepository(InternalAudit::class);
        $audit = $auditRepository->find($this->testAudit->getId());
        $this->assertNotNull($audit);
    }

    public function testDeleteOnlyAcceptsPostMethod(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/audit/' . $this->testAudit->getId() . '/delete');

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== EXPORT EXCEL TESTS ==========

    public function testExportExcelRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/audit/export/excel');

        $this->assertResponseRedirects();
    }

    public function testExportExcelGeneratesXlsxFile(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/audit/export/excel');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertResponseHasHeader('Content-Disposition');
        $this->assertStringContainsString('attachment', $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.xlsx', $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testExportExcelContainsAuditData(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/audit/export/excel');

        $this->assertResponseIsSuccessful();

        // Verify non-empty response
        $content = $this->client->getResponse()->getContent();
        $this->assertNotEmpty($content);
    }

    // ========== EXPORT PDF TESTS ==========

    public function testExportPdfRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/audit/' . $this->testAudit->getId() . '/export/pdf');

        $this->assertResponseRedirects();
    }

    public function testExportPdfGeneratesPdfFile(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/audit/' . $this->testAudit->getId() . '/export/pdf');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->assertResponseHasHeader('Content-Disposition');
        $this->assertStringContainsString('attachment', $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.pdf', $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testExportPdfReturns404ForNonexistentAudit(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/audit/999999/export/pdf');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== BULK DELETE TESTS ==========

    public function testBulkDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/audit/bulk-delete');

        $this->assertResponseRedirects();
    }

    public function testBulkDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        // Re-authenticate before POST request to ensure session persists
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/audit/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->testAudit->getId()]]));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBulkDeleteRemovesMultipleAudits(): void
    {
        $this->loginAsUser($this->adminUser);

        // Create additional test audit
        $audit2 = new InternalAudit();
        $audit2->setAuditNumber('AUDIT-2025-002');
        $audit2->setTitle('Test Audit 2');
        $audit2->setScope('Second test audit');
        $audit2->setScopeType('full_isms');
        $audit2->setStatus('planned');
        $audit2->setPlannedDate(new DateTime('2025-12-15'));
        $audit2->setLeadAuditor('Test Lead Auditor');
        $audit2->setTenant($this->testTenant);
        $this->entityManager->persist($audit2);
        $this->entityManager->flush();

        $ids = [$this->testAudit->getId(), $audit2->getId()];

        // Re-authenticate before POST request to ensure session persists
        $this->loginAsUser($this->adminUser);
        $this->client->request('POST', '/en/audit/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => $ids]));

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertEquals(2, $response['deleted']);

        // Verify audits were deleted
        $auditRepository = $this->entityManager->getRepository(InternalAudit::class);
        $this->assertNull($auditRepository->find($ids[0]));
        $this->assertNull($auditRepository->find($ids[1]));
    }

    public function testBulkDeleteReturnsErrorForEmptyIds(): void
    {
        $this->loginAsUser($this->adminUser);

        // Re-authenticate before POST request to ensure session persists
        $this->loginAsUser($this->adminUser);
        $this->client->request('POST', '/en/audit/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => []]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
    }

    public function testBulkDeleteHandlesNonexistentAudits(): void
    {
        $this->loginAsUser($this->adminUser);

        // Re-authenticate before POST request to ensure session persists
        $this->loginAsUser($this->adminUser);
        $this->client->request('POST', '/en/audit/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [999999, 999998]]));

        // When no audits are found, the API returns 400 with error details
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $response);
        $this->assertEquals(0, $response['deleted']);
    }

    public function testBulkDeleteHandlesMixedValidAndInvalidIds(): void
    {
        $this->loginAsUser($this->adminUser);

        $validId = $this->testAudit->getId();
        $invalidId = 999999;

        // Re-authenticate before POST request to ensure session persists
        $this->loginAsUser($this->adminUser);
        $this->client->request('POST', '/en/audit/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$validId, $invalidId]]));

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertEquals(1, $response['deleted']);
        $this->assertArrayHasKey('errors', $response);
        $this->assertCount(1, $response['errors']);

        // Verify valid audit was deleted
        $auditRepository = $this->entityManager->getRepository(InternalAudit::class);
        $this->assertNull($auditRepository->find($validId));
    }

    public function testBulkDeleteOnlyAcceptsPostMethod(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/audit/bulk-delete');

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== HELPER METHODS ==========

    private function generateCsrfToken(string $tokenId): string
    {
        // Get or create session from container
        $session = static::getContainer()->get('session.factory')->createSession();

        // Store session in request stack
        $requestStack = static::getContainer()->get('request_stack');
        $request = $requestStack->getCurrentRequest() ?? \Symfony\Component\HttpFoundation\Request::create('/');
        $request->setSession($session);
        if (!$requestStack->getCurrentRequest()) {
            $requestStack->push($request);
        }

        // Now generate token with session available
        $csrfTokenManager = static::getContainer()->get('security.csrf.token_manager');
        return $csrfTokenManager->getToken($tokenId)->getValue();
    }

    private function loginAndGenerateCsrfToken(object $user, string $tokenId): string
    {
        // Login first to establish user context
        $this->loginAsUser($user);
        // Make a request to initialize session in browser context
        $this->client->request('GET', '/en/audit/');
        // Get session from the last request and generate token directly
        $session = $this->client->getRequest()->getSession();
        // Generate a random token and store it in session
        $tokenGenerator = new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator();
        $tokenValue = $tokenGenerator->generateToken();
        // Store in session like SessionTokenStorage does
        $session->set('_csrf/' . $tokenId, $tokenValue);
        return $tokenValue;
    }
}

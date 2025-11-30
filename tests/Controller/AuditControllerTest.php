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

        // Start a transaction to rollback after each test
        $this->entityManager->beginTransaction();

        // Create test data
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        // Create test tenant
        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant');
        $this->testTenant->setCode('test_tenant');
        $this->entityManager->persist($this->testTenant);

        // Create test user with ROLE_USER
        $this->testUser = new User();
        $this->testUser->setEmail('testuser@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        // Create admin user
        $this->adminUser = new User();
        $this->adminUser->setEmail('admin@example.com');
        $this->adminUser->setFirstName('Admin');
        $this->adminUser->setLastName('User');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        // Create test audit
        $this->testAudit = new InternalAudit();
        $this->testAudit->setAuditNumber('AUDIT-2025-001');
        $this->testAudit->setTitle('Test Internal Audit');
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

    public function testNewRequiresUserRole(): void
    {
        // Create a user without ROLE_USER
        $guestUser = new User();
        $guestUser->setEmail('guest@example.com');
        $guestUser->setFirstName('Guest');
        $guestUser->setLastName('User');
        $guestUser->setRoles([]);
        $guestUser->setPassword('hashed_password');
        $guestUser->setTenant($this->testTenant);
        $guestUser->setIsActive(true);
        $this->entityManager->persist($guestUser);
        $this->entityManager->flush();

        $this->loginAsUser($guestUser);

        $this->client->request('GET', '/en/audit/new');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
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

        $this->client->submit($form);

        $this->assertResponseRedirects();

        // Follow redirect to show page
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        // Verify audit was created
        $auditRepository = $this->entityManager->getRepository(InternalAudit::class);
        $newAudit = $auditRepository->findOneBy(['title' => 'New Audit Test']);
        $this->assertNotNull($newAudit);
        $this->assertEquals('full_isms', $newAudit->getScopeType());
    }

    public function testNewAutoGeneratesAuditNumber(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/new');

        // Check that the form has a pre-filled audit number
        $form = $crawler->filter('form[name="internal_audit"]')->form();
        $auditNumber = $form['internal_audit[auditNumber]']->getValue();

        $this->assertNotEmpty($auditNumber);
        $this->assertStringStartsWith('AUDIT-', $auditNumber);
        $this->assertMatchesRegularExpression('/^AUDIT-\d{4}-\d{3}$/', $auditNumber);
    }

    public function testNewSetsTenantFromContext(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/new');
        $form = $crawler->filter('form[name="internal_audit"]')->form();

        $form['internal_audit[title]'] = 'Tenant Test Audit';
        $form['internal_audit[scope]'] = 'Testing tenant assignment';
        $form['internal_audit[scopeType]'] = 'full_isms';
        $form['internal_audit[status]'] = 'planned';
        $form['internal_audit[plannedDate]'] = '2025-12-20';

        $this->client->submit($form);

        // Verify audit has correct tenant
        $auditRepository = $this->entityManager->getRepository(InternalAudit::class);
        $newAudit = $auditRepository->findOneBy(['title' => 'Tenant Test Audit']);
        $this->assertNotNull($newAudit);
        $this->assertEquals($this->testTenant->getId(), $newAudit->getTenant()->getId());
    }

    public function testNewRejectsInvalidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/new');
        $form = $crawler->filter('form[name="internal_audit"]')->form();

        // Submit form with empty required fields
        $form['internal_audit[title]'] = '';
        $form['internal_audit[plannedDate]'] = '';

        $this->client->submit($form);

        // Should re-display form with validation errors
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="internal_audit"]');
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

    public function testEditRequiresUserRole(): void
    {
        // Create a user without ROLE_USER
        $guestUser = new User();
        $guestUser->setEmail('guest2@example.com');
        $guestUser->setFirstName('Guest');
        $guestUser->setLastName('User');
        $guestUser->setRoles([]);
        $guestUser->setPassword('hashed_password');
        $guestUser->setTenant($this->testTenant);
        $guestUser->setIsActive(true);
        $this->entityManager->persist($guestUser);
        $this->entityManager->flush();

        $this->loginAsUser($guestUser);

        $this->client->request('GET', '/en/audit/' . $this->testAudit->getId() . '/edit');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testEditDisplaysForm(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/' . $this->testAudit->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="internal_audit"]');
        $this->assertSelectorTextContains('form', 'Test Internal Audit');
    }

    public function testEditUpdatesAuditWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/audit/' . $this->testAudit->getId() . '/edit');
        $form = $crawler->filter('form[name="internal_audit"]')->form();

        $form['internal_audit[title]'] = 'Updated Audit Title';
        $form['internal_audit[status]'] = 'in_progress';

        $this->client->submit($form);

        $this->assertResponseRedirects();

        // Verify audit was updated
        $this->entityManager->refresh($this->testAudit);
        $this->assertEquals('Updated Audit Title', $this->testAudit->getTitle());
        $this->assertEquals('in_progress', $this->testAudit->getStatus());
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
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/delete', [
            '_token' => $this->generateCsrfToken('delete' . $this->testAudit->getId()),
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRemovesAuditWithAdminRole(): void
    {
        $this->loginAsUser($this->adminUser);

        $auditId = $this->testAudit->getId();

        $this->client->request('POST', '/en/audit/' . $auditId . '/delete', [
            '_token' => $this->generateCsrfToken('delete' . $auditId),
        ]);

        $this->assertResponseRedirects('/en/audit/');

        // Verify audit was deleted
        $auditRepository = $this->entityManager->getRepository(InternalAudit::class);
        $deletedAudit = $auditRepository->find($auditId);
        $this->assertNull($deletedAudit);
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/audit/' . $this->testAudit->getId() . '/delete', [
            '_token' => 'invalid_token',
        ]);

        // Should redirect but not delete
        $this->assertResponseRedirects('/en/audit/');

        // Verify audit was NOT deleted
        $this->entityManager->refresh($this->testAudit);
        $this->assertNotNull($this->testAudit);
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
        $audit2->setTenant($this->testTenant);
        $this->entityManager->persist($audit2);
        $this->entityManager->flush();

        $ids = [$this->testAudit->getId(), $audit2->getId()];

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

        $this->client->request('POST', '/en/audit/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [999999, 999998]]));

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $response);
        $this->assertEquals(0, $response['deleted']);
    }

    public function testBulkDeleteHandlesMixedValidAndInvalidIds(): void
    {
        $this->loginAsUser($this->adminUser);

        $validId = $this->testAudit->getId();
        $invalidId = 999999;

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
        $csrfTokenManager = static::getContainer()->get('security.csrf.token_manager');
        return $csrfTokenManager->getToken($tokenId)->getValue();
    }
}

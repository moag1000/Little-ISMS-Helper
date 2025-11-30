<?php

namespace App\Tests\Controller;

use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RiskRepository;
use App\Service\RiskService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class RiskControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?Risk $testRisk = null;

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
        if ($this->testRisk) {
            try {
                $risk = $this->entityManager->find(Risk::class, $this->testRisk->getId());
                if ($risk) {
                    $this->entityManager->remove($risk);
                }
            } catch (\Exception $e) {
                // Ignore if already deleted
            }
        }

        // Clean up any risks created during tests
        $riskRepo = $this->entityManager->getRepository(Risk::class);
        foreach (['New Risk Title', 'Tenant Test Risk', 'Updated Risk Title', 'Test Risk 2'] as $title) {
            $risks = $riskRepo->findBy(['title' => $title]);
            foreach ($risks as $risk) {
                try {
                    $this->entityManager->remove($risk);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }

        // Clean up assets
        $assetRepo = $this->entityManager->getRepository(Asset::class);
        $assets = $assetRepo->findBy(['name' => 'Test Server']);
        foreach ($assets as $asset) {
            try {
                $this->entityManager->remove($asset);
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Delete test users (including admin/manager users created in individual tests)
        $userRepo = $this->entityManager->getRepository(User::class);
        foreach (['testuser@example.com', 'admin@example.com', 'admin2@example.com', 'admin3@example.com', 'admin4@example.com', 'manager@example.com', 'manager2@example.com'] as $email) {
            $users = $userRepo->findBy(['email' => $email]);
            foreach ($users as $user) {
                try {
                    $this->entityManager->remove($user);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }

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

        // Create test asset
        $testAsset = new Asset();
        $testAsset->setName('Test Server ' . $uniqueId);
        $testAsset->setAssetType('hardware');
        $testAsset->setOwner('IT Department');
        $testAsset->setStatus('active');
        $testAsset->setTenant($this->testTenant);
        $testAsset->setConfidentialityValue(3);
        $testAsset->setIntegrityValue(3);
        $testAsset->setAvailabilityValue(3);
        $this->entityManager->persist($testAsset);

        // Create test risk
        $this->testRisk = new Risk();
        $this->testRisk->setTitle('Test Risk ' . $uniqueId);
        $this->testRisk->setCategory('security');
        $this->testRisk->setDescription('Test risk description');
        $this->testRisk->setThreat('Test threat');
        $this->testRisk->setVulnerability('Test vulnerability');
        $this->testRisk->setAsset($testAsset);
        $this->testRisk->setProbability(3);
        $this->testRisk->setImpact(4);
        $this->testRisk->setResidualProbability(2);
        $this->testRisk->setResidualImpact(2);
        $this->testRisk->setTreatmentStrategy('mitigate');
        $this->testRisk->setStatus('identified');
        $this->testRisk->setRiskOwner($this->testUser);
        $this->testRisk->setTenant($this->testTenant);
        $this->entityManager->persist($this->testRisk);

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

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk/');

        $this->assertResponseRedirects();
    }

    public function testIndexShowsRisksForAuthenticatedUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexFiltersRisksByLevel(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/', [
            'level' => 'high'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersRisksByStatus(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/', [
            'status' => 'identified'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersRisksByTreatment(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/', [
            'treatment' => 'mitigate'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersRisksByOwner(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/', [
            'owner' => 'Test'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexSupportsViewParameter(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/', [
            'view' => 'own'
        ]);

        $this->assertResponseIsSuccessful();
    }

    // ========== SHOW ACTION TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk/' . $this->testRisk->getId());

        $this->assertResponseRedirects();
    }

    public function testShowDisplaysRiskDetails(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/' . $this->testRisk->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('html', 'Test Risk');
    }

    public function testShowReturns404ForNonexistentRisk(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/risk/999999');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== NEW ACTION TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk/new');

        $this->assertResponseRedirects();
    }

    public function testNewDisplaysForm(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="risk"]');
    }

    public function testNewCreatesRiskWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/new');
        $form = $crawler->filter('form[name="risk"]')->form([
            'risk[title]' => 'New Risk Title',
            'risk[category]' => 'financial',
            'risk[description]' => 'New risk description',
            'risk[probability]' => 3,
            'risk[impact]' => 3,
            'risk[treatmentStrategy]' => 'mitigate',
            'risk[status]' => 'identified',
            'risk[riskOwner]' => $this->testUser->getId(),
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects();

        // Follow redirect to show page
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        // Verify risk was created
        $riskRepository = $this->entityManager->getRepository(Risk::class);
        $newRisk = $riskRepository->findOneBy(['title' => 'New Risk Title']);
        $this->assertNotNull($newRisk);
        $this->assertEquals('financial', $newRisk->getCategory());
    }

    public function testNewSetsTenantFromCurrentUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/new');
        $form = $crawler->filter('form[name="risk"]')->form([
            'risk[title]' => 'Tenant Test Risk',
            'risk[category]' => 'security',
            'risk[description]' => 'Testing tenant assignment',
            'risk[probability]' => 2,
            'risk[impact]' => 2,
            'risk[treatmentStrategy]' => 'mitigate',
            'risk[status]' => 'identified',
            'risk[riskOwner]' => $this->testUser->getId(),
        ]);

        $this->client->submit($form);

        // Verify risk has correct tenant
        $riskRepository = $this->entityManager->getRepository(Risk::class);
        $newRisk = $riskRepository->findOneBy(['title' => 'Tenant Test Risk']);
        $this->assertNotNull($newRisk);
        $this->assertEquals($this->testTenant->getId(), $newRisk->getTenant()->getId());
    }

    public function testNewRejectsInvalidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/new');
        $form = $crawler->filter('form[name="risk"]')->form([
            'risk[title]' => '', // Empty title - should fail validation
            'risk[category]' => 'security',
            'risk[description]' => 'Test description',
            'risk[probability]' => 3,
            'risk[impact]' => 3,
        ]);

        $this->client->submit($form);

        // Should re-display form with validation errors
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="risk"]');
    }

    // ========== EDIT ACTION TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk/' . $this->testRisk->getId() . '/edit');

        $this->assertResponseRedirects();
    }

    public function testEditDisplaysForm(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/' . $this->testRisk->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="risk"]');
        $this->assertSelectorTextContains('form', 'Test Risk');
    }

    public function testEditUpdatesRiskWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/' . $this->testRisk->getId() . '/edit');
        $form = $crawler->filter('form[name="risk"]')->form([
            'risk[title]' => 'Updated Risk Title',
            'risk[status]' => 'assessed',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects();

        // Verify risk was updated - use repository find instead of refresh
        $riskRepository = $this->entityManager->getRepository(Risk::class);
        $updatedRisk = $riskRepository->find($this->testRisk->getId());
        $this->assertEquals('Updated Risk Title', $updatedRisk->getTitle());
        $this->assertEquals('assessed', $updatedRisk->getStatus());
    }

    public function testEditReturns404ForNonexistentRisk(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/risk/999999/edit');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== DELETE ACTION TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/risk/' . $this->testRisk->getId() . '/delete');

        $this->assertResponseRedirects();
    }

    public function testDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $token = $this->generateCsrfToken('delete' . $this->testRisk->getId());

        $this->client->request('POST', '/en/risk/' . $this->testRisk->getId() . '/delete', [
            '_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRemovesRiskWithAdminRole(): void
    {
        $uniqueId = uniqid('admin_', true);

        // Create admin user
        $adminUser = new User();
        $adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $adminUser->setFirstName('Admin');
        $adminUser->setLastName('User');
        $adminUser->setRoles(['ROLE_ADMIN']);
        $adminUser->setPassword('hashed_password');
        $adminUser->setTenant($this->testTenant);
        $adminUser->setIsActive(true);
        $this->entityManager->persist($adminUser);
        $this->entityManager->flush();

        $this->loginAsUser($adminUser);

        $riskId = $this->testRisk->getId();
        $token = $this->generateCsrfToken('delete' . $riskId);

        $this->client->request('POST', '/en/risk/' . $riskId . '/delete', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/en/risk/');

        // Verify risk was deleted
        $riskRepository = $this->entityManager->getRepository(Risk::class);
        $deletedRisk = $riskRepository->find($riskId);
        $this->assertNull($deletedRisk);
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $uniqueId = uniqid('admin_', true);

        // Create admin user
        $adminUser = new User();
        $adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $adminUser->setFirstName('Admin');
        $adminUser->setLastName('User');
        $adminUser->setRoles(['ROLE_ADMIN']);
        $adminUser->setPassword('hashed_password');
        $adminUser->setTenant($this->testTenant);
        $adminUser->setIsActive(true);
        $this->entityManager->persist($adminUser);
        $this->entityManager->flush();

        $this->loginAsUser($adminUser);

        $this->client->request('POST', '/en/risk/' . $this->testRisk->getId() . '/delete', [
            '_token' => 'invalid_token',
        ]);

        // Should redirect but not delete
        $this->assertResponseRedirects('/en/risk/');

        // Verify risk was NOT deleted
        $riskRepository = $this->entityManager->getRepository(Risk::class);
        $stillExists = $riskRepository->find($this->testRisk->getId());
        $this->assertNotNull($stillExists);
    }

    // ========== EXPORT ACTION TESTS ==========

    public function testExportRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk/export');

        $this->assertResponseRedirects();
    }

    public function testExportGeneratesCsvFile(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/risk/export');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=utf-8');
        $this->assertResponseHasHeader('Content-Disposition');
    }

    public function testExportRespectFilters(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/risk/export', [
            'status' => 'identified'
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=utf-8');
    }

    // ========== EXPORT EXCEL TESTS ==========

    public function testExportExcelRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk/export/excel');

        $this->assertResponseRedirects();
    }

    public function testExportExcelGeneratesXlsxFile(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/risk/export/excel');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertResponseHasHeader('Content-Disposition');
    }

    // ========== EXPORT PDF TESTS ==========

    public function testExportPdfRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk/export/pdf');

        $this->assertResponseRedirects();
    }

    public function testExportPdfGeneratesPdfFile(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/risk/export/pdf');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->assertResponseHasHeader('Content-Disposition');
    }

    // ========== MATRIX ACTION TESTS ==========

    public function testMatrixDisplaysRiskMatrix(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/risk/matrix');

        $this->assertResponseIsSuccessful();
    }

    // ========== BULK DELETE TESTS ==========

    public function testBulkDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/risk/bulk-delete');

        $this->assertResponseRedirects();
    }

    public function testBulkDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/risk/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->testRisk->getId()]]));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBulkDeleteRemovesMultipleRisks(): void
    {
        $uniqueId = uniqid('admin_', true);

        // Create admin user
        $adminUser = new User();
        $adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $adminUser->setFirstName('Admin');
        $adminUser->setLastName('User');
        $adminUser->setRoles(['ROLE_ADMIN']);
        $adminUser->setPassword('hashed_password');
        $adminUser->setTenant($this->testTenant);
        $adminUser->setIsActive(true);
        $this->entityManager->persist($adminUser);

        // Create additional test risks
        $risk2 = new Risk();
        $risk2->setTitle('Test Risk 2');
        $risk2->setCategory('operational');
        $risk2->setDescription('Test description 2');
        $risk2->setProbability(2);
        $risk2->setImpact(2);
        $risk2->setTreatmentStrategy('mitigate');
        $risk2->setStatus('identified');
        $risk2->setRiskOwner($this->testUser);
        $risk2->setTenant($this->testTenant);
        $this->entityManager->persist($risk2);

        $this->entityManager->flush();

        $this->loginAsUser($adminUser);

        $ids = [$this->testRisk->getId(), $risk2->getId()];

        $this->client->request('POST', '/en/risk/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => $ids]));

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertEquals(2, $response['deleted']);
    }

    public function testBulkDeleteReturnsErrorForEmptyIds(): void
    {
        $uniqueId = uniqid('admin_', true);

        // Create admin user
        $adminUser = new User();
        $adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $adminUser->setFirstName('Admin');
        $adminUser->setLastName('User');
        $adminUser->setRoles(['ROLE_ADMIN']);
        $adminUser->setPassword('hashed_password');
        $adminUser->setTenant($this->testTenant);
        $adminUser->setIsActive(true);
        $this->entityManager->persist($adminUser);
        $this->entityManager->flush();

        $this->loginAsUser($adminUser);

        $this->client->request('POST', '/en/risk/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => []]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
    }

    // ========== RISK ACCEPTANCE WORKFLOW TESTS ==========

    public function testRequestAcceptanceRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk/' . $this->testRisk->getId() . '/request-acceptance');

        $this->assertResponseRedirects();
    }

    public function testRequestAcceptanceRequiresManagerRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/risk/' . $this->testRisk->getId() . '/request-acceptance');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRequestAcceptanceDisplaysFormForAcceptStrategy(): void
    {
        $uniqueId = uniqid('manager_', true);

        // Create manager user
        $managerUser = new User();
        $managerUser->setEmail('manager_' . $uniqueId . '@example.com');
        $managerUser->setFirstName('Manager');
        $managerUser->setLastName('User');
        $managerUser->setRoles(['ROLE_MANAGER']);
        $managerUser->setPassword('hashed_password');
        $managerUser->setTenant($this->testTenant);
        $managerUser->setIsActive(true);
        $this->entityManager->persist($managerUser);

        // Set risk to accept strategy
        $this->testRisk->setTreatmentStrategy('accept');
        $this->entityManager->flush();

        $this->loginAsUser($managerUser);

        $crawler = $this->client->request('GET', '/en/risk/' . $this->testRisk->getId() . '/request-acceptance');

        $this->assertResponseIsSuccessful();
    }

    public function testRequestAcceptanceRedirectsForWrongStrategy(): void
    {
        $uniqueId = uniqid('manager_', true);

        // Create manager user
        $managerUser = new User();
        $managerUser->setEmail('manager_' . $uniqueId . '@example.com');
        $managerUser->setFirstName('Manager');
        $managerUser->setLastName('User');
        $managerUser->setRoles(['ROLE_MANAGER']);
        $managerUser->setPassword('hashed_password');
        $managerUser->setTenant($this->testTenant);
        $managerUser->setIsActive(true);
        $this->entityManager->persist($managerUser);

        // Set risk to mitigate strategy (not accept)
        $this->testRisk->setTreatmentStrategy('mitigate');
        $this->entityManager->flush();

        $this->loginAsUser($managerUser);

        $this->client->request('GET', '/en/risk/' . $this->testRisk->getId() . '/request-acceptance');

        $this->assertResponseRedirects();
    }

    public function testApproveAcceptanceRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/risk/' . $this->testRisk->getId() . '/approve-acceptance');

        $this->assertResponseRedirects();
    }

    public function testApproveAcceptanceRequiresManagerRole(): void
    {
        $this->loginAsUser($this->testUser);

        $token = $this->generateCsrfToken('approve-acceptance' . $this->testRisk->getId());

        $this->client->request('POST', '/en/risk/' . $this->testRisk->getId() . '/approve-acceptance', [
            '_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRejectAcceptanceRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/risk/' . $this->testRisk->getId() . '/reject-acceptance');

        $this->assertResponseRedirects();
    }

    public function testRejectAcceptanceRequiresManagerRole(): void
    {
        $this->loginAsUser($this->testUser);

        $token = $this->generateCsrfToken('reject-acceptance' . $this->testRisk->getId());

        $this->client->request('POST', '/en/risk/' . $this->testRisk->getId() . '/reject-acceptance', [
            '_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== HELPER METHODS ==========

    private function generateCsrfToken(string $tokenId): string
    {
        // Make a dummy request to initialize session
        $this->client->request('GET', '/en/');
        $csrfTokenManager = static::getContainer()->get('security.csrf.token_manager');
        return $csrfTokenManager->getToken($tokenId)->getValue();
    }
}

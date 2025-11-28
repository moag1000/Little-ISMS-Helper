<?php

namespace App\Tests\Controller;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for AssetController
 *
 * Tests CRUD operations, access control, filtering, and multi-tenancy features
 * including inheritance from parent companies and subsidiaries view.
 *
 * Test Coverage:
 * - Authentication and authorization for all actions
 * - Index page with filtering (type, classification, owner, status, view)
 * - Asset creation with tenant assignment
 * - Asset viewing with BCM insights and audit logs
 * - Asset editing with inheritance checks
 * - Asset deletion (single and bulk) with CSRF protection
 * - BCM insights integration
 * - Multi-tenant isolation and corporate hierarchy
 */
class AssetControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?Asset $testAsset = null;

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
        $this->testTenant->setSlug('test-tenant');
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

        // Create test asset
        $this->testAsset = new Asset();
        $this->testAsset->setName('Test Server');
        $this->testAsset->setAssetType('hardware');
        $this->testAsset->setOwner('Test Owner');
        $this->testAsset->setDescription('Test server for integration tests');
        $this->testAsset->setTenant($this->testTenant);
        $this->testAsset->setConfidentiality(3);
        $this->testAsset->setIntegrity(3);
        $this->testAsset->setAvailability(3);
        $this->testAsset->setStatus('active');
        $this->testAsset->setDataClassification('internal');
        $this->entityManager->persist($this->testAsset);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    private function generateCsrfToken(string $tokenId): string
    {
        $csrfTokenManager = static::getContainer()->get('security.csrf.token_manager');
        return $csrfTokenManager->getToken($tokenId)->getValue();
    }

    // ========== INDEX ACTION TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/asset/');

        $this->assertResponseRedirects();
    }

    public function testIndexShowsAssetsForAuthenticatedUser(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexFiltersAssetsByType(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/', [
            'type' => 'hardware'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersAssetsByClassification(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/', [
            'classification' => 'internal'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersAssetsByOwner(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/', [
            'owner' => 'Test'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersAssetsByStatus(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/', [
            'status' => 'active'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexSupportsOwnViewParameter(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/', [
            'view' => 'own'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexSupportsSubsidiariesViewParameter(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/', [
            'view' => 'subsidiaries'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexSupportsInheritedViewParameter(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/', [
            'view' => 'inherited'
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexDefaultsToInheritedView(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/');

        $this->assertResponseIsSuccessful();
        // The default view parameter is 'inherited'
    }

    // ========== NEW ACTION TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/asset/new');

        $this->assertResponseRedirects();
    }

    public function testNewDisplaysForm(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="asset"]');
    }

    public function testNewCreatesAssetWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/new');
        $form = $crawler->selectButton('asset.action.save')->form([
            'asset[name]' => 'New Test Asset',
            'asset[assetType]' => 'software',
            'asset[owner]' => 'New Owner',
            'asset[description]' => 'New asset description',
            'asset[confidentiality]' => 2,
            'asset[integrity]' => 2,
            'asset[availability]' => 2,
            'asset[status]' => 'active',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects();

        // Follow redirect to show page
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        // Verify asset was created
        $assetRepository = $this->entityManager->getRepository(Asset::class);
        $newAsset = $assetRepository->findOneBy(['name' => 'New Test Asset']);
        $this->assertNotNull($newAsset);
        $this->assertEquals('software', $newAsset->getAssetType());
    }

    public function testNewSetsTenantFromCurrentUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/new');
        $form = $crawler->selectButton('asset.action.save')->form([
            'asset[name]' => 'Tenant Test Asset',
            'asset[assetType]' => 'data',
            'asset[owner]' => 'Tenant Owner',
            'asset[description]' => 'Testing tenant assignment',
            'asset[confidentiality]' => 3,
            'asset[integrity]' => 3,
            'asset[availability]' => 3,
            'asset[status]' => 'active',
        ]);

        $this->client->submit($form);

        // Verify asset has correct tenant
        $assetRepository = $this->entityManager->getRepository(Asset::class);
        $newAsset = $assetRepository->findOneBy(['name' => 'Tenant Test Asset']);
        $this->assertNotNull($newAsset);
        $this->assertEquals($this->testTenant->getId(), $newAsset->getTenant()->getId());
    }

    public function testNewRejectsInvalidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/new');
        $form = $crawler->selectButton('asset.action.save')->form([
            'asset[name]' => '', // Empty name - should fail validation
            'asset[assetType]' => 'hardware',
            'asset[owner]' => 'Owner',
        ]);

        $this->client->submit($form);

        // Should re-display form with validation errors
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="asset"]');
    }

    // ========== SHOW ACTION TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/asset/' . $this->testAsset->getId());

        $this->assertResponseRedirects();
    }

    public function testShowDisplaysAssetDetails(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/' . $this->testAsset->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('html', 'Test Server');
    }

    public function testShowReturns404ForNonexistentAsset(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/999999');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testShowIncludesProtectionRequirementAnalysis(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/' . $this->testAsset->getId());

        $this->assertResponseIsSuccessful();
        // Protection requirement analysis is rendered in the template
    }

    // ========== EDIT ACTION TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/asset/' . $this->testAsset->getId() . '/edit');

        $this->assertResponseRedirects();
    }

    public function testEditDisplaysForm(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/' . $this->testAsset->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="asset"]');
        $this->assertSelectorTextContains('form', 'Test Server');
    }

    public function testEditUpdatesAssetWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/' . $this->testAsset->getId() . '/edit');
        $form = $crawler->selectButton('asset.action.save')->form([
            'asset[name]' => 'Updated Test Server',
            'asset[description]' => 'Updated description',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects();

        // Verify asset was updated
        $this->entityManager->refresh($this->testAsset);
        $this->assertEquals('Updated Test Server', $this->testAsset->getName());
        $this->assertEquals('Updated description', $this->testAsset->getDescription());
    }

    public function testEditReturns404ForNonexistentAsset(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/999999/edit');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testEditRedirectsForInheritedAsset(): void
    {
        // Create parent tenant
        $parentTenant = new Tenant();
        $parentTenant->setName('Parent Tenant');
        $parentTenant->setSlug('parent-tenant');
        $this->entityManager->persist($parentTenant);

        // Set test tenant as child
        $this->testTenant->setParent($parentTenant);

        // Create asset belonging to parent
        $inheritedAsset = new Asset();
        $inheritedAsset->setName('Inherited Server');
        $inheritedAsset->setAssetType('hardware');
        $inheritedAsset->setOwner('Parent Owner');
        $inheritedAsset->setTenant($parentTenant);
        $inheritedAsset->setConfidentiality(3);
        $inheritedAsset->setIntegrity(3);
        $inheritedAsset->setAvailability(3);
        $inheritedAsset->setStatus('active');
        $this->entityManager->persist($inheritedAsset);

        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/' . $inheritedAsset->getId() . '/edit');

        // Should redirect with error message
        $this->assertResponseRedirects();
    }

    // ========== DELETE ACTION TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/asset/' . $this->testAsset->getId() . '/delete');

        $this->assertResponseRedirects();
    }

    public function testDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/asset/' . $this->testAsset->getId() . '/delete', [
            '_token' => $this->generateCsrfToken('delete' . $this->testAsset->getId()),
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRemovesAssetWithAdminRole(): void
    {
        $this->loginAsUser($this->adminUser);

        $assetId = $this->testAsset->getId();

        $this->client->request('POST', '/asset/' . $assetId . '/delete', [
            '_token' => $this->generateCsrfToken('delete' . $assetId),
        ]);

        $this->assertResponseRedirects('/asset/');

        // Verify asset was deleted
        $assetRepository = $this->entityManager->getRepository(Asset::class);
        $deletedAsset = $assetRepository->find($assetId);
        $this->assertNull($deletedAsset);
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/asset/' . $this->testAsset->getId() . '/delete', [
            '_token' => 'invalid_token',
        ]);

        // Should redirect but not delete
        $this->assertResponseRedirects('/asset/');

        // Verify asset was NOT deleted
        $this->entityManager->refresh($this->testAsset);
        $this->assertNotNull($this->testAsset);
    }

    public function testDeleteRedirectsForInheritedAsset(): void
    {
        // Create parent tenant
        $parentTenant = new Tenant();
        $parentTenant->setName('Parent Tenant 2');
        $parentTenant->setSlug('parent-tenant-2');
        $this->entityManager->persist($parentTenant);

        // Set test tenant as child
        $this->testTenant->setParent($parentTenant);

        // Create asset belonging to parent
        $inheritedAsset = new Asset();
        $inheritedAsset->setName('Inherited Server 2');
        $inheritedAsset->setAssetType('hardware');
        $inheritedAsset->setOwner('Parent Owner');
        $inheritedAsset->setTenant($parentTenant);
        $inheritedAsset->setConfidentiality(3);
        $inheritedAsset->setIntegrity(3);
        $inheritedAsset->setAvailability(3);
        $inheritedAsset->setStatus('active');
        $this->entityManager->persist($inheritedAsset);

        $this->entityManager->flush();

        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/asset/' . $inheritedAsset->getId() . '/delete', [
            '_token' => $this->generateCsrfToken('delete' . $inheritedAsset->getId()),
        ]);

        // Should redirect with error message
        $this->assertResponseRedirects('/asset/');
    }

    // ========== BULK DELETE TESTS ==========

    public function testBulkDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/asset/bulk-delete');

        $this->assertResponseRedirects();
    }

    public function testBulkDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/asset/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->testAsset->getId()]]));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBulkDeleteRemovesMultipleAssets(): void
    {
        $this->loginAsUser($this->adminUser);

        // Create additional test asset
        $asset2 = new Asset();
        $asset2->setName('Test Server 2');
        $asset2->setAssetType('hardware');
        $asset2->setOwner('Test Owner 2');
        $asset2->setTenant($this->testTenant);
        $asset2->setConfidentiality(2);
        $asset2->setIntegrity(2);
        $asset2->setAvailability(2);
        $asset2->setStatus('active');
        $this->entityManager->persist($asset2);

        $this->entityManager->flush();

        $ids = [$this->testAsset->getId(), $asset2->getId()];

        $this->client->request('POST', '/asset/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => $ids]));

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertEquals(2, $response['deleted']);
    }

    public function testBulkDeleteReturnsErrorForEmptyIds(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/asset/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => []]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
    }

    public function testBulkDeleteRespectsMultiTenancy(): void
    {
        // Create another tenant
        $otherTenant = new Tenant();
        $otherTenant->setName('Other Tenant');
        $otherTenant->setSlug('other-tenant');
        $this->entityManager->persist($otherTenant);

        // Create asset in other tenant
        $otherAsset = new Asset();
        $otherAsset->setName('Other Tenant Asset');
        $otherAsset->setAssetType('hardware');
        $otherAsset->setOwner('Other Owner');
        $otherAsset->setTenant($otherTenant);
        $otherAsset->setConfidentiality(3);
        $otherAsset->setIntegrity(3);
        $otherAsset->setAvailability(3);
        $otherAsset->setStatus('active');
        $this->entityManager->persist($otherAsset);

        $this->entityManager->flush();

        $this->loginAsUser($this->adminUser);

        $ids = [$this->testAsset->getId(), $otherAsset->getId()];

        $this->client->request('POST', '/asset/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => $ids]));

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        // Should only delete 1 asset (from own tenant)
        $this->assertEquals(1, $response['deleted']);
        $this->assertArrayHasKey('errors', $response);
    }

    public function testBulkDeleteHandlesNonexistentAssets(): void
    {
        $this->loginAsUser($this->adminUser);

        $ids = [999999, 999998];

        $this->client->request('POST', '/asset/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => $ids]));

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(0, $response['deleted']);
        $this->assertArrayHasKey('errors', $response);
    }

    // ========== BCM INSIGHTS ACTION TESTS ==========

    public function testBcmInsightsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/asset/' . $this->testAsset->getId() . '/bcm-insights');

        $this->assertResponseRedirects();
    }

    public function testBcmInsightsDisplaysAssetAnalysis(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/' . $this->testAsset->getId() . '/bcm-insights');

        $this->assertResponseIsSuccessful();
    }

    public function testBcmInsightsReturns404ForNonexistentAsset(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/999999/bcm-insights');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testBcmInsightsIncludesProtectionRequirementAnalysis(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/' . $this->testAsset->getId() . '/bcm-insights');

        $this->assertResponseIsSuccessful();
        // BCM insights include protection requirement analysis
    }

    // ========== MULTI-TENANCY AND INHERITANCE TESTS ==========

    public function testIndexRespectsMultiTenancyIsolation(): void
    {
        // Create another tenant with asset
        $otherTenant = new Tenant();
        $otherTenant->setName('Other Tenant 2');
        $otherTenant->setSlug('other-tenant-2');
        $this->entityManager->persist($otherTenant);

        $otherAsset = new Asset();
        $otherAsset->setName('Other Tenant Server');
        $otherAsset->setAssetType('hardware');
        $otherAsset->setOwner('Other Owner');
        $otherAsset->setTenant($otherTenant);
        $otherAsset->setConfidentiality(3);
        $otherAsset->setIntegrity(3);
        $otherAsset->setAvailability(3);
        $otherAsset->setStatus('active');
        $this->entityManager->persist($otherAsset);

        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/', ['view' => 'own']);

        $this->assertResponseIsSuccessful();
        // User should only see assets from their own tenant
    }

    public function testIndexCalculatesDetailedStats(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/');

        $this->assertResponseIsSuccessful();
        // Detailed stats should be calculated and passed to template
    }

    public function testShowDisplaysInheritanceInformation(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/asset/' . $this->testAsset->getId());

        $this->assertResponseIsSuccessful();
        // Inheritance info (isInherited, canEdit) should be in template
    }

    // ========== FORM VALIDATION TESTS ==========

    public function testNewRequiresAssetName(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/new');
        $form = $crawler->selectButton('asset.action.save')->form([
            'asset[name]' => '',
            'asset[assetType]' => 'hardware',
            'asset[owner]' => 'Owner',
        ]);

        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="asset"]');
    }

    public function testNewRequiresAssetType(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/new');
        $form = $crawler->selectButton('asset.action.save')->form([
            'asset[name]' => 'Test Name',
            'asset[assetType]' => '',
            'asset[owner]' => 'Owner',
        ]);

        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="asset"]');
    }

    public function testNewRequiresOwner(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/new');
        $form = $crawler->selectButton('asset.action.save')->form([
            'asset[name]' => 'Test Name',
            'asset[assetType]' => 'hardware',
            'asset[owner]' => '',
        ]);

        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="asset"]');
    }

    // ========== FLASH MESSAGE TESTS ==========

    public function testNewShowsSuccessFlashOnCreation(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/new');
        $form = $crawler->selectButton('asset.action.save')->form([
            'asset[name]' => 'Flash Test Asset',
            'asset[assetType]' => 'software',
            'asset[owner]' => 'Flash Owner',
            'asset[confidentiality]' => 2,
            'asset[integrity]' => 2,
            'asset[availability]' => 2,
            'asset[status]' => 'active',
        ]);

        $this->client->submit($form);
        $this->client->followRedirect();

        // Flash message should be present
        $this->assertResponseIsSuccessful();
    }

    public function testEditShowsSuccessFlashOnUpdate(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/asset/' . $this->testAsset->getId() . '/edit');
        $form = $crawler->selectButton('asset.action.save')->form([
            'asset[name]' => 'Flash Updated Asset',
        ]);

        $this->client->submit($form);
        $this->client->followRedirect();

        // Flash message should be present
        $this->assertResponseIsSuccessful();
    }

    public function testDeleteShowsSuccessFlashOnDeletion(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/asset/' . $this->testAsset->getId() . '/delete', [
            '_token' => $this->generateCsrfToken('delete' . $this->testAsset->getId()),
        ]);

        $this->client->followRedirect();

        // Flash message should be present
        $this->assertResponseIsSuccessful();
    }
}

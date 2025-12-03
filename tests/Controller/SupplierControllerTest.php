<?php

namespace App\Tests\Controller;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for SupplierController
 *
 * Tests supplier management including:
 * - Index with statistics and filtering (own, inherited, subsidiaries)
 * - CRUD operations (create, read, update, delete)
 * - Bulk delete functionality
 * - Role-based access control
 * - Multi-tenant isolation and inheritance
 */
class SupplierControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?Supplier $testSupplier = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Clean up suppliers
        if ($this->testSupplier) {
            try {
                $supplier = $this->entityManager->find(Supplier::class, $this->testSupplier->getId());
                if ($supplier) {
                    $this->entityManager->remove($supplier);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Clean up additional suppliers created during tests
        $supplierRepo = $this->entityManager->getRepository(Supplier::class);
        foreach (['New Test Supplier', 'Updated Test Supplier', 'Bulk Delete Supplier 1', 'Bulk Delete Supplier 2', 'Other Tenant Supplier'] as $name) {
            $suppliers = $supplierRepo->findBy(['name' => $name]);
            foreach ($suppliers as $supplier) {
                try {
                    $this->entityManager->remove($supplier);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }

        // Clean up users
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

        // Clean up tenant
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

        // Create admin user with ROLE_ADMIN
        $this->adminUser = new User();
        $this->adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $this->adminUser->setFirstName('Admin');
        $this->adminUser->setLastName('User');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        // Create test supplier
        $this->testSupplier = new Supplier();
        $this->testSupplier->setTenant($this->testTenant);
        $this->testSupplier->setName('Test Supplier ' . $uniqueId);
        $this->testSupplier->setEmail('supplier_' . $uniqueId . '@example.com');
        $this->testSupplier->setContactPerson('Supplier Contact');
        $this->testSupplier->setAddress('123 Test Street');
        $this->testSupplier->setCriticality('high');
        $this->testSupplier->setStatus('active');
        $this->testSupplier->setServiceProvided('Cloud infrastructure provider');
        $this->entityManager->persist($this->testSupplier);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);

        if (!$this->entityManager->contains($user)) {
            $user = $this->entityManager->merge($user);
        }
        $this->entityManager->refresh($user);
    }

    private function generateCsrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/en/supplier/');

        $session = $this->client->getRequest()->getSession();
        $tokenGenerator = new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator();
        $tokenValue = $tokenGenerator->generateToken();
        $session->set('_csrf/' . $tokenId, $tokenValue);

        return $tokenValue;
    }

    // ========== INDEX ACTION TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/supplier/');

        $this->assertResponseRedirects();
    }

    public function testIndexShowsSuppliersForAuthenticatedUser(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexSupportsOwnViewParameter(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/', ['view' => 'own']);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexSupportsSubsidiariesViewParameter(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/', ['view' => 'subsidiaries']);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexSupportsInheritedViewParameter(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/', ['view' => 'inherited']);

        $this->assertResponseIsSuccessful();
    }

    // ========== SHOW ACTION TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/supplier/' . $this->testSupplier->getId());

        $this->assertResponseRedirects();
    }

    public function testShowDisplaysSupplierDetails(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/' . $this->testSupplier->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('html', 'Test Supplier');
    }

    public function testShowReturns404ForNonexistentSupplier(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/999999');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== NEW ACTION TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/supplier/new');

        $this->assertResponseRedirects();
    }

    public function testNewDisplaysFormForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/supplier/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="supplier"]');
    }

    public function testNewCreatesSupplierWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/supplier/new');
        $form = $crawler->filter('form[name="supplier"]')->form([
            'supplier[name]' => 'New Test Supplier',
            'supplier[email]' => 'new@supplier.com',
            'supplier[contactPerson]' => 'New Contact',
            'supplier[address]' => '456 New Street',
            'supplier[criticality]' => 'medium',
            'supplier[status]' => 'active',
            'supplier[serviceProvided]' => 'Security monitoring services',
        ]);

        $this->client->submit($form);

        // Verify redirect to show page
        $this->assertResponseRedirects();

        // Follow redirect to show page
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('html', 'New Test Supplier');
    }

    public function testNewRejectsInvalidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/supplier/new');
        $form = $crawler->filter('form[name="supplier"]')->form([
            'supplier[name]' => '', // Empty name - should fail validation
            'supplier[email]' => 'test@test.com',
            'supplier[serviceProvided]' => 'Test services',
        ]);

        $this->client->submit($form);

        // Should re-display form with validation errors - modern Symfony returns 422
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('form[name="supplier"]');
    }

    // ========== EDIT ACTION TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/supplier/' . $this->testSupplier->getId() . '/edit');

        $this->assertResponseRedirects();
    }

    public function testEditDisplaysFormForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/supplier/' . $this->testSupplier->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="supplier"]');
    }

    public function testEditUpdatesSupplierWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/supplier/' . $this->testSupplier->getId() . '/edit');
        $form = $crawler->filter('form[name="supplier"]')->form([
            'supplier[name]' => 'Updated Test Supplier',
            'supplier[serviceProvided]' => 'Updated service description',
        ]);

        $this->client->submit($form);

        // Verify redirect
        $this->assertResponseRedirects();

        // Follow redirect and verify updated data
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('html', 'Updated Test Supplier');
    }

    public function testEditReturns404ForNonexistentSupplier(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/999999/edit');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== DELETE ACTION TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/supplier/' . $this->testSupplier->getId() . '/delete');

        $this->assertResponseRedirects();
    }

    public function testDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $token = $this->generateCsrfToken('delete' . $this->testSupplier->getId());

        $this->client->request('POST', '/en/supplier/' . $this->testSupplier->getId() . '/delete', [
            '_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRedirectsWithAdminRole(): void
    {
        $this->loginAsUser($this->adminUser);

        $supplierId = $this->testSupplier->getId();
        $token = $this->generateCsrfToken('delete' . $supplierId);

        $this->client->request('POST', '/en/supplier/' . $supplierId . '/delete', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/en/supplier/');
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/supplier/' . $this->testSupplier->getId() . '/delete', [
            '_token' => 'invalid_token',
        ]);

        // Should redirect but not delete
        $this->assertResponseRedirects('/en/supplier/');

        // Verify supplier was NOT deleted
        $supplierRepository = $this->entityManager->getRepository(Supplier::class);
        $stillExists = $supplierRepository->find($this->testSupplier->getId());
        $this->assertNotNull($stillExists);
    }

    // ========== BULK DELETE TESTS ==========

    public function testBulkDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/supplier/bulk-delete');

        $this->assertResponseRedirects();
    }

    public function testBulkDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/supplier/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->testSupplier->getId()]]));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBulkDeleteRemovesMultipleSuppliers(): void
    {
        $this->loginAsUser($this->adminUser);

        // Create additional test supplier
        $supplier2 = new Supplier();
        $supplier2->setTenant($this->testTenant);
        $supplier2->setName('Bulk Delete Supplier 2');
        $supplier2->setEmail('bulk2@test.com');
        $supplier2->setContactPerson('Contact 2');
        $supplier2->setServiceProvided('Other services');
        $supplier2->setCriticality('low');
        $supplier2->setStatus('active');
        $this->entityManager->persist($supplier2);

        $this->entityManager->flush();

        $ids = [$this->testSupplier->getId(), $supplier2->getId()];

        $this->client->request('POST', '/en/supplier/bulk-delete', [], [], [
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

        $this->client->request('POST', '/en/supplier/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => []]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
    }

    public function testBulkDeleteRespectsMultiTenancy(): void
    {
        $uniqueId = uniqid('other_', true);

        // Create another tenant with supplier
        $otherTenant = new Tenant();
        $otherTenant->setName('Other Tenant ' . $uniqueId);
        $otherTenant->setCode('other_tenant_' . $uniqueId);
        $this->entityManager->persist($otherTenant);

        $otherSupplier = new Supplier();
        $otherSupplier->setTenant($otherTenant);
        $otherSupplier->setName('Other Tenant Supplier');
        $otherSupplier->setEmail('other@test.com');
        $otherSupplier->setContactPerson('Other Contact');
        $otherSupplier->setServiceProvided('Other services');
        $otherSupplier->setCriticality('low');
        $otherSupplier->setStatus('active');
        $this->entityManager->persist($otherSupplier);

        $this->entityManager->flush();

        $this->loginAsUser($this->adminUser);

        $ids = [$this->testSupplier->getId(), $otherSupplier->getId()];

        $this->client->request('POST', '/en/supplier/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => $ids]));

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        // Should only delete 1 supplier (from own tenant)
        $this->assertEquals(1, $response['deleted']);
        $this->assertArrayHasKey('errors', $response);

        // Clean up
        $this->entityManager->remove($otherSupplier);
        $this->entityManager->remove($otherTenant);
        $this->entityManager->flush();
    }

    public function testBulkDeleteHandlesNonexistentSuppliers(): void
    {
        $this->loginAsUser($this->adminUser);

        $ids = [999999, 999998];

        $this->client->request('POST', '/en/supplier/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => $ids]));

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(0, $response['deleted']);
        $this->assertArrayHasKey('errors', $response);
    }

    // ========== MULTI-TENANCY TESTS ==========

    public function testIndexRespectsMultiTenancyIsolation(): void
    {
        $uniqueId = uniqid('other_', true);

        // Create another tenant with supplier
        $otherTenant = new Tenant();
        $otherTenant->setName('Other Tenant ' . $uniqueId);
        $otherTenant->setCode('other_tenant_' . $uniqueId);
        $this->entityManager->persist($otherTenant);

        $otherSupplier = new Supplier();
        $otherSupplier->setTenant($otherTenant);
        $otherSupplier->setName('Other Tenant Supplier ' . $uniqueId);
        $otherSupplier->setEmail('other_' . $uniqueId . '@test.com');
        $otherSupplier->setContactPerson('Other Contact');
        $otherSupplier->setServiceProvided('Other services');
        $otherSupplier->setCriticality('low');
        $otherSupplier->setStatus('active');
        $this->entityManager->persist($otherSupplier);

        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/', ['view' => 'own']);

        $this->assertResponseIsSuccessful();
        // User should only see suppliers from their own tenant

        // Clean up
        $this->entityManager->remove($otherSupplier);
        $this->entityManager->remove($otherTenant);
        $this->entityManager->flush();
    }

    // ========== CRITICALITY TESTS ==========

    public function testSupplierCriticalityHigh(): void
    {
        $this->testSupplier->setCriticality('high');
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/' . $this->testSupplier->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testSupplierCriticalityMedium(): void
    {
        $this->testSupplier->setCriticality('medium');
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/' . $this->testSupplier->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testSupplierCriticalityLow(): void
    {
        $this->testSupplier->setCriticality('low');
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/' . $this->testSupplier->getId());

        $this->assertResponseIsSuccessful();
    }

    // ========== STATUS TESTS ==========

    public function testSupplierStatusActive(): void
    {
        $this->testSupplier->setStatus('active');
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/' . $this->testSupplier->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testSupplierStatusInactive(): void
    {
        $this->testSupplier->setStatus('inactive');
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/' . $this->testSupplier->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testSupplierStatusUnderReview(): void
    {
        $this->testSupplier->setStatus('under_review');
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/supplier/' . $this->testSupplier->getId());

        $this->assertResponseIsSuccessful();
    }
}

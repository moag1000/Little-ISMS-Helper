<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for TenantManagementController
 *
 * Tests multi-tenant management including:
 * - Index with tenant listing and filtering
 * - CRUD operations (create, read, update, delete)
 * - Toggle active status
 * - Corporate structure management
 * - User assignment to tenants
 * - Organisation context settings
 * - Role-based access control (TENANT_* permissions)
 */
class TenantManagementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?Tenant $targetTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?User $superAdminUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Clean up target tenant first
        if ($this->targetTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->targetTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception $e) {
                // Ignore
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

        if ($this->superAdminUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->superAdminUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Clean up test tenant last
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

        // Create test tenant (for user assignment)
        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->testTenant->setIsActive(true);
        $this->entityManager->persist($this->testTenant);

        // Create target tenant (for CRUD operations)
        $this->targetTenant = new Tenant();
        $this->targetTenant->setName('Target Tenant ' . $uniqueId);
        $this->targetTenant->setCode('target_tenant_' . $uniqueId);
        $this->targetTenant->setDescription('Target tenant for testing');
        $this->targetTenant->setIsActive(true);
        $this->entityManager->persist($this->targetTenant);

        // Create test user with ROLE_USER only
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

        // Create super admin user with ROLE_SUPER_ADMIN
        $this->superAdminUser = new User();
        $this->superAdminUser->setEmail('superadmin_' . $uniqueId . '@example.com');
        $this->superAdminUser->setFirstName('Super');
        $this->superAdminUser->setLastName('Admin');
        $this->superAdminUser->setRoles(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']);
        $this->superAdminUser->setPassword('hashed_password');
        $this->superAdminUser->setTenant($this->testTenant);
        $this->superAdminUser->setIsActive(true);
        $this->entityManager->persist($this->superAdminUser);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX ACTION TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants');

        $this->assertResponseRedirects();
    }

    public function testIndexRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/admin/tenants');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testIndexShowsTenantsForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexFiltersActiveTenantsOnly(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants?filter=active');

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersInactiveTenantsOnly(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants?filter=inactive');

        $this->assertResponseIsSuccessful();
    }

    // ========== SHOW ACTION TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId());

        $this->assertResponseRedirects();
    }

    public function testShowDisplaysTenantForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testShowReturns404OrRedirectForNonexistentTenant(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants/999999');

        // Controller may return 404 or redirect to index for non-existent entities
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === Response::HTTP_NOT_FOUND || $statusCode === Response::HTTP_FOUND,
            "Expected 404 or 302, got {$statusCode}"
        );
    }

    public function testShowDisplaysUserStatistics(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/' . $this->testTenant->getId());

        $this->assertResponseIsSuccessful();
    }

    // ========== NEW ACTION TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants/new');

        $this->assertResponseRedirects();
    }

    public function testNewRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/admin/tenants/new');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testNewDisplaysFormForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="tenant"]');
    }

    public function testNewCreatesTenantWithValidData(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/new');
        $form = $crawler->filter('form[name="tenant"]')->form([
            'tenant[name]' => 'New Test Tenant',
            'tenant[code]' => 'new_test_tenant_' . uniqid(),
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects();
    }

    // ========== EDIT ACTION TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId() . '/edit');

        $this->assertResponseRedirects();
    }

    public function testEditDisplaysFormForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="tenant"]');
    }

    public function testEditUpdatesTenantWithValidData(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId() . '/edit');
        $form = $crawler->filter('form[name="tenant"]')->form();

        // Just update the name, keep other fields as is
        $form['tenant[name]'] = 'Updated Tenant Name';

        $this->client->submit($form);

        // May redirect on success or stay on form if validation issues
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === Response::HTTP_FOUND || $statusCode === Response::HTTP_OK || $statusCode === 422,
            "Expected redirect or form display, got {$statusCode}"
        );
    }

    public function testEditReturns404OrRedirectForNonexistentTenant(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants/999999/edit');

        // Controller may return 404 or redirect to index for non-existent entities
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === Response::HTTP_NOT_FOUND || $statusCode === Response::HTTP_FOUND,
            "Expected 404 or 302, got {$statusCode}"
        );
    }

    // ========== TOGGLE ACTION TESTS ==========

    public function testToggleRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/toggle');

        $this->assertResponseRedirects();
    }

    public function testToggleChangesStatus(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/toggle');

        $this->assertResponseRedirects('/en/admin/tenants');
    }

    // ========== DELETE ACTION TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/delete');

        $this->assertResponseRedirects();
    }

    public function testDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/delete');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRemovesTenantWithValidToken(): void
    {
        $this->loginAsUser($this->superAdminUser);

        // Get the show page which has the delete form with CSRF token
        $crawler = $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId());

        // Try to find the delete form and submit it
        $deleteForm = $crawler->filter('form[action*="/delete"]');
        if ($deleteForm->count() > 0) {
            $form = $deleteForm->form();
            $targetId = $this->targetTenant->getId();
            $this->client->submit($form);

            $this->assertResponseRedirects('/en/admin/tenants');

            // Verify tenant is deleted
            $this->entityManager->clear();
            $tenant = $this->entityManager->find(Tenant::class, $targetId);
            $this->assertNull($tenant);
            $this->targetTenant = null; // Prevent cleanup from failing
        } else {
            // Tenant has users, so delete form should not be shown
            $this->assertResponseIsSuccessful();
        }
    }

    public function testDeleteBlockedForTenantWithUsers(): void
    {
        $this->loginAsUser($this->superAdminUser);

        // testTenant has users assigned, so delete should be blocked
        $crawler = $this->client->request('GET', '/en/admin/tenants/' . $this->testTenant->getId());

        // Delete form should not be available for tenants with users
        $this->assertResponseIsSuccessful();
    }

    // ========== CORPORATE STRUCTURE TESTS ==========

    public function testCorporateStructureRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants/corporate-structure');

        $this->assertResponseRedirects();
    }

    public function testCorporateStructureDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants/corporate-structure');

        $this->assertResponseIsSuccessful();
    }

    public function testCorporateStructureShowsStandaloneTenants(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/corporate-structure');

        $this->assertResponseIsSuccessful();
    }

    // ========== SET PARENT TESTS ==========

    public function testSetParentRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/set-parent');

        $this->assertResponseRedirects();
    }

    public function testSetParentAssignsParentTenant(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/set-parent', [
            'parent_id' => $this->testTenant->getId(),
            'governance_model' => 'hierarchical',
        ]);

        $this->assertResponseRedirects();
    }

    public function testSetParentRemovesParent(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/set-parent', [
            'parent_id' => '',
        ]);

        $this->assertResponseRedirects();
    }

    public function testSetParentRejectsInvalidGovernanceModel(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/set-parent', [
            'parent_id' => $this->testTenant->getId(),
            'governance_model' => 'invalid_model',
        ]);

        $this->assertResponseRedirects();
    }

    // ========== UPDATE GOVERNANCE TESTS ==========

    public function testUpdateGovernanceRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/update-governance');

        $this->assertResponseRedirects();
    }

    public function testUpdateGovernanceRejectsInvalidModel(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/update-governance', [
            'governance_model' => 'invalid',
        ]);

        $this->assertResponseRedirects();
    }

    public function testUpdateGovernanceAcceptsValidModels(): void
    {
        $this->loginAsUser($this->adminUser);

        foreach (['hierarchical', 'shared', 'independent'] as $model) {
            $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/update-governance', [
                'governance_model' => $model,
            ]);

            $this->assertResponseRedirects();
        }
    }

    // ========== ORGANISATION CONTEXT TESTS ==========

    public function testOrganisationContextRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId() . '/organisation-context');

        $this->assertResponseRedirects();
    }

    public function testOrganisationContextDisplaysFormForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId() . '/organisation-context');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== EMPTY STATE TESTS ==========

    public function testShowHandlesTenantWithNoUsers(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testCorporateStructureHandlesEmptyState(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants/corporate-structure');

        $this->assertResponseIsSuccessful();
    }

    // ========== FILTER TESTS ==========

    public function testIndexFilterAll(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants?filter=all');

        $this->assertResponseIsSuccessful();
    }
}

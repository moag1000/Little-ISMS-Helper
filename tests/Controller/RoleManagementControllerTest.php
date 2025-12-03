<?php

namespace App\Tests\Controller;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for RoleManagementController
 *
 * Tests role-based access control management including:
 * - Index with role listing
 * - CRUD operations
 * - Role comparison
 * - Role templates
 */
class RoleManagementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?Role $testRole = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testRole) {
            try {
                $role = $this->entityManager->find(Role::class, $this->testRole->getId());
                if ($role) {
                    $this->entityManager->remove($role);
                }
            } catch (\Exception $e) {
                // Ignore
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
            // Ignore
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('test_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('testuser_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->adminUser = new User();
        $this->adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $this->adminUser->setFirstName('Admin');
        $this->adminUser->setLastName('User');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        $this->testRole = new Role();
        $this->testRole->setName('Test Role ' . $uniqueId);
        $this->testRole->setDescription('Test role description');
        $this->entityManager->persist($this->testRole);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles');
        $this->assertResponseRedirects();
    }

    public function testIndexRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/roles');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testIndexDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/roles');
        $this->assertResponseIsSuccessful();
    }

    // ========== SHOW TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles/' . $this->testRole->getId());
        $this->assertResponseRedirects();
    }

    public function testShowDisplaysRole(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/roles/' . $this->testRole->getId());
        $this->assertResponseIsSuccessful();
    }

    // ========== NEW TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles/new');
        $this->assertResponseRedirects();
    }

    public function testNewRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/roles/new');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testNewDisplaysForm(): void
    {
        $this->loginAsUser($this->adminUser);
        $crawler = $this->client->request('GET', '/en/admin/roles/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== EDIT TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles/' . $this->testRole->getId() . '/edit');
        $this->assertResponseRedirects();
    }

    public function testEditDisplaysForm(): void
    {
        $this->loginAsUser($this->adminUser);
        $crawler = $this->client->request('GET', '/en/admin/roles/' . $this->testRole->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== DELETE TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/roles/' . $this->testRole->getId() . '/delete');
        $this->assertResponseRedirects();
    }

    public function testDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/roles/' . $this->testRole->getId() . '/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== COMPARE TESTS ==========

    public function testCompareRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles/compare');
        $this->assertResponseRedirects();
    }

    public function testCompareDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/roles/compare');
        $this->assertResponseIsSuccessful();
    }

    // ========== TEMPLATES TESTS ==========

    public function testTemplatesRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles/templates');
        $this->assertResponseRedirects();
    }

    public function testTemplatesDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/roles/templates');
        $this->assertResponseIsSuccessful();
    }

    // ========== EMPTY STATE TESTS ==========

    public function testIndexHandlesNoRoles(): void
    {
        // Remove test role
        $this->entityManager->remove($this->testRole);
        $this->entityManager->flush();
        $this->testRole = null;

        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/roles');
        $this->assertResponseIsSuccessful();
    }
}

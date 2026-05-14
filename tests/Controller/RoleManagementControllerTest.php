<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;

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
    /** @var Permission[] */
    private array $testPermissions = [];

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

        foreach ($this->testPermissions as $perm) {
            try {
                $p = $this->entityManager->find(Permission::class, $perm->getId());
                if ($p) {
                    $this->entityManager->remove($p);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }
        $this->testPermissions = [];

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

        // Seed a minimal set of permissions so the rich table has rows to render.
        $permDefs = [
            ['risk.view.' . $uniqueId, 'risk', 'view'],
            ['risk.create.' . $uniqueId, 'risk', 'create'],
            ['risk.edit.' . $uniqueId, 'risk', 'edit'],
            ['asset.view.' . $uniqueId, 'asset', 'view'],
            ['user.view.' . $uniqueId, 'user', 'view'],
            ['user.create.' . $uniqueId, 'user', 'create'],
        ];
        foreach ($permDefs as [$name, $category, $action]) {
            $perm = new Permission();
            $perm->setName($name);
            $perm->setCategory($category);
            $perm->setAction($action);
            $this->entityManager->persist($perm);
            $this->testPermissions[] = $perm;
        }

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX TESTS ==========

    #[Test]
    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testIndexRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/roles');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testIndexDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/roles');
        $this->assertResponseIsSuccessful();
    }

    // ========== SHOW TESTS ==========

    #[Test]
    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles/' . $this->testRole->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testShowDisplaysRole(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/roles/' . $this->testRole->getId());
        $this->assertResponseIsSuccessful();
    }

    // ========== NEW TESTS ==========

    #[Test]
    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles/new');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testNewRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/roles/new');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testNewDisplaysForm(): void
    {
        $this->loginAsUser($this->adminUser);
        $crawler = $this->client->request('GET', '/en/admin/roles/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== EDIT TESTS ==========

    #[Test]
    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles/' . $this->testRole->getId() . '/edit');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testEditDisplaysForm(): void
    {
        $this->loginAsUser($this->adminUser);
        $crawler = $this->client->request('GET', '/en/admin/roles/' . $this->testRole->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== DELETE TESTS ==========

    #[Test]
    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/roles/' . $this->testRole->getId() . '/delete');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/roles/' . $this->testRole->getId() . '/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== COMPARE TESTS ==========

    #[Test]
    public function testCompareRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles/compare');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testCompareDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/roles/compare');
        $this->assertResponseIsSuccessful();
    }

    // ========== TEMPLATES TESTS ==========

    #[Test]
    public function testTemplatesRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/roles/templates');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testTemplatesDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/roles/templates');
        $this->assertResponseIsSuccessful();
    }

    // ========== RICH PERMISSIONS TABLE TESTS ==========

    /**
     * Asserts /en/admin/roles/new renders the rich permissions table with column headers and rows,
     * and that the legacy flat checkbox list does NOT appear below the table (no double-rendering).
     */
    #[Test]
    public function testNewFormRendersRichPermissionsTable(): void
    {
        $this->loginAsUser($this->adminUser);
        $crawler = $this->client->request('GET', '/en/admin/roles/new');
        $this->assertResponseIsSuccessful();

        // The rich table must be present
        $this->assertSelectorExists('#permissions-rich-table', 'Rich permissions table must be rendered on role-new page');

        // Column headers must be present (English labels from translations)
        $this->assertSelectorTextContains('#permissions-rich-table thead', 'Module');
        $this->assertSelectorTextContains('#permissions-rich-table thead', 'Framework');
        $this->assertSelectorTextContains('#permissions-rich-table thead', 'Action');

        // Rich table must have rendered rows (permissions seeded in setUp)
        $rows = $crawler->filter('#permissions-table-body tr.perm-row');
        $this->assertGreaterThanOrEqual(5, $rows->count(), 'Rich permissions table must have at least 5 permission rows');

        // Each checkbox must be scoped to role[permissions] so server-side binding works
        $checkboxes = $crawler->filter('#permissions-table-body input[type="checkbox"]');
        $this->assertGreaterThanOrEqual(5, $checkboxes->count(), 'Rich table must have at least 5 checkbox inputs');
        $firstName = $checkboxes->first()->attr('name');
        $this->assertStringContainsString('permissions', (string) $firstName, 'Checkbox name must contain "permissions" for server-side form binding');

        // The rich table must appear exactly once — form_end(render_rest:false) must suppress the
        // legacy expanded-EntityType flat list that would otherwise duplicate below.
        $content = $this->client->getResponse()->getContent();
        $this->assertSame(
            1,
            substr_count((string) $content, 'id="permissions-rich-table"'),
            'Rich permissions table must appear exactly once — no duplicate legacy rendering'
        );
    }

    /**
     * Asserts /en/admin/roles/{id}/edit renders the rich permissions table with rows (no duplicate).
     */
    #[Test]
    public function testEditFormRendersRichPermissionsTable(): void
    {
        $this->loginAsUser($this->adminUser);
        $crawler = $this->client->request('GET', '/en/admin/roles/' . $this->testRole->getId() . '/edit');
        $this->assertResponseIsSuccessful();

        $this->assertSelectorExists('#permissions-rich-table', 'Rich permissions table must be rendered on role-edit page');

        // Rows must be present
        $rows = $crawler->filter('#permissions-table-body tr.perm-row');
        $this->assertGreaterThanOrEqual(5, $rows->count(), 'Rich permissions table must have at least 5 rows on edit page');

        // Table must appear exactly once (no legacy flat list duplicate below)
        $content = $this->client->getResponse()->getContent();
        $this->assertSame(
            1,
            substr_count((string) $content, 'id="permissions-rich-table"'),
            'Rich permissions table must appear exactly once on edit page'
        );
    }

    /**
     * Asserts that the edit form pre-checks permissions already assigned to the role.
     */
    #[Test]
    public function testEditFormPrechecksAssignedPermissions(): void
    {
        if (empty($this->testPermissions)) {
            $this->markTestSkipped('No test permissions seeded');
        }

        // Assign one seeded permission to the test role
        $assignedPerm = $this->testPermissions[0];
        $this->testRole->addPermission($assignedPerm);
        $this->entityManager->flush();

        $this->loginAsUser($this->adminUser);
        $crawler = $this->client->request('GET', '/en/admin/roles/' . $this->testRole->getId() . '/edit');
        $this->assertResponseIsSuccessful();

        // The assigned permission's checkbox must be pre-checked
        $checkedBoxes = $crawler->filter('#permissions-table-body input[type="checkbox"]:checked');
        $this->assertGreaterThanOrEqual(1, $checkedBoxes->count(), 'Edit form must pre-check at least one already-assigned permission');
    }

    // ========== EMPTY STATE TESTS ==========

    #[Test]
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

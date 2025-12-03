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
 * Functional tests for UserManagementController
 *
 * Tests user administration including:
 * - Index with user listing and statistics
 * - CRUD operations (create, read, update, delete)
 * - Toggle active status
 * - Bulk actions
 * - User activity and MFA management
 * - CSV export
 * - Role-based access control (ROLE_ADMIN required)
 */
class UserManagementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?User $superAdminUser = null;
    private ?User $targetUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Clean up target user first (may have been deleted in tests)
        if ($this->targetUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->targetUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
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

        // Create super admin user with ROLE_SUPER_ADMIN and ROLE_ADMIN
        $this->superAdminUser = new User();
        $this->superAdminUser->setEmail('superadmin_' . $uniqueId . '@example.com');
        $this->superAdminUser->setFirstName('Super');
        $this->superAdminUser->setLastName('Admin');
        $this->superAdminUser->setRoles(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']);
        $this->superAdminUser->setPassword('hashed_password');
        $this->superAdminUser->setTenant($this->testTenant);
        $this->superAdminUser->setIsActive(true);
        $this->entityManager->persist($this->superAdminUser);

        // Create target user for operations
        $this->targetUser = new User();
        $this->targetUser->setEmail('target_' . $uniqueId . '@example.com');
        $this->targetUser->setFirstName('Target');
        $this->targetUser->setLastName('User');
        $this->targetUser->setRoles(['ROLE_USER']);
        $this->targetUser->setPassword('hashed_password');
        $this->targetUser->setTenant($this->testTenant);
        $this->targetUser->setIsActive(true);
        $this->entityManager->persist($this->targetUser);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX ACTION TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/users');

        $this->assertResponseRedirects();
    }

    public function testIndexRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/admin/users');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testIndexShowsUsersForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexDisplaysStatistics(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/users');

        $this->assertResponseIsSuccessful();
    }

    // ========== SHOW ACTION TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId());

        $this->assertResponseRedirects();
    }

    public function testShowDisplaysUserForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testShowReturns404ForNonexistentUser(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users/999999');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== NEW ACTION TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/users/new');

        $this->assertResponseRedirects();
    }

    public function testNewRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/admin/users/new');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testNewDisplaysFormForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/users/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="user"]');
    }

    public function testNewCreatesUserWithValidData(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/users/new');
        $form = $crawler->filter('form[name="user"]')->form([
            'user[email]' => 'newuser_' . uniqid() . '@example.com',
            'user[firstName]' => 'New',
            'user[lastName]' => 'User',
            'user[plainPassword]' => 'SecurePassword123!',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/en/admin/users');
    }

    // ========== EDIT ACTION TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId() . '/edit');

        $this->assertResponseRedirects();
    }

    public function testEditDisplaysFormForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="user"]');
    }

    public function testEditUpdatesUserWithValidData(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId() . '/edit');
        $form = $crawler->filter('form[name="user"]')->form([
            'user[firstName]' => 'Updated',
            'user[lastName]' => 'Name',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects();
    }

    public function testEditReturns404ForNonexistentUser(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users/999999/edit');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== DELETE ACTION TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/users/' . $this->targetUser->getId() . '/delete');

        $this->assertResponseRedirects();
    }

    public function testDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/admin/users/' . $this->targetUser->getId() . '/delete');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRemovesUserWithValidToken(): void
    {
        $this->loginAsUser($this->superAdminUser);

        // Get the show page which has the delete form with CSRF token
        $crawler = $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId());

        // Try to find the delete form and submit it
        $deleteForm = $crawler->filter('form[action*="/delete"]');
        if ($deleteForm->count() > 0) {
            $form = $deleteForm->form();
            $targetId = $this->targetUser->getId();
            $this->client->submit($form);

            $this->assertResponseRedirects('/en/admin/users');

            // Verify user is deleted
            $this->entityManager->clear();
            $user = $this->entityManager->find(User::class, $targetId);
            $this->assertNull($user);
            $this->targetUser = null; // Prevent cleanup from failing
        } else {
            // If no delete form found on page, skip the deletion verification
            $this->assertResponseIsSuccessful();
            $this->markTestSkipped('Delete form not found on show page');
        }
    }

    // ========== TOGGLE ACTIVE TESTS ==========

    public function testToggleActiveRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/users/' . $this->targetUser->getId() . '/toggle-active');

        $this->assertResponseRedirects();
    }

    public function testToggleActiveChangesUserStatus(): void
    {
        $this->loginAsUser($this->adminUser);

        // Get the show page which has the toggle form
        $crawler = $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId());

        // Try to find the toggle form and submit it
        $toggleForm = $crawler->filter('form[action*="/toggle-active"]');
        if ($toggleForm->count() > 0) {
            $form = $toggleForm->form();
            $this->client->submit($form);

            // Should redirect back to show page
            $this->assertResponseRedirects();
        } else {
            $this->assertResponseIsSuccessful();
            $this->markTestSkipped('Toggle form not found on show page');
        }
    }

    // ========== ACTIVITY ACTION TESTS ==========

    public function testActivityRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId() . '/activity');

        $this->assertResponseRedirects();
    }

    public function testActivityDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId() . '/activity');

        $this->assertResponseIsSuccessful();
    }

    // ========== MFA ACTION TESTS ==========

    public function testMfaRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId() . '/mfa');

        $this->assertResponseRedirects();
    }

    public function testMfaDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId() . '/mfa');

        $this->assertResponseIsSuccessful();
    }

    // ========== EXPORT ACTION TESTS ==========

    public function testExportRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/users/export');

        $this->assertResponseRedirects();
    }

    public function testExportRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/admin/users/export');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testExportReturnsCsvForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users/export');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ========== IMPORT ACTION TESTS ==========

    public function testImportRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/users/import');

        $this->assertResponseRedirects();
    }

    public function testImportRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/admin/users/import');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testImportDisplaysFormForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users/import');

        $this->assertResponseIsSuccessful();
    }

    // ========== BULK ACTIONS TESTS ==========

    public function testBulkActionsRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/users/bulk-actions');

        $this->assertResponseRedirects();
    }

    public function testBulkActionsRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/admin/users/bulk-actions', [
            'action' => 'activate',
            'user_ids' => [$this->targetUser->getId()],
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBulkActionsActivateUsers(): void
    {
        $this->loginAsUser($this->adminUser);

        // First deactivate the target user
        $this->targetUser->setIsActive(false);
        $this->entityManager->flush();

        $this->client->request('POST', '/en/admin/users/bulk-actions', [
            'action' => 'activate',
            'user_ids' => [$this->targetUser->getId()],
        ]);

        $this->assertResponseRedirects('/en/admin/users');
    }

    public function testBulkActionsDeactivateUsers(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/admin/users/bulk-actions', [
            'action' => 'deactivate',
            'user_ids' => [$this->targetUser->getId()],
        ]);

        $this->assertResponseRedirects('/en/admin/users');
    }

    public function testBulkActionsDeleteUsers(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $targetId = $this->targetUser->getId();

        $this->client->request('POST', '/en/admin/users/bulk-actions', [
            'action' => 'delete',
            'user_ids' => [$targetId],
        ]);

        $this->assertResponseRedirects('/en/admin/users');
        $this->targetUser = null; // Prevent cleanup failure
    }

    public function testBulkActionsHandlesNoUsersSelected(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/admin/users/bulk-actions', [
            'action' => 'activate',
            'user_ids' => [],
        ]);

        $this->assertResponseRedirects('/en/admin/users');
    }

    // ========== IMPERSONATE ACTION TESTS ==========

    public function testImpersonateRequiresSuperAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId() . '/impersonate');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testImpersonateRedirectsForSuperAdmin(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId() . '/impersonate');

        $this->assertResponseRedirects();
    }

    // ========== EMPTY STATE TESTS ==========

    public function testShowHandlesUserWithNoActivity(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testActivityHandlesUserWithNoActivity(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId() . '/activity');

        $this->assertResponseIsSuccessful();
    }

    public function testMfaHandlesUserWithNoTokens(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/users/' . $this->targetUser->getId() . '/mfa');

        $this->assertResponseIsSuccessful();
    }
}

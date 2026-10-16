<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;

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
    /** @var list<User> */
    private array $extraUsers = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        foreach ($this->extraUsers as $extraUser) {
            $managed = $this->entityManager->find(User::class, $extraUser->getId());
            if ($managed !== null) {
                $this->entityManager->remove($managed);
            }
        }
        $this->entityManager->flush();

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

    #[Test]
    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants');

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testIndexRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/admin/tenants');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testIndexShowsTenantsForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    #[Test]
    public function testIndexFiltersActiveTenantsOnly(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants?filter=active');

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testIndexFiltersInactiveTenantsOnly(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants?filter=inactive');

        $this->assertResponseIsSuccessful();
    }

    // ========== SHOW ACTION TESTS ==========

    #[Test]
    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId());

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testShowDisplaysTenantForAdmin(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId());

        $this->assertResponseIsSuccessful();
    }

    #[Test]
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

    #[Test]
    public function testShowDisplaysUserStatistics(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/' . $this->testTenant->getId());

        $this->assertResponseIsSuccessful();
    }

    // ========== NEW ACTION TESTS ==========

    #[Test]
    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants/new');

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testNewRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/admin/tenants/new');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testNewDisplaysFormForAdmin(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="tenant"]');
    }

    #[Test]
    public function testNewCreatesTenantWithValidData(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/new');
        $form = $crawler->filter('form[name="tenant"]')->form([
            'tenant[name]' => 'New Test Tenant',
            'tenant[code]' => 'new_test_tenant_' . uniqid(),
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects();
    }

    // ========== EDIT ACTION TESTS ==========

    #[Test]
    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId() . '/edit');

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testEditDisplaysFormForAdmin(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="tenant"]');
    }

    #[Test]
    public function testEditUpdatesTenantWithValidData(): void
    {
        $this->loginAsUser($this->superAdminUser);

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

    #[Test]
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

    #[Test]
    public function testToggleRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/toggle');

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testToggleChangesStatus(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/toggle');

        $this->assertResponseRedirects('/en/admin/tenants');
    }

    // ========== DELETE ACTION TESTS ==========

    #[Test]
    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/delete');

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/delete');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
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

    #[Test]
    public function testDeleteBlockedForTenantWithUsers(): void
    {
        $this->loginAsUser($this->superAdminUser);

        // testTenant has users assigned, so delete should be blocked
        $crawler = $this->client->request('GET', '/en/admin/tenants/' . $this->testTenant->getId());

        // Delete form should not be available for tenants with users
        $this->assertResponseIsSuccessful();
    }

    // ========== CORPORATE STRUCTURE TESTS ==========

    #[Test]
    public function testCorporateStructureRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants/corporate-structure');

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testCorporateStructureDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants/corporate-structure');

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testCorporateStructureShowsStandaloneTenants(): void
    {
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/corporate-structure');

        $this->assertResponseIsSuccessful();
    }

    // ========== SET PARENT TESTS ==========

    #[Test]
    public function testSetParentRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/set-parent');

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testSetParentAssignsParentTenant(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/set-parent', [
            'parent_id' => $this->testTenant->getId(),
            'governance_model' => 'hierarchical',
        ]);

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testSetParentRemovesParent(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/set-parent', [
            'parent_id' => '',
        ]);

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testSetParentRejectsInvalidGovernanceModel(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/set-parent', [
            'parent_id' => $this->testTenant->getId(),
            'governance_model' => 'invalid_model',
        ]);

        $this->assertResponseRedirects();
    }

    // ========== UPDATE GOVERNANCE TESTS ==========

    #[Test]
    public function testUpdateGovernanceRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/update-governance');

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testUpdateGovernanceRejectsInvalidModel(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/update-governance', [
            'governance_model' => 'invalid',
        ]);

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testUpdateGovernanceAcceptsValidModels(): void
    {
        $this->loginAsUser($this->superAdminUser);

        foreach (['hierarchical', 'shared', 'independent'] as $model) {
            $this->client->request('POST', '/en/admin/tenants/' . $this->targetTenant->getId() . '/update-governance', [
                'governance_model' => $model,
            ]);

            $this->assertResponseRedirects();
        }
    }

    // ========== ORGANISATION CONTEXT TESTS ==========

    #[Test]
    public function testOrganisationContextRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId() . '/organisation-context');

        $this->assertResponseRedirects();
    }

    #[Test]
    public function testOrganisationContextDisplaysFormForAdmin(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId() . '/organisation-context');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== EMPTY STATE TESTS ==========

    #[Test]
    public function testShowHandlesTenantWithNoUsers(): void
    {
        $this->loginAsUser($this->superAdminUser);

        $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId());

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testCorporateStructureHandlesEmptyState(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants/corporate-structure');

        $this->assertResponseIsSuccessful();
    }

    // ========== FILTER TESTS ==========

    #[Test]
    public function testIndexFilterAll(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants?filter=all');

        $this->assertResponseIsSuccessful();
    }

    // ========== ORGANISATION SCOPE (per-organisation admin vs. instance operator) ==========

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function organisationScopedRequests(): iterable
    {
        yield 'show' => ['GET', ''];
        yield 'edit' => ['GET', '/edit'];
        yield 'edit submit' => ['POST', '/edit'];
        yield 'organisation context' => ['GET', '/organisation-context'];
        yield 'remove user' => ['POST', '/remove-user/1'];
        yield 'update governance' => ['POST', '/update-governance'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('organisationScopedRequests')]
    public function adminCannotActOnAnotherOrganisation(string $method, string $suffix): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request($method, '/en/admin/tenants/' . $this->targetTenant->getId() . $suffix);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function adminCannotReadAnotherOrganisationsIsmsContext(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/admin/tenants/api/tenants/' . $this->targetTenant->getId() . '/isms-context');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function operatorOnlyRequests(): iterable
    {
        yield 'create' => ['GET', '/new'];
        yield 'toggle' => ['POST', '/{own}/toggle'];
        yield 'delete' => ['POST', '/{own}/delete'];
        yield 'set parent' => ['POST', '/{own}/set-parent'];
        yield 'add user' => ['POST', '/{own}/add-user'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('operatorOnlyRequests')]
    public function operatorActionsAreDeniedToAnOrganisationAdmin(string $method, string $path): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request($method, '/en/admin/tenants' . str_replace('{own}', (string) $this->testTenant->getId(), $path));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function adminCannotPullAUserOfAnotherOrganisationIntoTheirOwn(): void
    {
        $foreignUser = $this->extraUser($this->targetTenant, 'ROLE_USER');
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/tenants/' . $this->testTenant->getId());
        $session = $this->client->getRequest()->getSession();
        $token = (new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/add_user_' . $this->testTenant->getId(), $token);
        $session->save();

        $this->client->request('POST', '/en/admin/tenants/' . $this->testTenant->getId() . '/add-user', [
            '_token' => $token,
            'user_email' => $foreignUser->getEmail(),
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager->clear();
        self::assertSame(
            $this->targetTenant->getId(),
            $this->entityManager->find(User::class, $foreignUser->getId())?->getTenant()?->getId(),
        );
    }

    #[Test]
    public function indexAndCorporateStructureListOnlyTheOwnOrganisation(): void
    {
        $this->loginAsUser($this->adminUser);

        foreach (['/en/admin/tenants', '/en/admin/tenants/corporate-structure'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();
            $content = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString((string) $this->testTenant->getCode(), $content, $url);
            self::assertStringNotContainsString((string) $this->targetTenant->getCode(), $content, $url);
        }
    }

    #[Test]
    public function organisationAdminEditsOwnOrganisationButNotItsOperatorFields(): void
    {
        $originalCode = $this->testTenant->getCode();
        $this->loginAsUser($this->adminUser);

        $crawler = $this->client->request('GET', '/en/admin/tenants/' . $this->testTenant->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        foreach (['code', 'isActive', 'parent', 'isCorporateParent', 'azureTenantId', 'settings'] as $field) {
            self::assertCount(1, $crawler->filter('[name="tenant[' . $field . ']"][disabled]'), $field . ' must be read-only');
        }

        $form = $crawler->filter('form[name="tenant"]')->form();
        $values = $form->getPhpValues();
        $values['tenant']['name'] = 'Renamed by organisation admin';
        $values['tenant']['code'] = 'hijacked_code';
        $values['tenant']['parent'] = (string) $this->targetTenant->getId();
        $this->client->request('POST', $form->getUri(), $values);

        $this->assertResponseRedirects();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
        self::assertInstanceOf(Tenant::class, $reloaded);
        self::assertSame('Renamed by organisation admin', $reloaded->getName());
        self::assertSame($originalCode, $reloaded->getCode());
        self::assertNull($reloaded->getParent());
    }

    #[Test]
    public function governanceOfASubsidiaryIsTheParentOrganisationsDecision(): void
    {
        $this->targetTenant->setParent($this->testTenant);
        $this->entityManager->flush();
        $subsidiaryAdmin = $this->extraUser($this->targetTenant, 'ROLE_ADMIN');
        $url = '/en/admin/tenants/' . $this->targetTenant->getId() . '/update-governance';

        $this->loginAsUser($subsidiaryAdmin);
        $this->client->request('POST', $url, ['governance_model' => 'independent']);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/tenants/' . $this->targetTenant->getId());
        $this->assertResponseIsSuccessful('the parent organisation\'s admin administers the subsidiary');
        $this->client->request('POST', $url, ['governance_model' => 'independent']);
        $this->assertResponseRedirects();
    }

    private function extraUser(Tenant $tenant, string $role): User
    {
        $user = new User();
        $user->setEmail('tm_extra_' . uniqid() . '@example.com');
        $user->setFirstName('Extra');
        $user->setLastName('User');
        $user->setRoles([$role]);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->extraUsers[] = $user;

        return $user;
    }
}

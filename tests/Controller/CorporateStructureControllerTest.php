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
 * Functional tests for CorporateStructureController
 *
 * Tests corporate structure API including:
 * - Tree structure retrieval
 * - Corporate groups listing
 * - Parent-child relationships
 * - Governance model management
 * - Context inheritance
 * - Access control checks
 */
class CorporateStructureControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?Tenant $parentTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
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
            $user = $this->entityManager->find(User::class, $extraUser->getId());
            if ($user) {
                $this->entityManager->remove($user);
            }
        }
        // Detach the child before the tenants go (parent FK).
        if ($this->testTenant) {
            $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
            if ($tenant) {
                foreach ($this->entityManager->getRepository(\App\Entity\CorporateGovernance::class)->findBy(['tenant' => $tenant]) as $rule) {
                    $this->entityManager->remove($rule);
                }
                $tenant->setParent(null);
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

        if ($this->parentTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->parentTenant->getId());
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

        $this->parentTenant = new Tenant();
        $this->parentTenant->setName('Parent Tenant ' . $uniqueId);
        $this->parentTenant->setCode('parent_' . $uniqueId);
        $this->parentTenant->setIsCorporateParent(true);
        $this->entityManager->persist($this->parentTenant);

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

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    private function extraUser(string $role, Tenant $tenant): User
    {
        $user = new User();
        $user->setEmail(strtolower($role) . '_' . uniqid('', true) . '@example.com');
        $user->setFirstName('Extra');
        $user->setLastName($role);
        $user->setRoles([$role]);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->extraUsers[] = $user;

        return $user;
    }

    /**
     * Make testTenant a subsidiary of parentTenant with a default governance rule.
     */
    private function attachTestTenantToParent(): void
    {
        $this->testTenant->setParent($this->parentTenant);
        $governance = new \App\Entity\CorporateGovernance();
        $governance->setTenant($this->testTenant);
        $governance->setParent($this->parentTenant);
        $governance->setScope('default');
        $governance->setScopeId(null);
        $governance->setGovernanceModel(\App\Enum\GovernanceModel::INDEPENDENT);
        $this->entityManager->persist($governance);
        $this->entityManager->flush();
    }

    /**
     * Make a JSON request with CSRF protection.
     *
     * 'corporate_structure' is registered as a stateless CSRF token ID in
     * config/packages/csrf.yaml. The SameOriginCsrfTokenManager validates
     * these tokens using the Sec-Fetch-Site header (same-origin check),
     * which is the recommended approach for JSON API endpoints.
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @param array $data Request body data (will be JSON-encoded)
     */
    private function jsonRequestWithCsrf(string $method, string $uri, array $data = []): void
    {
        $this->client->request(
            $method,
            $uri,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => 'csrf-token',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
            json_encode($data)
        );
    }

    // ========== TREE TESTS ==========

    #[Test]
    public function testGetTreeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/tree/' . $this->testTenant->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testGetTreeRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/api/corporate-structure/tree/' . $this->testTenant->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testGetTreeReturnsJsonForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/tree/' . $this->testTenant->getId());
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ========== GROUPS TESTS ==========

    #[Test]
    public function testGetGroupsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/groups');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testGetGroupsRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/api/corporate-structure/groups');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testGetGroupsReturnsJsonForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/groups');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ========== SET PARENT TESTS ==========

    #[Test]
    public function testSetParentRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/api/corporate-structure/set-parent');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testSetParentRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/api/corporate-structure/set-parent');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testSetParentRequiresTenantId(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->jsonRequestWithCsrf('POST', '/en/api/corporate-structure/set-parent', []);
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[Test]
    public function testSetParentReturns404ForNonexistentTenant(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->jsonRequestWithCsrf('POST', '/en/api/corporate-structure/set-parent', ['tenantId' => 999999]);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function testSetParentRequiresValidGovernanceModel(): void
    {
        $this->loginAsUser($this->extraUser('ROLE_SUPER_ADMIN', $this->testTenant));
        $this->jsonRequestWithCsrf('POST', '/en/api/corporate-structure/set-parent', [
            'tenantId' => $this->testTenant->getId(),
            'parentId' => $this->parentTenant->getId(),
            'governanceModel' => 'invalid'
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[Test]
    public function testTenantAdminCannotAttachOwnTenantToForeignGroup(): void
    {
        // Attaching a tenant to another organisation's group would give that
        // group read access to it — SUPER_ADMIN only.
        $this->loginAsUser($this->adminUser);
        $this->jsonRequestWithCsrf('POST', '/en/api/corporate-structure/set-parent', [
            'tenantId' => $this->testTenant->getId(),
            'parentId' => $this->parentTenant->getId(),
            'governanceModel' => 'hierarchical',
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Tenant::class, $this->testTenant->getId())?->getParent());
    }

    #[Test]
    public function testHoldingAdminCanDetachOwnSubsidiaryAndRemovalIsAudited(): void
    {
        $this->attachTestTenantToParent();
        $holdingAdmin = $this->extraUser('ROLE_ADMIN', $this->parentTenant);

        $this->loginAsUser($holdingAdmin);
        $this->jsonRequestWithCsrf('POST', '/en/api/corporate-structure/set-parent', [
            'tenantId' => $this->testTenant->getId(),
            'parentId' => null,
        ]);
        $this->assertResponseIsSuccessful();

        $auditRows = $this->entityManager->getRepository(\App\Entity\AuditLog::class)->findBy([
            'entityType' => 'CorporateGovernance',
            'action' => 'delete',
        ]);
        self::assertNotEmpty($auditRows, 'bulk DQL delete of governance rules must be audit-logged');
    }

    #[Test]
    public function testSubsidiaryAdminCannotDetachItselfFromItsGroup(): void
    {
        $this->attachTestTenantToParent();

        $this->loginAsUser($this->adminUser);
        $this->jsonRequestWithCsrf('POST', '/en/api/corporate-structure/set-parent', [
            'tenantId' => $this->testTenant->getId(),
            'parentId' => null,
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testGovernanceOfSubsidiaryIsChangedByHoldingOnly(): void
    {
        $this->attachTestTenantToParent();
        $uri = '/en/api/corporate-structure/governance-model/' . $this->testTenant->getId();

        $this->loginAsUser($this->adminUser);
        $this->jsonRequestWithCsrf('PATCH', $uri, ['governanceModel' => 'shared']);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->loginAsUser($this->extraUser('ROLE_ADMIN', $this->parentTenant));
        $this->jsonRequestWithCsrf('PATCH', $uri, ['governanceModel' => 'shared']);
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testForeignTenantDataIsNotReadable(): void
    {
        // parentTenant is a separate organisation for adminUser here.
        $this->loginAsUser($this->adminUser);
        $foreignId = $this->parentTenant->getId();

        $this->client->request('GET', '/en/api/corporate-structure/tree/' . $foreignId);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request('GET', '/en/api/corporate-structure/effective-context/' . $foreignId);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request('GET', '/en/api/corporate-structure/' . $foreignId . '/governance');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->jsonRequestWithCsrf('POST', '/en/api/corporate-structure/propagate-context/' . $foreignId);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('GET', '/en/api/corporate-structure/groups');
        $ids = array_column(json_decode((string) $this->client->getResponse()->getContent(), true), 'id');
        self::assertNotContains($foreignId, $ids);
    }

    // ========== GOVERNANCE MODEL TESTS ==========

    #[Test]
    public function testUpdateGovernanceModelRequiresAuthentication(): void
    {
        $this->client->request('PATCH', '/en/api/corporate-structure/governance-model/' . $this->testTenant->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testUpdateGovernanceModelRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('PATCH', '/en/api/corporate-structure/governance-model/' . $this->testTenant->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testUpdateGovernanceModelRequiresValidModel(): void
    {
        $this->loginAsUser($this->extraUser('ROLE_SUPER_ADMIN', $this->testTenant));
        $this->jsonRequestWithCsrf(
            'PATCH',
            '/en/api/corporate-structure/governance-model/' . $this->testTenant->getId(),
            ['governanceModel' => 'invalid']
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // ========== EFFECTIVE CONTEXT TESTS ==========

    #[Test]
    public function testGetEffectiveContextRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/effective-context/' . $this->testTenant->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testGetEffectiveContextRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/api/corporate-structure/effective-context/' . $this->testTenant->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== GOVERNANCE MODELS LIST TESTS ==========

    #[Test]
    public function testGetGovernanceModelsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/governance-models');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testGetGovernanceModelsReturnsJsonForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/governance-models');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ========== CHECK ACCESS TESTS ==========

    #[Test]
    public function testCheckAccessRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/check-access/' . $this->testTenant->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testCheckAccessRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/api/corporate-structure/check-access/' . $this->testTenant->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testCheckAccessReturns404ForNonexistentTarget(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/check-access/999999');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function testCheckAccessReturnsJsonForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/check-access/' . $this->testTenant->getId());
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ========== PROPAGATE CONTEXT TESTS ==========

    #[Test]
    public function testPropagateContextRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/api/corporate-structure/propagate-context/' . $this->testTenant->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testPropagateContextRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/api/corporate-structure/propagate-context/' . $this->testTenant->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== GET GOVERNANCE RULES TESTS ==========

    #[Test]
    public function testGetGovernanceRulesRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testGetGovernanceRulesRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testGetGovernanceRulesReturns404ForNonexistentTenant(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/999999/governance');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function testGetGovernanceRulesReturnsJsonForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ========== SET SCOPE GOVERNANCE TESTS ==========

    #[Test]
    public function testSetScopeGovernanceRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance/risks');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testSetScopeGovernanceRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance/risks');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testSetScopeGovernanceRequiresValidModel(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->jsonRequestWithCsrf(
            'POST',
            '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance/risks',
            ['governanceModel' => 'invalid']
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // ========== DELETE SCOPE GOVERNANCE TESTS ==========

    #[Test]
    public function testDeleteScopeGovernanceRequiresAuthentication(): void
    {
        $this->client->request('DELETE', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance/risks/1');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testDeleteScopeGovernanceRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('DELETE', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance/risks/1');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}

<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

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

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
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

    // ========== TREE TESTS ==========

    public function testGetTreeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/tree/' . $this->testTenant->getId());
        $this->assertResponseRedirects();
    }

    public function testGetTreeRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/api/corporate-structure/tree/' . $this->testTenant->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testGetTreeReturnsJsonForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/tree/' . $this->testTenant->getId());
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ========== GROUPS TESTS ==========

    public function testGetGroupsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/groups');
        $this->assertResponseRedirects();
    }

    public function testGetGroupsRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/api/corporate-structure/groups');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testGetGroupsReturnsJsonForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/groups');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ========== SET PARENT TESTS ==========

    public function testSetParentRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/api/corporate-structure/set-parent');
        $this->assertResponseRedirects();
    }

    public function testSetParentRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/api/corporate-structure/set-parent');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testSetParentRequiresTenantId(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request(
            'POST',
            '/en/api/corporate-structure/set-parent',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testSetParentReturns404ForNonexistentTenant(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request(
            'POST',
            '/en/api/corporate-structure/set-parent',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['tenantId' => 999999])
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSetParentRequiresValidGovernanceModel(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request(
            'POST',
            '/en/api/corporate-structure/set-parent',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'tenantId' => $this->testTenant->getId(),
                'parentId' => $this->parentTenant->getId(),
                'governanceModel' => 'invalid'
            ])
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // ========== GOVERNANCE MODEL TESTS ==========

    public function testUpdateGovernanceModelRequiresAuthentication(): void
    {
        $this->client->request('PATCH', '/en/api/corporate-structure/governance-model/' . $this->testTenant->getId());
        $this->assertResponseRedirects();
    }

    public function testUpdateGovernanceModelRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('PATCH', '/en/api/corporate-structure/governance-model/' . $this->testTenant->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testUpdateGovernanceModelRequiresValidModel(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request(
            'PATCH',
            '/en/api/corporate-structure/governance-model/' . $this->testTenant->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['governanceModel' => 'invalid'])
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // ========== EFFECTIVE CONTEXT TESTS ==========

    public function testGetEffectiveContextRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/effective-context/' . $this->testTenant->getId());
        $this->assertResponseRedirects();
    }

    public function testGetEffectiveContextRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/api/corporate-structure/effective-context/' . $this->testTenant->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== GOVERNANCE MODELS LIST TESTS ==========

    public function testGetGovernanceModelsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/governance-models');
        $this->assertResponseRedirects();
    }

    public function testGetGovernanceModelsReturnsJsonForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/governance-models');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ========== CHECK ACCESS TESTS ==========

    public function testCheckAccessRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/check-access/' . $this->testTenant->getId());
        $this->assertResponseRedirects();
    }

    public function testCheckAccessRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/api/corporate-structure/check-access/' . $this->testTenant->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCheckAccessReturns404ForNonexistentTarget(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/check-access/999999');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCheckAccessReturnsJsonForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/check-access/' . $this->testTenant->getId());
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ========== PROPAGATE CONTEXT TESTS ==========

    public function testPropagateContextRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/api/corporate-structure/propagate-context/' . $this->testTenant->getId());
        $this->assertResponseRedirects();
    }

    public function testPropagateContextRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/api/corporate-structure/propagate-context/' . $this->testTenant->getId());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== GET GOVERNANCE RULES TESTS ==========

    public function testGetGovernanceRulesRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance');
        $this->assertResponseRedirects();
    }

    public function testGetGovernanceRulesRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testGetGovernanceRulesReturns404ForNonexistentTenant(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/999999/governance');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGetGovernanceRulesReturnsJsonForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ========== SET SCOPE GOVERNANCE TESTS ==========

    public function testSetScopeGovernanceRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance/risks');
        $this->assertResponseRedirects();
    }

    public function testSetScopeGovernanceRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance/risks');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testSetScopeGovernanceRequiresValidModel(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request(
            'POST',
            '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance/risks',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['governanceModel' => 'invalid'])
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // ========== DELETE SCOPE GOVERNANCE TESTS ==========

    public function testDeleteScopeGovernanceRequiresAuthentication(): void
    {
        $this->client->request('DELETE', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance/risks/1');
        $this->assertResponseRedirects();
    }

    public function testDeleteScopeGovernanceRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('DELETE', '/en/api/corporate-structure/' . $this->testTenant->getId() . '/governance/risks/1');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}

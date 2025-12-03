<?php

namespace App\Tests\Controller;

use App\Entity\RiskAppetite;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class RiskAppetiteControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?RiskAppetite $testRiskAppetite = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testRiskAppetite) {
            try {
                $riskAppetite = $this->entityManager->find(RiskAppetite::class, $this->testRiskAppetite->getId());
                if ($riskAppetite) {
                    $this->entityManager->remove($riskAppetite);
                }
            } catch (\Exception $e) {}
        }

        if ($this->testUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->testUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {}
        }

        if ($this->adminUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->adminUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {}
        }

        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception $e) {}
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {}

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

        $this->testRiskAppetite = new RiskAppetite();
        $this->testRiskAppetite->setTenant($this->testTenant);
        $this->testRiskAppetite->setDescription('Test Risk Appetite ' . $uniqueId);
        $this->testRiskAppetite->setMaxAcceptableRisk(15);
        $this->testRiskAppetite->setIsActive(true);
        $this->entityManager->persist($this->testRiskAppetite);

        $this->entityManager->flush();
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk-appetite/');
        $this->assertResponseRedirects();
    }

    public function testIndexDisplaysForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/risk-appetite/');
        $this->assertResponseIsSuccessful();
    }

    public function testNewRequiresAdminRole(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/risk-appetite/new');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testNewDisplaysFormForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/risk-appetite/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk-appetite/' . $this->testRiskAppetite->getId());
        $this->assertResponseRedirects();
    }

    public function testShowDisplaysForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/risk-appetite/' . $this->testRiskAppetite->getId());
        $this->assertResponseIsSuccessful();
    }

    public function testEditRequiresAdminRole(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/risk-appetite/' . $this->testRiskAppetite->getId() . '/edit');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testEditDisplaysFormForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/risk-appetite/' . $this->testRiskAppetite->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testDeleteRequiresAdminRole(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('POST', '/en/risk-appetite/' . $this->testRiskAppetite->getId() . '/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRequiresPost(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/risk-appetite/' . $this->testRiskAppetite->getId() . '/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}

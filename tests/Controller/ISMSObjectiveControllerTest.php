<?php

namespace App\Tests\Controller;

use App\Entity\ISMSObjective;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ISMSObjectiveControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?ISMSObjective $testObjective = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testObjective) {
            try {
                $objective = $this->entityManager->find(ISMSObjective::class, $this->testObjective->getId());
                if ($objective) {
                    $this->entityManager->remove($objective);
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

        $this->testObjective = new ISMSObjective();
        $this->testObjective->setTenant($this->testTenant);
        $this->testObjective->setTitle('Test Objective ' . $uniqueId);
        $this->testObjective->setDescription('Test description');
        $this->testObjective->setCategory('security');
        $this->testObjective->setResponsiblePerson('Test User');
        $this->testObjective->setStatus('in_progress');
        $this->testObjective->setTargetDate(new \DateTime('+30 days'));
        $this->entityManager->persist($this->testObjective);

        $this->entityManager->flush();
    }

    public function testIndexDisplaysWithoutAuthentication(): void
    {
        // ISMSObjectiveController index has no IsGranted attribute
        $this->client->request('GET', '/en/objective/');
        // May redirect to login or be accessible - check for redirect
        $this->assertResponseRedirects();
    }

    public function testIndexDisplaysForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/objective/');
        $this->assertResponseIsSuccessful();
    }

    public function testNewRequiresAdminRole(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/objective/new');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testNewDisplaysFormForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/objective/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testShowDisplaysForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/objective/' . $this->testObjective->getId());
        $this->assertResponseIsSuccessful();
    }

    public function testEditRequiresAdminRole(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/objective/' . $this->testObjective->getId() . '/edit');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testEditDisplaysFormForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/objective/' . $this->testObjective->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testDeleteRequiresAdminRole(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('POST', '/en/objective/' . $this->testObjective->getId() . '/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRequiresPost(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/objective/' . $this->testObjective->getId() . '/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}

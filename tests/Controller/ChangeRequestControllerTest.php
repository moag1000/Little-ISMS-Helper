<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ChangeRequest;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;

class ChangeRequestControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?ChangeRequest $testChangeRequest = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testChangeRequest) {
            try {
                $changeRequest = $this->entityManager->find(ChangeRequest::class, $this->testChangeRequest->getId());
                if ($changeRequest) {
                    $this->entityManager->remove($changeRequest);
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

        $this->testChangeRequest = new ChangeRequest();
        $this->testChangeRequest->setTenant($this->testTenant);
        $this->testChangeRequest->setChangeNumber('CR-' . $uniqueId);
        $this->testChangeRequest->setTitle('Test Change Request ' . $uniqueId);
        $this->testChangeRequest->setChangeType('normal');
        $this->testChangeRequest->setDescription('Test description');
        $this->testChangeRequest->setJustification('Test justification');
        $this->testChangeRequest->setRequestedBy('Test User');
        $this->testChangeRequest->setRequestedDate(new \DateTime());
        $this->testChangeRequest->setStatus('draft');
        $this->testChangeRequest->setPriority('medium');
        $this->entityManager->persist($this->testChangeRequest);

        $this->entityManager->flush();
    }

    #[Test]
    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/change-request/');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testIndexDisplaysForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/change-request/');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/change-request/new');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testNewDisplaysFormForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/change-request/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    #[Test]
    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/change-request/' . $this->testChangeRequest->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testShowDisplaysForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/change-request/' . $this->testChangeRequest->getId());
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/change-request/' . $this->testChangeRequest->getId() . '/edit');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testEditDisplaysFormForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/change-request/' . $this->testChangeRequest->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    #[Test]
    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/change-request/' . $this->testChangeRequest->getId() . '/delete');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testDeleteRequiresAdminRole(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('POST', '/en/change-request/' . $this->testChangeRequest->getId() . '/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testDeleteRequiresPost(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/change-request/' . $this->testChangeRequest->getId() . '/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}

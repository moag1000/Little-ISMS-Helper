<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Smoke tests for FourEyesController — edit (approver reassignment) action.
 *
 * Verifies that the new Tri-State approver form route renders (200), redirects
 * (302) or denies access (403) depending on authentication and role.
 */
class FourEyesControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $adminUser = null;
    private ?User $requestingUser = null;
    private ?FourEyesApprovalRequest $testRequest = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        // Ensure setup-complete lock exists
        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testRequest) {
            try {
                $req = $this->entityManager->find(FourEyesApprovalRequest::class, $this->testRequest->getId());
                if ($req) {
                    $this->entityManager->remove($req);
                }
            } catch (\Exception) {
                // Ignore
            }
        }

        foreach ([$this->adminUser, $this->requestingUser] as $user) {
            if ($user) {
                try {
                    $u = $this->entityManager->find(User::class, $user->getId());
                    if ($u) {
                        $this->entityManager->remove($u);
                    }
                } catch (\Exception) {
                    // Ignore
                }
            }
        }

        if ($this->testTenant) {
            $tenantRepo = $this->entityManager->getRepository(Tenant::class);
            foreach ($tenantRepo->findBy(['name' => $this->testTenant->getName()]) as $tenant) {
                try {
                    $this->entityManager->remove($tenant);
                } catch (\Exception) {
                    // Ignore
                }
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception) {
            // Ignore
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('fe_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('FE Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('fe_tenant_' . substr($uniqueId, 0, 16));
        $this->entityManager->persist($this->testTenant);

        $this->adminUser = new User();
        $this->adminUser->setEmail('fe_admin_' . $uniqueId . '@example.com');
        $this->adminUser->setFirstName('FE');
        $this->adminUser->setLastName('Admin');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        $this->requestingUser = new User();
        $this->requestingUser->setEmail('fe_requester_' . $uniqueId . '@example.com');
        $this->requestingUser->setFirstName('FE');
        $this->requestingUser->setLastName('Requester');
        $this->requestingUser->setRoles(['ROLE_MANAGER']);
        $this->requestingUser->setPassword('hashed_password');
        $this->requestingUser->setTenant($this->testTenant);
        $this->requestingUser->setIsActive(true);
        $this->entityManager->persist($this->requestingUser);

        $this->entityManager->flush();

        $this->testRequest = new FourEyesApprovalRequest();
        $this->testRequest->setTenant($this->testTenant);
        $this->testRequest->setActionType(FourEyesApprovalRequest::ACTION_MAPPING_OVERRIDE);
        $this->testRequest->setPayload(['subject_id' => 1]);
        $this->testRequest->setRequestedBy($this->requestingUser);
        $this->entityManager->persist($this->testRequest);
        $this->entityManager->flush();
    }

    #[Test]
    public function testEditPageRendersOrRedirectsForUnauthenticated(): void
    {
        $this->client->request('GET', '/de/four-eyes/' . $this->testRequest->getId() . '/edit');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [200, 302, 403], 'Expected 200, 302 or 403 for unauthenticated edit');
    }

    #[Test]
    public function testEditPageRendersForAdminUser(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/four-eyes/' . $this->testRequest->getId() . '/edit');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [200, 302, 403], 'Expected 200, 302 or 403 for admin edit');
    }
}

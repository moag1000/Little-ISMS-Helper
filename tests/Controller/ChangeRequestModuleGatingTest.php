<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ChangeRequest;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Verifies that ChangeRequestController correctly enforces the `change_requests` module gate.
 *
 * Two scenarios per endpoint:
 *   module OFF → authenticated user is redirected (302) to the dashboard
 *   module ON  → authenticated user receives a 200 OK response
 *
 * Guards against regressions in module-gating enforcement for Change Management
 * (ISO 27001 A.8.32).
 */
#[AllowMockObjectsWithoutExpectations]
class ChangeRequestModuleGatingTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?ChangeRequest $testChangeRequest = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        // Ensure setup-complete lock exists — without it SetupRequiredSubscriber
        // redirects every authenticated request to /de/setup/.
        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        foreach ([$this->testChangeRequest, $this->testUser, $this->testTenant] as $entity) {
            if ($entity === null) {
                continue;
            }
            try {
                $managed = $this->entityManager->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                }
            } catch (\Exception) {
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception) {
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helper: swap ModuleConfigurationService in the test container
    // -------------------------------------------------------------------------

    /**
     * @param list<string> $activeModules Module keys that should appear active.
     */
    private function mockModules(array $activeModules): void
    {
        $mock = $this->createMock(ModuleConfigurationService::class);
        $mock->method('isModuleActive')->willReturnCallback(
            fn(string $key): bool => in_array($key, $activeModules, true)
        );
        static::getContainer()->set(ModuleConfigurationService::class, $mock);
    }

    /** All required + optional modules active, including change_requests. */
    private function moduleOn(): void
    {
        $this->mockModules([
            'core', 'authentication', 'audit_logging', 'documents', 'objectives', 'workflows',
            'assets', 'risks', 'controls', 'incidents', 'audits', 'training', 'reviews',
            'bcm', 'compliance', 'change_requests',
        ]);
    }

    /** Required modules active but change_requests explicitly absent. */
    private function moduleOff(): void
    {
        $this->mockModules([
            'core', 'authentication', 'audit_logging', 'documents', 'objectives', 'workflows',
            'assets', 'risks', 'controls', 'incidents', 'audits', 'training', 'reviews',
            'bcm', 'compliance',
            // change_requests intentionally absent
        ]);
    }

    private function createTestData(): void
    {
        $uid = uniqid('cr_gate_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('ModuleGate Tenant ' . $uid);
        $this->testTenant->setCode('mg_' . substr($uid, -8));
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('mg_user_' . $uid . '@example.com');
        $this->testUser->setFirstName('Gate');
        $this->testUser->setLastName('Tester');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->testChangeRequest = new ChangeRequest();
        $this->testChangeRequest->setTenant($this->testTenant);
        $this->testChangeRequest->setChangeNumber('CHG-GATE-' . $uid);
        $this->testChangeRequest->setTitle('Gate Test Change Request ' . $uid);
        $this->testChangeRequest->setDescription('Module gating test fixture');
        $this->testChangeRequest->setJustification('Module gating test justification');
        $this->testChangeRequest->setChangeType('normal');
        $this->testChangeRequest->setPriority('medium');
        $this->testChangeRequest->setStatus('draft');
        $this->testChangeRequest->setRequestedBy('gate-tester');
        $this->testChangeRequest->setRequestedDate(new \DateTime());
        $this->entityManager->persist($this->testChangeRequest);

        $this->entityManager->flush();
    }

    // =========================================================================
    // ChangeRequestController — module OFF (should redirect)
    // =========================================================================

    #[Test]
    public function changeRequestIndexRedirectsWhenModuleOff(): void
    {
        $this->moduleOff();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/change-request');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function changeRequestNewRedirectsWhenModuleOff(): void
    {
        $this->moduleOff();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/change-request/new');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function changeRequestShowRedirectsWhenModuleOff(): void
    {
        $this->moduleOff();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/change-request/' . $this->testChangeRequest->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function changeRequestEditRedirectsWhenModuleOff(): void
    {
        $this->moduleOff();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/change-request/' . $this->testChangeRequest->getId() . '/edit');
        $this->assertResponseRedirects();
    }

    // =========================================================================
    // ChangeRequestController — module ON (should succeed)
    // =========================================================================

    #[Test]
    public function changeRequestIndexSucceedsWhenModuleOn(): void
    {
        $this->moduleOn();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/change-request');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function changeRequestNewSucceedsWhenModuleOn(): void
    {
        $this->moduleOn();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/change-request/new');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function changeRequestShowSucceedsWhenModuleOn(): void
    {
        $this->moduleOn();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/change-request/' . $this->testChangeRequest->getId());
        $this->assertResponseIsSuccessful();
    }
}

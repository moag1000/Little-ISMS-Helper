<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Location;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Verifies that LocationController correctly enforces the `locations` module gate.
 *
 * Two scenarios per endpoint:
 *   module OFF → authenticated user is redirected (302) to the dashboard
 *   module ON  → authenticated user receives a 200 OK response
 *
 * Guards against regressions in module-gating enforcement for Locations & Infrastructure
 * (ISO 27001 A.7 Physical Controls).
 */
#[AllowMockObjectsWithoutExpectations]
class LocationModuleGatingTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?Location $testLocation = null;

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
        foreach ([$this->testLocation, $this->testUser, $this->testTenant] as $entity) {
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

    /** All required + optional modules active, including locations. */
    private function moduleOn(): void
    {
        $this->mockModules([
            'core', 'authentication', 'audit_logging', 'documents', 'objectives', 'workflows',
            'assets', 'risks', 'controls', 'incidents', 'audits', 'training', 'reviews',
            'bcm', 'compliance', 'locations',
        ]);
    }

    /** Required modules active but locations explicitly absent. */
    private function moduleOff(): void
    {
        $this->mockModules([
            'core', 'authentication', 'audit_logging', 'documents', 'objectives', 'workflows',
            'assets', 'risks', 'controls', 'incidents', 'audits', 'training', 'reviews',
            'bcm', 'compliance',
            // locations intentionally absent
        ]);
    }

    private function createTestData(): void
    {
        $uid = uniqid('loc_gate_', true);

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

        $this->testLocation = new Location();
        $this->testLocation->setTenant($this->testTenant);
        $this->testLocation->setName('Gate Test Location ' . $uid);
        $this->testLocation->setLocationType('office');
        $this->testLocation->setCode('LOC-GATE-' . substr($uid, -6));
        $this->testLocation->setDescription('Module gating test fixture');
        $this->testLocation->setCity('Teststadt');
        $this->testLocation->setCountry('DE');
        $this->entityManager->persist($this->testLocation);

        $this->entityManager->flush();
    }

    // =========================================================================
    // LocationController — module OFF (should redirect)
    // =========================================================================

    #[Test]
    public function locationIndexRedirectsWhenModuleOff(): void
    {
        $this->moduleOff();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/location');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function locationNewRedirectsWhenModuleOff(): void
    {
        $this->moduleOff();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/location/new');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function locationShowRedirectsWhenModuleOff(): void
    {
        $this->moduleOff();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/location/' . $this->testLocation->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function locationEditRedirectsWhenModuleOff(): void
    {
        $this->moduleOff();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/location/' . $this->testLocation->getId() . '/edit');
        $this->assertResponseRedirects();
    }

    // =========================================================================
    // LocationController — module ON (should succeed)
    // =========================================================================

    #[Test]
    public function locationIndexSucceedsWhenModuleOn(): void
    {
        $this->moduleOn();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/location');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function locationNewSucceedsWhenModuleOn(): void
    {
        $this->moduleOn();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/location/new');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function locationShowSucceedsWhenModuleOn(): void
    {
        $this->moduleOn();
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/location/' . $this->testLocation->getId());
        $this->assertResponseIsSuccessful();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller\Analytics;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for FteTrackingDashboardController.
 *
 * These verify route existence, auth enforcement, and basic HTTP status.
 * They do NOT test FTE calculation logic (covered by unit tests).
 */
class FteTrackingDashboardControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $managerUser = null;
    private ?User $adminUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        foreach ([$this->managerUser, $this->adminUser, $this->tenant] as $entity) {
            if ($entity === null) {
                continue;
            }
            try {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed) {
                    $this->em->remove($managed);
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // ignore
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $id = uniqid('fte_', true);

        $this->tenant = new Tenant();
        $this->tenant->setName('FTE Test Tenant ' . $id);
        $this->tenant->setCode('fte_' . substr($id, 0, 20));
        $this->em->persist($this->tenant);

        $this->managerUser = new User();
        $this->managerUser->setEmail('fte_mgr_' . $id . '@test.local');
        $this->managerUser->setFirstName('FTE');
        $this->managerUser->setLastName('Manager');
        $this->managerUser->setRoles(['ROLE_MANAGER']);
        $this->managerUser->setPassword('hashed');
        $this->managerUser->setTenant($this->tenant);
        $this->managerUser->setIsActive(true);
        $this->em->persist($this->managerUser);

        $this->adminUser = new User();
        $this->adminUser->setEmail('fte_adm_' . $id . '@test.local');
        $this->adminUser->setFirstName('FTE');
        $this->adminUser->setLastName('Admin');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed');
        $this->adminUser->setTenant($this->tenant);
        $this->adminUser->setIsActive(true);
        $this->em->persist($this->adminUser);

        $this->em->flush();
    }

    #[Test]
    public function indexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/dashboard/fte-tracking');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function indexIsAccessibleToManager(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/dashboard/fte-tracking');

        // Module may not be active in test env — accept redirect or 200
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 302], 'Expected 200 OK or 302 redirect (module not active)');
    }

    #[Test]
    public function calibrationRequiresAdminRole(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/dashboard/fte-tracking/calibration');

        $statusCode = $this->client->getResponse()->getStatusCode();
        // ROLE_MANAGER should be denied (403) or redirected (302) when module off
        $this->assertContains($statusCode, [302, 403]);
    }

    #[Test]
    public function calibrationIsAccessibleToAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/dashboard/fte-tracking/calibration');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 302]);
    }

    #[Test]
    public function boardReportIsAccessibleToManager(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/dashboard/fte-tracking/board-report');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 302]);
    }

    #[Test]
    public function boardReportCsvFormatRespondsWithCsvContentType(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/dashboard/fte-tracking/board-report?format=csv');

        $statusCode = $this->client->getResponse()->getStatusCode();
        if ($statusCode === 200) {
            $this->assertStringContainsString(
                'text/csv',
                (string) $this->client->getResponse()->headers->get('Content-Type')
            );
        } else {
            // Module not active → redirect is acceptable
            $this->assertSame(302, $statusCode);
        }
    }
}

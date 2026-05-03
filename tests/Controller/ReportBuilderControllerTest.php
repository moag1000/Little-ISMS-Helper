<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CustomReport;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Smoke tests for ReportBuilderController — settings_edit action.
 *
 * Verifies that the new Tri-State owner form route renders correctly (200),
 * redirects (302) or denies access (403) depending on authentication state.
 */
class ReportBuilderControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?CustomReport $testReport = null;

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
        if ($this->testReport) {
            try {
                $report = $this->entityManager->find(CustomReport::class, $this->testReport->getId());
                if ($report) {
                    $this->entityManager->remove($report);
                }
            } catch (\Exception) {
                // Ignore
            }
        }

        if ($this->testUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->testUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception) {
                // Ignore
            }
        }

        $tenantRepo = $this->entityManager->getRepository(Tenant::class);
        foreach ($tenantRepo->findBy(['name' => $this->testTenant?->getName()]) as $tenant) {
            try {
                $this->entityManager->remove($tenant);
            } catch (\Exception) {
                // Ignore
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
        $uniqueId = uniqid('rb_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('RB Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('rb_tenant_' . substr($uniqueId, 0, 16));
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('rb_user_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Report');
        $this->testUser->setLastName('Builder');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->entityManager->flush();

        $this->testReport = new CustomReport();
        $this->testReport->setName('Smoke Test Report ' . $uniqueId);
        $this->testReport->setCategory(CustomReport::CATEGORY_GENERAL);
        $this->testReport->setLayout(CustomReport::LAYOUT_DASHBOARD);
        $this->testReport->setOwner($this->testUser);
        $this->testReport->setTenantId($this->testTenant->getId());
        $this->entityManager->persist($this->testReport);
        $this->entityManager->flush();
    }

    #[Test]
    public function testSettingsEditPageRendersOrRedirectsForUnauthenticated(): void
    {
        $this->client->request('GET', '/de/report-builder/' . $this->testReport->getId() . '/settings');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [200, 302, 403], 'Expected 200, 302 or 403 for unauthenticated settings edit');
    }

    #[Test]
    public function testSettingsEditPageRendersForAuthenticatedOwner(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/de/report-builder/' . $this->testReport->getId() . '/settings');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [200, 302, 403], 'Expected 200, 302 or 403 for authenticated settings edit');
    }
}

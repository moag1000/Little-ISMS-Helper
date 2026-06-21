<?php

declare(strict_types=1);

namespace App\Tests\Smoke\Planning;

use App\Entity\PlanningSettings;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Functional smoke tests for the resource_planning HTTP surface.
 *
 * Mirrors the CrisisTeamControllerTest harness exactly: createClient + mocked
 * ModuleConfigurationService (resource_planning active) + unique Tenant/User +
 * loginUser. No manual setup-lock — CI provisions its own DB.
 */
#[AllowMockObjectsWithoutExpectations]
final class PlanningRoutesSmokeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UrlGeneratorInterface $urlGenerator;
    private ?Tenant $testTenant = null;
    private ?User $managerUser = null;
    private ?User $plainUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $moduleService = $this->createMock(\App\Service\ModuleConfigurationService::class);
        $moduleService->method('isModuleActive')->willReturnCallback(
            fn (string $key) => in_array($key, [
                'core', 'authentication', 'documents', 'audit_logging',
                'workflows', 'objectives', 'resource_planning',
            ], true)
        );
        $container->set(\App\Service\ModuleConfigurationService::class, $moduleService);

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->urlGenerator = $container->get(UrlGeneratorInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        $tenantId = $this->testTenant?->getId();
        if ($tenantId !== null) {
            try {
                foreach ($this->entityManager->getRepository(PlanningSettings::class)->findBy(['tenant' => $tenantId]) as $ps) {
                    $this->entityManager->remove($ps);
                }
                foreach ([$this->managerUser, $this->plainUser] as $user) {
                    if ($user === null) {
                        continue;
                    }
                    $managed = $this->entityManager->find(User::class, $user->getId());
                    if ($managed) {
                        $this->entityManager->remove($managed);
                    }
                }
                $this->entityManager->flush();

                $tenant = $this->entityManager->find(Tenant::class, $tenantId);
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                    $this->entityManager->flush();
                }
            } catch (\Exception) {
            }
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('plan_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Plan Tenant ' . $uniqueId);
        $this->testTenant->setCode('plan_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        $this->managerUser = new User();
        $this->managerUser->setEmail('manager_' . $uniqueId . '@example.com');
        $this->managerUser->setFirstName('Manager');
        $this->managerUser->setLastName('User');
        $this->managerUser->setRoles(['ROLE_MANAGER']);
        $this->managerUser->setPassword('hashed_password');
        $this->managerUser->setTenant($this->testTenant);
        $this->managerUser->setIsActive(true);
        $this->entityManager->persist($this->managerUser);

        $this->plainUser = new User();
        $this->plainUser->setEmail('plain_' . $uniqueId . '@example.com');
        $this->plainUser->setFirstName('Plain');
        $this->plainUser->setLastName('User');
        $this->plainUser->setRoles(['ROLE_USER']);
        $this->plainUser->setPassword('hashed_password');
        $this->plainUser->setTenant($this->testTenant);
        $this->plainUser->setIsActive(true);
        $this->entityManager->persist($this->plainUser);

        $this->entityManager->flush();
    }

    private function url(string $routeName, array $params = []): string
    {
        return $this->urlGenerator->generate(
            $routeName,
            array_merge(['_locale' => 'en'], $params),
        );
    }

    #[Test]
    public function planningHubRedirectsThenAdminLoads(): void
    {
        $this->client->loginUser($this->managerUser);

        // /en/planning -> 302 redirect to /en/planning/admin
        $this->client->request('GET', $this->url('app_planning_index'));
        $this->assertResponseRedirects();

        // Follow to admin -> 200, no 500.
        $this->client->request('GET', $this->url('app_planning_admin'));
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function capacityReportLoadsWithEmptyData(): void
    {
        // No teams, no allocations seeded -> must not divide by zero.
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', $this->url('app_planning_roadmap_report'));
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function capacityReportForbiddenForPlainUser(): void
    {
        $this->client->loginUser($this->plainUser);
        $this->client->request('GET', $this->url('app_planning_roadmap_report'));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function teamsRouteLoadsForManager(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', $this->url('app_planning_team_index'));
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function measuresDueFilterLoadsForUser(): void
    {
        $this->client->loginUser($this->plainUser);
        $this->client->request('GET', $this->url('app_planning_action_item_index', ['filter' => 'due']));
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function settingsValidPostPersists(): void
    {
        $this->client->loginUser($this->managerUser);
        $crawler = $this->client->request('GET', $this->url('app_planning_settings'));
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('button[name="form[save]"], input[name="form[save]"]')->form();
        $form['form[fullTimeHoursPerWeek]'] = '40';
        $form['form[hoursPerDay]'] = '8';
        $this->client->submit($form);

        $this->assertResponseRedirects();

        // Re-read: a valid value was stored.
        $this->entityManager->clear();
        $settings = $this->entityManager->getRepository(PlanningSettings::class)
            ->findOneBy(['tenant' => $this->testTenant->getId()]);
        self::assertNotNull($settings);
        self::assertSame(8.0, $settings->getHoursPerDay());
    }

    #[Test]
    public function settingsInvalidPostRejectedAndNotPersisted(): void
    {
        $this->client->loginUser($this->managerUser);
        $crawler = $this->client->request('GET', $this->url('app_planning_settings'));
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('button[name="form[save]"], input[name="form[save]"]')->form();
        $form['form[fullTimeHoursPerWeek]'] = '40';
        $form['form[hoursPerDay]'] = '0'; // violates Assert\Positive -> H-1 guard
        $this->client->submit($form);

        $status = $this->client->getResponse()->getStatusCode();
        // Rejection proof: NOT a 500, and NOT a 302-success redirect.
        self::assertNotSame(500, $status);
        self::assertFalse(
            $this->client->getResponse()->isRedirection(),
            'Invalid settings form must not redirect (no successful save).',
        );

        // Invalid value must NOT be persisted.
        $this->entityManager->clear();
        $settings = $this->entityManager->getRepository(PlanningSettings::class)
            ->findOneBy(['tenant' => $this->testTenant->getId()]);
        if ($settings !== null) {
            self::assertNotSame(0.0, $settings->getHoursPerDay());
        }
    }
}

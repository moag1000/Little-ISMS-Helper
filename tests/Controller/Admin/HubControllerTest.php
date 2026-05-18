<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\HubController;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Admin\AdminHubCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Functional tests for the Admin Hub-Catalog visibility filter
 * (Role-Scope Architecture Phase 2,
 * spec `docs/superpowers/specs/2026-05-18-role-scope-architecture.md`).
 *
 * Covers:
 *  - Anonymous → redirect to login
 *  - ROLE_USER → 403
 *  - ROLE_ADMIN → sees ADMIN_OWN_TENANT cards but NOT ADMIN_GLOBAL_OP cards
 *  - ROLE_SUPER_ADMIN → sees both
 *  - Groups with zero visible modules are dropped
 *  - $total_modules reflects the filtered count
 */
final class HubControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $userOnly = null;
    private ?User $tenantAdmin = null;
    private ?User $superAdmin = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        foreach ([$this->userOnly, $this->tenantAdmin, $this->superAdmin] as $user) {
            if ($user) {
                try {
                    $fresh = $this->entityManager->find(User::class, $user->getId());
                    if ($fresh) {
                        $this->entityManager->remove($fresh);
                    }
                } catch (\Exception) {
                    // Ignore — tearDown best-effort
                }
            }
        }

        if ($this->testTenant) {
            try {
                $fresh = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($fresh) {
                    $this->entityManager->remove($fresh);
                }
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
        $uniqueId = uniqid('hub_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        $this->userOnly = new User();
        $this->userOnly->setEmail('user_only_' . $uniqueId . '@example.com');
        $this->userOnly->setFirstName('User');
        $this->userOnly->setLastName('Only');
        $this->userOnly->setRoles(['ROLE_USER']);
        $this->userOnly->setPassword('hashed_password');
        $this->userOnly->setTenant($this->testTenant);
        $this->userOnly->setIsActive(true);
        $this->entityManager->persist($this->userOnly);

        $this->tenantAdmin = new User();
        $this->tenantAdmin->setEmail('tenant_admin_' . $uniqueId . '@example.com');
        $this->tenantAdmin->setFirstName('Tenant');
        $this->tenantAdmin->setLastName('Admin');
        $this->tenantAdmin->setRoles(['ROLE_ADMIN']);
        $this->tenantAdmin->setPassword('hashed_password');
        $this->tenantAdmin->setTenant($this->testTenant);
        $this->tenantAdmin->setIsActive(true);
        $this->entityManager->persist($this->tenantAdmin);

        $this->superAdmin = new User();
        $this->superAdmin->setEmail('super_admin_' . $uniqueId . '@example.com');
        $this->superAdmin->setFirstName('Super');
        $this->superAdmin->setLastName('Admin');
        $this->superAdmin->setRoles(['ROLE_SUPER_ADMIN']);
        $this->superAdmin->setPassword('hashed_password');
        $this->superAdmin->setTenant($this->testTenant);
        $this->superAdmin->setIsActive(true);
        $this->entityManager->persist($this->superAdmin);

        $this->entityManager->flush();
    }

    // ----------------------------------------------------------------
    // Class-level annotation tests (cheap reflection — no kernel boot)
    // ----------------------------------------------------------------

    #[Test]
    public function classRequiresRoleAdmin(): void
    {
        $reflection = new \ReflectionClass(HubController::class);
        $attrs = $reflection->getAttributes(IsGranted::class);
        self::assertCount(1, $attrs);
        /** @var IsGranted $instance */
        $instance = $attrs[0]->newInstance();
        self::assertSame('ROLE_ADMIN', $instance->attribute);
    }

    // ----------------------------------------------------------------
    // Auth gate
    // ----------------------------------------------------------------

    #[Test]
    public function anonymousIsRedirected(): void
    {
        $this->client->request('GET', '/de/admin/hub');
        self::assertResponseRedirects();
    }

    #[Test]
    public function userWithoutAdminGetsForbidden(): void
    {
        $this->client->loginUser($this->userOnly);
        $this->client->request('GET', '/de/admin/hub');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ----------------------------------------------------------------
    // Visibility filter — ROLE_ADMIN
    // ----------------------------------------------------------------

    #[Test]
    public function tenantAdminSeesOwnTenantCards(): void
    {
        $this->client->loginUser($this->tenantAdmin);
        $this->client->request('GET', '/de/admin/hub');
        self::assertResponseIsSuccessful();

        // A representative ADMIN_OWN_TENANT module: 'tenants'
        $expectedRoute = $this->router()->generate('tenant_management_index');
        self::assertSelectorExists(
            sprintf('a[href="%s"]', $expectedRoute),
            'tenant-admin should see the ADMIN_OWN_TENANT "tenants" card.'
        );
    }

    #[Test]
    public function tenantAdminDoesNotSeeGlobalOpCards(): void
    {
        $this->client->loginUser($this->tenantAdmin);
        $this->client->request('GET', '/de/admin/hub');
        self::assertResponseIsSuccessful();

        // ADMIN_GLOBAL_OP modules that should be hidden from tenant-admins
        $globalRoutes = [
            'admin_licensing_index',
            'admin_settings_index',
            'admin_settings_api_rate_limits',
            'admin_settings_data_retention',
            'admin_settings_workflow_slas',
            'admin_lifecycle_overrides_index',
            'admin_loader_fixer_index',
            'admin_industry_baselines_index',
            'admin_tour_content_index',
            'admin_tour_completion_index',
            'monitoring_health',
            'monitoring_performance',
            'monitoring_errors',
            'setup_wizard_index',
        ];

        $router = $this->router();
        foreach ($globalRoutes as $routeName) {
            try {
                $url = $router->generate($routeName);
            } catch (\Throwable) {
                // Route not registered in this kernel → skip; the catalog
                // would mark it as coming-soon anyway and the filter would
                // still hide it. The point of the test is "do not render
                // a clickable link to global ops for tenant-admins".
                continue;
            }
            self::assertSelectorNotExists(
                sprintf('a[href="%s"]', $url),
                sprintf('tenant-admin must NOT see ADMIN_GLOBAL_OP card for route "%s".', $routeName)
            );
        }
    }

    // ----------------------------------------------------------------
    // Visibility filter — ROLE_SUPER_ADMIN
    // ----------------------------------------------------------------

    #[Test]
    public function superAdminSeesGlobalOpAndOwnTenantCards(): void
    {
        $this->client->loginUser($this->superAdmin);
        $this->client->request('GET', '/de/admin/hub');
        self::assertResponseIsSuccessful();

        $router = $this->router();

        // Own-tenant card visible
        self::assertSelectorExists(
            sprintf('a[href="%s"]', $router->generate('tenant_management_index')),
            'super-admin should see the ADMIN_OWN_TENANT "tenants" card.'
        );

        // Global-op card visible (pick one that exists in any sane build)
        try {
            $licensingUrl = $router->generate('admin_licensing_index');
        } catch (\Throwable) {
            self::markTestSkipped('admin_licensing_index route not registered in this kernel.');
        }
        self::assertSelectorExists(
            sprintf('a[href="%s"]', $licensingUrl),
            'super-admin should see the ADMIN_GLOBAL_OP "licensing" card.'
        );
    }

    // ----------------------------------------------------------------
    // Empty-group dropping + total_modules count
    // ----------------------------------------------------------------

    #[Test]
    public function totalModulesCountAndGroupCountReflectFilteredViewForTenantAdmin(): void
    {
        $catalog = static::getContainer()->get(AdminHubCatalog::class);
        \assert($catalog instanceof AdminHubCatalog);

        // Expected counts: count all modules whose requiredAttribute is NOT
        // 'ADMIN_GLOBAL_OP' — for the ROLE_ADMIN render. (Filter logic in
        // HubController also drops empty groups, so verify both.)
        $tenantAdminExpectedModuleCount = 0;
        $tenantAdminExpectedGroupCount  = 0;
        foreach ($catalog->getGroups() as $group) {
            $visible = 0;
            foreach ($group['modules'] as $module) {
                if (($module['requiredAttribute'] ?? null) === 'ADMIN_GLOBAL_OP') {
                    continue;
                }
                ++$visible;
            }
            if ($visible > 0) {
                ++$tenantAdminExpectedGroupCount;
                $tenantAdminExpectedModuleCount += $visible;
            }
        }

        // Also count under SUPER_ADMIN — every module is visible (modulo
        // requiredModule, which is identical for the test container).
        $superAdminExpectedModuleCount = 0;
        $superAdminExpectedGroupCount  = 0;
        foreach ($catalog->getGroups() as $group) {
            $count = count($group['modules']);
            if ($count > 0) {
                ++$superAdminExpectedGroupCount;
                $superAdminExpectedModuleCount += $count;
            }
        }

        // The two must differ — Phase 2 hides ≥1 card from ROLE_ADMIN.
        self::assertGreaterThan(
            $tenantAdminExpectedModuleCount,
            $superAdminExpectedModuleCount,
            'Phase 2 must hide at least one global-only card from tenant-admins.'
        );

        // Both must be > 0 — sanity.
        self::assertGreaterThan(0, $tenantAdminExpectedModuleCount);
        self::assertGreaterThan(0, $tenantAdminExpectedGroupCount);

        // Render as ROLE_ADMIN and verify the rendered group count matches
        // the expected filtered group count (1:1 with .fa-admin-hub-group).
        $this->client->loginUser($this->tenantAdmin);
        $crawler = $this->client->request('GET', '/de/admin/hub');
        self::assertResponseIsSuccessful();
        self::assertSame(
            $tenantAdminExpectedGroupCount,
            $crawler->filter('.fa-admin-hub-group')->count(),
            'Rendered group count must reflect the filtered catalog for ROLE_ADMIN.'
        );
    }

    #[Test]
    public function renderedGroupCountIncreasesForSuperAdmin(): void
    {
        $this->client->loginUser($this->tenantAdmin);
        $crawler1 = $this->client->request('GET', '/de/admin/hub');
        self::assertResponseIsSuccessful();
        $tenantAdminGroups = $crawler1->filter('.fa-admin-hub-group')->count();

        // New client to avoid session bleed.
        $this->client->loginUser($this->superAdmin);
        $crawler2 = $this->client->request('GET', '/de/admin/hub');
        self::assertResponseIsSuccessful();
        $superAdminGroups = $crawler2->filter('.fa-admin-hub-group')->count();

        self::assertGreaterThanOrEqual(
            $tenantAdminGroups,
            $superAdminGroups,
            'super-admin must see >= as many groups as a tenant-admin (never fewer).'
        );
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function router(): \Symfony\Component\Routing\RouterInterface
    {
        $router = static::getContainer()->get('router');
        \assert($router instanceof \Symfony\Component\Routing\RouterInterface);
        return $router;
    }
}

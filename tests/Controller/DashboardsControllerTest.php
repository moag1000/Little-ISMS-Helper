<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persona-role dashboard visibility tests.
 *
 * Role-Scope Architecture Phase 6 (spec
 * `docs/superpowers/specs/2026-05-18-role-scope-architecture.md`). Verifies
 * the four persona dashboards now guarded via
 * `TenantScopedAdminVoter::PERSONA_*` attributes (semantic equivalent of the
 * previous `#[IsGranted('ROLE_X')]`):
 *
 *   - /{_locale}/dashboards/ciso              → PERSONA_CISO       (ROLE_CISO)
 *   - /{_locale}/dashboards/risk-manager      → PERSONA_RISK       (ROLE_RISK_MANAGER)
 *   - /{_locale}/dashboards/dpo               → PERSONA_DPO        (ROLE_DPO)
 *   - /{_locale}/dashboards/compliance-manager → PERSONA_COMPLIANCE (ROLE_COMPLIANCE_MANAGER)
 *
 * For each persona, two assertions:
 *   1. User holding the matching role gets 200 OK (or 302/404 — anything
 *      that is NOT 403, since the dashboard body may still need optional
 *      tenant fixtures).
 *   2. Plain ROLE_USER without the persona role gets 403.
 *
 * Additionally: ROLE_ADMIN inherits all persona roles via the role
 * hierarchy and must see every dashboard (smoke check, not 403).
 */
class DashboardsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    private ?Tenant $tenant = null;
    private ?User $userPlain = null;
    private ?User $userCiso = null;
    private ?User $userRisk = null;
    private ?User $userDpo = null;
    private ?User $userCompliance = null;
    private ?User $userAdmin = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        // Module-gate the dashboards: 'privacy' active (DPO needs it implicitly via
        // module-aware templates), 'compliance' active (Compliance-Manager-Dashboard
        // assumes it). Use a permissive stub so we don't have to seed active_modules.
        $moduleService = $this->createMock(ModuleConfigurationService::class);
        $moduleService->method('isModuleActive')->willReturn(true);
        $container->set(ModuleConfigurationService::class, $moduleService);

        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        foreach ([$this->userPlain, $this->userCiso, $this->userRisk, $this->userDpo, $this->userCompliance, $this->userAdmin] as $u) {
            if ($u === null) {
                continue;
            }
            try {
                $managed = $this->entityManager->find(User::class, $u->getId());
                if ($managed) {
                    $this->entityManager->remove($managed);
                }
            } catch (\Exception) {
                // ignore
            }
        }
        if ($this->tenant) {
            try {
                $managed = $this->entityManager->find(Tenant::class, $this->tenant->getId());
                if ($managed) {
                    $this->entityManager->remove($managed);
                }
            } catch (\Exception) {
                // ignore
            }
        }
        try {
            $this->entityManager->flush();
        } catch (\Exception) {
            // ignore
        }
        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uid = uniqid('persona_', true);

        $this->tenant = new Tenant();
        $this->tenant->setName('Persona Tenant ' . $uid);
        $this->tenant->setCode('persona_tenant_' . $uid);
        $this->entityManager->persist($this->tenant);

        $this->userPlain      = $this->makeUser('plain_' . $uid, ['ROLE_USER']);
        $this->userCiso       = $this->makeUser('ciso_' . $uid, ['ROLE_CISO']);
        $this->userRisk       = $this->makeUser('risk_' . $uid, ['ROLE_RISK_MANAGER']);
        $this->userDpo        = $this->makeUser('dpo_' . $uid, ['ROLE_DPO']);
        $this->userCompliance = $this->makeUser('compliance_' . $uid, ['ROLE_COMPLIANCE_MANAGER']);
        $this->userAdmin      = $this->makeUser('admin_' . $uid, ['ROLE_ADMIN']);

        $this->entityManager->flush();
    }

    /**
     * @param list<string> $roles
     */
    private function makeUser(string $tag, array $roles): User
    {
        $u = new User();
        $u->setEmail($tag . '@example.com');
        $u->setFirstName('Persona');
        $u->setLastName($tag);
        $u->setRoles($roles);
        $u->setPassword('hashed_password');
        $u->setTenant($this->tenant);
        $u->setIsActive(true);
        $this->entityManager->persist($u);
        return $u;
    }

    // ========== CISO Dashboard ==========

    #[Test]
    public function cisoDashboardAllowsRoleCiso(): void
    {
        $this->client->loginUser($this->userCiso);
        $this->client->request('GET', '/en/dashboards/ciso');
        $this->assertNotSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function cisoDashboardForbidsPlainUser(): void
    {
        $this->client->loginUser($this->userPlain);
        $this->client->request('GET', '/en/dashboards/ciso');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== Risk-Manager Dashboard ==========

    #[Test]
    public function riskManagerDashboardAllowsRoleRiskManager(): void
    {
        $this->client->loginUser($this->userRisk);
        $this->client->request('GET', '/en/dashboards/risk-manager');
        $this->assertNotSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function riskManagerDashboardForbidsPlainUser(): void
    {
        $this->client->loginUser($this->userPlain);
        $this->client->request('GET', '/en/dashboards/risk-manager');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== DPO Dashboard ==========

    #[Test]
    public function dpoDashboardAllowsRoleDpo(): void
    {
        $this->client->loginUser($this->userDpo);
        $this->client->request('GET', '/en/dashboards/dpo');
        // 200 OK (with tenant) or any non-403 (controller may 404 on missing
        // tenant). The gate test is "NOT 403".
        $this->assertNotSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function dpoDashboardForbidsPlainUser(): void
    {
        $this->client->loginUser($this->userPlain);
        $this->client->request('GET', '/en/dashboards/dpo');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== Compliance-Manager Dashboard ==========

    #[Test]
    public function complianceManagerDashboardAllowsRoleComplianceManager(): void
    {
        $this->client->loginUser($this->userCompliance);
        $this->client->request('GET', '/en/dashboards/compliance-manager');
        $this->assertNotSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function complianceManagerDashboardForbidsPlainUser(): void
    {
        $this->client->loginUser($this->userPlain);
        $this->client->request('GET', '/en/dashboards/compliance-manager');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== ROLE_ADMIN inheritance — sees ALL persona dashboards ==========

    #[Test]
    public function roleAdminInheritsAllPersonaDashboards(): void
    {
        $this->client->loginUser($this->userAdmin);

        foreach ([
            '/en/dashboards/ciso',
            '/en/dashboards/risk-manager',
            '/en/dashboards/dpo',
            '/en/dashboards/compliance-manager',
        ] as $url) {
            $this->client->request('GET', $url);
            $this->assertNotSame(
                Response::HTTP_FORBIDDEN,
                $this->client->getResponse()->getStatusCode(),
                "ROLE_ADMIN was forbidden from $url — persona-role inheritance is broken."
            );
        }
    }

    // ========== Persona-role isolation — CISO does NOT see DPO dashboard ==========

    #[Test]
    public function cisoUserIsForbiddenFromDpoDashboard(): void
    {
        $this->client->loginUser($this->userCiso);
        $this->client->request('GET', '/en/dashboards/dpo');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function dpoUserIsForbiddenFromCisoDashboard(): void
    {
        $this->client->loginUser($this->userDpo);
        $this->client->request('GET', '/en/dashboards/ciso');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}

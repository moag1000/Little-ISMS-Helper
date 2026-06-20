<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC tests for DataReuseHubController.
 *
 * The Data-Reuse-Hub is a READ-ONLY KPI dashboard (FTE saved, top-reused
 * docs/suppliers) and the junior's entry point to the inheritance queue CTA.
 * It has NO write actions. Relaxing the gate from ROLE_MANAGER → ROLE_USER
 * lets juniors view the payoff of the mapping work (ISO 27001 A.5.3 does
 * not restrict read-only KPI visibility).
 */
class DataReuseHubControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $userRole = null;
    private ?User $managerRole = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $uniqueId = uniqid('drh_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('DRH Tenant ' . $uniqueId);
        $this->testTenant->setCode('drh_' . substr(md5($uniqueId), 0, 12));
        $this->entityManager->persist($this->testTenant);

        $this->userRole = $this->makeUser('user_' . $uniqueId, ['ROLE_USER']);
        $this->managerRole = $this->makeUser('mgr_' . $uniqueId, ['ROLE_MANAGER']);

        $this->entityManager->flush();
    }

    private function makeUser(string $id, array $roles): User
    {
        $user = new User();
        $user->setEmail($id . '@example.com');
        $user->setFirstName('T');
        $user->setLastName('U');
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        $user->setTenant($this->testTenant);
        $user->setIsActive(true);
        $this->entityManager->persist($user);

        return $user;
    }

    protected function tearDown(): void
    {
        foreach ([$this->userRole, $this->managerRole] as $u) {
            if ($u) {
                try {
                    $found = $this->entityManager->find(User::class, $u->getId());
                    if ($found) {
                        $this->entityManager->remove($found);
                    }
                } catch (\Exception) {
                }
            }
        }
        if ($this->testTenant) {
            try {
                $found = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($found) {
                    $this->entityManager->remove($found);
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

    // ----- index() — the main hub page -----

    #[Test]
    public function testRoleUserCanViewHub(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/reuse');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK, 'ROLE_USER must be able to GET the Data-Reuse-Hub (was 403 before this fix).');
    }

    #[Test]
    public function testRoleManagerCanViewHub(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('GET', '/en/reuse');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK, 'ROLE_MANAGER must still be able to GET the Data-Reuse-Hub.');
    }

    // ----- heatmap() — the entity-level heatmap page -----

    #[Test]
    public function testRoleUserCanViewHeatmap(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/reuse/heatmap');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK, 'ROLE_USER must be able to GET the heatmap (read-only).');
    }

    #[Test]
    public function testRoleManagerCanViewHeatmap(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('GET', '/en/reuse/heatmap');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK, 'ROLE_MANAGER must be able to GET the heatmap.');
    }

    // ----- Unauthenticated users are redirected (not 200 / not 403) -----

    #[Test]
    public function testUnauthenticatedUserIsRedirectedFromHub(): void
    {
        $this->client->request('GET', '/en/reuse');

        // Security layer redirects unauthenticated requests to /login.
        $this->assertTrue(
            $this->client->getResponse()->isRedirect(),
            'Unauthenticated GET /en/reuse must result in a redirect to login.',
        );
    }
}

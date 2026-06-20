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
 * RBAC tests for SeedReviewQueueController.
 *
 * ROLE_USER may READ the seed-review queue (audit transparency) but the
 * approve/reject WRITE actions stay gated to ROLE_MANAGER.
 */
class SeedReviewQueueControllerRbacTest extends WebTestCase
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

        $uniqueId = uniqid('seed_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Seed Tenant ' . $uniqueId);
        $this->testTenant->setCode('seed_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        $this->userRole = $this->makeUser('seeduser_' . $uniqueId, ['ROLE_USER']);
        $this->managerRole = $this->makeUser('seedmanager_' . $uniqueId, ['ROLE_MANAGER']);

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

    // ----- READ access: ROLE_USER can view the queue (read-only) -----

    #[Test]
    public function testRoleUserCanReadQueueWithoutWriteControls(): void
    {
        $this->client->loginUser($this->userRole);
        $crawler = $this->client->request('GET', '/en/compliance/mapping/seed-review');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertSame(
            0,
            $crawler->filter('form[action*="/approve"], form[action*="/reject"]')->count(),
            'ROLE_USER must not see seed-review write forms.',
        );
        $this->assertStringContainsString(
            'four-eyes principle',
            $this->client->getResponse()->getContent() ?: '',
            'Manager-hint (four-eyes) text must appear for ROLE_USER.',
        );
    }

    // ----- WRITE actions: ROLE_USER forbidden (403) -----

    #[Test]
    public function testRoleUserPostApproveForbidden(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/compliance/mapping/seed-review/1/approve');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testRoleUserPostRejectForbidden(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/compliance/mapping/seed-review/1/reject');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ----- MANAGER: read 200, write past the role gate (not 403) -----

    #[Test]
    public function testRoleManagerCanReadQueue(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('GET', '/en/compliance/mapping/seed-review');
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    #[Test]
    public function testRoleManagerWriteRouteNotForbidden(): void
    {
        $this->client->loginUser($this->managerRole);
        // With a valid CSRF token the manager passes BOTH the role gate and the
        // CSRF check; the only remaining failure is the non-existent mapping →
        // 404 (NOT 403). This distinguishes a role-gate denial from a CSRF denial.
        $token = $this->generateCsrfToken('seed_review_999999');
        $this->client->request(
            'POST',
            '/en/compliance/mapping/seed-review/999999/approve',
            ['_token' => $token],
        );
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertNotSame(
            Response::HTTP_FORBIDDEN,
            $status,
            'ROLE_MANAGER must pass the approve role gate (got 403).',
        );
        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $status,
            'ROLE_MANAGER with valid CSRF reaches the entity lookup → 404 for missing mapping.',
        );
    }

    private function generateCsrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/en/compliance/mapping/seed-review');
        $session = $this->client->getRequest()->getSession();

        $tokenGenerator = new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator();
        $tokenValue = $tokenGenerator->generateToken();
        $session->set('_csrf/' . $tokenId, $tokenValue);
        $session->save();

        return $tokenValue;
    }
}

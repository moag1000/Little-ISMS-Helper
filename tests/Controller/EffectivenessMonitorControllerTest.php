<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for EffectivenessMonitorController.
 *
 * Covers:
 *   - ROLE_AUDITOR can access the page
 *   - ROLE_USER receives 403 (access denied)
 *   - Unauthenticated request is redirected to login
 */
class EffectivenessMonitorControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $userAuditor = null;
    private ?User $userRegular = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        // Clean up test fixtures
        foreach ([$this->userAuditor, $this->userRegular] as $u) {
            if ($u) {
                $found = $this->em->find(User::class, $u->getId());
                if ($found) {
                    $this->em->remove($found);
                }
            }
        }
        if ($this->tenant) {
            $found = $this->em->find(Tenant::class, $this->tenant->getId());
            if ($found) {
                $this->em->remove($found);
            }
        }
        try {
            $this->em->flush();
        } catch (\Exception) {
            // Suppress cleanup errors
        }
        parent::tearDown();
    }

    private function createFixtures(): void
    {
        $uid = uniqid('em_', true);

        $this->tenant = new Tenant();
        $this->tenant->setName('EM Test Tenant ' . $uid);
        $this->tenant->setCode('em_' . substr($uid, 0, 15));
        $this->em->persist($this->tenant);

        $this->userAuditor = new User();
        $this->userAuditor->setEmail('em_auditor_' . $uid . '@test.invalid');
        $this->userAuditor->setFirstName('Auditor');
        $this->userAuditor->setLastName('Test');
        $this->userAuditor->setRoles(['ROLE_AUDITOR']);
        $this->userAuditor->setPassword('hashed');
        $this->userAuditor->setTenant($this->tenant);
        $this->userAuditor->setIsActive(true);
        $this->em->persist($this->userAuditor);

        $this->userRegular = new User();
        $this->userRegular->setEmail('em_user_' . $uid . '@test.invalid');
        $this->userRegular->setFirstName('Regular');
        $this->userRegular->setLastName('User');
        $this->userRegular->setRoles(['ROLE_USER']);
        $this->userRegular->setPassword('hashed');
        $this->userRegular->setTenant($this->tenant);
        $this->userRegular->setIsActive(true);
        $this->em->persist($this->userRegular);

        $this->em->flush();
    }

    // ── Access Control ──────────────────────────────────────────────────────

    #[Test]
    public function testUnauthenticatedRequestRedirectsToLogin(): void
    {
        $this->client->request('GET', '/en/effectiveness-monitor');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testRoleAuditorCanAccessMonitor(): void
    {
        $this->client->loginUser($this->userAuditor);
        $this->client->request('GET', '/en/effectiveness-monitor');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testRoleUserReceives403(): void
    {
        $this->client->loginUser($this->userRegular);
        $this->client->request('GET', '/en/effectiveness-monitor');
        $this->assertResponseStatusCodeSame(403);
    }

    // ── Route + Category Filter ─────────────────────────────────────────────

    #[Test]
    public function testCategoryFilterIsAccepted(): void
    {
        $this->client->loginUser($this->userAuditor);
        $this->client->request('GET', '/en/effectiveness-monitor?category=5.&threshold=12');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testThresholdParameterIsAccepted(): void
    {
        $this->client->loginUser($this->userAuditor);
        $this->client->request('GET', '/en/effectiveness-monitor?threshold=6');
        $this->assertResponseIsSuccessful();
    }
}

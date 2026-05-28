<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditProgram;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditProgramRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Controller tests for AuditProgramController (ISO 19011 §5.4 Audit Programme).
 *
 * Targeted tests per feedback_targeted_tests.md — only AuditProgram scenarios.
 * Run with: php bin/phpunit tests/Controller/AuditProgramControllerTest.php
 */
class AuditProgramControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $auditorUser = null;
    private ?User $managerUser = null;
    private ?AuditProgram $testProgram = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testProgram?->getId()) {
            try {
                $prog = $this->entityManager->find(AuditProgram::class, $this->testProgram->getId());
                if ($prog) {
                    $this->entityManager->remove($prog);
                }
            } catch (\Throwable) {
            }
        }

        foreach ([$this->auditorUser, $this->managerUser] as $user) {
            if ($user?->getId()) {
                try {
                    $u = $this->entityManager->find(User::class, $user->getId());
                    if ($u) {
                        $this->entityManager->remove($u);
                    }
                } catch (\Throwable) {
                }
            }
        }

        if ($this->testTenant?->getId()) {
            try {
                $t = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($t) {
                    $this->entityManager->remove($t);
                }
            } catch (\Throwable) {
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uid = uniqid('ap_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('AuditProg Test Tenant ' . $uid);
        $this->testTenant->setCode('ap_' . substr(md5($uid), 0, 8));
        $this->entityManager->persist($this->testTenant);

        $this->auditorUser = new User();
        $this->auditorUser->setEmail('auditor_' . $uid . '@example.com');
        $this->auditorUser->setFirstName('Auditor');
        $this->auditorUser->setLastName('User');
        $this->auditorUser->setRoles(['ROLE_AUDITOR']);
        $this->auditorUser->setPassword('hashed_password');
        $this->auditorUser->setTenant($this->testTenant);
        $this->auditorUser->setIsActive(true);
        $this->entityManager->persist($this->auditorUser);

        $this->managerUser = new User();
        $this->managerUser->setEmail('manager_' . $uid . '@example.com');
        $this->managerUser->setFirstName('Manager');
        $this->managerUser->setLastName('User');
        $this->managerUser->setRoles(['ROLE_MANAGER']);
        $this->managerUser->setPassword('hashed_password');
        $this->managerUser->setTenant($this->testTenant);
        $this->managerUser->setIsActive(true);
        $this->entityManager->persist($this->managerUser);

        $this->testProgram = new AuditProgram();
        $this->testProgram->setName('Test Audit Programme ' . $uid);
        $this->testProgram->setTenant($this->testTenant);
        $this->testProgram->setStartDate(new DateTimeImmutable('2026-01-01'));
        $this->testProgram->setEndDate(new DateTimeImmutable('2026-12-31'));
        $this->entityManager->persist($this->testProgram);

        $this->entityManager->flush();
    }

    // ── Access control tests ───────────────────────────────────────────────────

    #[Test]
    public function indexRedirectsUnauthenticated(): void
    {
        $this->client->request('GET', '/de/audit-programs');
        self::assertResponseRedirects();
    }

    #[Test]
    public function indexAllowsAuditorRole(): void
    {
        $this->client->loginUser($this->auditorUser);
        $this->client->request('GET', '/de/audit-programs');
        // Either 200 (module active) or 302 (module inactive redirect)
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [200, 302],
            'Auditor should be able to reach the audit program index (or be redirected to module gate)'
        );
    }

    #[Test]
    public function newPageRequiresManagerRole(): void
    {
        $this->client->loginUser($this->auditorUser);
        $this->client->request('GET', '/de/audit-programs/new');
        // Should be 403 or redirect to module gate or login
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function newPageAllowsManagerRole(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/de/audit-programs/new');
        // Either 200 (module active) or 302 (module inactive redirect)
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [200, 302],
            'Manager should be able to reach the new audit program page (or module gate)'
        );
    }

    #[Test]
    public function showRedirectsUnauthenticated(): void
    {
        $id = $this->testProgram->getId();
        $this->client->request('GET', '/de/audit-programs/' . $id);
        self::assertResponseRedirects();
    }

    // ── Entity invariant tests ─────────────────────────────────────────────────

    #[Test]
    public function testProgramHasCorrectStatus(): void
    {
        $repo = static::getContainer()->get(AuditProgramRepository::class);
        $found = $repo->find($this->testProgram->getId());
        self::assertNotNull($found);
        self::assertSame('planning', $found->getStatus());
    }

    #[Test]
    public function testProgramTenantIsolation(): void
    {
        // Programs should be retrievable by tenant
        $programs = static::getContainer()
            ->get(AuditProgramRepository::class)
            ->findAllByTenant($this->testTenant);

        $ids = array_map(fn(AuditProgram $p) => $p->getId(), $programs);
        self::assertContains($this->testProgram->getId(), $ids);
    }
}

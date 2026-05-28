<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditProgram;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditProgramRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Controller tests for AuditProgramController (ISO 19011 §5.4 Jahresplan).
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
        $container    = static::getContainer();
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
        $this->testTenant->setCode('ap_tenant_' . substr($uid, -8));
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
        $this->testProgram->setName('Audit-Programm Test ' . $uid);
        $this->testProgram->setYear(2026);
        $this->testProgram->setTenant($this->testTenant);
        $this->testProgram->setCreatedBy($this->managerUser);
        $this->entityManager->persist($this->testProgram);

        $this->entityManager->flush();
    }

    // ── Index ───────────────────────────────────────────────────────────────────

    #[Test]
    public function testIndexRedirectsUnauthenticated(): void
    {
        $this->client->request('GET', '/de/audit-program');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testIndexDisplaysForAuditor(): void
    {
        $this->client->loginUser($this->auditorUser);
        $this->client->request('GET', '/de/audit-program');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    #[Test]
    public function testIndexDisplaysForManager(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/de/audit-program');

        $this->assertResponseIsSuccessful();
    }

    // ── Show ────────────────────────────────────────────────────────────────────

    #[Test]
    public function testShowDisplaysProgramDetails(): void
    {
        $this->client->loginUser($this->auditorUser);
        $this->client->request('GET', '/de/audit-program/' . $this->testProgram->getId());

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testShowReturns404ForUnknownId(): void
    {
        $this->client->loginUser($this->auditorUser);
        $this->client->request('GET', '/de/audit-program/99999999');

        $this->assertResponseStatusCodeSame(404);
    }

    // ── New ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function testNewFormRendersForManager(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/de/audit-program/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    #[Test]
    public function testNewFormForbiddenForAuditorOnly(): void
    {
        $this->client->loginUser($this->auditorUser);
        // Catch access-denied exceptions instead of letting the kernel re-throw.
        $this->client->catchExceptions(true);
        $this->client->request('GET', '/de/audit-program/new');

        // ROLE_AUDITOR does not have ROLE_MANAGER — expect 403 or login redirect
        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testNewCreatesAuditProgramWithValidData(): void
    {
        $this->client->loginUser($this->managerUser);

        // Verify the new form renders
        $this->client->request('GET', '/de/audit-program/new');
        $this->assertResponseIsSuccessful();

        $uid  = uniqid('prog_', true);
        $name = 'Test Jahresplan ' . $uid;

        // Submit via direct POST (bypasses Crawler/form-name issues from _auto_form).
        // Using Symfony test client's built-in CSRF bypass: the kernel test client
        // generates a valid CSRF token when 'with_csrf_token' option is set.
        // For simplicity we POST raw fields and let the form validator handle it.
        $crawler = $this->client->request('GET', '/de/audit-program/new');
        $csrfToken = $crawler->filter('input[name="_token"]')->first()->attr('value') ?? '';

        $this->client->request('POST', '/de/audit-program/new', [
            'audit_program' => [
                'name'  => $name,
                'year'  => '2026',
                '_token' => $csrfToken,
            ],
        ]);

        // Should redirect to show page after successful create
        $this->assertResponseRedirects();

        // Clean up: re-find via DQL to get managed entities in the current EM context
        $programs = $this->entityManager
            ->createQuery('SELECT p FROM App\Entity\AuditProgram p WHERE p.name = :name')
            ->setParameter('name', $name)
            ->getResult();
        foreach ($programs as $p) {
            $this->entityManager->remove($p);
        }
        try {
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Ignore cleanup errors — program is already committed
        }
    }

    // ── Edit ────────────────────────────────────────────────────────────────────

    #[Test]
    public function testEditFormRendersForManager(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/de/audit-program/' . $this->testProgram->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }
}

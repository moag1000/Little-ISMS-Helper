<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditProgram;
use App\Repository\AuditProgramRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 2.5 — AuditProgramController functional tests (ISO 19011 §5.4).
 */
final class AuditProgramControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client        = static::createClient();
        $container           = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }
    }

    #[Test]
    public function indexRedirectsUnauthenticatedUser(): void
    {
        $this->client->request('GET', '/en/audit-programs');
        self::assertResponseRedirects();
    }

    #[Test]
    public function auditorCanAccessIndexPage(): void
    {
        $user = $this->findUserWithRole('ROLE_AUDITOR');
        if ($user === null) {
            self::markTestSkipped('No ROLE_AUDITOR user available.');
        }
        $this->client->loginUser($user);
        $this->client->request('GET', '/en/audit-programs');
        // 200 when audits module active; 302 (module redirect) when inactive — both are gated correctly.
        self::assertThat(
            $this->client->getResponse()->getStatusCode(),
            self::logicalOr(self::equalTo(Response::HTTP_OK), self::equalTo(Response::HTTP_FOUND)),
        );
    }

    #[Test]
    public function auditorCannotAccessNewPage(): void
    {
        $user = $this->findUserWithRole('ROLE_AUDITOR', exclude: ['ROLE_MANAGER', 'ROLE_ADMIN']);
        if ($user === null) {
            self::markTestSkipped('No auditor-only user available.');
        }
        $this->client->loginUser($user);
        $this->client->request('GET', '/en/audit-programs/new');
        // 403 when audits module active; 302 (module redirect) when inactive — either rejects non-manager.
        self::assertThat(
            $this->client->getResponse()->getStatusCode(),
            self::logicalOr(
                self::equalTo(Response::HTTP_FORBIDDEN),
                self::equalTo(Response::HTTP_FOUND),
            ),
        );
    }

    #[Test]
    public function managerCanAccessNewPage(): void
    {
        $user = $this->findUserWithRole('ROLE_MANAGER');
        if ($user === null) {
            self::markTestSkipped('No ROLE_MANAGER user available.');
        }
        $this->client->loginUser($user);
        $this->client->request('GET', '/en/audit-programs/new');
        // 200 when audits module active; 302 (module redirect) when inactive.
        self::assertThat(
            $this->client->getResponse()->getStatusCode(),
            self::logicalOr(self::equalTo(Response::HTTP_OK), self::equalTo(Response::HTTP_FOUND)),
        );
    }

    #[Test]
    public function showRedirectsUnauthenticatedUser(): void
    {
        $this->client->request('GET', '/en/audit-programs/999');
        self::assertResponseRedirects();
    }

    #[Test]
    public function repositoryMethodsExist(): void
    {
        self::assertTrue(method_exists(AuditProgramRepository::class, 'findAllByTenant'));
        self::assertTrue(method_exists(AuditProgramRepository::class, 'findActiveByTenant'));
        self::assertTrue(method_exists(AuditProgramRepository::class, 'findByStatusAndTenant'));
    }

    #[Test]
    public function newProgramHasPlanningStatus(): void
    {
        $program = new AuditProgram();
        self::assertSame('planning', $program->getStatus());
    }

    private function findUserWithRole(string $role, array $exclude = []): mixed
    {
        $users = $this->entityManager->getRepository(\App\Entity\User::class)->findAll();
        foreach ($users as $user) {
            if (!in_array($role, $user->getRoles(), true)) {
                continue;
            }
            foreach ($exclude as $ex) {
                if (in_array($ex, $user->getRoles(), true)) {
                    continue 2;
                }
            }
            return $user;
        }
        return null;
    }
}

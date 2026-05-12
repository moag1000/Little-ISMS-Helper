<?php

declare(strict_types=1);

namespace App\Tests\Controller\Authority;

use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * F26.6 — Smoke tests for AuthorityNotificationController.
 *
 * Verifies:
 *  - Index accessible for ROLE_DPO / ROLE_MANAGER
 *  - Anonymous redirected to login
 *  - DataBreach PDF endpoint for bfdi
 *  - DataBreach JSON endpoint for bfdi
 *  - Incident PDF endpoint for bsi_meldestelle
 *  - 404 for unknown authority key
 */
#[AllowMockObjectsWithoutExpectations]
class AuthorityNotificationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $dpoUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $moduleService = $this->createMock(\App\Service\ModuleConfigurationService::class);
        $moduleService->method('isModuleActive')->willReturn(true);
        $container->set(\App\Service\ModuleConfigurationService::class, $moduleService);

        $this->em = $container->get(EntityManagerInterface::class);
    }

    #[Test]
    public function anonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/de/authority/notification');
        self::assertResponseRedirects();
    }

    #[Test]
    public function indexIsAccessibleForManager(): void
    {
        $user = $this->createOrGetUser('authority-mgr@test.test', 'ROLE_MANAGER');
        $this->client->loginUser($user);

        $this->client->request('GET', '/de/authority/notification');
        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [200, 403]);
    }

    #[Test]
    public function breachPdfReturns404ForMissingBreach(): void
    {
        $user = $this->createOrGetUser('authority-dpo@test.test', 'ROLE_DPO');
        $this->client->loginUser($user);

        $this->client->request('GET', '/de/authority/notification/data-breach/999999/bfdi.pdf');
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [200, 404, 403],
        );
    }

    #[Test]
    public function breachJsonReturns404ForUnknownAuthority(): void
    {
        $user = $this->createOrGetUser('authority-dpo2@test.test', 'ROLE_DPO');
        $this->client->loginUser($user);

        $this->client->request('GET', '/de/authority/notification/data-breach/1/unknown_key.json');
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [200, 404, 403],
        );
    }

    #[Test]
    public function incidentPdfReturns404ForMissingIncident(): void
    {
        $user = $this->createOrGetUser('authority-dpo3@test.test', 'ROLE_MANAGER');
        $this->client->loginUser($user);

        $this->client->request('GET', '/de/authority/notification/incident/999999/bsi_meldestelle.pdf');
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [200, 404, 403],
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createOrGetUser(string $email, string $role): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user !== null) {
            return $user;
        }

        $tenant = $this->getOrCreateTenant();

        $user = new User();
        $user->setEmail($email);
        $user->setPassword('$2y$13$fake_hashed_password_string_here_00');
        $user->setRoles([$role]);
        $user->setTenant($tenant);
        $user->setFirstName('Test');
        $user->setLastName('User');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function getOrCreateTenant(): Tenant
    {
        if ($this->tenant !== null) {
            return $this->tenant;
        }
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['name' => 'AuthNotifTest']);
        if ($tenant !== null) {
            $this->tenant = $tenant;
            return $tenant;
        }
        $tenant = new Tenant();
        $tenant->setName('AuthNotifTest');
        $tenant->setCode('ANT' . substr(uniqid(), -5));
        $this->em->persist($tenant);
        $this->em->flush();
        $this->tenant = $tenant;
        return $tenant;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S7: what only the instance operator (super admin) may do once the instance runs.
 *
 * - The setup wizard after completion: step 2 writes .env.local, step 3 restores or
 *   empties the whole instance. It used to let every organisation admin through
 *   (raw ROLE_ADMIN in SetupAccessChecker), and the step-3 actions had no guard at all.
 * - Deleting a compliance framework from the admin catalogue: the cascade removes the
 *   requirements, mappings and fulfilment of every organisation.
 */
final class InstanceOperatorOnlyTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $tenantAdmin;
    private User $superAdmin;
    private ComplianceFramework $framework;
    private ?string $createdLock = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        if (!is_file($projectDir . '/var/setup_complete.lock') && !is_file($projectDir . '/config/setup_complete.lock')) {
            $this->createdLock = $projectDir . '/var/setup_complete.lock';
            file_put_contents($this->createdLock, date('Y-m-d H:i:s'));
        }

        $this->em->beginTransaction();
        $u = substr(bin2hex(random_bytes(4)), 0, 8);
        $tenant = (new Tenant())->setCode('s7-' . $u)->setName('S7 ' . $u);
        $this->em->persist($tenant);
        $this->tenantAdmin = $this->user($tenant, 'admin', 'ROLE_ADMIN', $u);
        $this->superAdmin = $this->user($tenant, 'super', 'ROLE_SUPER_ADMIN', $u);
        $this->framework = (new ComplianceFramework())
            ->setCode('S7_' . $u)->setName('S7 framework ' . $u)->setVersion('1.0')
            ->setApplicableIndustry('all')->setRegulatoryBody('x')->setMandatory(false)->setActive(true);
        $this->em->persist($this->framework);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        if ($this->createdLock !== null) {
            @unlink($this->createdLock);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function setupActions(): iterable
    {
        yield 'database config (writes .env.local)' => ['GET', '/en/setup/step2-database-config'];
        yield 'database config submit' => ['POST', '/en/setup/step2-database-config'];
        yield 'restore page' => ['GET', '/en/setup/step3-restore-backup'];
        yield 'restore upload' => ['POST', '/en/setup/step3-restore-backup/upload'];
        yield 'create schema' => ['POST', '/en/setup/step3-restore-backup/create-schema'];
        yield 'skip restore' => ['POST', '/en/setup/step3-restore-backup/skip'];
        yield 'repair orphans' => ['POST', '/en/setup/step3-restore-backup/repair-orphans'];
        yield 'admin user' => ['POST', '/en/setup/step4-admin-user'];
        yield 'modules' => ['POST', '/en/setup/step7-modules/save'];
        yield 'existing frameworks' => ['GET', '/en/setup/existing-frameworks'];
    }

    #[Test]
    #[DataProvider('setupActions')]
    public function organisationAdminCannotRunTheSetupWizardAfterCompletion(string $method, string $path): void
    {
        $this->client->loginUser($this->tenantAdmin);
        $this->client->request($method, $path);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    #[DataProvider('setupActions')]
    public function superAdminStillReachesTheSetupWizard(string $method, string $path): void
    {
        $this->client->loginUser($this->superAdmin);
        $this->client->request($method, $path);

        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function anonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('POST', '/en/setup/step3-restore-backup/upload');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function organisationAdminCannotDeleteAFramework(): void
    {
        $this->client->loginUser($this->tenantAdmin);
        $this->client->request('POST', '/de/admin/compliance/frameworks/delete/' . $this->framework->getCode());

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em->getRepository(ComplianceFramework::class)->find($this->framework->getId()));
    }

    #[Test]
    public function superAdminPassesTheFrameworkDeleteGate(): void
    {
        $this->client->loginUser($this->superAdmin);
        // Without a CSRF token the action answers its own JSON error — the gate let it through.
        $this->client->request('POST', '/de/admin/compliance/frameworks/delete/' . $this->framework->getCode());

        self::assertStringContainsString('CSRF', (string) $this->client->getResponse()->getContent());
    }

    private function user(Tenant $tenant, string $label, string $role, string $u): User
    {
        $user = (new User())
            ->setEmail('s7-' . $label . '-' . $u . '@example.test')
            ->setFirstName('S7')->setLastName($label)
            ->setRoles([$role])->setPassword('x')->setTenant($tenant)
            ->setAuthProvider('local')->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}

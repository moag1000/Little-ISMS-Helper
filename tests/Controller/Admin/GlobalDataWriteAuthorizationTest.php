<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Instance-wide settings and the global compliance catalogue are shared by all
 * organisations: a tenant ROLE_ADMIN may read them but not write them; only a
 * SUPER_ADMIN (ADMIN_GLOBAL_OP) may. Tenant-uploaded requirements stay with
 * their tenant.
 */
final class GlobalDataWriteAuthorizationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $tenantAdmin;
    private User $manager;
    private User $superAdmin;
    private ComplianceFramework $framework;
    private ComplianceRequirement $globalRequirement;
    private ComplianceRequirement $ownRequirement;
    private ComplianceRequirement $foreignRequirement;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
        $u = substr(bin2hex(random_bytes(4)), 0, 8);

        $this->tenantA = (new Tenant())->setCode('gw-a-' . $u)->setName('GW A ' . $u);
        $this->tenantB = (new Tenant())->setCode('gw-b-' . $u)->setName('GW B ' . $u);
        $this->em->persist($this->tenantA);
        $this->em->persist($this->tenantB);

        $this->tenantAdmin = $this->user('admin', ['ROLE_ADMIN'], $u);
        $this->manager = $this->user('manager', ['ROLE_MANAGER'], $u);
        $this->superAdmin = $this->user('super', ['ROLE_SUPER_ADMIN'], $u);

        $this->framework = (new ComplianceFramework())
            ->setCode('GW_' . $u)->setName('GW framework ' . $u)->setVersion('1.0')
            ->setApplicableIndustry('all')->setRegulatoryBody('x')->setMandatory(false)->setActive(true);
        $this->em->persist($this->framework);
        $this->em->flush();

        $this->globalRequirement = $this->requirement('G-' . $u, null);
        $this->ownRequirement = $this->requirement('O-' . $u, $this->tenantA);
        $this->foreignRequirement = $this->requirement('F-' . $u, $this->tenantB);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function globalWritePosts(): iterable
    {
        yield 'quick-fix settings' => ['/de/admin/quick-fix-settings'];
        yield 'audit retention' => ['/de/admin/audit-log/retention'];
        yield 'compliance policy' => ['/de/admin/compliance/settings'];
        yield 'application settings' => ['/de/admin/settings/application'];
        yield 'security settings' => ['/de/admin/settings/security'];
        yield 'framework toggle' => ['/de/compliance/framework/{fw}/toggle'];
        yield 'framework delete' => ['/de/compliance/framework/{fw}/delete'];
        yield 'framework duplicate' => ['/de/compliance/framework/{fw}/duplicate'];
        yield 'assign orphans' => ['/de/admin/data-repair/assign-orphans'];
        yield 'tenant mismatches' => ['/de/admin/data-repair/fix-tenant-mismatches'];
        yield 'quarantine uploads' => ['/de/admin/data-repair/quarantine-uploads'];
        yield 'cleanup dangling refs' => ['/de/admin/data-repair/cleanup-dangling-refs'];
    }

    #[Test]
    #[DataProvider('globalWritePosts')]
    public function tenantAdminCannotWriteGlobalData(string $path): void
    {
        $this->client->loginUser($this->tenantAdmin);
        $this->client->request('POST', $this->path($path), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    #[DataProvider('globalWritePosts')]
    public function superAdminPassesTheAuthorizationGate(string $path): void
    {
        $this->client->loginUser($this->superAdmin);
        $this->client->request('POST', $this->path($path), ['_token' => 'x']);
        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function tenantAdminStillReadsSettingsPageWithHintInsteadOfSave(): void
    {
        $this->client->loginUser($this->tenantAdmin);
        $this->client->request('GET', '/de/admin/audit-log/retention');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'nur Super-Admins');
    }

    #[Test]
    public function tenantAdminCannotEditOrCreateGlobalCatalogue(): void
    {
        $this->client->loginUser($this->tenantAdmin);
        $this->client->request('GET', '/de/compliance/framework/' . $this->framework->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/de/compliance/requirement/' . $this->globalRequirement->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/de/compliance/requirement/new');
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function superAdminMayEditGlobalRequirement(): void
    {
        $this->client->loginUser($this->superAdmin);
        $this->client->request('GET', '/de/compliance/requirement/' . $this->globalRequirement->getId() . '/edit');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function tenantManagerEditsOnlyOwnUploadedRequirement(): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request('GET', '/de/compliance/requirement/' . $this->ownRequirement->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/de/compliance/requirement/' . $this->globalRequirement->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/de/compliance/requirement/' . $this->foreignRequirement->getId() . '/edit');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/de/compliance/requirement/' . $this->foreignRequirement->getId());
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function quickUpdateNeedsManagerAndHidesForeignRows(): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request('POST', '/de/compliance/requirement/' . $this->foreignRequirement->getId() . '/quick-update', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    private function path(string $path): string
    {
        return str_replace('{fw}', (string) $this->framework->getId(), $path);
    }

    /** @param list<string> $roles */
    private function user(string $label, array $roles, string $u): User
    {
        $user = (new User())
            ->setEmail('gw-' . $label . '-' . $u . '@example.test')
            ->setFirstName('GW')->setLastName($label)
            ->setRoles($roles)->setPassword('x')->setTenant($this->tenantA)
            ->setAuthProvider('local')->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function requirement(string $id, ?Tenant $owner): ComplianceRequirement
    {
        $r = (new ComplianceRequirement())
            ->setFramework($this->framework)->setRequirementId($id)->setTitle('Req ' . $id)
            ->setDescription('d')->setPriority('medium')->setRequirementType('core');
        $r->setUploadTenant($owner);
        $this->em->persist($r);

        return $r;
    }
}

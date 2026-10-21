<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CorporateGovernance;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * Security hotfix (admin scope): a tenant admin (ROLE_ADMIN) may only manage
 * tenants of its own tree (TenantScopedAdminVoter::ADMIN_OWN_TENANT) and may
 * not move users across tenants via /admin/tenants/{id}/add-user|remove-user.
 *
 * Since the tenant-isolation release after 3.15.3, the route guards
 * (#[IsGranted] with ADMIN_OWN_TENANT / ADMIN_GLOBAL_OP) answer an out-of-scope
 * request with 403 before the controller's own 404 check runs, and add-user is
 * an instance-operator action (ROLE_SUPER_ADMIN only).
 *
 * Fixture tree:  A (parent) ── A-child          B (unrelated)
 */
final class TenantManagementAdminScopeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;

    private Tenant $tenantA;
    private Tenant $tenantAChild;
    private Tenant $tenantB;
    private User $adminA;
    private User $superA;
    private User $userA;
    private User $userB;
    private User $superB;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);

        $this->tenantA = $this->tenant('A');
        $this->tenantAChild = $this->tenant('A-child', $this->tenantA);
        $this->tenantB = $this->tenant('B');

        $this->adminA = $this->user('admin_a', ['ROLE_ADMIN'], $this->tenantA);
        $this->superA = $this->user('super_a', ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], $this->tenantA);
        $this->userA = $this->user('user_a', ['ROLE_USER'], $this->tenantA);
        $this->userB = $this->user('user_b', ['ROLE_USER'], $this->tenantB);
        $this->superB = $this->user('super_b', ['ROLE_SUPER_ADMIN'], $this->tenantB);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $users = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.email LIKE :p')->setParameter('p', '%' . $this->uid . '%')->getQuery()->getResult();
        foreach ($users as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
        foreach ([$this->tenantAChild, $this->tenantA, $this->tenantB] as $tenant) {
            if (($managed = $this->em->find(Tenant::class, $tenant->getId())) !== null) {
                $managed->setParent(null);
                $this->em->flush();
                $this->em->remove($managed);
                $this->em->flush();
            }
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- CRITICAL 1: add-user

    #[Test]
    public function adminCannotMoveItselfIntoForeignTenant(): void
    {
        $this->client->loginUser($this->adminA);
        $this->postWithToken('/en/admin/tenants/' . $this->tenantB->getId() . '/add-user', 'add_user_' . $this->tenantB->getId(), [
            'user_email' => $this->adminA->getEmail(),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame($this->tenantA->getId(), $this->tenantIdOf($this->adminA));
    }

    #[Test]
    public function adminCannotPullForeignUserOrSuperAdminIntoOwnTenant(): void
    {
        $this->client->loginUser($this->adminA);
        foreach ([$this->userB, $this->superB] as $victim) {
            $this->postWithToken('/en/admin/tenants/' . $this->tenantA->getId() . '/add-user', 'add_user_' . $this->tenantA->getId(), [
                'user_email' => $victim->getEmail(),
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertSame($this->tenantB->getId(), $this->tenantIdOf($victim), (string) $victim->getEmail());
        }
    }

    #[Test]
    public function adminCannotMoveOwnUserIntoChildTenant(): void
    {
        // add-user is an instance-operator action, even inside the own tree.
        $this->client->loginUser($this->adminA);
        $this->postWithToken('/en/admin/tenants/' . $this->tenantAChild->getId() . '/add-user', 'add_user_' . $this->tenantAChild->getId(), [
            'user_email' => $this->userA->getEmail(),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame($this->tenantA->getId(), $this->tenantIdOf($this->userA));
    }

    #[Test]
    public function superAdminCanMoveUserIntoTenant(): void
    {
        $this->client->loginUser($this->superA);
        $this->postWithToken('/en/admin/tenants/' . $this->tenantAChild->getId() . '/add-user', 'add_user_' . $this->tenantAChild->getId(), [
            'user_email' => $this->userB->getEmail(),
        ]);

        self::assertResponseRedirects();
        self::assertSame($this->tenantAChild->getId(), $this->tenantIdOf($this->userB));
    }

    // ---------------------------------------------------------------- CRITICAL 2: remove-user

    #[Test]
    public function adminCannotMakeUsersTenantless(): void
    {
        $this->client->loginUser($this->adminA);

        $this->postWithToken(
            '/en/admin/tenants/' . $this->tenantA->getId() . '/remove-user/' . $this->superA->getId(),
            'remove_user_' . $this->tenantA->getId() . '_' . $this->superA->getId(),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame($this->tenantA->getId(), $this->tenantIdOf($this->superA));

        $this->postWithToken(
            '/en/admin/tenants/' . $this->tenantB->getId() . '/remove-user/' . $this->userB->getId(),
            'remove_user_' . $this->tenantB->getId() . '_' . $this->userB->getId(),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame($this->tenantB->getId(), $this->tenantIdOf($this->userB));
    }

    #[Test]
    public function superAdminCanStillRemoveUserFromTenant(): void
    {
        $this->client->loginUser($this->superA);
        $this->postWithToken(
            '/en/admin/tenants/' . $this->tenantB->getId() . '/remove-user/' . $this->userB->getId(),
            'remove_user_' . $this->tenantB->getId() . '_' . $this->userB->getId(),
        );

        self::assertResponseRedirects();
        self::assertNull($this->tenantIdOf($this->userB));
    }

    // ---------------------------------------------------------------- IMPORTANT 1: tenant scope

    #[Test]
    public function foreignTenantEndpointsAreDeniedForTenantAdmin(): void
    {
        $this->client->loginUser($this->adminA);
        $b = $this->tenantB->getId();

        foreach ([
            ['GET', "/en/admin/tenants/$b"],
            ['GET', "/en/admin/tenants/$b/edit"],
            ['GET', "/en/admin/tenants/$b/organisation-context"],
            ['GET', "/en/admin/tenants/api/tenants/$b/isms-context"],
            ['POST', "/en/admin/tenants/$b/toggle"],
            ['POST', "/en/admin/tenants/$b/delete"],
            ['POST', "/en/admin/tenants/$b/set-parent"],
            ['POST', "/en/admin/tenants/$b/update-governance"],
        ] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, "$method $url");
        }

        $this->client->request('GET', '/en/admin/tenants/' . $this->tenantAChild->getId());
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function tenantAdminCannotCreateOrganisations(): void
    {
        // #1059: "new" would create a root organisation outside the admin's
        // tree. Creating organisations is an instance-operator action.
        $this->client->loginUser($this->adminA);
        $code = 'root_probe_' . substr(md5(uniqid('', true)), 0, 10);

        $this->client->request('GET', '/en/admin/tenants/new');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request('POST', '/en/admin/tenants/new', ['tenant' => ['code' => $code, 'name' => $code]]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Tenant::class)->findOneBy(['code' => $code]));
    }

    #[Test]
    public function indexAndCorporateStructureOnlyListAccessibleTenants(): void
    {
        $this->client->loginUser($this->adminA);

        foreach (['/en/admin/tenants', '/en/admin/tenants/corporate-structure'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $html = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString((string) $this->tenantAChild->getName(), $html, $url);
            self::assertStringNotContainsString((string) $this->tenantB->getName(), $html, $url);
        }

        $this->client->loginUser($this->superA);
        $this->client->request('GET', '/en/admin/tenants');
        self::assertStringContainsString((string) $this->tenantB->getName(), (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function adminCannotHangOwnTenantUnderForeignParent(): void
    {
        // An existing default governance row lets set-parent pass
        // CorporateStructureService::validateStructure() (otherwise the
        // endpoint aborts before flushing and the test would prove nothing).
        $governance = (new CorporateGovernance())
            ->setTenant($this->tenantAChild)
            ->setParent($this->tenantA)
            ->setScope('default')
            ->setScopeId(null)
            ->setGovernanceModel(GovernanceModel::HIERARCHICAL);
        $this->em->persist($governance);
        $this->em->flush();

        $this->client->loginUser($this->adminA);
        $this->postWithToken('/en/admin/tenants/' . $this->tenantAChild->getId() . '/set-parent', 'set_parent', [
            'parent_id' => (string) $this->tenantB->getId(),
            'governance_model' => 'hierarchical',
        ]);

        $this->em->clear();
        $child = $this->em->find(Tenant::class, $this->tenantAChild->getId());
        self::assertSame($this->tenantA->getId(), $child?->getParent()?->getId());
    }

    #[Test]
    public function adminCannotReparentViaEditForm(): void
    {
        $this->client->loginUser($this->adminA);
        $url = '/en/admin/tenants/' . $this->tenantAChild->getId() . '/edit';
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $values = $crawler->filter('form[name="tenant"]')->form()->getPhpValues();
        $values['tenant']['parent'] = (string) $this->tenantB->getId();
        $this->client->request('POST', $url, $values);

        $this->em->clear();
        $child = $this->em->find(Tenant::class, $this->tenantAChild->getId());
        self::assertSame($this->tenantA->getId(), $child?->getParent()?->getId());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $params
     */
    private function postWithToken(string $url, string $tokenId, array $params = []): void
    {
        // Session bootstrap pattern from AssetControllerTest::generateCsrfToken().
        $this->client->request('GET', '/en/admin/users');
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();

        $this->client->request('POST', $url, ['_token' => $token] + $params);
    }

    private function tenantIdOf(User $user): ?int
    {
        $this->em->clear();

        return $this->em->find(User::class, $user->getId())?->getTenant()?->getId();
    }

    private function tenant(string $label, ?Tenant $parent = null): Tenant
    {
        $tenant = (new Tenant())
            ->setName('TScope ' . $label . ' ' . $this->uid)
            ->setCode('tscope_' . strtolower(str_replace('-', '_', $label)) . '_' . $this->uid)
            ->setIsActive(true);
        if ($parent !== null) {
            $tenant->setParent($parent);
        }
        $this->em->persist($tenant);

        return $tenant;
    }

    private function user(string $label, array $roles, Tenant $tenant): User
    {
        $user = (new User())
            ->setEmail($label . '_' . $this->uid . '@example.com')
            ->setFirstName(ucfirst($label))
            ->setLastName('TScope')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}

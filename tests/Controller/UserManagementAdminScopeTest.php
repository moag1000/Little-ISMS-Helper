<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security hotfix (admin scope): a tenant admin (ROLE_ADMIN) must not be able
 * to escalate to ROLE_SUPER_ADMIN / holding roles, nor read or manage users
 * outside its accessible tenant tree (own + descendants).
 *
 * Fixture tree:  A (parent) ── A-child          B (unrelated)
 */
final class UserManagementAdminScopeTest extends WebTestCase
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
    private User $userAChild;
    private User $userB;

    /** @var list<User> */
    private array $createdUsers = [];
    private ?Role $privilegedCustomRole = null;

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
        $this->userAChild = $this->user('user_a_child', ['ROLE_USER'], $this->tenantAChild);
        $this->userB = $this->user('user_b', ['ROLE_USER'], $this->tenantB);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $userRepo = $this->em->getRepository(User::class);
        foreach ($this->createdUsers as $user) {
            if ($user->getId() !== null && ($managed = $userRepo->find($user->getId())) !== null) {
                $this->em->remove($managed);
            }
        }
        foreach ($userRepo->createQueryBuilder('u')->where('u.email LIKE :p')
            ->setParameter('p', '%' . $this->uid . '%')->getQuery()->getResult() as $leftover) {
            $this->em->remove($leftover);
        }
        $this->em->flush();

        if ($this->privilegedCustomRole?->getId() !== null
            && ($role = $this->em->find(Role::class, $this->privilegedCustomRole->getId())) !== null) {
            $this->em->remove($role);
            $this->em->flush();
        }

        foreach ([$this->tenantAChild, $this->tenantA, $this->tenantB] as $tenant) {
            if (($managed = $this->em->find(Tenant::class, $tenant->getId())) !== null) {
                $this->em->remove($managed);
                $this->em->flush();
            }
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- (a) / (b)

    #[Test]
    public function adminFormDoesNotOfferSuperAdminRoleOrForeignTenants(): void
    {
        $this->client->loginUser($this->adminA);
        $crawler = $this->client->request('GET', '/en/admin/users/new');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name^="user[roles]"][value="ROLE_SUPER_ADMIN"]'));
        $tenantOptions = $crawler->filter('select[name="user[tenant]"] option')->extract(['value']);
        self::assertContains((string) $this->tenantA->getId(), $tenantOptions);
        self::assertContains((string) $this->tenantAChild->getId(), $tenantOptions);
        self::assertNotContains((string) $this->tenantB->getId(), $tenantOptions);
    }

    #[Test]
    public function adminCannotGrantItselfSuperAdmin(): void
    {
        $this->client->loginUser($this->adminA);
        $url = '/en/admin/users/' . $this->adminA->getId() . '/edit';
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $values = $this->formValues($crawler);
        $values['user']['roles'][] = 'ROLE_SUPER_ADMIN';
        $this->client->request('POST', $url, $values);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNotContains('ROLE_SUPER_ADMIN', $this->reload($this->adminA)->getRoles());
    }

    #[Test]
    public function adminCannotGrantPrivilegedRoleViaCustomRoleNamedLikeIt(): void
    {
        $this->privilegedCustomRole = $this->em->getRepository(Role::class)->findOneBy(['name' => 'ROLE_SUPER_ADMIN']);
        if ($this->privilegedCustomRole === null) {
            $this->privilegedCustomRole = (new Role())->setName('ROLE_SUPER_ADMIN')->setDescription('escalation probe');
            $this->em->persist($this->privilegedCustomRole);
            $this->em->flush();
        } else {
            $this->privilegedCustomRole = null; // pre-existing — do not delete in tearDown
        }
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => 'ROLE_SUPER_ADMIN']);

        $this->client->loginUser($this->adminA);
        $url = '/en/admin/users/' . $this->userA->getId() . '/edit';
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $values = $this->formValues($crawler);
        $values['user']['customRoles'][] = (string) $role->getId();
        $this->client->request('POST', $url, $values);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNotContains('ROLE_SUPER_ADMIN', $this->reload($this->userA)->getRoles());
    }

    #[Test]
    public function adminCannotCreateSuperAdmin(): void
    {
        $this->client->loginUser($this->adminA);
        $crawler = $this->client->request('GET', '/en/admin/users/new');
        $email = 'new_super_' . $this->uid . '@example.com';

        $values = $this->formValues($crawler);
        $values['user'] = array_merge($values['user'], $this->newUserFields($email));
        $values['user']['roles'][] = 'ROLE_SUPER_ADMIN';
        $this->client->request('POST', '/en/admin/users/new', $values);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->findByEmail($email));
    }

    #[Test]
    public function adminEditKeepsExistingHoldingRoleItCannotSeeOrRevoke(): void
    {
        $holding = $this->user('holding_a', ['ROLE_GROUP_CISO'], $this->tenantA);
        $this->em->flush();

        $this->client->loginUser($this->adminA);
        $url = '/en/admin/users/' . $holding->getId() . '/edit';
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $values = $this->formValues($crawler);
        $values['user']['firstName'] = 'Kept';
        $this->client->request('POST', $url, $values);

        self::assertResponseRedirects();
        $reloaded = $this->reload($holding);
        self::assertSame('Kept', $reloaded->getFirstName());
        self::assertContains('ROLE_GROUP_CISO', $reloaded->getRoles());
    }

    #[Test]
    public function bulkAssignOfPrivilegedCustomRoleIsSkippedForAdmin(): void
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => 'ROLE_KONZERN_AUDITOR']);
        if ($role === null) {
            $role = (new Role())->setName('ROLE_KONZERN_AUDITOR')->setDescription('escalation probe');
            $this->em->persist($role);
            $this->em->flush();
            $this->privilegedCustomRole = $role;
        }

        $this->bulkAssignRole($this->adminA, $role, $this->userA);
        self::assertNotContains('ROLE_KONZERN_AUDITOR', $this->reload($this->userA)->getRoles());

        // Positive control (proves the request itself is valid): SUPER_ADMIN may.
        $this->bulkAssignRole($this->superA, $role, $this->userA);
        self::assertContains('ROLE_KONZERN_AUDITOR', $this->reload($this->userA)->getRoles());
    }

    private function bulkAssignRole(User $actor, Role $role, User $target): void
    {
        $this->client->loginUser($actor);
        $this->client->request('GET', '/en/admin/users');
        self::assertResponseIsSuccessful();
        // Same pattern as AssetControllerTest::generateCsrfToken().
        $session = $this->client->getRequest()->getSession();
        $token = (new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/bulk_actions', $token);
        $session->save();

        $this->client->request('POST', '/en/admin/users/bulk-actions', [
            '_token' => $token,
            'action' => 'assign_role',
            'role_id' => (string) $role->getId(),
            'user_ids' => [(string) $target->getId()],
        ]);
        self::assertResponseRedirects();
    }

    // ---------------------------------------------------------------- (c)

    #[Test]
    public function adminCannotCreateUserInForeignTenant(): void
    {
        $this->client->loginUser($this->adminA);
        $crawler = $this->client->request('GET', '/en/admin/users/new');
        $email = 'new_in_b_' . $this->uid . '@example.com';

        $values = $this->formValues($crawler);
        $values['user'] = array_merge($values['user'], $this->newUserFields($email));
        $values['user']['tenant'] = (string) $this->tenantB->getId();
        $this->client->request('POST', '/en/admin/users/new', $values);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->findByEmail($email));
    }

    #[Test]
    public function adminCannotMoveUserIntoForeignTenant(): void
    {
        $this->client->loginUser($this->adminA);
        $url = '/en/admin/users/' . $this->userA->getId() . '/edit';
        $crawler = $this->client->request('GET', $url);

        $values = $this->formValues($crawler);
        $values['user']['tenant'] = (string) $this->tenantB->getId();
        $this->client->request('POST', $url, $values);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame($this->tenantA->getId(), $this->reload($this->userA)->getTenant()?->getId());
    }

    #[Test]
    public function newUserWithoutTenantDefaultsToAdminsTenant(): void
    {
        $this->client->loginUser($this->adminA);
        $crawler = $this->client->request('GET', '/en/admin/users/new');
        $email = 'new_default_' . $this->uid . '@example.com';

        $values = $this->formValues($crawler);
        $values['user'] = array_merge($values['user'], $this->newUserFields($email));
        $values['user']['tenant'] = '';
        $this->client->request('POST', '/en/admin/users/new', $values);

        self::assertResponseRedirects('/en/admin/users');
        $created = $this->findByEmail($email);
        self::assertNotNull($created);
        self::assertSame($this->tenantA->getId(), $created->getTenant()?->getId());
    }

    // ---------------------------------------------------------------- (d) / (e)

    #[Test]
    public function adminCannotSeeOrEditUserOfForeignTenant(): void
    {
        $this->client->loginUser($this->adminA);

        $this->client->request('GET', '/en/admin/users/' . $this->userB->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('GET', '/en/admin/users/' . $this->userB->getId() . '/edit');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function adminCannotEditSuperAdminOfOwnTenant(): void
    {
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/users/' . $this->superA->getId() . '/edit');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ---------------------------------------------------------------- (f)

    #[Test]
    public function indexAndExportOnlyListAccessibleTenants(): void
    {
        $this->client->loginUser($this->adminA);

        $this->client->request('GET', '/en/admin/users');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $this->userA->getEmail(), $html);
        self::assertStringContainsString((string) $this->userAChild->getEmail(), $html);
        self::assertStringNotContainsString((string) $this->userB->getEmail(), $html);

        ob_start();
        $this->client->request('GET', '/en/admin/users/export');
        $csv = (string) ob_get_clean() . (string) $this->client->getInternalResponse()->getContent();
        self::assertResponseIsSuccessful();
        // The CSV export is narrower than the list: own organisation only
        // (audit M-7, JobTenantScope), never subsidiaries or foreign tenants.
        self::assertStringContainsString((string) $this->userA->getEmail(), $csv);
        self::assertStringNotContainsString((string) $this->userAChild->getEmail(), $csv);
        self::assertStringNotContainsString((string) $this->userB->getEmail(), $csv);
    }

    // ---------------------------------------------------------------- (g)

    #[Test]
    public function superAdminCanStillGrantSuperAdminAndAssignAnyTenant(): void
    {
        $this->client->loginUser($this->superA);
        $url = '/en/admin/users/' . $this->userA->getId() . '/edit';
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name^="user[roles]"][value="ROLE_SUPER_ADMIN"]'));

        $values = $this->formValues($crawler);
        $values['user']['roles'][] = 'ROLE_SUPER_ADMIN';
        $values['user']['tenant'] = (string) $this->tenantB->getId();
        $this->client->request('POST', $url, $values);

        self::assertResponseRedirects();
        $reloaded = $this->reload($this->userA);
        self::assertContains('ROLE_SUPER_ADMIN', $reloaded->getRoles());
        self::assertSame($this->tenantB->getId(), $reloaded->getTenant()?->getId());
    }

    // ---------------------------------------------------------------- (h)

    #[Test]
    public function parentTenantAdminCanManageUserOfChildTenant(): void
    {
        $this->client->loginUser($this->adminA);
        $url = '/en/admin/users/' . $this->userAChild->getId() . '/edit';
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $values = $this->formValues($crawler);
        $values['user']['firstName'] = 'Renamed';
        $this->client->request('POST', $url, $values);

        self::assertResponseRedirects();
        $reloaded = $this->reload($this->userAChild);
        self::assertSame('Renamed', $reloaded->getFirstName());
        self::assertSame($this->tenantAChild->getId(), $reloaded->getTenant()?->getId());
    }

    // ---------------------------------------------------------------- helpers

    private function tenant(string $label, ?Tenant $parent = null): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Scope ' . $label . ' ' . $this->uid);
        $tenant->setCode('scope_' . strtolower(str_replace('-', '_', $label)) . '_' . $this->uid);
        $tenant->setIsActive(true);
        if ($parent !== null) {
            $tenant->setParent($parent);
        }
        $this->em->persist($tenant);

        return $tenant;
    }

    private function user(string $label, array $roles, Tenant $tenant): User
    {
        $user = new User();
        $user->setEmail($label . '_' . $this->uid . '@example.com');
        $user->setFirstName(ucfirst($label));
        $user->setLastName('Scope');
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->createdUsers[] = $user;

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function formValues(Crawler $crawler): array
    {
        return $crawler->filter('form[name="user"]')->form()->getPhpValues();
    }

    /**
     * @return array<string, string>
     */
    private function newUserFields(string $email): array
    {
        return [
            'email' => $email,
            'firstName' => 'New',
            'lastName' => 'Scope',
            'plainPassword' => 'SecurePassword123!',
        ];
    }

    private function reload(User $user): User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($user->getId());
    }

    private function findByEmail(string $email): ?User
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user !== null) {
            $this->createdUsers[] = $user;
        }

        return $user;
    }
}

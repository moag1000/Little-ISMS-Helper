<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Security hotfix: custom Role entities are global (no tenant). A tenant
 * admin must not change the name/permissions of — or delete — a custom role
 * that is held by users outside its own tenant tree, because that would
 * change what those foreign users may do.
 *
 * Since the release after 3.15.3 this is subsumed by the stricter rule that
 * only ROLE_SUPER_ADMIN changes roles at all (RoleVoter): a tenant admin is
 * denied even when every holder is inside its own tree. Holder visibility on
 * show/index stays scoped as in 3.15.3.
 */
final class RoleManagementHolderScopeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;
    private User $userA;
    private User $userB;
    private User $super;
    /** @var list<string> extra user labels created by individual tests */
    private array $extraUsers = [];
    private ?Tenant $subsidiary = null;
    private Role $role;
    private Permission $permission;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);

        $this->tenantA = (new Tenant())->setName('Holder A ' . $this->uid)->setCode('holder_a_' . $this->uid);
        $this->tenantB = (new Tenant())->setName('Holder B ' . $this->uid)->setCode('holder_b_' . $this->uid);
        $this->em->persist($this->tenantA);
        $this->em->persist($this->tenantB);

        $this->adminA = $this->user('admina', ['ROLE_ADMIN'], $this->tenantA);
        $this->userA = $this->user('usera', ['ROLE_USER'], $this->tenantA);
        $this->userB = $this->user('userb', ['ROLE_USER'], $this->tenantB);
        $this->super = $this->user('super', ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], $this->tenantA);

        $this->permission = (new Permission())
            ->setName('holder_probe.view_' . $this->uid)
            ->setDescription('Holder probe')
            ->setCategory('holder_probe')
            ->setAction('view');
        $this->em->persist($this->permission);

        $this->role = (new Role())
            ->setName('Holder Probe ' . $this->uid)
            ->setDescription('original')
            ->setIsSystemRole(false);
        $this->em->persist($this->role);
        $this->role->addUser($this->userA);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach (['admina', 'usera', 'userb', 'super', ...$this->extraUsers] as $label) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $label . '_' . $this->uid . '@example.com']);
            if ($user !== null) {
                $this->em->remove($user);
            }
        }
        $this->em->flush();
        foreach ([Role::class => $this->role->getId(), Permission::class => $this->permission->getId()] as $class => $id) {
            if ($id !== null && ($entity = $this->em->find($class, $id)) !== null) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();
        foreach ([$this->subsidiary?->getId(), $this->tenantA->getId(), $this->tenantB->getId()] as $id) {
            if ($id !== null && ($tenant = $this->em->find(Tenant::class, $id)) !== null) {
                $this->em->remove($tenant);
                $this->em->flush();
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function tenantAdminCannotEditRoleHeldInForeignTenant(): void
    {
        // Form values as an operator would submit them; the tenant admin's
        // POST must be rejected server-side.
        $values = $this->formValues($this->super, 'changed');
        $this->assignToUserB();
        $this->client->loginUser($this->adminA);

        $this->client->request('GET', $this->editUrl());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', $this->editUrl(), $values);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertNotEmpty($session->getFlashBag()->peek('danger'));

        $role = $this->reloadRole();
        self::assertSame('original', $role->getDescription());
        self::assertCount(0, $role->getPermissions(), 'permission set must stay unchanged');
    }

    #[Test]
    public function tenantAdminCannotDeleteRoleHeldInForeignTenant(): void
    {
        $this->assignToUserB();
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/roles/' . $this->role->getId());
        $session = $this->client->getRequest()->getSession();
        $token = (new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/delete' . $this->role->getId(), $token);
        $session->save();

        $this->client->request('POST', '/en/admin/roles/' . $this->role->getId() . '/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertNotNull($this->reloadRole());
    }

    #[Test]
    public function tenantAdminCannotEditRoleHeldBySuperAdminOfOwnTenant(): void
    {
        $this->role->addUser($this->super);
        $this->em->flush();
        $this->client->loginUser($this->adminA);

        $this->client->request('GET', $this->editUrl());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function tenantAdminCannotEditRoleHeldInOwnSubsidiary(): void
    {
        $this->subsidiary = (new Tenant())->setName('Holder Sub ' . $this->uid)->setCode('holder_sub_' . $this->uid)
            ->setParent($this->tenantA);
        $this->em->persist($this->subsidiary);
        $this->extraUsers[] = 'usersub';
        $this->role->addUser($this->user('usersub', ['ROLE_USER'], $this->subsidiary));
        $this->em->flush();

        $values = $this->formValues($this->super, 'changed');
        $this->client->loginUser($this->adminA);
        $this->client->request('POST', $this->editUrl(), $values);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('original', $this->reloadRole()->getDescription());
    }

    #[Test]
    public function tenantAdminCannotEditRoleHeldByTenantlessUser(): void
    {
        $this->extraUsers[] = 'notenant';
        $this->role->addUser($this->user('notenant', ['ROLE_USER'], null));
        $this->em->flush();
        $this->client->loginUser($this->adminA);

        $this->client->request('GET', $this->editUrl());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function showAndIndexNeverExposeForeignHolders(): void
    {
        $this->assignToUserB();
        $this->client->loginUser($this->adminA);

        $crawler = $this->client->request('GET', '/en/admin/roles/' . $this->role->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('usera_' . $this->uid . '@example.com', $html);
        self::assertStringNotContainsString('userb_' . $this->uid, $html, 'foreign holder identity must not leak');
        self::assertStringContainsString('1', $crawler->filter('[data-testid="role-hidden-holders"]')->text());
        self::assertStringNotContainsString($this->editUrl(), $html, 'edit link hidden for unmodifiable role');
        self::assertStringNotContainsString('/admin/roles/' . $this->role->getId() . '/delete', $html);

        $this->client->request('GET', '/en/admin/roles');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('userb_' . $this->uid, $html);
        self::assertStringNotContainsString($this->editUrl(), $html);

        // SUPER_ADMIN sees every holder and no hidden-holder hint.
        $this->client->loginUser($this->super);
        $crawler = $this->client->request('GET', '/en/admin/roles/' . $this->role->getId());
        self::assertStringContainsString('userb_' . $this->uid . '@example.com', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-testid="role-hidden-holders"]'));
    }

    #[Test]
    public function tenantAdminCannotEditRoleHeldOnlyInOwnTenant(): void
    {
        $values = $this->formValues($this->super, 'changed');
        $this->client->loginUser($this->adminA);

        $this->client->request('GET', $this->editUrl());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request('POST', $this->editUrl(), $values);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $role = $this->reloadRole();
        self::assertSame('original', $role->getDescription());
        self::assertCount(0, $role->getPermissions());
    }

    #[Test]
    public function superAdminCanEditRoleHeldInForeignTenant(): void
    {
        $this->assignToUserB();
        $values = $this->formValues($this->super, 'changed');

        $this->client->request('POST', $this->editUrl(), $values);
        self::assertResponseRedirects();

        self::assertSame('changed', $this->reloadRole()->getDescription());
    }

    /**
     * Form values (incl. CSRF token) as rendered for $actor while the role is
     * still editable by it.
     *
     * @return array<string, mixed>
     */
    #[Test]
    public function roleOverviewDoesNotFetchUsersTimesPermissions(): void
    {
        // #1059: one query fetch-joining users AND permissions returned
        // users × permissions rows per role.
        $this->assignToUserB();
        $extra = [];
        foreach (['edit', 'delete'] as $action) {
            $extra[] = $permission = (new Permission())
                ->setName('holder_probe.' . $action . '_' . $this->uid)
                ->setDescription('Holder probe')
                ->setCategory('holder_probe')
                ->setAction($action);
            $this->em->persist($permission);
        }
        foreach ([$this->permission, ...$extra] as $permission) {
            $this->role->addPermission($permission);
        }
        $this->em->flush();
        $this->em->clear();

        try {
            $debug = static::getContainer()->get('doctrine.debug_data_holder');
            $debug->reset();
            $roles = $this->em->getRepository(Role::class)->getRolesWithUserCount();

            $probe = array_values(array_filter($roles, fn (Role $r): bool => $r->getId() === $this->role->getId()))[0] ?? null;
            self::assertInstanceOf(Role::class, $probe);
            self::assertCount(2, $probe->getUsers());
            self::assertCount(3, $probe->getPermissions());
            foreach ($debug->getData() as $queries) {
                foreach ($queries as $query) {
                    $sql = strtolower((string) $query['sql']);
                    self::assertFalse(
                        str_contains($sql, 'user_roles') && str_contains($sql, 'role_permissions'),
                        'users and permissions must not be fetch-joined in one query: ' . $sql,
                    );
                }
            }
        } finally {
            $this->em->clear();
            foreach ($extra as $permission) {
                if (($managed = $this->em->find(Permission::class, $permission->getId())) !== null) {
                    foreach ($managed->getRoles() as $role) {
                        $role->removePermission($managed);
                    }
                    $this->em->remove($managed);
                }
            }
            $this->em->flush();
        }
    }

    #[Test]
    public function permissionShowCountsOnlyHoldersTheActorMayView(): void
    {
        // #1059: the affected-users count summed every holder instance-wide
        // (and counted a holder of two roles twice).
        $this->assignToUserB();
        $second = (new Role())->setName('Holder Probe 2 ' . $this->uid)->setDescription('second')->setIsSystemRole(false);
        $this->em->persist($second);
        $second->addUser($this->userA);
        $this->role->addPermission($this->permission);
        $second->addPermission($this->permission);
        $this->em->flush();
        $this->em->clear(); // the inverse Permission::roles side is only populated from the DB
        $url = '/en/admin/permissions/' . $this->permission->getId();

        try {
            $this->client->loginUser($this->adminA);
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('insgesamt 1 Benutzer', (string) $this->client->getResponse()->getContent());

            $this->client->loginUser($this->super);
            $this->client->request('GET', $url);
            self::assertStringContainsString('insgesamt 2 Benutzer', (string) $this->client->getResponse()->getContent());
        } finally {
            $this->em->clear();
            if (($managed = $this->em->find(Role::class, $second->getId())) !== null) {
                foreach ($managed->getUsers()->toArray() as $holder) {
                    $managed->removeUser($holder);
                }
                $this->em->remove($managed);
                $this->em->flush();
            }
        }
    }

    private function formValues(User $actor, string $description): array
    {
        $this->client->loginUser($actor);
        $crawler = $this->client->request('GET', $this->editUrl());
        self::assertResponseIsSuccessful();
        $values = $crawler->filter('form[name="role"]')->form()->getPhpValues();
        $values['role']['description'] = $description;
        $values['role']['permissions'] = [(string) $this->permission->getId()];

        return $values;
    }

    private function assignToUserB(): void
    {
        $this->role->addUser($this->userB);
        $this->em->flush();
    }

    private function editUrl(): string
    {
        return '/en/admin/roles/' . $this->role->getId() . '/edit';
    }

    private function reloadRole(): ?Role
    {
        $this->em->clear();

        return $this->em->find(Role::class, $this->role->getId());
    }

    private function user(string $label, array $roles, ?Tenant $tenant): User
    {
        $user = (new User())
            ->setEmail($label . '_' . $this->uid . '@example.com')
            ->setFirstName(ucfirst($label))
            ->setLastName('Holder')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}

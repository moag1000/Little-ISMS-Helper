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
        $this->userA->addCustomRole($this->role);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach (['admina', 'usera', 'userb', 'super'] as $label) {
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
        foreach ([$this->tenantA->getId(), $this->tenantB->getId()] as $id) {
            if (($tenant = $this->em->find(Tenant::class, $id)) !== null) {
                $this->em->remove($tenant);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    #[Test]
    public function tenantAdminCannotEditRoleHeldInForeignTenant(): void
    {
        // Form fetched while the role was held only in tenant A, then the
        // role gets a tenant-B holder: the POST must be rejected server-side.
        $values = $this->formValues($this->adminA, 'changed');
        $this->assignToUserB();

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
        $this->super->addCustomRole($this->role);
        $this->em->flush();
        $this->client->loginUser($this->adminA);

        $this->client->request('GET', $this->editUrl());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function tenantAdminCanEditRoleHeldOnlyInOwnTenant(): void
    {
        $values = $this->formValues($this->adminA, 'changed');

        $this->client->request('POST', $this->editUrl(), $values);
        self::assertResponseRedirects();

        $role = $this->reloadRole();
        self::assertSame('changed', $role->getDescription());
        self::assertCount(1, $role->getPermissions());
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
        $this->userB->addCustomRole($this->role);
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

    private function user(string $label, array $roles, Tenant $tenant): User
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

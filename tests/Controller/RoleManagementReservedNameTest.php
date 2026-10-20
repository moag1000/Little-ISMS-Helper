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
use Symfony\Component\HttpFoundation\Response;

/**
 * Security hotfix: User::getRoles() turns custom Role names into Symfony
 * roles. A tenant admin must not be able to create/rename a custom role to a
 * reserved Symfony role name (e.g. ROLE_ALLOWED_TO_SWITCH → impersonate any
 * user), flag roles as system roles, or edit the global system roles.
 *
 * Since the release after 3.15.3 a tenant admin cannot create or edit roles at
 * all (RoleVoter: ROLE_SUPER_ADMIN only); the reserved-name validation still
 * applies to the operator's own custom roles.
 */
final class RoleManagementReservedNameTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private Tenant $tenant;
    private User $admin;
    private User $super;
    /** @var list<string> */
    private array $roleNamesToCleanUp = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);

        $this->tenant = (new Tenant())->setName('Reserved ' . $this->uid)->setCode('reserved_' . $this->uid);
        $this->em->persist($this->tenant);
        $this->admin = $this->user('admin', ['ROLE_ADMIN']);
        $this->super = $this->user('super', ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach (['admin', 'super'] as $label) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $label . '_' . $this->uid . '@example.com']);
            if ($user !== null) {
                $this->em->remove($user);
            }
        }
        $this->em->flush();
        foreach ($this->roleNamesToCleanUp as $name) {
            $role = $this->em->getRepository(Role::class)->findOneBy(['name' => $name]);
            if ($role !== null) {
                $this->em->remove($role);
            }
        }
        $this->em->flush();
        if (($tenant = $this->em->find(Tenant::class, $this->tenant->getId())) !== null) {
            $this->em->remove($tenant);
            $this->em->flush();
        }
        parent::tearDown();
    }

    #[Test]
    public function adminCannotCreateRoles(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/en/admin/roles/new');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function customRoleWithReservedNameIsRejectedEvenForSuperAdmin(): void
    {
        foreach (['ROLE_ALLOWED_TO_SWITCH', ' role_super_admin '] as $name) {
            $this->assertRoleAbsent(trim($name));
            $this->submitNewRole($this->super, $name, false);

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $name);
            $this->assertRoleAbsent(trim($name));
        }
    }

    #[Test]
    public function adminCannotSmuggleReservedNameAsSystemRole(): void
    {
        $this->assertRoleAbsent('ROLE_ALLOWED_TO_SWITCH');
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/en/admin/roles/new', [
            'role' => ['name' => 'ROLE_ALLOWED_TO_SWITCH', 'isSystemRole' => '1'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertRoleAbsent('ROLE_ALLOWED_TO_SWITCH');
    }

    #[Test]
    public function onlySuperAdminMayEditSystemRoles(): void
    {
        $name = 'System Probe ' . $this->uid;
        $this->roleNamesToCleanUp[] = $name;
        $role = (new Role())->setName($name)->setIsSystemRole(true);
        $this->em->persist($role);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/en/admin/roles/' . $role->getId() . '/edit');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->loginUser($this->super);
        $this->client->request('GET', '/en/admin/roles/' . $role->getId() . '/edit');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function templateRouteIsOperatorOnlyAndRejectsReservedCustomName(): void
    {
        $this->assertRoleAbsent('ROLE_ALLOWED_TO_SWITCH');
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/en/admin/roles/templates');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->loginUser($this->super);
        $crawler = $this->client->request('GET', '/en/admin/roles/templates');
        self::assertResponseIsSuccessful();
        $session = $this->client->getRequest()->getSession();
        $token = (new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/role_template_apply', $token);
        $session->save();
        $template = $crawler->filter('[name="template"]')->count() > 0
            ? (string) $crawler->filter('[name="template"]')->first()->attr('value')
            : 'auditor';

        $this->client->request('POST', '/en/admin/roles/templates', [
            '_token' => $token,
            'template' => $template,
            'custom_name' => 'ROLE_ALLOWED_TO_SWITCH',
        ]);

        $this->assertRoleAbsent('ROLE_ALLOWED_TO_SWITCH');
    }

    #[Test]
    public function legacyCustomRoleNamedSwitchUserDoesNotAllowImpersonation(): void
    {
        // Simulates a role created before the hotfix (bypassing validation).
        $this->assertRoleAbsent('ROLE_ALLOWED_TO_SWITCH');
        $role = (new Role())->setName('ROLE_ALLOWED_TO_SWITCH')->setIsSystemRole(true);
        $this->em->persist($role);
        $this->admin->addCustomRole($role);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/en/dashboard?_switch_user=' . urlencode((string) $this->super->getEmail()));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function submitNewRole(User $actor, string $name, bool $systemRole): void
    {
        $this->client->loginUser($actor);
        $crawler = $this->client->request('GET', '/en/admin/roles/new');
        self::assertResponseIsSuccessful();
        $values = $crawler->filter('form[name="role"]')->form()->getPhpValues();
        $values['role']['name'] = $name;
        if ($systemRole) {
            $values['role']['isSystemRole'] = '1';
        } else {
            unset($values['role']['isSystemRole']);
        }
        $this->client->request('POST', '/en/admin/roles/new', $values);
    }

    private function assertRoleAbsent(string $name): void
    {
        // Absent at start => safe to clean up whatever a regression creates.
        if (!in_array($name, $this->roleNamesToCleanUp, true)) {
            $this->roleNamesToCleanUp[] = $name;
        }
        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(Role::class)->findOneBy(['name' => $name]),
            sprintf('role "%s" must not exist', $name),
        );
    }

    private function user(string $label, array $roles): User
    {
        $user = (new User())
            ->setEmail($label . '_' . $this->uid . '@example.com')
            ->setFirstName(ucfirst($label))
            ->setLastName('Reserved')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}

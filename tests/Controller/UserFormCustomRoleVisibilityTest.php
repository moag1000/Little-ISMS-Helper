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

/**
 * Security hotfix (M3): the user form offered custom roles named like
 * privileged roles (e.g. a system role ROLE_GROUP_CISO) to tenant admins.
 * The server rejected them, but they must not be offered either — and a
 * target's hidden role must survive an edit by a tenant admin.
 */
final class UserFormCustomRoleVisibilityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private Tenant $tenantA;
    private User $adminA;
    private User $userA;
    private User $super;
    private Role $holding;
    private bool $holdingCreated = false;
    private Role $ordinary;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);

        $this->tenantA = (new Tenant())->setName('RoleVis A ' . $this->uid)->setCode('rolevis_a_' . $this->uid);
        $this->em->persist($this->tenantA);

        $holding = $this->em->getRepository(Role::class)->findOneBy(['name' => 'ROLE_GROUP_CISO']);
        if (!$holding instanceof Role) {
            $holding = (new Role())->setName('ROLE_GROUP_CISO')->setDescription('holding probe')->setIsSystemRole(true);
            $this->em->persist($holding);
            $this->holdingCreated = true;
        }
        $this->holding = $holding;
        $this->ordinary = (new Role())->setName('RoleVis ' . $this->uid)->setDescription('ordinary')->setIsSystemRole(false);
        $this->em->persist($this->ordinary);

        $this->adminA = $this->user('admina', ['ROLE_ADMIN']);
        $this->userA = $this->user('usera', ['ROLE_USER']);
        $this->super = $this->user('super', ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']);
        $this->userA->addCustomRole($this->holding);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach (['admina', 'usera', 'super'] as $label) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $label . '_' . $this->uid . '@example.com']);
            if ($user !== null) {
                $this->em->remove($user);
            }
        }
        $this->em->flush();
        $ids = [$this->ordinary->getId(), $this->holdingCreated ? $this->holding->getId() : null];
        foreach (array_filter($ids) as $id) {
            if (($role = $this->em->find(Role::class, $id)) !== null) {
                $this->em->remove($role);
            }
        }
        $this->em->flush();
        if (($tenant = $this->em->find(Tenant::class, $this->tenantA->getId())) !== null) {
            $this->em->remove($tenant);
            $this->em->flush();
        }
        parent::tearDown();
    }

    #[Test]
    public function tenantAdminIsNotOfferedPrivilegedCustomRoleAndEditKeepsIt(): void
    {
        $this->client->loginUser($this->adminA);
        $url = '/en/admin/users/' . $this->userA->getId() . '/edit';
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter($this->checkbox($this->holding)), 'privileged custom role must not be offered');
        self::assertCount(1, $crawler->filter($this->checkbox($this->ordinary)), 'ordinary custom role stays assignable');

        $values = $crawler->filter('form[name="user"]')->form()->getPhpValues();
        $values['user']['firstName'] = 'Kept';
        $this->client->request('POST', $url, $values);
        self::assertResponseRedirects();

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->userA->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('Kept', $reloaded->getFirstName());
        self::assertContains(
            $this->holding->getId(),
            array_map(static fn (Role $r): ?int => $r->getId(), $reloaded->getCustomRoles()->toArray()),
            'hidden role held by the target is carried over, not revoked',
        );
    }

    #[Test]
    public function superAdminIsStillOfferedPrivilegedCustomRole(): void
    {
        $this->client->loginUser($this->super);
        $crawler = $this->client->request('GET', '/en/admin/users/' . $this->userA->getId() . '/edit');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter($this->checkbox($this->holding)));
    }

    private function checkbox(Role $role): string
    {
        return sprintf('input[name="user[customRoles][]"][value="%d"]', $role->getId());
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $label, array $roles): User
    {
        $user = (new User())
            ->setEmail($label . '_' . $this->uid . '@example.com')
            ->setFirstName(ucfirst($label))
            ->setLastName('RoleVis')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($this->tenantA)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}

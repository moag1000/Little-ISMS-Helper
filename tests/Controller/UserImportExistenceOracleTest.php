<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Security hotfix (M4): the user import told a tenant admin that an e-mail
 * "already exists" — confirming accounts in other tenants. The per-row
 * message is now generic and identical for own-tenant and foreign accounts.
 */
final class UserImportExistenceOracleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;
    private string $csvPath = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);

        $this->tenantA = (new Tenant())->setName('Import A ' . $this->uid)->setCode('import_a_' . $this->uid);
        $this->tenantB = (new Tenant())->setName('Import B ' . $this->uid)->setCode('import_b_' . $this->uid);
        $this->em->persist($this->tenantA);
        $this->em->persist($this->tenantB);
        $this->adminA = $this->user('admina', ['ROLE_ADMIN'], $this->tenantA);
        $this->user('usera', ['ROLE_USER'], $this->tenantA);
        $this->user('userb', ['ROLE_USER'], $this->tenantB);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->csvPath !== '' && is_file($this->csvPath)) {
            unlink($this->csvPath);
        }
        $this->em->clear();
        foreach (['admina', 'usera', 'userb'] as $label) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email($label)]);
            if ($user !== null) {
                $this->em->remove($user);
            }
        }
        $this->em->flush();
        foreach ([$this->tenantA->getId(), $this->tenantB->getId()] as $id) {
            if ($id !== null && ($tenant = $this->em->find(Tenant::class, $id)) !== null) {
                $this->em->remove($tenant);
                $this->em->flush();
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function importDoesNotDistinguishForeignTenantAccounts(): void
    {
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/users/import');
        $session = $this->client->getRequest()->getSession();
        $token = bin2hex(random_bytes(16));
        $session->set('_csrf/user_import', $token);
        $session->save();

        $this->csvPath = (string) tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($this->csvPath, implode("\n", [
            'email,first_name,last_name,password,is_active,roles',
            $this->email('usera') . ',Own,Tenant,,yes,',
            $this->email('userb') . ',Foreign,Tenant,,yes,',
        ]) . "\n");
        $file = new UploadedFile($this->csvPath, 'users.csv', 'text/csv', null, true);

        $this->client->request('POST', '/en/admin/users/import', ['_token' => $token], ['import_file' => $file]);
        self::assertResponseRedirects();

        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        $warnings = array_values(array_map('strval', $session->getFlashBag()->peek('warning')));
        self::assertCount(2, $warnings);
        foreach ($warnings as $warning) {
            self::assertStringNotContainsStringIgnoringCase('already exists', $warning);
        }
        self::assertSame(
            str_replace($this->email('usera'), '<email>', $warnings[0]),
            str_replace($this->email('userb'), '<email>', $warnings[1]),
            'own-tenant and foreign-tenant collisions must read the same',
        );

        $this->em->clear();
        $foreign = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email('userb')]);
        self::assertSame($this->tenantB->getId(), $foreign?->getTenant()?->getId(), 'foreign account untouched');
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $label, array $roles, Tenant $tenant): User
    {
        $user = (new User())
            ->setEmail($this->email($label))
            ->setFirstName(ucfirst($label))
            ->setLastName('Import')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function email(string $label): string
    {
        return $label . '_' . $this->uid . '@example.com';
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * Security hotfix (admin scope): download / delete / preview of a backup file
 * must apply the same visibility rule as the listing. Invisible backups
 * (instance-wide, legacy, foreign tenant) answer 404 for non-SUPER_ADMIN.
 */
final class AdminBackupScopeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private string $backupDir;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;
    private User $superA;

    /** @var string[] */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);
        $this->backupDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/backups';
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0775, true);
        }

        $this->tenantA = $this->tenant('A');
        $this->tenantB = $this->tenant('B');
        $this->adminA = $this->user('admin_a', ['ROLE_ADMIN'], $this->tenantA);
        $this->superA = $this->user('super_a', ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], $this->tenantA);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($this->backupDir . '/' . $file);
            @unlink($this->backupDir . '/' . $file . '.meta.json');
        }
        $this->em->clear();
        $users = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.email LIKE :p')->setParameter('p', '%' . $this->uid . '%')->getQuery()->getResult();
        foreach ($users as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
        foreach ([$this->tenantA, $this->tenantB] as $tenant) {
            if (($managed = $this->em->find(Tenant::class, $tenant->getId())) !== null) {
                $this->em->remove($managed);
                $this->em->flush();
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function tenantAdminCannotDownloadInstanceWideBackup(): void
    {
        $file = $this->backup([]);
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/data/backup/download/' . $file);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function tenantAdminCannotDownloadForeignTenantOrLegacyBackup(): void
    {
        $foreign = $this->backup([$this->tenantB->getId()]);
        $legacy = $this->backup(null);
        $this->client->loginUser($this->adminA);
        foreach ([$foreign, $legacy] as $file) {
            $this->client->request('GET', '/en/admin/data/backup/download/' . $file);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $file);
        }
    }

    #[Test]
    public function tenantAdminCanDownloadOwnTenantBackup(): void
    {
        $file = $this->backup([$this->tenantA->getId()]);
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/data/backup/download/' . $file);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function superAdminCanDownloadInstanceWideBackup(): void
    {
        $file = $this->backup([]);
        $this->client->loginUser($this->superA);
        $this->client->request('GET', '/en/admin/data/backup/download/' . $file);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function tenantAdminCannotDeleteInvisibleBackups(): void
    {
        $this->client->loginUser($this->adminA);
        foreach ([$this->backup([]), $this->backup([$this->tenantB->getId()]), $this->backup(null)] as $file) {
            $this->postWithToken('/en/admin/data/backup/delete/' . $file, 'data_backup_delete');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $file);
            self::assertFileExists($this->backupDir . '/' . $file);
        }
    }

    #[Test]
    public function tenantAdminCanDeleteOwnBackupAndSuperAdminAnyBackup(): void
    {
        $own = $this->backup([$this->tenantA->getId()]);
        $this->client->loginUser($this->adminA);
        $this->postWithToken('/en/admin/data/backup/delete/' . $own, 'data_backup_delete');
        self::assertResponseIsSuccessful();
        self::assertFileDoesNotExist($this->backupDir . '/' . $own);

        $global = $this->backup([]);
        $this->client->loginUser($this->superA);
        $this->postWithToken('/en/admin/data/backup/delete/' . $global, 'data_backup_delete');
        self::assertResponseIsSuccessful();
        self::assertFileDoesNotExist($this->backupDir . '/' . $global);
    }

    #[Test]
    public function tenantAdminCannotPreviewInvisibleBackup(): void
    {
        $this->client->loginUser($this->adminA);
        foreach ([$this->backup([]), $this->backup([$this->tenantB->getId()])] as $file) {
            $this->client->request('GET', '/en/admin/data/backup/preview/' . $file);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $file);
        }
    }

    /** @param int[]|null $scope null = legacy backup without tenant_scope */
    private function backup(?array $scope): string
    {
        $name = sprintf(
            'backup_2099-%02d-%02d_%02d-%02d-%02d.json',
            random_int(1, 12),
            random_int(1, 28),
            random_int(0, 23),
            random_int(0, 59),
            random_int(0, 59),
        );
        $metadata = ['version' => '2.0'] + ($scope === null ? [] : ['tenant_scope' => $scope]);
        file_put_contents($this->backupDir . '/' . $name, (string) json_encode(['metadata' => $metadata, 'data' => []]));
        $this->files[] = $name;

        return $name;
    }

    private function postWithToken(string $url, string $tokenId): void
    {
        $this->client->request('GET', '/en/admin/users');
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();

        $this->client->request('POST', $url, ['_token' => $token]);
    }

    private function tenant(string $label): Tenant
    {
        $tenant = (new Tenant())
            ->setName('BScope ' . $label . ' ' . $this->uid)
            ->setCode('bscope_' . strtolower($label) . '_' . $this->uid)
            ->setIsActive(true);
        $this->em->persist($tenant);

        return $tenant;
    }

    private function user(string $label, array $roles, Tenant $tenant): User
    {
        $user = (new User())
            ->setEmail($label . '_' . $this->uid . '@example.com')
            ->setFirstName(ucfirst($label))
            ->setLastName('BScope')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}

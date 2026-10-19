<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Job\JobStatusService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security hotfix (M2): the async user-export download was addressable by any
 * user-admin who knew the job UUID. It is bound to the user who started the
 * export (SUPER_ADMIN may download any). The binding is the job owner recorded
 * by JobStatusService (owner_user_id) and enforced by AsyncJobVoter::ACCESS,
 * so every other user gets 403 — for existing and unknown job ids alike.
 */
final class UserExportDownloadOwnerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private Tenant $tenantA;
    private User $adminA;
    private User $otherAdminA;
    private User $super;
    /** @var list<string> */
    private array $jobIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);

        $this->tenantA = (new Tenant())->setName('Export A ' . $this->uid)->setCode('export_a_' . $this->uid);
        $this->em->persist($this->tenantA);
        $this->adminA = $this->user('admina', ['ROLE_ADMIN']);
        $this->otherAdminA = $this->user('otheradmina', ['ROLE_ADMIN']);
        $this->super = $this->user('super', ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $jobs = static::getContainer()->get(JobStatusService::class);
        foreach ($this->jobIds as $id) {
            $jobs->delete($id);
            @unlink(static::getContainer()->getParameter('kernel.project_dir') . '/var/exports/' . $id . '.csv');
        }
        $this->em->clear();
        foreach (['admina', 'otheradmina', 'super'] as $label) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email($label)]);
            if ($user !== null) {
                $this->em->remove($user);
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
    public function onlyTheCreatorOrSuperAdminCanDownloadTheExport(): void
    {
        $jobId = $this->dispatchExportAs($this->adminA);
        $record = static::getContainer()->get(JobStatusService::class)->read($jobId);
        self::assertSame($this->adminA->getId(), $record['owner_user_id'] ?? null, 'dispatch records the creator');

        $this->client->loginUser($this->otherAdminA);
        $this->client->request('GET', $this->downloadUrl($jobId));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // The rejected request must not have consumed the file.
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', $this->downloadUrl($jobId));
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function superAdminCanDownloadAnyExport(): void
    {
        $jobId = $this->dispatchExportAs($this->adminA);

        $this->client->loginUser($this->super);
        $this->client->request('GET', $this->downloadUrl($jobId));
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function exportWithoutRecordedCreatorIsNotDownloadableForTenantAdmins(): void
    {
        $jobs = static::getContainer()->get(JobStatusService::class);
        $jobId = $jobs->create('user_management.export', []);
        $this->jobIds[] = $jobId;
        $jobs->markSucceeded($jobId);
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/var/exports';
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/' . $jobId . '.csv', "email\n");

        $this->client->loginUser($this->adminA);
        $this->client->request('GET', $this->downloadUrl($jobId));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function dispatchExportAs(User $actor): string
    {
        $this->client->loginUser($actor);
        $this->client->request('GET', '/en/admin/users');
        $session = $this->client->getRequest()->getSession();
        $token = bin2hex(random_bytes(16));
        $session->set('_csrf/user_management_export_dispatch', $token);
        $session->save();

        $this->client->request('POST', '/en/admin/users/export/dispatch', ['_token' => $token]);
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/', $location);
        preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/', $location, $m);
        $this->jobIds[] = $m[0];
        self::assertSame('succeeded', static::getContainer()->get(JobStatusService::class)->read($m[0])['status']);

        return $m[0];
    }

    private function downloadUrl(string $jobId): string
    {
        return '/en/admin/users/export/download/' . $jobId;
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $label, array $roles): User
    {
        $user = (new User())
            ->setEmail($this->email($label))
            ->setFirstName(ucfirst($label))
            ->setLastName('Export')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($this->tenantA)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function email(string $label): string
    {
        return $label . '_' . $this->uid . '@example.com';
    }
}

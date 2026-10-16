<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * End-to-end: a risk export dispatched by one user can be polled and
 * downloaded by that user only — not by a colleague who learns the job id.
 */
final class AsyncJobOwnershipTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    #[Test]
    public function onlyTheDispatchingUserCanPollAndDownloadTheExport(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Job Owner Tenant');
        $tenant->setCode('job_owner_' . uniqid());
        $this->em->persist($tenant);
        $owner = $this->user($tenant, 'owner');
        $colleague = $this->user($tenant, 'colleague');
        $this->em->flush();
        $this->cleanup = [$tenant, $owner, $colleague];

        $this->client->loginUser($owner);
        $this->client->request('POST', '/en/risk/export/dispatch', ['_token' => $this->seedCsrf('risk_export_dispatch')]);
        self::assertResponseStatusCodeSame(303);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/admin/jobs/([0-9a-f-]{36})/progress#', $location);
        preg_match('#/admin/jobs/([0-9a-f-]{36})/progress#', $location, $m);
        $jobId = $m[1];

        $this->client->request('GET', '/en/admin/jobs/' . $jobId . '/status');
        self::assertResponseIsSuccessful();

        $this->client->loginUser($colleague);
        $this->client->request('GET', '/en/admin/jobs/' . $jobId . '/status');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/en/admin/jobs/' . $jobId . '/progress');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/en/risk/export/download/' . $jobId);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($owner);
        $this->client->request('GET', '/en/risk/export/download/' . $jobId);
        self::assertResponseIsSuccessful();
    }

    private function user(Tenant $tenant, string $marker): User
    {
        $user = new User();
        $user->setEmail('job_' . $marker . '_' . uniqid() . '@example.com');
        $user->setFirstName($marker);
        $user->setLastName('Job');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function seedCsrf(string $tokenId): string
    {
        $this->client->request('GET', '/en/risk/');
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();

        return $token;
    }
}

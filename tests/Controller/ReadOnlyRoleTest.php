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

/**
 * Reviewer roles (ROLE_AUDITOR without ROLE_MANAGER) are read-only end to end:
 * they read lists, but neither open nor submit create forms of ISMS records.
 */
final class ReadOnlyRoleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $created = [];
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = substr(uniqid('', true), -8);
        $this->tenant = $this->keep((new Tenant())->setName('RO ' . $uid)->setCode('ro_' . $uid));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            try {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
                }
            } catch (\Throwable) {
            }
        }
        try {
            $this->em->flush();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function auditorReadsTheRiskListButCannotOpenOrSubmitTheRiskForm(): void
    {
        $this->client->loginUser($this->user(['ROLE_AUDITOR']));

        $this->client->request('GET', '/de/risk');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/de/risk/new');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', '/de/risk/new', ['risk' => ['title' => 'x']]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function auditorGetsJsonForbiddenOnFetchWrites(): void
    {
        $this->client->loginUser($this->user(['ROLE_AUDITOR']));

        $this->client->request('POST', '/de/risk/new', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('read_only_role', json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? null);
    }

    #[Test]
    public function managerStillOpensTheRiskForm(): void
    {
        $this->client->loginUser($this->user(['ROLE_MANAGER']));

        $this->client->request('GET', '/de/risk/new');

        self::assertResponseIsSuccessful();
    }

    /** @param list<string> $roles */
    private function user(array $roles): User
    {
        $user = $this->keep((new User())->setEmail(uniqid('ro', true) . '@x.test')->setFirstName('Read')
            ->setLastName('Only')->setRoles($roles)->setPassword('hashed')->setTenant($this->tenant)->setIsActive(true));
        $this->em->flush();

        return $user;
    }

    private function keep(object $entity): object
    {
        $this->em->persist($entity);
        $this->created[] = $entity;

        return $entity;
    }
}

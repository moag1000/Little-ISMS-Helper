<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\IsmsRegisterEntry;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RegisterType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Smoke and isolation tests for the generic ISMS registers (ISO 27001 4.1–7.4).
 */
final class RegisterControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $object) {
            try {
                $found = $this->em->find($object::class, $object->getId());
                if ($found !== null) {
                    $this->em->remove($found);
                }
            } catch (\Throwable) {
                // ignore
            }
        }
        try {
            $this->em->flush();
        } catch (\Throwable) {
            // ignore
        }
        parent::tearDown();
    }

    private function tenant(): Tenant
    {
        $id = uniqid('reg_', true);
        $tenant = new Tenant();
        $tenant->setName('Register ' . $id);
        $tenant->setCode('reg_' . str_replace('.', '', $id));
        $this->em->persist($tenant);
        $this->created[] = $tenant;

        return $tenant;
    }

    /** @param list<string> $roles */
    private function user(Tenant $tenant, array $roles): User
    {
        $user = new User();
        $user->setEmail(uniqid('reg', true) . '@example.com');
        $user->setFirstName('Reg');
        $user->setLastName('User');
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->created[] = $user;

        return $user;
    }

    private function entry(Tenant $tenant, RegisterType $type, string $title): IsmsRegisterEntry
    {
        $entry = (new IsmsRegisterEntry($type))
            ->setTenant($tenant)
            ->setTitle($title)
            ->setNextReviewDate(new DateTimeImmutable('-1 day'));
        $this->em->persist($entry);
        $this->created[] = $entry;

        return $entry;
    }

    #[Test]
    public function readerSeesOverviewListAndEntry(): void
    {
        $tenant = $this->tenant();
        $user = $this->user($tenant, ['ROLE_USER']);
        $entry = $this->entry($tenant, RegisterType::ISSUE_EXTERNAL, 'Fachkräftemangel');
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/de/register');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/de/register/issue_external');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Fachkräftemangel');

        $this->client->request('GET', '/de/register/entry/' . $entry->getId());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/de/register/export.csv');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function readerCannotCreateEntries(): void
    {
        $tenant = $this->tenant();
        $user = $this->user($tenant, ['ROLE_USER']);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/de/register/role/new');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function managerOpensEveryRegisterForm(): void
    {
        $tenant = $this->tenant();
        $manager = $this->user($tenant, ['ROLE_MANAGER']);
        $this->em->flush();
        $this->client->loginUser($manager);

        foreach (RegisterType::cases() as $type) {
            $this->client->request('GET', '/de/register/' . $type->value . '/new');
            self::assertResponseIsSuccessful($type->value);
        }
    }

    #[Test]
    public function entryOfAnotherOrganisationIsNotFound(): void
    {
        $own = $this->tenant();
        $foreign = $this->tenant();
        $manager = $this->user($own, ['ROLE_MANAGER']);
        $entry = $this->entry($foreign, RegisterType::LEGAL_REQUIREMENT, 'NIS2UmsuCG');
        $this->em->flush();
        $this->client->loginUser($manager);

        $this->client->request('GET', '/de/register/entry/' . $entry->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}

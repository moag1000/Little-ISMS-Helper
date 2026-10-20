<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\InterestedParty;
use App\Entity\ISMSObjective;
use App\Entity\Location;
use App\Entity\Patch;
use App\Entity\Person;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Vulnerability;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Person-picker sweep: editing an existing record through the real form stores
 * the structured owner (User) and a deputy (Person), keeps the legacy
 * free-text value, and the show page displays the structured owner.
 */
final class PersonPickerSweepControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $actor;
    private User $owner;
    private Person $deputy;
    private User $foreignUser;
    private string $uid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = str_replace('.', '', uniqid('pp', true));

        $this->tenant = (new Tenant())->setName('PP Tenant ' . $this->uid)->setCode('pp_' . $this->uid);
        $this->em->persist($this->tenant);

        $this->actor = $this->makeUser('Actor', 'Admin', ['ROLE_ADMIN']);
        $this->owner = $this->makeUser('Olivia', 'Ownerpick' . $this->uid, ['ROLE_USER']);

        $this->deputy = (new Person())->setFullName('Dora Deputypick' . $this->uid)->setTenant($this->tenant);
        $this->em->persist($this->deputy);
        $foreignTenant = (new Tenant())->setName('PP Foreign ' . $this->uid)->setCode('ppf_' . $this->uid);
        $this->em->persist($foreignTenant);
        $this->foreignUser = $this->makeUser('Foreigner', 'Foreignuser' . $this->uid, ['ROLE_USER']);
        $this->foreignUser->setTenant($foreignTenant);
        $this->em->flush();
    }

    /** @param list<string> $roles */
    private function makeUser(string $first, string $last, array $roles): User
    {
        $user = (new User())
            ->setEmail(strtolower($first) . '_' . $this->uid . '@example.com')
            ->setFirstName($first)
            ->setLastName($last)
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     *     [entity class, URL slug, field base, deputies field]
     */
    public static function entityProvider(): iterable
    {
        yield 'objective' => [ISMSObjective::class, 'objective', 'responsiblePerson', 'responsibleDeputyPersons'];
        yield 'interested party' => [InterestedParty::class, 'interested-party', 'contactPerson', 'contactDeputyPersons'];
        yield 'location' => [Location::class, 'location', 'responsiblePerson', 'responsibleDeputyPersons'];
        yield 'patch' => [Patch::class, 'patch', 'responsiblePerson', 'responsibleDeputyPersons'];
        yield 'supplier' => [Supplier::class, 'supplier', 'contactPerson', 'contactDeputyPersons'];
        yield 'vulnerability' => [Vulnerability::class, 'vulnerability', 'responsiblePerson', 'responsibleDeputyPersons'];
    }

    private function seed(string $class): object
    {
        $u = $this->uid;
        $e = match ($class) {
            ISMSObjective::class => (new ISMSObjective())
                ->setTitle('Objective ' . $u)->setDescription('d')->setCategory('security')
                ->setStatus('in_progress')->setTargetDate(new \DateTime('+30 days')),
            InterestedParty::class => (new InterestedParty())
                ->setName('Party ' . $u)->setPartyType('customer')->setImportance('high')->setRequirements('r'),
            Location::class => (new Location())
                ->setName('Location ' . $u)->setDescription('d')->setLocationType('building')
                ->setAddress('1 Test St')->setCity('Test City')->setCountry('Germany'),
            Patch::class => (new Patch())
                ->setPatchId('PATCH-' . $u)->setTitle('Patch ' . $u)->setDescription('d')->setVendor('V')
                ->setProduct('P')->setPatchType('security')->setPriority('medium')->setStatus('available')
                ->setReleaseDate(new \DateTimeImmutable()),
            Supplier::class => (new Supplier())
                ->setName('Supplier ' . $u)->setEmail('s_' . $u . '@example.com')->setAddress('1 Test St')
                ->setCriticality('high')->setStatus('active')->setServiceProvided('Cloud'),
            Vulnerability::class => (new Vulnerability())
                ->setCveId('CVE-2024-' . substr($u, -8))->setTitle('Vuln ' . $u)->setDescription('d')
                ->setSeverity('medium')->setSource('internal')->setStatus('open')
                ->setDiscoveredDate(new \DateTimeImmutable()),
        };
        $e->setTenant($this->tenant);
        $this->em->persist($e);
        $this->em->flush();

        return $e;
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('entityProvider')]
    public function editStoresUserAndDeputyAndShowDisplaysOwner(
        string $class,
        string $slug,
        string $base,
        string $deputies,
    ): void {
        $entity = $this->seed($class);
        $id = $entity->getId();
        $legacySetter = 'set' . ucfirst($base);
        $entity->{$legacySetter}('Legacy Freetext ' . $this->uid);
        $this->em->flush();

        $this->client->loginUser($this->actor);
        $crawler = $this->client->request('GET', sprintf('/en/%s/%d/edit', $slug, $id));
        self::assertResponseIsSuccessful();

        $userSelect = $crawler->filter(sprintf('select[name$="[%sUser]"]', $base));
        self::assertCount(1, $userSelect, 'owner User select is rendered on the edit page');
        $form = $userSelect->closest('form')->form();
        $prefix = preg_replace('/\[' . $base . 'User\]$/', '', $userSelect->attr('name'));

        $form[$prefix . '[' . $base . 'User]']->select((string) $this->owner->getId());
        $form[$prefix . '[' . $deputies . ']']->select([(string) $this->deputy->getId()]);

        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $saved = $this->em->find($class, $id);
        self::assertSame($this->owner->getId(), $saved->{'get' . ucfirst($base) . 'User'}()?->getId());
        $role = str_starts_with($base, 'contact') ? 'Contact' : 'Responsible';
        $stored = $saved->{'get' . $role . 'DeputyPersons'}();
        self::assertCount(1, $stored);
        self::assertSame($this->deputy->getId(), $stored->first()->getId());
        self::assertSame('Legacy Freetext ' . $this->uid, $saved->{'get' . ucfirst($base)}(), 'legacy text round-trips');
        self::assertSame($this->owner->getFullName(), $saved->{'getEffective' . ucfirst($base)}());

        $this->client->request('GET', sprintf('/en/%s/%d', $slug, $id));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            (string) $this->owner->getFullName(),
            (string) $this->client->getResponse()->getContent(),
            'show page displays the structured owner',
        );
        self::assertStringNotContainsString(
            'Legacy Freetext ' . $this->uid,
            (string) $this->client->getResponse()->getContent(),
            'show page prefers the structured owner over the legacy text',
        );
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('entityProvider')]
    public function foreignTenantUserIsNeitherListedNorAccepted(
        string $class,
        string $slug,
        string $base,
        string $deputies,
    ): void {
        $entity = $this->seed($class);
        $id = $entity->getId();

        $this->client->loginUser($this->actor);
        $crawler = $this->client->request('GET', sprintf('/en/%s/%d/edit', $slug, $id));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Foreignuser' . $this->uid, $html, 'foreign user name leaked into picker');
        self::assertStringContainsString('Ownerpick' . $this->uid, $html, 'same-tenant user is listed');

        $userSelect = $crawler->filter(sprintf('select[name$="[%sUser]"]', $base));
        $form = $userSelect->closest('form')->form();
        $name = $userSelect->attr('name');
        $form[$name]->disableValidation()->select((string) $this->foreignUser->getId());
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        self::assertNull($this->em->find($class, $id)->{'get' . ucfirst($base) . 'User'}(), 'foreign user was persisted');
    }

    #[Test]
    public function objectiveWithoutAnyOwnerShowsTranslatedError(): void
    {
        $objective = $this->seed(ISMSObjective::class);
        $this->client->loginUser($this->actor);
        $crawler = $this->client->request('GET', '/en/objective/' . $objective->getId() . '/edit');
        $userSelect = $crawler->filter('select[name$="[responsiblePersonUser]"]');
        $form = $userSelect->closest('form')->form();
        $prefix = preg_replace('/\[responsiblePersonUser\]$/', '', $userSelect->attr('name'));
        $form[$prefix . '[responsiblePerson]'] = '';

        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Please specify a responsible person', $html);
        self::assertStringNotContainsString('objective.validation.owner_required', $html, 'raw translation key shown');
    }

    #[Test]
    public function tenantlessSuperAdminEditingForeignRecordKeepsItsOwnerAndSeesOnlyItsUsers(): void
    {
        $superAdmin = $this->makeUser('Super', 'Admin', ['ROLE_SUPER_ADMIN']);
        $superAdmin->setTenant(null);
        $objective = $this->seed(ISMSObjective::class);
        $objective->setResponsiblePersonUser($this->owner);
        $this->em->flush();
        $id = $objective->getId();

        $this->client->loginUser($superAdmin);
        $crawler = $this->client->request('GET', '/en/objective/' . $id . '/edit');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ownerpick' . $this->uid, $html, 'record-tenant owner is listed');
        self::assertStringNotContainsString('Foreignuser' . $this->uid, $html, 'users of other tenants are not listed');

        $form = $crawler->filter('select[name$="[responsiblePersonUser]"]')->closest('form')->form();
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        self::assertSame($this->owner->getId(), $this->em->find(ISMSObjective::class, $id)->getResponsiblePersonUser()?->getId());
    }
}

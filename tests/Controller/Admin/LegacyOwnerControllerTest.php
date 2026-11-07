<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Asset;
use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin report "Freitext-Verantwortliche": lists records whose owner is only
 * legacy free text, resolves them to the matched account or a new Person, and
 * never reaches a record of another organisation.
 */
final class LegacyOwnerControllerTest extends WebTestCase
{
    private const string URL = '/en/admin/data-repair/legacy-owners';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private Tenant $tenant;
    private User $admin;
    private User $erika;
    private Asset $matched;
    private Asset $external;
    private Asset $foreign;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $lock = static::getContainer()->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lock)) {
            @file_put_contents($lock, date('c'));
        }

        $this->uid = substr(str_replace('.', '', uniqid('', true)), -10);
        $this->tenant = $this->keep((new Tenant())->setName('LegacyOwner ' . $this->uid)->setCode('lo_' . $this->uid));
        $other = $this->keep((new Tenant())->setName('LegacyOther ' . $this->uid)->setCode('lx_' . $this->uid));

        $this->admin = $this->user('admin', 'Lo', 'Admin', ['ROLE_ADMIN'], $this->tenant);
        $this->erika = $this->user('erika', 'Erika', 'Beispiel' . $this->uid, ['ROLE_USER'], $this->tenant);

        $this->matched = $this->asset('Matched', '  erika   beispiel' . $this->uid . ' ', $this->tenant);
        $this->external = $this->asset('External', 'Externe Firma ' . $this->uid, $this->tenant);
        $this->foreign = $this->asset('Foreign', 'Erika Beispiel' . $this->uid, $other);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        try {
            $conn->executeStatement('DELETE FROM asset WHERE tenant_id IN (SELECT id FROM tenant WHERE code IN (?, ?))', ['lo_' . $this->uid, 'lx_' . $this->uid]);
            $conn->executeStatement('DELETE FROM person WHERE tenant_id IN (SELECT id FROM tenant WHERE code IN (?, ?))', ['lo_' . $this->uid, 'lx_' . $this->uid]);
            $conn->executeStatement('DELETE FROM users WHERE tenant_id IN (SELECT id FROM tenant WHERE code IN (?, ?))', ['lo_' . $this->uid, 'lx_' . $this->uid]);
            $conn->executeStatement('DELETE FROM tenant WHERE code IN (?, ?)', ['lo_' . $this->uid, 'lx_' . $this->uid]);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function listsOwnOpenRowsWithSuggestionButNotOtherOrganisations(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Matched ' . $this->uid, $html);
        self::assertStringContainsString('External ' . $this->uid, $html);
        self::assertStringNotContainsString('Foreign ' . $this->uid, $html);
        self::assertCount(1, $crawler->filter(sprintf('form[action$="/asset/%d/apply"]', $this->matched->getId())));
        self::assertCount(0, $crawler->filter(sprintf('form[action$="/asset/%d/apply"]', $this->external->getId())));
    }

    #[Test]
    public function applyingTheSuggestionSetsTheUserAndKeepsTheText(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', self::URL);
        $form = $crawler->filter(sprintf('form[action$="/asset/%d/apply"]', $this->matched->getId()))->form();
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $asset = $this->em->find(Asset::class, $this->matched->getId());
        self::assertSame($this->erika->getId(), $asset?->getOwnerUser()?->getId());
        self::assertSame('  erika   beispiel' . $this->uid . ' ', $asset->getOwner());
    }

    #[Test]
    public function recordingAsPersonCreatesThePersonInTheRecordsOrganisation(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', self::URL);
        $form = $crawler->filter(sprintf('form[action$="/asset/%d/person"]', $this->external->getId()))->form();
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $asset = $this->em->find(Asset::class, $this->external->getId());
        $person = $asset?->getOwnerPerson();
        self::assertInstanceOf(Person::class, $person);
        self::assertSame('Externe Firma ' . $this->uid, $person->getFullName());
        self::assertSame($this->tenant->getId(), $person->getTenant()?->getId());
    }

    #[Test]
    public function aRecordOfAnotherOrganisationIsNeverChanged(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', self::URL);
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', sprintf('%s/asset/%d/person', self::URL, $this->foreign->getId()), ['_token' => $token]);
        self::assertResponseRedirects();
        $this->client->request('POST', self::URL . '/apply-all', ['_token' => $token]);
        self::assertResponseRedirects();

        $this->em->clear();
        $foreign = $this->em->find(Asset::class, $this->foreign->getId());
        self::assertNull($foreign?->getOwnerUser());
        self::assertNull($foreign?->getOwnerPerson());
        $matched = $this->em->find(Asset::class, $this->matched->getId());
        self::assertSame($this->erika->getId(), $matched?->getOwnerUser()?->getId(), 'apply-all resolves the own organisation');
    }

    #[Test]
    public function postWithoutTokenIsRejected(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', sprintf('%s/asset/%d/apply', self::URL, $this->matched->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function aUserWithoutAdminRoleIsDenied(): void
    {
        $this->client->loginUser($this->erika);
        $this->client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $prefix, string $first, string $last, array $roles, Tenant $tenant): User
    {
        return $this->keep((new User())
            ->setEmail($prefix . '_' . $this->uid . '@legacy-owner.test')
            ->setFirstName($first)->setLastName($last)
            ->setRoles($roles)->setPassword('hashed')
            ->setTenant($tenant)->setIsActive(true));
    }

    private function asset(string $name, string $owner, Tenant $tenant): Asset
    {
        return $this->keep((new Asset())
            ->setName($name . ' ' . $this->uid)->setAssetType('hardware')->setOwner($owner)
            ->setTenant($tenant)->setConfidentialityValue(2)->setIntegrityValue(2)
            ->setAvailabilityValue(2)->setStatus('active')->setDataClassification('internal'));
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function keep(object $entity): object
    {
        $this->em->persist($entity);

        return $entity;
    }
}

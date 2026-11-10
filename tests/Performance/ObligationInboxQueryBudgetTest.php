<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S31: My Day costs the same number of queries for a small and a large organisation.
 * Before, the inbox loaded every record of ~28 deadline types per call (whole risk,
 * control, supplier … tables) and read deputies and acknowledgements one query per
 * record, so the page grew with the organisation.
 *
 * The page is requested twice: with a small data set and with 2,000 assets plus a few
 * hundred due risks and controls (owners, deputies). The large one adds at most a
 * handful of queries over the small one — that is the guarantee. The ceiling itself is
 * the measured constant (about 120 on 2026-11-09: one query per deadline type, ~55 types,
 * plus layout and security); it may only go down.
 *
 * QUERY_DUMP=/path/file php bin/phpunit … writes the SQL of both requests for a look.
 */
final class ObligationInboxQueryBudgetTest extends WebTestCase
{
    private const int BUDGET = 130;
    /** Extra queries the large organisation may cost (one-off loads, not per record). */
    private const int GROWTH = 5;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private User $ciso;
    /** @var list<Person> */
    private array $people = [];
    private int $batch = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = substr(uniqid('', true), -10);
        $this->tenant = (new Tenant())->setName('Budget ' . $uid)->setCode('budget_' . $uid);
        $this->em->persist($this->tenant);
        $this->ciso = $this->user('ciso_' . $uid, ['ROLE_CISO']);
        for ($i = 0; $i < 10; ++$i) {
            $person = (new Person())->setFullName('Person ' . $i . ' ' . $uid)->setTenant($this->tenant)
                ->setLinkedUser($this->user('p' . $i . '_' . $uid, ['ROLE_USER']));
            $this->em->persist($person);
            $this->people[] = $person;
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $tenantId = $this->tenant?->getId();
        if ($tenantId !== null) {
            $this->em->clear();
            $tenant = $this->em->getReference(Tenant::class, $tenantId);
            foreach ([Risk::class, Control::class] as $class) {
                foreach ($this->em->getRepository($class)->findBy(['tenant' => $tenant]) as $record) {
                    $this->em->remove($record);
                }
            }
            $this->em->flush();
            $conn = $this->em->getConnection();
            foreach ([
                'DELETE FROM asset WHERE tenant_id = ?',
                'UPDATE person SET linked_user_id = NULL WHERE tenant_id = ?',
                'DELETE FROM person WHERE tenant_id = ?',
                'DELETE FROM obligation_notice WHERE tenant_id = ?',
                'DELETE FROM users WHERE tenant_id = ?',
                'DELETE FROM tenant WHERE id = ?',
            ] as $sql) {
                try {
                    $conn->executeStatement($sql, [$tenantId]);
                } catch (\Throwable) {
                    // best effort
                }
            }
        }
        $this->tenant = null;
        parent::tearDown();
    }

    #[Test]
    public function myDayQueriesDoNotGrowWithTheOrganisation(): void
    {
        $this->records(assets: 5, risks: 3, controls: 3);
        $this->myDayQueries(); // warm-up: caches filled on the first page view do not count
        $small = $this->myDayQueries();

        $this->records(assets: 2000, risks: 300, controls: 150);
        $large = $this->myDayQueries();

        self::assertLessThanOrEqual(self::BUDGET, $large, sprintf('My Day with 2,000 assets: %d queries (small org: %d).', $large, $small));
        self::assertLessThanOrEqual($small + self::GROWTH, $large, sprintf(
            'My Day grows with the data: %d queries small, %d large — a query per record crept in.',
            $small,
            $large,
        ));
    }

    private function myDayQueries(): int
    {
        $this->em->clear();
        $this->client->loginUser($this->em->find(User::class, $this->ciso->getId()));
        $debug = static::getContainer()->get('doctrine.debug_data_holder');
        $before = array_map('count', $debug->getData());
        $this->client->request('GET', '/en/my-day');
        self::assertResponseIsSuccessful();
        $data = array_map(static fn (array $queries, string $connection): array => array_slice($queries, $before[$connection] ?? 0), $debug->getData(), array_keys($debug->getData()));

        if (getenv('QUERY_DUMP')) {
            foreach ($data as $queries) {
                foreach ($queries as $q) {
                    file_put_contents(getenv('QUERY_DUMP'), preg_replace('/\s+/', ' ', (string) $q['sql']) . "\n", FILE_APPEND);
                }
            }
            file_put_contents(getenv('QUERY_DUMP'), "=====\n", FILE_APPEND);
        }

        return array_sum(array_map('count', $data));
    }

    private function records(int $assets, int $risks, int $controls): void
    {
        $tenant = $this->em->find(Tenant::class, $this->tenant->getId());
        $people = array_map(fn (Person $p): Person => $this->em->find(Person::class, $p->getId()), $this->people);
        $overdue = new DateTimeImmutable('-3 days');
        $later = new DateTimeImmutable('+200 days');
        $batch = ++$this->batch;
        // more colleagues in the larger organisation (role look-ups per person)
        for ($i = 0; $i < intdiv($assets, 100); ++$i) {
            $this->em->persist((new User())->setEmail(sprintf('colleague%d_%d_%s@example.test', $batch, $i, $tenant->getCode()))
                ->setFirstName('Colleague')->setLastName((string) $i)->setRoles(['ROLE_USER'])->setPassword('x')
                ->setTenant($tenant)->setIsActive(true));
        }
        for ($i = 0; $i < $assets; ++$i) {
            $this->em->persist((new Asset())->setName('Asset ' . $i)->setAssetType('software')
                ->setConfidentialityValue(2)->setIntegrityValue(2)->setAvailabilityValue(2)->setTenant($tenant));
            if ($i % 500 === 499) {
                $this->em->flush();
            }
        }
        for ($i = 0; $i < $risks; ++$i) {
            $risk = (new Risk())->setTitle('Risk ' . $i)->setCategory('operational')->setDescription('Budget fixture.')
                ->setProbability(3)->setImpact(3)->setStatus(RiskStatus::Identified)
                ->setReviewDate(\DateTime::createFromImmutable($i % 2 === 0 ? $overdue : $later));
            $risk->setTenant($tenant);
            $risk->setCreatedAt(new DateTimeImmutable());
            $risk->setRiskOwnerPerson($people[$i % 10]);
            $risk->addRiskOwnerDeputyPerson($people[($i + 1) % 10]);
            $risk->addRiskOwnerDeputyPerson($people[($i + 2) % 10]);
            $this->em->persist($risk);
        }
        for ($i = 0; $i < $controls; ++$i) {
            $control = (new Control())->setControlId(sprintf('B%d.%d', $batch, $i))->setName('Control ' . $i)->setDescription('Budget fixture.')
                ->setCategory('organizational')->setApplicable(true)->setTenant($tenant)
                ->setNextReviewDate(\DateTime::createFromImmutable($i % 2 === 0 ? $overdue : $later));
            $control->setResponsiblePersonRef($people[$i % 10]);
            $control->addResponsibleDeputyPerson($people[($i + 3) % 10]);
            $this->em->persist($control);
        }
        $this->em->flush();
    }

    /** @param list<string> $roles */
    private function user(string $name, array $roles): User
    {
        $user = (new User())->setEmail($name . '@example.test')->setFirstName($name)->setLastName('Budget')
            ->setRoles($roles)->setPassword('x')->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}

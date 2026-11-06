<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The lists on the list skeleton render for real (filter bar, table, pager,
 * empty state) and page + search on the server.
 */
final class ListSkeletonPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private User $admin;
    private string $uid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->uid = substr(uniqid('', true), -8);
        $this->tenant = (new Tenant())->setName('Lists ' . $this->uid)->setCode('ls_' . $this->uid);
        $this->em->persist($this->tenant);

        $this->admin = new User();
        $this->admin->setEmail('lists_' . $this->uid . '@example.com');
        $this->admin->setFirstName('List');
        $this->admin->setLastName('Admin');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword('hashed_password');
        $this->admin->setTenant($this->tenant);
        $this->admin->setIsActive(true);
        $this->em->persist($this->admin);

        for ($i = 1; $i <= 30; $i++) {
            $risk = new Risk();
            $risk->setTenant($this->tenant);
            $risk->setTitle(sprintf('Listenrisiko %s %02d', $this->uid, $i));
            $risk->setCategory('operational');
            $risk->setDescription('List skeleton fixture.');
            $risk->setProbability($i === 1 ? 5 : 2);
            $risk->setImpact($i === 1 ? 5 : 2);
            $risk->setStatus(RiskStatus::Identified);
            $risk->setCreatedAt(new DateTimeImmutable());
            $this->em->persist($risk);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $tenantId = $this->tenant?->getId();
        if ($tenantId !== null) {
            $conn = $this->em->getConnection();
            foreach ([
                'DELETE FROM risk WHERE tenant_id = ?',
                'DELETE FROM users WHERE tenant_id = ?',
                'DELETE FROM tenant WHERE id = ?',
            ] as $sql) {
                try {
                    $conn->executeStatement($sql, [$tenantId]);
                } catch (\Throwable) {
                    // best effort: audit rows written during the request may block
                }
            }
        }
        $this->tenant = null;

        parent::tearDown();
    }

    #[Test]
    public function riskListPagesAndShowsSummaryChips(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/en/risk?per_page=25');

        self::assertResponseIsSuccessful();
        self::assertSame(25, $crawler->filter('#risk-rows tr')->count());
        self::assertSelectorExists('nav.fa-pager a[rel="next"]');
        self::assertSelectorTextContains('.fa-pager-bar__info', '1–25');
        self::assertSelectorExists('.fa-list-page__summary .fa-status-pill--danger');
        self::assertSelectorNotExists('.fa-feature-card');

        $crawler = $this->client->request('GET', '/en/risk?per_page=25&page=2');
        self::assertSame(5, $crawler->filter('#risk-rows tr')->count());
    }

    #[Test]
    public function searchNarrowsTheList(): void
    {
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/en/risk?q=' . rawurlencode($this->uid . ' 07'));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#risk-rows tr')->count());
        self::assertSelectorNotExists('.fa-pager-bar');
    }

    #[Test]
    #[DataProvider('listUrls')]
    public function migratedListRenders(string $url): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#list-filter-form');
    }

    /** @return iterable<string, array{string}> */
    public static function listUrls(): iterable
    {
        yield 'assets' => ['/en/asset'];
        yield 'incidents' => ['/en/incident'];
        yield 'documents' => ['/en/document'];
        yield 'vulnerabilities' => ['/en/vulnerability'];
        yield 'audit log' => ['/en/admin/audit-log'];
    }
}

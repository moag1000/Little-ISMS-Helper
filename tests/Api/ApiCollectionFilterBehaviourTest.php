<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Service\ModuleConfigurationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/risks (and /api/controls for booleans) filters behave as before the #[ApiFilter] → #[QueryParameter]
 * migration: partial + exact search, date ranges, sorting, collection-only.
 * The owner filters additionally require ROLE_MANAGER, like the owner field.
 */
final class ApiCollectionFilterBehaviourTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private User $manager;
    private User $member;
    private Risk $alpha;
    private string $uid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $modules = static::getContainer()->get(ModuleConfigurationService::class);

        $this->uid = substr(uniqid('', true), -8);
        $this->tenant = (new Tenant())->setName('Api ' . $this->uid)->setCode('api_' . $this->uid);
        $this->tenant->setActiveModules(array_values(array_unique([...$modules->getInstanceDefaultModules(), 'risks', 'controls'])));
        $this->em->persist($this->tenant);

        $this->manager = $this->user('manager', 'Mara', 'Owner', ['ROLE_MANAGER']);
        $this->member = $this->user('member', 'Max', 'Member', ['ROLE_USER']);

        $this->alpha = $this->risk('Alpha ' . $this->uid, RiskStatus::Assessed, '2026-01-10', $this->manager);
        $this->risk('Beta ' . $this->uid, RiskStatus::Identified, '2026-03-10', null);
        $this->risk('Gamma ' . $this->uid, RiskStatus::Assessed, '2026-05-10', null);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->tenant !== null && $this->em->isOpen()) {
            $connection = $this->em->getConnection();
            foreach ([
                'DELETE FROM risk WHERE tenant_id = ?',
                'DELETE FROM users WHERE tenant_id = ?',
                'DELETE FROM tenant WHERE id = ?',
            ] as $sql) {
                try {
                    $connection->executeStatement($sql, [$this->tenant->getId()]);
                } catch (\Throwable) {
                    // best effort: audit rows written during the request may block
                }
            }
        }
        $this->tenant = null;

        parent::tearDown();
    }

    /** @param list<string> $roles */
    private function user(string $prefix, string $first, string $last, array $roles): User
    {
        $user = new User();
        $user->setEmail($prefix . '_' . $this->uid . '@example.com');
        $user->setFirstName($first);
        $user->setLastName($last);
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        $user->setTenant($this->tenant);
        $user->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function risk(string $title, RiskStatus $status, string $createdAt, ?User $owner): Risk
    {
        $risk = new Risk();
        $risk->setTenant($this->tenant);
        $risk->setTitle($title);
        $risk->setCategory('operational');
        $risk->setDescription('API filter fixture.');
        $risk->setProbability(2);
        $risk->setImpact(2);
        $risk->setStatus($status);
        $risk->setCreatedAt(new DateTimeImmutable($createdAt));
        $risk->setRiskOwner($owner);
        $this->em->persist($risk);

        return $risk;
    }

    /**
     * @return list<string> titles of the returned members, in response order
     */
    private function titles(string $query, ?User $as = null): array
    {
        $this->client->loginUser($as ?? $this->manager);
        $this->client->request('GET', '/api/risks?' . $query, server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $members = $data['member'] ?? $data['hydra:member'] ?? [];

        return array_map(static fn (array $risk): string => (string) $risk['title'], $members);
    }

    #[Test]
    public function partialTitleSearchIsCaseInsensitive(): void
    {
        self::assertSame(['Alpha ' . $this->uid], $this->titles('title=alpha%20' . $this->uid));
    }

    #[Test]
    public function exactStatusAcceptsOneOrSeveralValues(): void
    {
        $assessed = $this->titles('status=assessed&title=' . $this->uid . '&order[title]=asc');
        self::assertSame(['Alpha ' . $this->uid, 'Gamma ' . $this->uid], $assessed);

        $both = $this->titles('status[]=assessed&status[]=identified&title=' . $this->uid);
        self::assertCount(3, $both);
    }

    #[Test]
    public function dateRangeAndSortingWork(): void
    {
        self::assertSame(
            ['Gamma ' . $this->uid, 'Beta ' . $this->uid],
            $this->titles('createdAt[after]=2026-02-01&title=' . $this->uid . '&order[createdAt]=desc'),
        );
        self::assertSame(
            ['Alpha ' . $this->uid],
            $this->titles('createdAt[strictly_before]=2026-02-01&title=' . $this->uid),
        );
    }

    #[Test]
    public function ownerFilterIsForManagersOnly(): void
    {
        self::assertSame(['Alpha ' . $this->uid], $this->titles('riskOwner.email=manager_' . $this->uid));
        self::assertSame(['Alpha ' . $this->uid], $this->titles('riskOwner.lastName=owner&title=' . $this->uid));

        $this->client->loginUser($this->member);
        $this->client->request('GET', '/api/risks?riskOwner.email=manager_' . $this->uid, server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function anInvalidSortDirectionIsRejected(): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request('GET', '/api/risks?order[title]=sideways', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function booleanFiltersAcceptTrueFalseOnly(): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request('GET', '/api/controls?applicable=true', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/controls?applicable=maybe', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function filtersDoNotTouchTheItemEndpoint(): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request('GET', '/api/risks/' . $this->alpha->getId() . '?title=nomatch', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseIsSuccessful();
    }
}

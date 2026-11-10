<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S31: the large lists filter, count and page in SQL ({@see \App\Util\ListQuery}). A list page costs the same
 * number of queries for 5 and for 2,000 records (300 risks), and the queries on the list's own table stay a handful (a
 * COUNT per summary chip, one page of rows, one aggregate for the row columns).
 *
 * Every list URL is requested with its filters too, so a broken DQL condition fails here and not on a
 * customer's filter click. QUERY_DUMP=/path/file writes the SQL.
 */
final class ListQueryBudgetTest extends WebTestCase
{
    /** Extra queries a large organisation may cost over a small one. */
    private const int GROWTH = 2;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private User $admin;
    private int $batch = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = substr(uniqid('', true), -10);
        $this->tenant = (new Tenant())->setName('Lists ' . $uid)->setCode('lists_' . $uid);
        $this->em->persist($this->tenant);
        $this->admin = (new User())->setEmail('lists_' . $uid . '@example.test')->setFirstName('List')->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])->setPassword('x')->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $tenantId = $this->tenant?->getId();
        if ($tenantId !== null) {
            $this->em->clear();
            $conn = $this->em->getConnection();
            foreach ([
                'DELETE FROM risk WHERE tenant_id = ?',
                'DELETE FROM asset WHERE tenant_id = ?',
                'DELETE FROM incident WHERE tenant_id = ?',
                'DELETE FROM document WHERE tenant_id = ?',
                'DELETE FROM person WHERE tenant_id = ?',
                'DELETE FROM audit_log WHERE tenant_id = ?',
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

    /**
     * List URL, its table, how many queries may read that table (spec S31: asset list ≤ 15, audit log ≤ 10),
     * the page total measured on 2026-11-09 (layout and security included; may only go down), filters.
     *
     * @return iterable<string, array{string, string, int, int, list<string>}>
     */
    public static function lists(): iterable
    {
        yield 'assets' => ['/en/asset', 'asset', 15, 25, [
            'type=software', 'classification=restricted', 'owner=List', 'status=active', 'view=own', 'view=subsidiaries',
            'location=1', 'tag=NIS2', 'scope=in', 'dora_relevant=1', 'q=Asset 1', 'page=999',
        ]];
        yield 'risks' => ['/en/risk', 'risk', 15, 26, [
            'level=high', 'level=low', 'status=identified', 'treatment=mitigate', 'owner=List', 'focus=no_owner',
            'review_overdue=1', 'location=1', 'tag=NIS2', 'q=Risk', 'view=subsidiaries',
        ]];
        yield 'audit log' => ['/en/admin/audit-log', 'audit_log', 10, 22, ['action=create', 'entityType=Asset', 'page=2']];
    }

    #[Test]
    #[DataProvider('lists')]
    public function listQueriesDoNotGrowWithTheOrganisation(string $url, string $table, int $tableBudget, int $budget, array $filters): void
    {
        $this->records(5, risks: 5);
        $this->queries($url); // warm-up: caches filled on the first page view do not count
        $small = $this->queries($url);

        $this->records(2000, risks: 300);
        $large = $this->queries($url, $table, $onTable);

        self::assertLessThanOrEqual($tableBudget, $onTable, sprintf('%s reads %s %d times.', $url, $table, $onTable));
        self::assertLessThanOrEqual($budget, $large, sprintf('%s: %d queries (budget %d).', $url, $large, $budget));

        self::assertLessThanOrEqual($small + self::GROWTH, $large, sprintf(
            '%s grows with the data: %d queries for 5 records, %d for 2,005 — a query per row crept in.',
            $url,
            $small,
            $large,
        ));
        foreach ($filters as $filter) {
            $this->queries($url . '?' . $filter);
        }
    }

    /** @return iterable<string, array{string}> list URLs with filters, rendered on an empty organisation */
    public static function filteredLists(): iterable
    {
        foreach ([
            '/en/document', '/en/document?status=archived', '/en/document?status=draft&include_history=1',
            '/en/document?review_overdue=1&category=policy&q=x', '/en/document?focus=lifecycle_stuck', '/en/document?view=subsidiaries',
            '/en/vulnerability', '/en/vulnerability?focus=unpatched&q=CVE', '/en/vulnerability?view=subsidiaries',
            '/en/patch', '/en/patch?q=KB&view=inherited',
            '/en/person', '/en/person?type=employee&q=a',
            '/en/audit-finding/', '/en/audit-finding/?status=open&severity=high&q=x', '/en/audit-finding/?focus=overdue',
            '/en/risk?level=critical&review_overdue=1', '/en/admin/audit-log/statistics',
        ] as $url) {
            yield $url => [$url];
        }
    }

    /** Every condition a list builds is valid DQL on every filter (the lists are empty here). */
    #[Test]
    #[DataProvider('filteredLists')]
    public function listsRenderWithTheirFilters(string $url): void
    {
        $this->queries($url);
    }

    /** The document list's own rules, now in SQL: deleted/archived hidden, superseded versions only with history. */
    #[Test]
    public function theDocumentListHidesDeletedArchivedAndSupersededDocuments(): void
    {
        $conn = $this->em->getConnection();
        $insert = function (string $name, string $status, ?int $supersedes = null) use ($conn): int {
            $conn->insert('document', [
                'filename' => $name, 'original_filename' => $name, 'mime_type' => 'application/pdf', 'file_size' => 1,
                'file_path' => 'x/' . $name, 'category' => 'policy', 'uploaded_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'is_archived' => false, 'status' => $status, 'lock_version' => 1, 'inheritable' => false, 'override_allowed' => true,
                'is_immutable' => false, 'review_interval_months' => 12, 'version' => '1.0', 'requires_acknowledgement' => false,
                'tenant_id' => $this->tenant->getId(), 'supersedes_id' => $supersedes,
            ], array_fill_keys(['is_archived', 'inheritable', 'override_allowed', 'is_immutable', 'requires_acknowledgement'], Types::BOOLEAN));

            return (int) $conn->lastInsertId();
        };
        $old = $insert('doc-old-version.pdf', 'published');
        $insert('doc-current.pdf', 'published', $old);
        $insert('doc-deleted.pdf', 'deleted');
        $insert('doc-archived.pdf', 'archived');

        $shown = function (string $url): array {
            $this->queries($url);
            preg_match_all('/doc-[a-z-]+\.pdf/', (string) $this->client->getResponse()->getContent(), $m);

            return array_values(array_unique($m[0]));
        };
        self::assertSame(['doc-current.pdf'], $shown('/en/document?view=own'));
        self::assertEqualsCanonicalizing(['doc-current.pdf', 'doc-old-version.pdf'], $shown('/en/document?view=own&include_history=1'));
        self::assertSame(['doc-archived.pdf'], $shown('/en/document?view=own&status=archived'));
    }

    private function queries(string $url, ?string $table = null, ?int &$onTable = null): int
    {
        $this->em->clear();
        $this->client->loginUser($this->em->find(User::class, $this->admin->getId()));
        $debug = static::getContainer()->get('doctrine.debug_data_holder');
        $debug->reset(); // count only this request's queries
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);
        $data = $debug->getData();
        if (getenv('QUERY_DUMP')) {
            file_put_contents(getenv('QUERY_DUMP'), "===== $url\n", FILE_APPEND);
            foreach ($data as $queries) {
                foreach ($queries as $q) {
                    file_put_contents(getenv('QUERY_DUMP'), preg_replace('/\s+/', ' ', (string) $q['sql']) . "\n", FILE_APPEND);
                }
            }
        }

        $onTable = 0;
        foreach ($data as $queries) {
            foreach ($queries as $q) {
                if ($table !== null && preg_match('/\b(FROM|JOIN) "?' . $table . '"?\s/i', (string) $q['sql']) === 1) {
                    ++$onTable;
                }
            }
        }

        return array_sum(array_map('count', $data));
    }

    /**
     * Assets and audit-log rows go in with plain INSERTs (2,000 ORM persists with all listeners took minutes);
     * the risks, which the register shows with their relations, through the ORM.
     */
    private function records(int $count, int $risks): void
    {
        $tenantId = (int) $this->tenant->getId();
        $ownerId = (int) $this->admin->getId();
        $batch = ++$this->batch;
        $conn = $this->em->getConnection();
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $assetIds = [];
        $conn->beginTransaction();
        for ($i = 0; $i < $count; ++$i) {
            $conn->insert('asset', [
                'name' => sprintf('Asset %d.%d', $batch, $i), 'asset_type' => 'software', 'confidentiality_value' => $i % 5 + 1,
                'integrity_value' => 2, 'availability_value' => 2, 'is_dora_relevant' => false, 'status' => 'active',
                'lock_version' => 1, 'created_at' => $now, 'in_isms_scope' => true, 'tenant_id' => $tenantId, 'owner_user_id' => $ownerId,
            ], ['is_dora_relevant' => Types::BOOLEAN, 'in_isms_scope' => Types::BOOLEAN]);
            if ($i < $risks) {
                $assetIds[] = (int) $conn->lastInsertId();
            }
            $conn->insert('audit_log', [
                'entity_type' => 'Asset', 'entity_id' => $i, 'action' => $i % 2 === 0 ? 'create' : 'update',
                'user_name' => 'lists', 'created_at' => $now, 'tenant_id' => $tenantId,
            ]);
        }
        $conn->commit();

        $tenant = $this->em->find(Tenant::class, $tenantId);
        $owner = $this->em->find(User::class, $ownerId);
        foreach ($assetIds as $i => $assetId) {
            $risk = (new Risk())->setTitle(sprintf('Risk %d.%d', $batch, $i))->setCategory('operational')->setDescription('List fixture.')
                ->setProbability($i % 5 + 1)->setImpact(3)->setStatus(RiskStatus::Identified);
            $risk->setTenant($tenant);
            $risk->setCreatedAt(new DateTimeImmutable());
            $risk->setRiskOwner($owner);
            $risk->setAsset($this->em->getReference(Asset::class, $assetId));
            $this->em->persist($risk);
        }
        $this->em->flush();
    }
}

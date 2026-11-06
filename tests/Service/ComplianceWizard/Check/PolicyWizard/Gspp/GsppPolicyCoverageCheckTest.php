<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Gspp;

use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\Bsi\GsppReuseMap;
use App\Service\ComplianceWizard\Check\PolicyWizard\Gspp\GsppPolicyCoverageCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class GsppPolicyCoverageCheckTest extends TestCase
{
    private GsppReuseMap $map;

    protected function setUp(): void
    {
        $this->map = new GsppReuseMap(__DIR__ . '/../../../../../..');
    }

    #[Test]
    public function passes_when_every_topic_has_a_gspp_document(): void
    {
        $result = $this->check(array_keys($this->map->policyTopics()))->run($this->createStub(Tenant::class));

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertNull($result->gap);
    }

    #[Test]
    public function lists_topics_without_a_gspp_document(): void
    {
        $result = $this->check(['top_level', 'access_control'])->run($this->createStub(Tenant::class));
        $total = count($this->map->policyTopics());

        self::assertFalse($result->passed);
        self::assertSame(round(2 / $total * 100, 1), $result->score);
        self::assertSame($total - 2, $result->details['missing_count']);
        self::assertNotContains('top_level', $result->gap['items']);
        self::assertSame('gspp', $this->check([])->getStandard());
    }

    #[Test]
    public function fails_without_tenant(): void
    {
        self::assertFalse($this->check([])->run(null)->passed);
    }

    /** @param list<string> $coveredTopics */
    private function check(array $coveredTopics): GsppPolicyCoverageCheck
    {
        $query = $this->createStub(Query::class);
        $query->method('getSingleColumnResult')->willReturn($coveredTopics);
        $qb = $this->createStub(QueryBuilder::class);
        foreach (['select', 'innerJoin', 'where', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        $repository = $this->createStub(DocumentRepository::class);
        $repository->method('createQueryBuilder')->willReturn($qb);

        return new GsppPolicyCoverageCheck($repository, $this->map);
    }
}

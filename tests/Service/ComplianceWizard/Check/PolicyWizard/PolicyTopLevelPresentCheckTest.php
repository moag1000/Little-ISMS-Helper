<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyTopLevelPresentCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class PolicyTopLevelPresentCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private PolicyTopLevelPresentCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->check = new PolicyTopLevelPresentCheck($this->documentRepository);
    }

    #[Test]
    public function checkIdAndStandardAreStable(): void
    {
        self::assertSame('policy_top_level_present', $this->check->getCheckId());
        self::assertSame('iso27001', $this->check->getStandard());
    }

    #[Test]
    public function nullTenantFailsClosed(): void
    {
        $result = $this->check->run(null);
        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame('no_tenant', $result->details['reason']);
    }

    #[Test]
    public function passesWhenAtLeastOnePublishedTopLevelDocumentExists(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubScalarQueryBuilder(2),
        );

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(2, $result->details['published_documents']);
        self::assertNull($result->gap);
    }

    #[Test]
    public function failsWithCriticalGapWhenNoPublishedTopLevelDocument(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubScalarQueryBuilder(0),
        );

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(0, $result->details['published_documents']);
        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
    }

    private function stubScalarQueryBuilder(int $count): QueryBuilder&MockObject
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $query->method('getSingleScalarResult')->willReturn($count);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'innerJoin', 'where', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}

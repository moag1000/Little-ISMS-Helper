<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Bcm;

use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmsScopeStatementPresentCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class BcmsScopeStatementPresentCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private BcmsScopeStatementPresentCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->check = new BcmsScopeStatementPresentCheck($this->documentRepository);
    }

    #[Test]
    public function testPassesWhenScopeStatementPublished(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(1));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(1, $result->details['published_documents']);
        self::assertSame('bcm', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenScopeStatementMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
    }

    #[Test]
    public function testGapMessageActionable(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $result = $this->check->run($tenant);

        self::assertNotNull($result->gap);
        self::assertSame('app_policy_wizard_index', $result->gap['route']);
        self::assertSame('policy_wizard', $result->gap['translation_domain']);
        self::assertSame(
            'compliance_check.bcms_scope_statement_present.fail_message',
            $result->gap['title'],
        );

        $nullResult = $this->check->run(null);
        self::assertFalse($nullResult->passed);
        self::assertSame('no_tenant', $nullResult->details['reason']);
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

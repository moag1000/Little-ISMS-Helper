<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Privacy;

use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\RopaMethodologyPresentCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class RopaMethodologyPresentCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private RopaMethodologyPresentCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->check = new RopaMethodologyPresentCheck($this->documentRepository);
    }

    #[Test]
    public function testPassesWhenPublishedRopaMethodologyExists(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(2));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(2, $result->details['published_documents']);
        self::assertNull($result->gap);
        self::assertSame('gdpr', $this->check->getStandard());
        self::assertSame('ropa_methodology_present', $result->checkId);
    }

    #[Test]
    public function testFailsWhenNoRopaMethodology(): void
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
            'compliance_check.ropa_methodology_present.fail_message',
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

<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Privacy;

use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\GdprSectionCoverageCheck;
use App\Service\PolicyWizard\GdprSectionCatalogue;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class GdprSectionCoverageCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private GdprSectionCatalogue $catalogue;
    private GdprSectionCoverageCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->catalogue = new GdprSectionCatalogue();
        $this->check = new GdprSectionCoverageCheck(
            $this->documentRepository,
            $this->catalogue,
        );
    }

    #[Test]
    public function testPassesWhenAllCatalogueSectionsCovered(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $rowCount = $this->catalogue->count();
        // Stub: every QB call returns 1 (every catalogue row is covered).
        $stubs = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $stubs[] = $this->stubScalarQueryBuilder(1);
        }
        $this->documentRepository->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(...$stubs);

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame($rowCount, $result->details['expected_sections']);
        self::assertSame($rowCount, $result->details['covered_sections']);
        self::assertNull($result->gap);
        self::assertSame('gdpr', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenSomeSectionsMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $rowCount = $this->catalogue->count();
        // 2 rows missing → others covered.
        $stubs = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $stubs[] = $this->stubScalarQueryBuilder($i < 2 ? 0 : 1);
        }
        $this->documentRepository->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(...$stubs);

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertLessThan(100.0, $result->score);
        self::assertSame($rowCount, $result->details['expected_sections']);
        self::assertSame($rowCount - 2, $result->details['covered_sections']);
        self::assertSame(2, $result->details['missing_count']);
        self::assertNotNull($result->gap);
        self::assertSame('high', $result->gap['priority']);
    }

    #[Test]
    public function testGapActionableAndNullTenant(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $rowCount = $this->catalogue->count();
        $stubs = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $stubs[] = $this->stubScalarQueryBuilder(0);
        }
        $this->documentRepository->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(...$stubs);

        $result = $this->check->run($tenant);

        self::assertNotNull($result->gap);
        self::assertSame('app_policy_wizard_index', $result->gap['route']);
        self::assertSame('policy_wizard', $result->gap['translation_domain']);
        self::assertSame(
            'compliance_check.gdpr_section_coverage.fail_message',
            $result->gap['title'],
        );
        self::assertNotEmpty($result->gap['items']);
        self::assertArrayHasKey('iso_topic', $result->gap['items'][0]);
        self::assertArrayHasKey('section_key', $result->gap['items'][0]);

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

<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\Document;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraExitStrategyDocumentedCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DoraExitStrategyDocumentedCheckTest extends TestCase
{
    private SupplierRepository&MockObject $supplierRepository;
    private DoraExitStrategyDocumentedCheck $check;

    protected function setUp(): void
    {
        $this->supplierRepository = $this->createMock(SupplierRepository::class);
        $this->check = new DoraExitStrategyDocumentedCheck($this->supplierRepository);
    }

    #[Test]
    public function testPassesWhenConditionsMet(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $document = $this->createMock(Document::class);

        $supplier1 = (new Supplier())->setName('Bank A');
        $supplier1->setHasExitStrategy(true);
        $supplier1->setExitStrategyDocument($document);
        $supplier2 = (new Supplier())->setName('Bank B');
        $supplier2->setHasExitStrategy(true);
        $supplier2->setExitStrategyDocument($document);

        $this->supplierRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$supplier1, $supplier2]));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(2, $result->details['critical_ict_suppliers']);
        self::assertSame(2, $result->details['with_exit_strategy']);
    }

    #[Test]
    public function testFailsWhenConditionsMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $supplier1 = (new Supplier())->setName('Bank A');
        $supplier1->setHasExitStrategy(false);
        $supplier2 = (new Supplier())->setName('Bank B');
        $supplier2->setHasExitStrategy(true); // flag yes but no document linked
        $supplier2->setExitStrategyDocument(null);

        $this->supplierRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$supplier1, $supplier2]));

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(2, $result->details['critical_ict_suppliers']);
        self::assertSame(0, $result->details['with_exit_strategy']);
        self::assertSame(2, $result->details['missing_count']);
    }

    #[Test]
    public function testGapMessageActionable(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $supplier = (new Supplier())->setName('Critical Bank');
        $supplier->setHasExitStrategy(false);

        $this->supplierRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$supplier]));

        $result = $this->check->run($tenant);

        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
        self::assertSame('app_supplier_index', $result->gap['route']);
        self::assertSame('policy_wizard', $result->gap['translation_domain']);
        self::assertSame(
            'compliance_check.dora_exit_strategy_documented.fail_message',
            $result->gap['title'],
        );
        self::assertCount(1, $result->gap['items']);
        self::assertSame('Critical Bank', $result->gap['items'][0]['name']);

        // Empty critical-supplier list → vacuously satisfied (no gap surfaces).
        $emptyCheck = new DoraExitStrategyDocumentedCheck(
            $this->createMock(SupplierRepository::class),
        );
        // Reset the supplierRepository for this isolated case.
        $repo = $this->createMock(SupplierRepository::class);
        $repo->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([]));
        $isolated = new DoraExitStrategyDocumentedCheck($repo);
        $emptyResult = $isolated->run($tenant);
        self::assertTrue($emptyResult->passed);
        self::assertNull($emptyResult->gap);
    }

    /**
     * @param list<Supplier> $rows
     */
    private function stubResultQueryBuilder(array $rows): QueryBuilder&MockObject
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn($rows);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'where', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraThirdPartyRegisterMaintainedCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DoraThirdPartyRegisterMaintainedCheckTest extends TestCase
{
    private SupplierRepository&MockObject $supplierRepository;
    private DoraThirdPartyRegisterMaintainedCheck $check;

    protected function setUp(): void
    {
        $this->supplierRepository = $this->createMock(SupplierRepository::class);
        $this->check = new DoraThirdPartyRegisterMaintainedCheck($this->supplierRepository);
    }

    #[Test]
    public function testPassesWhenConditionsMet(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->supplierRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(3));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(3, $result->details['ict_third_party_count']);
        self::assertNull($result->gap);
    }

    #[Test]
    public function testFailsWhenConditionsMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->supplierRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(0, $result->details['ict_third_party_count']);
        self::assertNotNull($result->gap);
    }

    #[Test]
    public function testGapMessageActionable(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->supplierRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $result = $this->check->run($tenant);

        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
        self::assertSame('app_supplier_index', $result->gap['route']);
        self::assertSame('policy_wizard', $result->gap['translation_domain']);
        self::assertSame(
            'compliance_check.dora_third_party_register_maintained.fail_message',
            $result->gap['title'],
        );
    }

    private function stubScalarQueryBuilder(int $count): QueryBuilder&MockObject
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $query->method('getSingleScalarResult')->willReturn($count);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'where', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}

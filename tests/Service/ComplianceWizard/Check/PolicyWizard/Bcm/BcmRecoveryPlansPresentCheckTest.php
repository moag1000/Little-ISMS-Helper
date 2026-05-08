<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Bcm;

use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmRecoveryPlansPresentCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class BcmRecoveryPlansPresentCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private AssetRepository&MockObject $assetRepository;
    private BcmRecoveryPlansPresentCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->assetRepository = $this->createMock(AssetRepository::class);
        $this->check = new BcmRecoveryPlansPresentCheck(
            $this->documentRepository,
            $this->assetRepository,
        );
    }

    #[Test]
    public function testPassesWhenRecoveryPlanCoversCrownJewels(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // Crown-jewel count = 2
        $this->assetRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(2));

        // Recovery plan documents = 1
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(1));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(2, $result->details['crown_jewel_assets']);
        self::assertSame(1, $result->details['recovery_plans']);
        self::assertNull($result->gap);
        self::assertSame('bcm', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenCrownJewelsHaveNoRecoveryPlan(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $this->assetRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(3));

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(3, $result->details['crown_jewel_assets']);
        self::assertSame(0, $result->details['recovery_plans']);
        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
    }

    #[Test]
    public function testGapMessageActionableAndNoCrownJewelsVacuouslyPasses(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // No crown jewels → vacuously satisfied (no need to ask for plans).
        $this->assetRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $result = $this->check->run($tenant);
        self::assertTrue($result->passed);
        self::assertSame('no_crown_jewels_in_scope', $result->details['reason']);
        self::assertNull($result->gap);

        // Strict scenario: crown jewels exist but no plan → gap surfaces.
        $strictAsset = $this->createMock(AssetRepository::class);
        $strictAsset->method('createQueryBuilder')->willReturn($this->stubScalarQueryBuilder(1));
        $strictDocs = $this->createMock(DocumentRepository::class);
        $strictDocs->method('createQueryBuilder')->willReturn($this->stubScalarQueryBuilder(0));
        $strictCheck = new BcmRecoveryPlansPresentCheck($strictDocs, $strictAsset);
        $strictResult = $strictCheck->run($tenant);

        self::assertNotNull($strictResult->gap);
        self::assertSame('app_policy_wizard_index', $strictResult->gap['route']);
        self::assertSame('policy_wizard', $strictResult->gap['translation_domain']);
        self::assertSame(
            'compliance_check.bcm_recovery_plans_present.fail_message',
            $strictResult->gap['title'],
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

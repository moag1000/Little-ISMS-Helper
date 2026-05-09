<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Bsi;

use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Repository\DocumentRepository;
use App\Repository\TenantPolicySettingRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiTierConsistencyCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class BsiTierConsistencyCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private TenantPolicySettingRepository&MockObject $settingRepository;
    private BsiTierConsistencyCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->settingRepository = $this->createMock(TenantPolicySettingRepository::class);
        $this->check = new BsiTierConsistencyCheck(
            $this->documentRepository,
            $this->settingRepository,
        );
    }

    #[Test]
    public function testPassesWhenBasisOnlyAndNoForbiddenDocuments(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $setting = new TenantPolicySetting();
        $setting->setKey(BsiTierConsistencyCheck::SETTING_KEY_TIER_FILTER);
        $setting->setValue(BsiTierConsistencyCheck::TIER_FILTER_BASIS_ONLY);
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn($setting);

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame('basis_only', $result->details['tier_filter']);
        self::assertSame(0, $result->details['forbidden_tier_documents']);
        self::assertNull($result->gap);
        self::assertSame('bsi', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenBasisOnlyButKernDocumentExists(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $setting = new TenantPolicySetting();
        $setting->setKey(BsiTierConsistencyCheck::SETTING_KEY_TIER_FILTER);
        $setting->setValue(BsiTierConsistencyCheck::TIER_FILTER_BASIS_ONLY);
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn($setting);

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(3));

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(3, $result->details['forbidden_tier_documents']);
        self::assertNotNull($result->gap);
        self::assertSame('high', $result->gap['priority']);
    }

    #[Test]
    public function testGapMessageActionableAndVacuousFilterPasses(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // Without a declared filter, check passes vacuously.
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn(null);
        $resultUndeclared = $this->check->run($tenant);
        self::assertTrue($resultUndeclared->passed);
        self::assertSame('undeclared', $resultUndeclared->details['tier_filter']);
        self::assertNull($resultUndeclared->gap);

        // Filter='all' is also vacuous (everything allowed).
        $allSettingRepo = $this->createMock(TenantPolicySettingRepository::class);
        $allSetting = new TenantPolicySetting();
        $allSetting->setKey(BsiTierConsistencyCheck::SETTING_KEY_TIER_FILTER);
        $allSetting->setValue(BsiTierConsistencyCheck::TIER_FILTER_ALL);
        $allSettingRepo->method('findOneByTenantAndKey')->willReturn($allSetting);
        $allCheck = new BsiTierConsistencyCheck($this->documentRepository, $allSettingRepo);
        $resultAll = $allCheck->run($tenant);
        self::assertTrue($resultAll->passed);
        self::assertSame('all', $resultAll->details['tier_filter']);

        // Inconsistency surfaces an actionable gap routed to the wizard.
        $strictRepo = $this->createMock(TenantPolicySettingRepository::class);
        $strictSetting = new TenantPolicySetting();
        $strictSetting->setKey(BsiTierConsistencyCheck::SETTING_KEY_TIER_FILTER);
        $strictSetting->setValue(BsiTierConsistencyCheck::TIER_FILTER_BASIS_STANDARD);
        $strictRepo->method('findOneByTenantAndKey')->willReturn($strictSetting);
        $strictDocs = $this->createMock(DocumentRepository::class);
        $strictDocs->method('createQueryBuilder')->willReturn($this->stubScalarQueryBuilder(1));
        $strictCheck = new BsiTierConsistencyCheck($strictDocs, $strictRepo);
        $resultStrict = $strictCheck->run($tenant);
        self::assertFalse($resultStrict->passed);
        self::assertNotNull($resultStrict->gap);
        self::assertSame('app_policy_wizard_index', $resultStrict->gap['route']);
        self::assertSame('policy_wizard', $resultStrict->gap['translation_domain']);
        self::assertSame(
            'compliance_check.bsi_tier_consistency.fail_message',
            $resultStrict->gap['title'],
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

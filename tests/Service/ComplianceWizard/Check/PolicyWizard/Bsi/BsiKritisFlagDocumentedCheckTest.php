<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Bsi;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Repository\DocumentRepository;
use App\Repository\TenantPolicySettingRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiKritisFlagDocumentedCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class BsiKritisFlagDocumentedCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private TenantPolicySettingRepository&MockObject $settingRepository;
    private BsiKritisFlagDocumentedCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->settingRepository = $this->createMock(TenantPolicySettingRepository::class);
        $this->check = new BsiKritisFlagDocumentedCheck(
            $this->documentRepository,
            $this->settingRepository,
        );
    }

    #[Test]
    public function testPassesWhenKritisOperatorAndMarkerInSubstitutionVars(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $setting = new TenantPolicySetting();
        $setting->setKey(BsiKritisFlagDocumentedCheck::SETTING_KEY_KRITIS);
        $setting->setValue(true);
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn($setting);

        $doc = new Document();
        $doc->setSubstitutionVariables(['legal_basis' => 'BSIG §8a / KRITIS-DachG']);

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertTrue($result->details['is_kritis_operator']);
        self::assertNull($result->gap);
        self::assertSame('bsi', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenKritisOperatorButMarkerMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $setting = new TenantPolicySetting();
        $setting->setKey(BsiKritisFlagDocumentedCheck::SETTING_KEY_KRITIS);
        $setting->setValue(true);
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn($setting);

        $doc = new Document();
        $doc->setSubstitutionVariables(['legal_basis' => 'Freiwillige Anwendung']);

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertTrue($result->details['is_kritis_operator']);
        self::assertSame('no_kritis_marker_found', $result->details['reason']);
        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
    }

    #[Test]
    public function testGapMessageActionableAndNonKritisVacuouslyPasses(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // Non-KRITIS tenant: vacuously satisfied.
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn(null);
        $nonKritis = $this->check->run($tenant);
        self::assertTrue($nonKritis->passed);
        self::assertFalse($nonKritis->details['is_kritis_operator']);
        self::assertNull($nonKritis->gap);

        // KRITIS but no Document at all → fails closed with reason.
        $strictRepo = $this->createMock(TenantPolicySettingRepository::class);
        $sigSetting = new TenantPolicySetting();
        $sigSetting->setKey(BsiKritisFlagDocumentedCheck::SETTING_KEY_KRITIS);
        $sigSetting->setValue(true);
        $strictRepo->method('findOneByTenantAndKey')->willReturn($sigSetting);
        $strictDocs = $this->createMock(DocumentRepository::class);
        $strictDocs->method('createQueryBuilder')->willReturn($this->stubResultQueryBuilder([]));
        $strictCheck = new BsiKritisFlagDocumentedCheck($strictDocs, $strictRepo);
        $strictResult = $strictCheck->run($tenant);
        self::assertFalse($strictResult->passed);
        self::assertSame('no_it_security_policy_document', $strictResult->details['reason']);
        self::assertNotNull($strictResult->gap);
        self::assertSame('app_policy_wizard_index', $strictResult->gap['route']);
        self::assertSame('policy_wizard', $strictResult->gap['translation_domain']);
        self::assertSame(
            'compliance_check.bsi_kritis_flag_documented.fail_message',
            $strictResult->gap['title'],
        );

        $nullResult = $this->check->run(null);
        self::assertFalse($nullResult->passed);
        self::assertSame('no_tenant', $nullResult->details['reason']);
    }

    /**
     * @param list<Document> $rows
     */
    private function stubResultQueryBuilder(array $rows): QueryBuilder&MockObject
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn($rows);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['innerJoin', 'where', 'andWhere', 'setParameter', 'orderBy'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}

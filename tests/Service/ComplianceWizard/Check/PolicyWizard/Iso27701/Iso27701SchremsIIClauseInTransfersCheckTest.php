<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Iso27701;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\DocumentSectionRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701SchremsIIClauseInTransfersCheck;
use App\Service\TenantSettingResolver\OverrideMode;
use App\Service\TenantSettingResolver\PolicySettingProvider;
use App\Service\TenantSettingResolver\SettingResolutionResult;
use App\Service\TenantSettingResolver\TenantSettingResolver;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class Iso27701SchremsIIClauseInTransfersCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private DocumentSectionRepository&MockObject $sectionRepository;
    private TenantSettingResolver&MockObject $resolver;
    private PolicySettingProvider $policySettingProvider;
    private Iso27701SchremsIIClauseInTransfersCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->sectionRepository = $this->createMock(DocumentSectionRepository::class);
        $this->resolver = $this->createMock(TenantSettingResolver::class);
        $this->policySettingProvider = new PolicySettingProvider($this->resolver);
        $this->check = new Iso27701SchremsIIClauseInTransfersCheck(
            $this->documentRepository,
            $this->sectionRepository,
            $this->policySettingProvider,
        );
    }

    #[Test]
    public function testPassesWhenSchremsAndSupplementaryWordingPresentInDescription(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->stubResolver(true, PolicySettingProvider::ISO27701_VERSION_2025);

        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn(101);
        $doc->method('getDescription')
            ->willReturn('Includes Schrems II impact note and supplementary measures TIA.');
        $doc->method('getSubstitutionVariables')->willReturn([]);

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));
        $this->sectionRepository->method('findOneByDocumentAndKey')->willReturn(null);

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(101, $result->details['matched_document_id']);
        self::assertNull($result->gap);
        self::assertSame('iso27701', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenWordingMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->stubResolver(true, PolicySettingProvider::ISO27701_VERSION_2025);

        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn(102);
        $doc->method('getDescription')->willReturn('Standard transfers, SCC referenced.');
        $doc->method('getSubstitutionVariables')->willReturn([]);

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));
        $this->sectionRepository->method('findOneByDocumentAndKey')->willReturn(null);

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(
            'schrems_ii_or_supplementary_measures_wording_missing',
            $result->details['reason'],
        );
        self::assertNotNull($result->gap);
        self::assertSame('high', $result->gap['priority']);
    }

    #[Test]
    public function testGapActionableAndVacuousWhenPimsDisabledOr2019(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // PIMS disabled → vacuous.
        $disabledProvider = $this->makeProvider(false, PolicySettingProvider::ISO27701_VERSION_2025);
        $disabledCheck = new Iso27701SchremsIIClauseInTransfersCheck(
            $this->documentRepository,
            $this->sectionRepository,
            $disabledProvider,
        );
        $vacuous1 = $disabledCheck->run($tenant);
        self::assertTrue($vacuous1->passed);
        self::assertSame('pims_not_enabled', $vacuous1->details['reason']);

        // PIMS enabled but version 2019 → vacuous (Schrems II added in 2025 ed.).
        $legacyProvider = $this->makeProvider(true, PolicySettingProvider::ISO27701_VERSION_2019);
        $legacyCheck = new Iso27701SchremsIIClauseInTransfersCheck(
            $this->documentRepository,
            $this->sectionRepository,
            $legacyProvider,
        );
        $vacuous2 = $legacyCheck->run($tenant);
        self::assertTrue($vacuous2->passed);
        self::assertSame(
            'schrems_ii_required_only_in_2025_edition',
            $vacuous2->details['reason'],
        );
        self::assertNull($vacuous2->gap);

        // Strict: 2025 + no host doc → fails with no_information_transfer_host.
        $strictProvider = $this->makeProvider(true, PolicySettingProvider::ISO27701_VERSION_2025);
        $strictDocs = $this->createMock(DocumentRepository::class);
        $strictDocs->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([]));
        $strictSecs = $this->createMock(DocumentSectionRepository::class);
        $strict = new Iso27701SchremsIIClauseInTransfersCheck(
            $strictDocs,
            $strictSecs,
            $strictProvider,
        );
        $strictResult = $strict->run($tenant);
        self::assertFalse($strictResult->passed);
        self::assertSame(
            'no_information_transfer_host_document',
            $strictResult->details['reason'],
        );
        self::assertSame('app_policy_wizard_index', $strictResult->gap['route']);
        self::assertSame('policy_wizard', $strictResult->gap['translation_domain']);
        self::assertSame(
            'compliance_check.iso27701_schrems_ii_clause_in_transfers.fail_message',
            $strictResult->gap['title'],
        );

        // Section evidence path — wording in section snapshot.
        $sectionProvider = $this->makeProvider(true, PolicySettingProvider::ISO27701_VERSION_2025);
        $sectionDoc = $this->createMock(Document::class);
        $sectionDoc->method('getId')->willReturn(203);
        $sectionDoc->method('getDescription')->willReturn(null);
        $sectionDoc->method('getSubstitutionVariables')->willReturn([]);
        $section = new DocumentSection();
        $section->setSectionKey('gdpr_international_transfers');
        $section->setContentSnapshot(
            'Following Schrems-II ruling, supplementary measures and a TIA are required.',
        );
        $sectionDocs = $this->createMock(DocumentRepository::class);
        $sectionDocs->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$sectionDoc]));
        $sectionSecs = $this->createMock(DocumentSectionRepository::class);
        $sectionSecs->method('findOneByDocumentAndKey')->willReturn($section);
        $sectionCheck = new Iso27701SchremsIIClauseInTransfersCheck(
            $sectionDocs,
            $sectionSecs,
            $sectionProvider,
        );
        $sectionResult = $sectionCheck->run($tenant);
        self::assertTrue($sectionResult->passed);
        self::assertSame(203, $sectionResult->details['matched_document_id']);

        $nullResult = $this->check->run(null);
        self::assertFalse($nullResult->passed);
        self::assertSame('no_tenant', $nullResult->details['reason']);
    }

    private function stubResolver(bool $isEnabled, string $version): void
    {
        $this->resolver->method('resolveFor')->willReturnCallback(
            fn (Tenant $t, string $key, mixed $default = null): SettingResolutionResult => match ($key) {
                PolicySettingProvider::SETTING_ISO27701_ENABLED =>
                    new SettingResolutionResult($isEnabled, null, OverrideMode::Free),
                PolicySettingProvider::SETTING_ISO27701_VERSION =>
                    new SettingResolutionResult($version, null, OverrideMode::Free),
                default => new SettingResolutionResult($default, null, OverrideMode::Free),
            },
        );
    }

    private function makeProvider(bool $isEnabled, string $version): PolicySettingProvider
    {
        $resolver = $this->createMock(TenantSettingResolver::class);
        $resolver->method('resolveFor')->willReturnCallback(
            fn (Tenant $t, string $key, mixed $default = null): SettingResolutionResult => match ($key) {
                PolicySettingProvider::SETTING_ISO27701_ENABLED =>
                    new SettingResolutionResult($isEnabled, null, OverrideMode::Free),
                PolicySettingProvider::SETTING_ISO27701_VERSION =>
                    new SettingResolutionResult($version, null, OverrideMode::Free),
                default => new SettingResolutionResult($default, null, OverrideMode::Free),
            },
        );
        return new PolicySettingProvider($resolver);
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
        foreach (['select', 'innerJoin', 'where', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}

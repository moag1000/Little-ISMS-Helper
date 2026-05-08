<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Iso27701;

use App\Entity\Document;
use App\Entity\EntityTag;
use App\Entity\PolicyTemplate;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\EntityTagRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701ClauseTagsAppliedCheck;
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
final class Iso27701ClauseTagsAppliedCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private EntityTagRepository&MockObject $entityTagRepository;
    private TenantSettingResolver&MockObject $resolver;
    private PolicySettingProvider $policySettingProvider;
    private Iso27701ClauseTagsAppliedCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->entityTagRepository = $this->createMock(EntityTagRepository::class);
        $this->resolver = $this->createMock(TenantSettingResolver::class);
        $this->policySettingProvider = new PolicySettingProvider($this->resolver);
        $this->check = new Iso27701ClauseTagsAppliedCheck(
            $this->documentRepository,
            $this->entityTagRepository,
            $this->policySettingProvider,
        );
    }

    #[Test]
    public function testPassesWhenAllDocsHaveClauseTag(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->stubResolver(true, PolicySettingProvider::ISO27701_VERSION_2025);

        $template = new PolicyTemplate();
        $template->setIso27701Clauses2025(['5.1', '5.2']);

        $doc = $this->makeDocument(101, $template);
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));

        $tag = new Tag();
        $tag->setName('iso27701:5.1');
        $entityTag = new EntityTag();
        $entityTag->setTag($tag);
        $this->entityTagRepository->method('findActiveFor')
            ->willReturn([$entityTag]);

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(1, $result->details['documents_with_clause_mapping']);
        self::assertSame(1, $result->details['tagged']);
        self::assertNull($result->gap);
        self::assertSame('iso27701', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenSomeDocsMissingClauseTag(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->stubResolver(true, PolicySettingProvider::ISO27701_VERSION_2025);

        $template = new PolicyTemplate();
        $template->setIso27701Clauses2025(['7.2.8']);
        $doc = $this->makeDocument(102, $template);

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));

        // No iso27701:* tag → untagged.
        $unrelatedTag = new Tag();
        $unrelatedTag->setName('dora-validity:2025-01-17');
        $entityTag = new EntityTag();
        $entityTag->setTag($unrelatedTag);
        $this->entityTagRepository->method('findActiveFor')->willReturn([$entityTag]);

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertLessThan(100.0, $result->score);
        self::assertSame(1, $result->details['documents_with_clause_mapping']);
        self::assertSame(0, $result->details['tagged']);
        self::assertSame(1, $result->details['untagged_count']);
        self::assertNotNull($result->gap);
        self::assertSame('high', $result->gap['priority']);
    }

    #[Test]
    public function testGapActionableAndVacuousWhenPimsDisabled(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // PIMS disabled → vacuously satisfied.
        $disabledResolver = $this->createMock(TenantSettingResolver::class);
        $disabledResolver->method('resolveFor')->willReturn(
            new SettingResolutionResult(false, null, OverrideMode::Free),
        );
        $disabledProvider = new PolicySettingProvider($disabledResolver);
        $disabledCheck = new Iso27701ClauseTagsAppliedCheck(
            $this->documentRepository,
            $this->entityTagRepository,
            $disabledProvider,
        );
        $vacuous = $disabledCheck->run($tenant);
        self::assertTrue($vacuous->passed);
        self::assertSame('pims_not_enabled', $vacuous->details['reason']);
        self::assertNull($vacuous->gap);

        // Strict re-test: untagged doc → gap details.
        $strictResolver = $this->createMock(TenantSettingResolver::class);
        $strictResolver->method('resolveFor')->willReturnCallback(
            fn (Tenant $t, string $key, mixed $default = null): SettingResolutionResult => match ($key) {
                PolicySettingProvider::SETTING_ISO27701_ENABLED =>
                    new SettingResolutionResult(true, null, OverrideMode::Free),
                PolicySettingProvider::SETTING_ISO27701_VERSION =>
                    new SettingResolutionResult(PolicySettingProvider::ISO27701_VERSION_2025, null, OverrideMode::Free),
                default => new SettingResolutionResult($default, null, OverrideMode::Free),
            },
        );
        $strictProvider = new PolicySettingProvider($strictResolver);

        $template = new PolicyTemplate();
        $template->setIso27701Clauses2025(['5.1']);
        $doc = $this->makeDocument(103, $template);

        $strictDocs = $this->createMock(DocumentRepository::class);
        $strictDocs->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));
        $strictTags = $this->createMock(EntityTagRepository::class);
        $strictTags->method('findActiveFor')->willReturn([]);

        $strict = new Iso27701ClauseTagsAppliedCheck($strictDocs, $strictTags, $strictProvider);
        $strictResult = $strict->run($tenant);
        self::assertFalse($strictResult->passed);
        self::assertSame('app_document_index', $strictResult->gap['route']);
        self::assertSame('policy_wizard', $strictResult->gap['translation_domain']);
        self::assertSame(
            'compliance_check.iso27701_clause_tags_applied.fail_message',
            $strictResult->gap['title'],
        );

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

    private function makeDocument(int $id, PolicyTemplate $template): Document&MockObject
    {
        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn($id);
        $doc->method('getGeneratedFromTemplate')->willReturn($template);
        $doc->method('getOriginalFilename')->willReturn('doc.pdf');
        $doc->method('getFilename')->willReturn('doc.pdf');
        return $doc;
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

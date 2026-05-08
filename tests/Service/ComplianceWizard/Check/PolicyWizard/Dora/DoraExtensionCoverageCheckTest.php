<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\Document;
use App\Entity\EntityTag;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\EntityTagRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraExtensionCoverageCheck;
use App\Service\PolicyWizard\DoraExtensionCatalogue;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DoraExtensionCoverageCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private EntityTagRepository&MockObject $entityTagRepository;
    private DoraExtensionCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->entityTagRepository = $this->createMock(EntityTagRepository::class);
        $this->catalogue = new DoraExtensionCatalogue();
    }

    #[Test]
    public function testPassesWhenConditionsMet(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // Every catalogue topic resolves to one ISO Document carrying the
        // dora-extension:applied tag.
        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn(1);

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));
        $this->entityTagRepository->method('findActiveFor')
            ->willReturn([$this->makeEntityTagWith(DoraExtensionCoverageCheck::EXTENSION_TAG_NAME)]);

        $check = new DoraExtensionCoverageCheck(
            $this->documentRepository,
            $this->entityTagRepository,
            $this->catalogue,
        );
        $result = $check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame($this->catalogue->count(), $result->details['expected_topics']);
        self::assertSame($this->catalogue->count(), $result->details['applicable_topics']);
        self::assertSame($this->catalogue->count(), $result->details['covered_topics']);
    }

    #[Test]
    public function testFailsWhenConditionsMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn(2);

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));
        // Returns no extension tag — every ISO doc lacks the marker.
        $this->entityTagRepository->method('findActiveFor')
            ->willReturn([$this->makeEntityTagWith('policy-wizard-generated')]);

        $check = new DoraExtensionCoverageCheck(
            $this->documentRepository,
            $this->entityTagRepository,
            $this->catalogue,
        );
        $result = $check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertGreaterThan(0, count($result->details['missing_topics']));
        self::assertSame(DoraExtensionCoverageCheck::EXTENSION_TAG_NAME, $result->details['expected_tag']);
    }

    #[Test]
    public function testGapMessageActionable(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // No ISO documents in the tenant — vacuously satisfied (orthogonal
        // failure tracked by PolicyTopicPresentCheck, not this one).
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([]));

        $check = new DoraExtensionCoverageCheck(
            $this->documentRepository,
            $this->entityTagRepository,
            $this->catalogue,
        );
        $result = $check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(0, $result->details['applicable_topics']);
        self::assertNull($result->gap);

        // Validate gap shape via a tenant with documents but no extension tag.
        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn(3);
        $repo = $this->createMock(DocumentRepository::class);
        $repo->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));
        $tagRepo = $this->createMock(EntityTagRepository::class);
        $tagRepo->method('findActiveFor')->willReturn([]);

        $check2 = new DoraExtensionCoverageCheck($repo, $tagRepo, $this->catalogue);
        $gapResult = $check2->run($tenant);

        self::assertNotNull($gapResult->gap);
        self::assertSame('high', $gapResult->gap['priority']);
        self::assertSame('app_policy_wizard_index', $gapResult->gap['route']);
        self::assertSame('policy_wizard', $gapResult->gap['translation_domain']);
        self::assertSame(
            'compliance_check.dora_extension_coverage.fail_message',
            $gapResult->gap['title'],
        );
    }

    private function makeEntityTagWith(string $tagName): EntityTag
    {
        $tag = new Tag();
        $tag->setName($tagName);
        $tag->setType(Tag::TYPE_CUSTOM);
        $entityTag = new EntityTag();
        $entityTag->setTag($tag);
        $entityTag->setEntityClass(Document::class);
        $entityTag->setEntityId(1);
        return $entityTag;
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

<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\Document;
use App\Entity\EntityTag;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\EntityTagRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraValidityFromCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DoraValidityFromCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private EntityTagRepository&MockObject $entityTagRepository;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->entityTagRepository = $this->createMock(EntityTagRepository::class);
    }

    #[Test]
    public function testPassesWhenConditionsMet(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn(101);

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));
        $this->entityTagRepository->method('findActiveFor')
            ->willReturn([$this->makeEntityTagWith(DoraValidityFromCheck::VALIDITY_TAG_NAME)]);

        $check = new DoraValidityFromCheck($this->documentRepository, $this->entityTagRepository);
        $result = $check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(1, $result->details['dora_documents']);
        self::assertSame(1, $result->details['tagged']);
    }

    #[Test]
    public function testFailsWhenConditionsMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn(202);
        $doc->method('getOriginalFilename')->willReturn('dora-policy-v1.pdf');
        $doc->method('getFilename')->willReturn('dora-policy-v1.pdf');

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$doc]));
        // No matching tag — return only an unrelated tag.
        $this->entityTagRepository->method('findActiveFor')
            ->willReturn([$this->makeEntityTagWith('standard:dora')]);

        $check = new DoraValidityFromCheck($this->documentRepository, $this->entityTagRepository);
        $result = $check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(1, $result->details['dora_documents']);
        self::assertSame(0, $result->details['tagged']);
        self::assertSame(1, $result->details['untagged_count']);
        self::assertSame(DoraValidityFromCheck::VALIDITY_TAG_NAME, $result->details['expected_tag']);
    }

    #[Test]
    public function testGapMessageActionable(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // Vacuous-pass case: no DORA documents at all.
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([]));

        $check = new DoraValidityFromCheck($this->documentRepository, $this->entityTagRepository);
        $vacuous = $check->run($tenant);

        self::assertTrue($vacuous->passed);
        self::assertSame(100.0, $vacuous->score);
        self::assertSame(0, $vacuous->details['dora_documents']);
        self::assertNull($vacuous->gap);

        // Pin the validity-from tag name (regulatory anchor — bumping it is a
        // deliberate code change with audit trail).
        self::assertSame('dora-validity:2025-01-17', DoraValidityFromCheck::VALIDITY_TAG_NAME);
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

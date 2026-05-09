<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\EntityTagRepository;
use App\Repository\TagRepository;
use App\Service\PolicyWizard\ExistingDocumentInventoryService;
use App\Service\PolicyWizard\ExistingDocumentMatcher;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W4-C — MUST #3: Sammelpolitik suggestion in inventory.
 *
 * Verifies the inventory service maps a matcher-detected umbrella policy
 * to the safer `split_to_topics` action (instead of the destructive
 * `replace`). Without the matcher dep wired the legacy heuristic still
 * applies (regression guard).
 */
#[AllowMockObjectsWithoutExpectations]
final class ExistingDocumentInventoryServiceSammelpolitikTest extends TestCase
{
    private function makeUser(): User
    {
        $u = new User();
        $u->setFirstName('Jane');
        $u->setLastName('Doe');
        return $u;
    }

    private function makeDocument(
        int $id,
        string $title,
        string $category,
        DateTimeImmutable $uploadedAt,
        ?string $description = null,
        int $fileSize = 1,
    ): Document {
        $doc = $this->getMockBuilder(Document::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $doc->method('getId')->willReturn($id);
        $doc->setOriginalFilename($title);
        $doc->setFilename($title);
        $doc->setCategory($category);
        $doc->setMimeType('application/pdf');
        $doc->setFileSize($fileSize);
        $doc->setFilePath('virtual:test/' . $title);
        $doc->setUploadedAt($uploadedAt);
        $doc->setUploadedBy($this->makeUser());
        $doc->setDescription($description);
        return $doc;
    }

    private function makeTenant(): Tenant
    {
        $t = new Tenant();
        $t->setName('Test');
        $t->setCode('test');
        return $t;
    }

    private function makeService(
        array $byCategory,
        array $taggedIds = [],
        bool $withMatcher = true,
    ): ExistingDocumentInventoryService {
        $documentRepo = $this->createMock(DocumentRepository::class);
        $documentRepo->method('findByCategoryAndTenant')->willReturnCallback(
            static function (Tenant $tenant, string $category) use ($byCategory): array {
                return $byCategory[$category] ?? [];
            },
        );

        $tagRepo = $this->createMock(TagRepository::class);
        $tag = new Tag();
        $tag->setName(ExistingDocumentInventoryService::POLICY_WIZARD_TAG);
        $tagRepo->method('findOneByName')->willReturn($tag);

        $entityTagRepo = $this->createMock(EntityTagRepository::class);
        $entityTagRepo->method('findEntityIdsWithTag')->willReturn($taggedIds);

        return new ExistingDocumentInventoryService(
            $documentRepo,
            $tagRepo,
            $entityTagRepo,
            $withMatcher ? new ExistingDocumentMatcher() : null,
        );
    }

    #[Test]
    public function sammelpolitikSuggestsSplit(): void
    {
        $byCategory = [
            'policy' => [
                $this->makeDocument(
                    42,
                    'IT-Sicherheitsleitlinie 2023',
                    'policy',
                    new DateTimeImmutable('-3 months'),
                    description: 'Zugriffskontrolle, Kryptographie, Backup und Logging.',
                    fileSize: 250_000,
                ),
            ],
        ];
        $service = $this->makeService($byCategory);

        $rows = $service->inventoryFor($this->makeTenant());

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['isSammelpolitik'], 'Multi-topic umbrella policy must be flagged.');
        self::assertSame(
            ExistingDocumentInventoryService::ACTION_SPLIT_TO_TOPICS,
            $rows[0]['suggestedAction'],
            'Sammelpolitik must default to split_to_topics, NOT replace.',
        );
    }

    #[Test]
    public function cleanTopLevelStillReplaceSuggested(): void
    {
        // Old, single-topic top-level document → matcher returns top_level
        // only, so the legacy age heuristic kicks in → suggest replace.
        $byCategory = [
            'policy' => [
                $this->makeDocument(
                    7,
                    'Sicherheitsleitlinie',
                    'policy',
                    new DateTimeImmutable('-30 months'),
                ),
            ],
        ];
        $service = $this->makeService($byCategory);

        $rows = $service->inventoryFor($this->makeTenant());

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['isSammelpolitik']);
        self::assertSame(
            ExistingDocumentInventoryService::ACTION_REPLACE,
            $rows[0]['suggestedAction'],
            'Clean top_level (no extra topics) keeps the age-based replace suggestion.',
        );
    }
}

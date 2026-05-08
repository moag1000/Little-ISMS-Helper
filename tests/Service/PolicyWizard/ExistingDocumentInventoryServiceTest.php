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
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W4-C — ExistingDocumentInventoryService tests.
 *
 * Stubs DocumentRepository / TagRepository / EntityTagRepository so the
 * heuristics for `suggestedAction` (replace / keep / review) and the
 * "newest first" sort are unit-testable without a database.
 */
#[AllowMockObjectsWithoutExpectations]
final class ExistingDocumentInventoryServiceTest extends TestCase
{
    private function makeUser(string $first = 'Jane', string $last = 'Doe'): User
    {
        $u = new User();
        $u->setFirstName($first);
        $u->setLastName($last);
        return $u;
    }

    /**
     * Build a Document stub. We use a real Document instance (entity is
     * non-final) so getters return the values set via setters.
     */
    private function makeDocument(
        int $id,
        string $title,
        string $category,
        ?DateTimeImmutable $uploadedAt,
        ?DateTimeImmutable $updatedAt = null,
    ): Document {
        $doc = $this->getMockBuilder(Document::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $doc->method('getId')->willReturn($id);
        $doc->setOriginalFilename($title);
        $doc->setFilename($title);
        $doc->setCategory($category);
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(1);
        $doc->setFilePath('virtual:test/' . $title);
        $doc->setUploadedAt($uploadedAt ?? new DateTimeImmutable('-3 months'));
        $doc->setUploadedBy($this->makeUser());
        if ($updatedAt !== null) {
            $doc->setUpdatedAt($updatedAt);
        }
        return $doc;
    }

    private function makeServiceWithDocuments(array $byCategory, array $taggedIds = []): ExistingDocumentInventoryService
    {
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

        return new ExistingDocumentInventoryService($documentRepo, $tagRepo, $entityTagRepo);
    }

    private function makeTenant(): Tenant
    {
        $t = new Tenant();
        $t->setName('Test');
        $t->setCode('test');
        return $t;
    }

    #[Test]
    public function inventoryReturnsRowPerGovernanceDocumentAcrossCategories(): void
    {
        $now = new DateTimeImmutable();
        $byCategory = [
            'policy' => [$this->makeDocument(1, 'ISMS-Leitlinie', 'policy', $now->modify('-1 month'))],
            'plan' => [$this->makeDocument(2, 'Notfallplan', 'plan', $now->modify('-2 months'))],
            'methodology' => [],
            'programme' => [$this->makeDocument(3, 'Awareness Programme', 'programme', $now->modify('-6 months'))],
        ];
        $service = $this->makeServiceWithDocuments($byCategory);

        $rows = $service->inventoryFor($this->makeTenant());

        self::assertCount(3, $rows, 'Inventory must surface every governance category.');
        $titles = array_column($rows, 'title');
        self::assertContains('ISMS-Leitlinie', $titles);
        self::assertContains('Notfallplan', $titles);
        self::assertContains('Awareness Programme', $titles);
    }

    #[Test]
    public function suggestedActionReplaceForOldDocument(): void
    {
        $oldDate = (new DateTimeImmutable())->modify('-30 months');
        $byCategory = [
            'policy' => [$this->makeDocument(1, 'ISMS-Leitlinie 2022', 'policy', $oldDate)],
        ];
        $service = $this->makeServiceWithDocuments($byCategory);

        $rows = $service->inventoryFor($this->makeTenant());

        self::assertSame('replace', $rows[0]['suggestedAction'], 'Documents older than 24 months → replace.');
    }

    #[Test]
    public function suggestedActionKeepForWizardManagedDocument(): void
    {
        $byCategory = [
            'policy' => [$this->makeDocument(7, 'ISMS-Leitlinie 2026', 'policy', new DateTimeImmutable('-1 month'))],
        ];
        $service = $this->makeServiceWithDocuments($byCategory, taggedIds: [7]);

        $rows = $service->inventoryFor($this->makeTenant());

        self::assertSame('keep', $rows[0]['suggestedAction'], 'Wizard-tagged documents → keep.');
        self::assertTrue($rows[0]['hasPolicyWizardTag']);
    }

    #[Test]
    public function inventorySortsNewestFirst(): void
    {
        $byCategory = [
            'policy' => [
                $this->makeDocument(1, 'Old', 'policy', new DateTimeImmutable('-12 months')),
                $this->makeDocument(2, 'New', 'policy', new DateTimeImmutable('-1 day')),
                $this->makeDocument(3, 'Mid', 'policy', new DateTimeImmutable('-6 months')),
            ],
        ];
        $service = $this->makeServiceWithDocuments($byCategory);

        $rows = $service->inventoryFor($this->makeTenant());

        self::assertSame(['New', 'Mid', 'Old'], array_column($rows, 'title'));
    }
}

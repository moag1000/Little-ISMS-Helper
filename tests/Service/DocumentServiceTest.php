<?php

namespace App\Tests\Service;

use App\Entity\CorporateGovernance;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Repository\CorporateGovernanceRepository;
use App\Repository\DocumentRepository;
use App\Service\CorporateStructureService;
use App\Service\DocumentService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DocumentServiceTest extends TestCase
{
    private MockObject $documentRepository;
    private MockObject $corporateStructureService;
    private MockObject $governanceRepository;
    private DocumentService $service;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->corporateStructureService = $this->createMock(CorporateStructureService::class);
        $this->governanceRepository = $this->createMock(CorporateGovernanceRepository::class);

        $this->service = new DocumentService(
            $this->documentRepository,
            $this->corporateStructureService,
            $this->governanceRepository
        );
    }

    public function testGetDocumentsForTenantWithoutParent(): void
    {
        $tenant = $this->createTenant(1, null);
        $documents = [$this->createMock(Document::class)];

        $this->documentRepository->method('findByTenant')
            ->with($tenant)
            ->willReturn($documents);

        $result = $this->service->getDocumentsForTenant($tenant);

        $this->assertSame($documents, $result);
    }

    public function testGetDocumentsForTenantWithHierarchicalGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $inheritedDocuments = [
            $this->createMock(Document::class),
            $this->createMock(Document::class),
            $this->createMock(Document::class),
        ];

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'document')
            ->willReturn($governance);

        $this->documentRepository->method('findByTenantIncludingParent')
            ->with($child, $parent)
            ->willReturn($inheritedDocuments);

        $result = $this->service->getDocumentsForTenant($child);

        $this->assertSame($inheritedDocuments, $result);
        $this->assertCount(3, $result);
    }

    public function testGetDocumentsForTenantWithIndependentGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownDocuments = [$this->createMock(Document::class)];

        $governance = $this->createGovernance('independent');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'document')
            ->willReturn($governance);

        $this->documentRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownDocuments);

        $result = $this->service->getDocumentsForTenant($child);

        $this->assertSame($ownDocuments, $result);
    }

    public function testGetDocumentsForTenantFallbackToDefaultGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'document')
            ->willReturn(null);

        $defaultGovernance = $this->createGovernance('shared');
        $this->governanceRepository->method('findDefaultGovernance')
            ->with($child)
            ->willReturn($defaultGovernance);

        $ownDocuments = [$this->createMock(Document::class)];
        $this->documentRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownDocuments);

        $result = $this->service->getDocumentsForTenant($child);

        $this->assertSame($ownDocuments, $result);
    }

    public function testGetDocumentInheritanceInfoWithoutParent(): void
    {
        $tenant = $this->createTenant(1, null);

        $info = $this->service->getDocumentInheritanceInfo($tenant);

        $this->assertFalse($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertNull($info['governanceModel']);
    }

    public function testGetDocumentInheritanceInfoWithHierarchicalParent(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'document')
            ->willReturn($governance);

        $info = $this->service->getDocumentInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertTrue($info['canInherit']);
        $this->assertSame('hierarchical', $info['governanceModel']);
    }

    public function testGetDocumentInheritanceInfoWithSharedParent(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $governance = $this->createGovernance('shared');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'document')
            ->willReturn($governance);

        $info = $this->service->getDocumentInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertSame('shared', $info['governanceModel']);
    }

    public function testIsInheritedDocumentTrue(): void
    {
        $parentTenant = $this->createTenant(1, null);
        $childTenant = $this->createTenant(2, $parentTenant);

        $document = $this->createMock(Document::class);
        $document->method('getTenant')->willReturn($parentTenant);

        $this->assertTrue($this->service->isInheritedDocument($document, $childTenant));
    }

    public function testIsInheritedDocumentFalse(): void
    {
        $tenant = $this->createTenant(1, null);

        $document = $this->createMock(Document::class);
        $document->method('getTenant')->willReturn($tenant);

        $this->assertFalse($this->service->isInheritedDocument($document, $tenant));
    }

    public function testIsInheritedDocumentWithNullTenant(): void
    {
        $tenant = $this->createTenant(1, null);

        $document = $this->createMock(Document::class);
        $document->method('getTenant')->willReturn(null);

        $this->assertFalse($this->service->isInheritedDocument($document, $tenant));
    }

    public function testIsInheritedDocumentWithNullIds(): void
    {
        $tenant1 = $this->createTenant(null, null);
        $tenant2 = $this->createTenant(null, null);

        $document = $this->createMock(Document::class);
        $document->method('getTenant')->willReturn($tenant1);

        $this->assertFalse($this->service->isInheritedDocument($document, $tenant2));
    }

    public function testCanEditDocumentOwnDocument(): void
    {
        $tenant = $this->createTenant(1, null);

        $document = $this->createMock(Document::class);
        $document->method('getTenant')->willReturn($tenant);

        $this->assertTrue($this->service->canEditDocument($document, $tenant));
    }

    public function testCanEditDocumentInheritedDocument(): void
    {
        $parentTenant = $this->createTenant(1, null);
        $childTenant = $this->createTenant(2, $parentTenant);

        $document = $this->createMock(Document::class);
        $document->method('getTenant')->willReturn($parentTenant);

        $this->assertFalse($this->service->canEditDocument($document, $childTenant));
    }

    public function testGetDocumentStatsWithInheritance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $ownDocuments = [
            $this->createMock(Document::class),
            $this->createMock(Document::class),
        ];

        $allDocuments = array_merge($ownDocuments, [
            $this->createMock(Document::class),
            $this->createMock(Document::class),
            $this->createMock(Document::class),
        ]);

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->willReturn($governance);

        $this->documentRepository->method('findByTenantIncludingParent')
            ->willReturn($allDocuments);

        $this->documentRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownDocuments);

        $stats = $this->service->getDocumentStatsWithInheritance($child);

        $this->assertSame(5, $stats['total']);
        $this->assertSame(2, $stats['ownDocuments']);
        $this->assertSame(3, $stats['inheritedDocuments']);
    }

    public function testServiceWorksWithoutOptionalDependencies(): void
    {
        $simpleService = new DocumentService($this->documentRepository, null, null);

        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownDocuments = [$this->createMock(Document::class)];

        $this->documentRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownDocuments);

        $result = $simpleService->getDocumentsForTenant($child);

        $this->assertSame($ownDocuments, $result);
    }

    private function createTenant(?int $id, ?Tenant $parent): MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getParent')->willReturn($parent);
        return $tenant;
    }

    private function createGovernance(string $modelValue): MockObject
    {
        $model = match ($modelValue) {
            'hierarchical' => GovernanceModel::HIERARCHICAL,
            'shared' => GovernanceModel::SHARED,
            'independent' => GovernanceModel::INDEPENDENT,
            default => GovernanceModel::INDEPENDENT,
        };

        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($model);
        return $governance;
    }
}

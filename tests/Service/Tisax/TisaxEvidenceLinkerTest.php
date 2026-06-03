<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Entity\ComplianceRequirement;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\Tisax\TisaxEvidenceLinker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for B3 evidence linkage (spec §9.1).
 *
 * Verifies the conservative exact/normalised-filename contract: a citation that
 * matches a Document is linked via addEvidenceDocument; a citation that does not
 * match lands in the typed dataSourceMapping.unlinked_citations review list and
 * is NEVER fuzzily auto-linked or silently dropped.
 */
#[AllowMockObjectsWithoutExpectations]
final class TisaxEvidenceLinkerTest extends TestCase
{
    private DocumentRepository $documentRepo;
    private TisaxEvidenceLinker $linker;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->documentRepo = $this->createMock(DocumentRepository::class);
        $this->linker       = new TisaxEvidenceLinker($this->documentRepo);

        $this->tenant = new Tenant();
        $this->tenant->setName('Test Tenant');
        $this->tenant->setCode('test_tenant');
    }

    private function makeDocument(string $original, ?string $filename = null): Document
    {
        $doc = new Document();
        $doc->setOriginalFilename($original);
        $doc->setFilename($filename ?? $original);
        $doc->setTenant($this->tenant);

        return $doc;
    }

    private function makeRequirement(string $referenceDocumentation): ComplianceRequirement
    {
        $req = new ComplianceRequirement();
        $req->setRequirementId('1.1.1');
        $req->setTitle('Information Security Policy');
        $req->setDataSourceMapping(['referenceDocumentation' => $referenceDocumentation]);

        return $req;
    }

    #[Test]
    public function links_document_on_exact_normalised_match(): void
    {
        $doc = $this->makeDocument('Informationssicherheitsleitlinie.pdf');
        $this->documentRepo->method('findByTenantIncludingParent')->willReturn([$doc]);

        // Citation differs only by extension + casing + whitespace — still exact.
        $req = $this->makeRequirement('  informationssicherheitsleitlinie ');

        $result = $this->linker->linkRequirement($req, $this->tenant);

        self::assertSame(1, $result['linked']);
        self::assertSame(0, $result['unlinked']);
        self::assertTrue($req->getEvidenceDocuments()->contains($doc));
        $mapping = $req->getDataSourceMapping();
        self::assertArrayNotHasKey('unlinked_citations', $mapping);
    }

    #[Test]
    public function records_unmatched_citation_in_review_list(): void
    {
        $this->documentRepo->method('findByTenantIncludingParent')->willReturn([]);

        $req = $this->linkRequirementWith('Nonexistent Policy.docx');

        $result = $this->linker->linkRequirement($req, $this->tenant);

        self::assertSame(0, $result['linked']);
        self::assertSame(1, $result['unlinked']);
        $mapping = $req->getDataSourceMapping();
        self::assertSame(['Nonexistent Policy.docx'], $mapping['unlinked_citations']);
        self::assertSame(1, $mapping['unlinked_citations_count']);
        self::assertCount(0, $req->getEvidenceDocuments());
    }

    #[Test]
    public function splits_multiple_citations_and_links_partial(): void
    {
        $doc = $this->makeDocument('Backup-Konzept.pdf');
        $this->documentRepo->method('findByTenantIncludingParent')->willReturn([$doc]);

        // Two citations separated by a semicolon; only one matches.
        $req = $this->makeRequirement('Backup-Konzept; Notfallhandbuch');

        $result = $this->linker->linkRequirement($req, $this->tenant);

        self::assertSame(1, $result['linked']);
        self::assertSame(1, $result['unlinked']);
        self::assertSame(['Notfallhandbuch'], $req->getDataSourceMapping()['unlinked_citations']);
        self::assertTrue($req->getEvidenceDocuments()->contains($doc));
    }

    #[Test]
    public function does_not_link_on_weak_partial_match(): void
    {
        // Document title is a SUPERSET of the citation — must NOT fuzzy-link.
        $doc = $this->makeDocument('Informationssicherheitsleitlinie Konzern 2026.pdf');
        $this->documentRepo->method('findByTenantIncludingParent')->willReturn([$doc]);

        $req = $this->makeRequirement('Informationssicherheitsleitlinie');

        $result = $this->linker->linkRequirement($req, $this->tenant);

        self::assertSame(0, $result['linked']);
        self::assertSame(1, $result['unlinked']);
        self::assertCount(0, $req->getEvidenceDocuments());
    }

    #[Test]
    public function empty_citation_is_a_no_op(): void
    {
        $this->documentRepo->method('findByTenantIncludingParent')->willReturn([]);

        $req = $this->makeRequirement('   ');

        $result = $this->linker->linkRequirement($req, $this->tenant);

        self::assertSame(0, $result['linked']);
        self::assertSame(0, $result['unlinked']);
        self::assertArrayNotHasKey('unlinked_citations', $req->getDataSourceMapping() ?? []);
    }

    #[Test]
    public function rerun_clears_stale_unlinked_after_document_appears(): void
    {
        // First run: no document → unlinked recorded.
        $this->documentRepo->method('findByTenantIncludingParent')
            ->willReturnOnConsecutiveCalls([], [$this->makeDocument('Backup-Konzept.pdf')]);

        $req = $this->makeRequirement('Backup-Konzept');

        $this->linker->linkRequirement($req, $this->tenant);
        self::assertArrayHasKey('unlinked_citations', $req->getDataSourceMapping());

        // Second run: the document now exists → stale unlinked entry cleared.
        $result = $this->linker->linkRequirement($req, $this->tenant);
        self::assertSame(1, $result['linked']);
        self::assertArrayNotHasKey('unlinked_citations', $req->getDataSourceMapping());
    }

    #[Test]
    public function link_batch_aggregates_counts(): void
    {
        $doc = $this->makeDocument('Policy A.pdf');
        $this->documentRepo->method('findByTenantIncludingParent')->willReturn([$doc]);

        $reqA = $this->makeRequirement('Policy A');
        $reqB = $this->makeRequirement('Policy B');

        $summary = $this->linker->linkBatch([$reqA, $reqB], $this->tenant);

        self::assertSame(1, $summary['linked']);
        self::assertSame(1, $summary['unlinked']);
        self::assertSame(1, $summary['requirements_with_unlinked']);
    }

    private function linkRequirementWith(string $citation): ComplianceRequirement
    {
        return $this->makeRequirement($citation);
    }
}

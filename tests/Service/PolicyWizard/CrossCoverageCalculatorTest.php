<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\DocumentRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\PolicyWizard\CrossCoverageCalculator;
use App\Service\PolicyWizard\GdprSectionCatalogue;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard — CrossCoverageCalculator unit tests.
 *
 * Covers the four scenarios derived from the May 2026 persona walkthrough:
 *   1. ISO-only run (Annex A controls only)
 *   2. ISO + DORA dual-framework run
 *   3. Multi-document multi-framework run with overlap
 *   4. Empty run (sandbox / failed) renders without errors
 *   5. Topic-driven GDPR coverage from catalogue
 */
#[AllowMockObjectsWithoutExpectations]
final class CrossCoverageCalculatorTest extends TestCase
{
    private function makeTenant(int $id = 41): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeRun(int $id, array $documentIds): WizardRun
    {
        $run = new WizardRun();
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, $id);
        $run->setGeneratedDocumentIds($documentIds);
        return $run;
    }

    /**
     * @param array<string, mixed> $links keys: annex, bsi, dora, iso27701, topic
     */
    private function makeDocument(int $id, Tenant $tenant, array $links): Document
    {
        $template = new PolicyTemplate();
        if (isset($links['annex'])) {
            $template->setLinkedAnnexAControls($links['annex']);
        }
        if (isset($links['bsi'])) {
            $template->setLinkedBausteine($links['bsi']);
        }
        if (isset($links['dora'])) {
            $template->setLinkedDoraArticles($links['dora']);
        }
        if (isset($links['iso27701'])) {
            $template->setIso27701Clauses2025($links['iso27701']);
        }
        if (isset($links['topic'])) {
            $template->setTopic($links['topic']);
        }
        // Required scalar fields so PolicyTemplate is in a valid state
        // for any code that reads them (calculator only reads links + topic).
        $template->setStandard($links['standard'] ?? 'iso27001');

        $document = new Document();
        $document->setTenant($tenant);
        $document->setGeneratedFromTemplate($template);
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($document, $id);
        return $document;
    }

    /**
     * @param list<Document> $documents
     */
    private function makeCalculator(array $documents): CrossCoverageCalculator
    {
        $documentRepo = $this->createMock(DocumentRepository::class);
        $documentRepo->method('findBy')->willReturnCallback(
            function (array $criteria) use ($documents): array {
                $ids = $criteria['id'] ?? [];
                if (!is_array($ids)) {
                    return [];
                }
                $byId = [];
                foreach ($documents as $doc) {
                    $byId[(int) $doc->getId()] = $doc;
                }
                $hits = [];
                foreach ($ids as $id) {
                    if (isset($byId[(int) $id])) {
                        $hits[] = $byId[(int) $id];
                    }
                }
                return $hits;
            },
        );

        $workflowRepo = $this->createMock(WorkflowInstanceRepository::class);
        $workflowRepo->method('findByEntity')->willReturn([]);

        return new CrossCoverageCalculator(
            documentRepository: $documentRepo,
            workflowInstanceRepository: $workflowRepo,
            gdprSectionCatalogue: new GdprSectionCatalogue(),
        );
    }

    #[Test]
    public function isoOnlyRunReportsAnnexCoverage(): void
    {
        $tenant = $this->makeTenant();
        $doc = $this->makeDocument(101, $tenant, [
            'annex' => ['A.5.15', 'A.5.16', 'A.5.17'],
        ]);
        $run = $this->makeRun(900, [101]);

        $report = $this->makeCalculator([$doc])->calculateForRun($run);

        self::assertTrue($report->hasAnyCoverage());
        self::assertSame(3, $report->coverageByFramework['ISO27001']['covered_requirements']);
        self::assertSame(93, $report->coverageByFramework['ISO27001']['total_requirements']);
        // 3 / 93 = 3.2% (rounded to 1 decimal)
        self::assertEqualsWithDelta(3.2, $report->coverageByFramework['ISO27001']['coverage_percent'], 0.1);
        self::assertSame(0, $report->coverageByFramework['DORA']['covered_requirements']);
        self::assertSame([
            ['code' => 'ISO27001', 'label' => 'ISO/IEC 27001:2022 (Annex A)', 'refs' => ['A.5.15', 'A.5.16', 'A.5.17']],
        ], $report->documentToFrameworks[101]);
    }

    #[Test]
    public function isoAndDoraRunReportsBothFrameworks(): void
    {
        $tenant = $this->makeTenant();
        $doc = $this->makeDocument(202, $tenant, [
            'annex' => ['A.5.7'],
            'dora'  => ['Art. 9.4', 'Art. 10.1'],
        ]);
        $run = $this->makeRun(901, [202]);

        $report = $this->makeCalculator([$doc])->calculateForRun($run);

        self::assertSame(1, $report->coverageByFramework['ISO27001']['covered_requirements']);
        self::assertSame(2, $report->coverageByFramework['DORA']['covered_requirements']);
        self::assertSame(45, $report->coverageByFramework['DORA']['total_requirements']);
        $codes = array_map(static fn (array $row): string => $row['code'], $report->documentToFrameworks[202]);
        self::assertContains('ISO27001', $codes);
        self::assertContains('DORA', $codes);
    }

    #[Test]
    public function multiDocumentMultiFrameworkRunDeduplicatesRefs(): void
    {
        $tenant = $this->makeTenant();
        $doc1 = $this->makeDocument(301, $tenant, [
            'annex' => ['A.5.15', 'A.5.16'],
            'bsi'   => ['ORP.4'],
        ]);
        $doc2 = $this->makeDocument(302, $tenant, [
            'annex' => ['A.5.15', 'A.5.17'], // A.5.15 overlaps with doc1
            'bsi'   => ['ORP.4', 'ORP.5'],   // ORP.4 overlaps
        ]);
        $run = $this->makeRun(902, [301, 302]);

        $report = $this->makeCalculator([$doc1, $doc2])->calculateForRun($run);

        // 3 unique annex controls (A.5.15, A.5.16, A.5.17), 2 unique BSI bausteine
        self::assertSame(3, $report->coverageByFramework['ISO27001']['covered_requirements']);
        self::assertSame(2, $report->coverageByFramework['BSI_GRUNDSCHUTZ']['covered_requirements']);
        self::assertCount(2, $report->documentToFrameworks);
        self::assertArrayHasKey(301, $report->documentToFrameworks);
        self::assertArrayHasKey(302, $report->documentToFrameworks);
    }

    #[Test]
    public function emptyRunReturnsZeroCoverageWithoutErrors(): void
    {
        $run = $this->makeRun(903, []);

        $report = $this->makeCalculator([])->calculateForRun($run);

        self::assertFalse($report->hasAnyCoverage());
        self::assertSame(0, $report->coverageByFramework['ISO27001']['covered_requirements']);
        self::assertSame(0, $report->coverageByFramework['DORA']['covered_requirements']);
        self::assertSame(0.0, $report->coverageByFramework['GDPR']['coverage_percent']);
        self::assertSame([], $report->documentToFrameworks);
    }

    #[Test]
    public function gdprCoverageIsDerivedFromTopicCatalogue(): void
    {
        $tenant = $this->makeTenant();
        // `incident_management` topic → maps to `gdpr_breach_72h` per catalogue.
        $doc = $this->makeDocument(404, $tenant, [
            'annex' => ['A.5.24'],
            'topic' => 'incident_management',
        ]);
        $run = $this->makeRun(904, [404]);

        $report = $this->makeCalculator([$doc])->calculateForRun($run);

        self::assertSame(1, $report->coverageByFramework['GDPR']['covered_requirements']);
        self::assertContains('gdpr_breach_72h', $report->coverageByFramework['GDPR']['covered_refs']);
        // 9 of 10 catalogue entries should remain as gaps.
        self::assertCount(9, $report->gapsByFramework['GDPR']);
        self::assertNotContains('gdpr_breach_72h', $report->gapsByFramework['GDPR']);
    }
}

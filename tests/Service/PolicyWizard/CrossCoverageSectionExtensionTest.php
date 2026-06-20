<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\WizardRun;
use App\Repository\DocumentRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\PolicyWizard\CrossCoverageCalculator;
use App\Service\PolicyWizard\DoraExtensionCatalogue;
use App\Service\PolicyWizard\GdprSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtensionRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see CrossCoverageCalculator} includes control-refs from
 * {@see SectionExtensionRegistry} catalogues in the coverage report —
 * not just refs from PolicyTemplate link fields.
 *
 * The critical path tested:
 *   A WizardRun adopting 'dora' has a generated document whose template
 *   has an ISO topic covered by DoraExtensionCatalogue but whose
 *   getLinkedDoraArticles() returns NULL (the template was authored before
 *   the DORA field was back-filled). Without the registry seam the DORA
 *   row in coverageByFramework would show covered_requirements = 0.
 *   After the extension the section-extension controlRefs are counted.
 *
 * Additionally verifies that when the template DOES have getLinkedDoraArticles()
 * populated, the same refs are NOT double-counted.
 */
final class CrossCoverageSectionExtensionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeDocumentRepository(array $documents): DocumentRepository
    {
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findBy')->willReturn($documents);
        return $repo;
    }

    private function makeWorkflowInstanceRepository(): WorkflowInstanceRepository
    {
        $repo = $this->createStub(WorkflowInstanceRepository::class);
        $repo->method('findByEntity')->willReturn([]);
        return $repo;
    }

    private function makeTemplate(string $topic, ?array $doraArticles = null): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setTopic($topic);
        $template->setLinkedDoraArticles($doraArticles);
        return $template;
    }

    private function makeDocument(PolicyTemplate $template): Document
    {
        $document = new Document();
        $document->setGeneratedFromTemplate($template);
        return $document;
    }

    private function makeRun(array $standards, array $documentIds): WizardRun
    {
        $run = new WizardRun();
        $run->setStandardsAdopted($standards);
        // We rely on the mock DocumentRepository, not actual IDs. We just need
        // getGeneratedDocumentIds() to return a non-empty array so the calculator
        // triggers findBy().
        $run->setGeneratedDocumentIds($documentIds ?: [999]);
        return $run;
    }

    private function makeCalculator(
        array $documents,
        ?SectionExtensionRegistry $registry = null,
    ): CrossCoverageCalculator {
        return new CrossCoverageCalculator(
            documentRepository: $this->makeDocumentRepository($documents),
            workflowInstanceRepository: $this->makeWorkflowInstanceRepository(),
            gdprSectionCatalogue: new GdprSectionCatalogue(),
            sectionExtensionRegistry: $registry,
        );
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Core case: template has no linkedDoraArticles but the topic IS covered by
     * DoraExtensionCatalogue → section-extension refs must appear in DORA coverage.
     */
    #[Test]
    public function sectionExtensionRefsCountedWhenTemplateHasNoLinkedDoraArticles(): void
    {
        // 'backup' topic → DoraExtensionCatalogue::EXTENSIONS['backup'] = ['Art. 12']
        $template = $this->makeTemplate('backup', null);
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'dora'], [999]);

        $registry = new SectionExtensionRegistry([
            new GdprSectionCatalogue(),
            new DoraExtensionCatalogue(),
        ]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $coverage = $report->coverageByFramework;

        self::assertArrayHasKey('DORA', $coverage, 'DORA must appear in coverageByFramework');
        self::assertGreaterThan(
            0,
            $coverage['DORA']['covered_requirements'],
            'covered_requirements for DORA must be > 0 when section-extension refs are present',
        );
        self::assertContains(
            'Art. 12',
            $coverage['DORA']['covered_refs'],
            'Art. 12 (from DoraExtensionCatalogue backup entry) must be in DORA covered_refs',
        );
    }

    /**
     * No-double-count: when the template already lists the same DORA article in
     * getLinkedDoraArticles(), the ref must appear EXACTLY ONCE in covered_refs.
     */
    #[Test]
    public function sectionExtensionRefsAreNotDoubleCountedWithTemplateLinkedArticles(): void
    {
        // 'backup' topic → catalogue contributes ['Art. 12']
        // template also has 'Art. 12' in linkedDoraArticles
        $template = $this->makeTemplate('backup', ['Art. 12']);
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'dora'], [999]);

        $registry = new SectionExtensionRegistry([
            new DoraExtensionCatalogue(),
        ]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $doraRefs = $report->coverageByFramework['DORA']['covered_refs'];

        // Count occurrences of 'Art. 12' — must be exactly 1 (no double-count)
        $occurrences = array_count_values($doraRefs)['Art. 12'] ?? 0;
        self::assertSame(
            1,
            $occurrences,
            '"Art. 12" must appear exactly once in DORA covered_refs (no double-count from template field + section-extension)',
        );
    }

    /**
     * BC: when sectionExtensionRegistry is null (e.g. legacy container config
     * without the registry wired), the calculator still produces a valid report
     * using only template link fields — no error, no regression.
     */
    #[Test]
    public function nullRegistryPreservesExistingBehaviour(): void
    {
        $template = $this->makeTemplate('backup', ['Art. 12']);
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'dora'], [999]);

        $calculator = $this->makeCalculator([$document], null);
        $report = $calculator->calculateForRun($run);

        $doraRefs = $report->coverageByFramework['DORA']['covered_refs'];
        self::assertContains('Art. 12', $doraRefs);
        // Exactly 1 occurrence — no duplication via the null path either
        self::assertSame(1, array_count_values($doraRefs)['Art. 12'] ?? 0);
    }

    /**
     * A standard in standardsAdopted that has NO catalogue (e.g. 'iso27001')
     * must not cause errors — the registry simply returns null → no-op.
     */
    #[Test]
    public function standardWithNoCatalogueIsSkippedGracefully(): void
    {
        // Only DoraExtensionCatalogue registered; run adopts 'iso27001' + 'dora'
        $template = $this->makeTemplate('monitoring', null);
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'dora'], [999]);

        $registry = new SectionExtensionRegistry([new DoraExtensionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        // 'monitoring' → ['Art. 10'] per DoraExtensionCatalogue
        self::assertContains('Art. 10', $report->coverageByFramework['DORA']['covered_refs']);
        // No error for iso27001 standard having no catalogue
        self::assertArrayHasKey('ISO27001', $report->coverageByFramework);
    }
}

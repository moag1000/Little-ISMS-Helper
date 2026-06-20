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
use App\Service\PolicyWizard\SectionExtension\BcmSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\Nis2SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtensionRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see CrossCoverageCalculator} includes ISO 22301 clause refs
 * from {@see BcmSectionCatalogue} in the coverage report when a WizardRun adopts
 * both 'iso27001' and 'bcm'.
 *
 * Mirrors {@see CrossCoverageBsiSectionExtensionTest} for the BSI path.
 * Framework code in FRAMEWORK_DEFAULTS / SECTION_EXTENSION_FRAMEWORK_MAP: 'ISO-22301'.
 */
final class CrossCoverageBcmSectionExtensionTest extends TestCase
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

    private function makeTemplate(string $topic, ?array $linkedAnnexAControls = null): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setTopic($topic);
        $template->setLinkedAnnexAControls($linkedAnnexAControls);
        // ISO 22301 refs are NOT stored in a PolicyTemplate field — they come
        // exclusively from BcmSectionCatalogue via the registry path.
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
     * Core case: template has no BCM-specific field, but the topic IS covered
     * by BcmSectionCatalogue → ISO 22301 clause refs must appear in ISO-22301 coverage.
     */
    #[Test]
    public function bcmSectionExtensionRefsCountedForContinuity(): void
    {
        // 'continuity' → BcmSectionCatalogue returns ['8.2', '8.3', '8.4', '8.5']
        $template = $this->makeTemplate('continuity');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'bcm'], [999]);

        $registry = new SectionExtensionRegistry([
            new GdprSectionCatalogue(),
            new DoraExtensionCatalogue(),
            new Nis2SectionCatalogue(),
            new BcmSectionCatalogue(),
        ]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $coverage = $report->coverageByFramework;

        self::assertArrayHasKey(
            'ISO-22301',
            $coverage,
            'ISO-22301 must appear in coverageByFramework when bcm standard is adopted',
        );
        self::assertGreaterThan(
            0,
            $coverage['ISO-22301']['covered_requirements'],
            'covered_requirements for ISO-22301 must be > 0 when section-extension refs are present',
        );
        self::assertContains(
            '8.2',
            $coverage['ISO-22301']['covered_refs'],
            '8.2 (BIA clause, from BcmSectionCatalogue continuity entry) must be in ISO-22301 covered_refs',
        );
        self::assertContains(
            '8.4',
            $coverage['ISO-22301']['covered_refs'],
            '8.4 (BC plans clause) must be in ISO-22301 covered_refs',
        );
    }

    /**
     * Verifies label, total and controlRefs metadata from FRAMEWORK_DEFAULTS
     * are propagated correctly for the 'bcm' adoption path.
     */
    #[Test]
    public function bcmCoverageReportContainsExpectedMetadata(): void
    {
        $template = $this->makeTemplate('continuity');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'bcm'], [999]);

        $registry = new SectionExtensionRegistry([new BcmSectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $bcm = $report->coverageByFramework['ISO-22301'];

        self::assertSame('ISO-22301', $bcm['code']);
        self::assertSame('ISO 22301 (BCMS)', $bcm['label']);
        self::assertSame(30, $bcm['total_requirements'], 'Total should be 30 (heuristic from FRAMEWORK_DEFAULTS)');
        self::assertContains('8.2', $bcm['covered_refs'], 'continuity topic maps to 8.2 (BIA)');
        self::assertContains('8.3', $bcm['covered_refs'], 'continuity topic maps to 8.3 (strategy)');
        self::assertContains('8.5', $bcm['covered_refs'], 'continuity topic maps to 8.5 (exercising)');
    }

    /**
     * Without 'bcm' in standardsAdopted, the ISO-22301 key must NOT appear
     * in coverageByFramework even when the catalogue is registered.
     */
    #[Test]
    public function bcmNotInStandardsAdoptedProducesNoBcmCoverage(): void
    {
        $template = $this->makeTemplate('continuity');
        $document = $this->makeDocument($template);
        // Only iso27001 adopted — NOT bcm
        $run = $this->makeRun(['iso27001'], [999]);

        $registry = new SectionExtensionRegistry([new BcmSectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        // ISO-22301 key must either be absent or have 0 covered_refs
        if (isset($report->coverageByFramework['ISO-22301'])) {
            self::assertEmpty(
                $report->coverageByFramework['ISO-22301']['covered_refs'],
                'ISO-22301 covered_refs must be empty when bcm is not in standardsAdopted',
            );
        } else {
            self::assertArrayNotHasKey('ISO-22301', $report->coverageByFramework);
        }
    }

    /**
     * BCM refs are not double-counted across multiple documents for the same topic.
     */
    #[Test]
    public function bcmRefsAreDeduplicatedAcrossDocumentsWithSameTopic(): void
    {
        // Two documents with the same topic → 8.2 should appear only once
        $template1 = $this->makeTemplate('continuity');
        $template2 = $this->makeTemplate('continuity');
        $doc1 = $this->makeDocument($template1);
        $doc2 = $this->makeDocument($template2);
        $run = $this->makeRun(['iso27001', 'bcm'], [998, 999]);

        $registry = new SectionExtensionRegistry([new BcmSectionCatalogue()]);

        $calculator = $this->makeCalculator([$doc1, $doc2], $registry);
        $report = $calculator->calculateForRun($run);

        $bcmRefs = $report->coverageByFramework['ISO-22301']['covered_refs'];
        $occurrences = array_count_values($bcmRefs)['8.2'] ?? 0;

        self::assertSame(1, $occurrences, '8.2 must appear exactly once (no double-count across documents)');
    }

    /**
     * Multiple topics (continuity + backup) contribute distinct refs to ISO-22301.
     */
    #[Test]
    public function multipleBcmTopicsUnionTheirRefs(): void
    {
        $templateContinuity = $this->makeTemplate('continuity');
        $templateBackup = $this->makeTemplate('backup');
        $docContinuity = $this->makeDocument($templateContinuity);
        $docBackup = $this->makeDocument($templateBackup);
        $run = $this->makeRun(['iso27001', 'bcm'], [997, 998]);

        $registry = new SectionExtensionRegistry([new BcmSectionCatalogue()]);

        $calculator = $this->makeCalculator([$docContinuity, $docBackup], $registry);
        $report = $calculator->calculateForRun($run);

        $bcmRefs = $report->coverageByFramework['ISO-22301']['covered_refs'];

        // 8.5 comes from continuity only; 8.4 shared; all should be present once
        self::assertContains('8.5', $bcmRefs, '8.5 (exercising, from continuity) must be present');
        self::assertContains('8.2', $bcmRefs, '8.2 (BIA, from continuity) must be present');
        self::assertContains('8.3', $bcmRefs, '8.3 (strategy, from both continuity and backup) must be present once');
        // 8.3 appears in both continuity and backup — must be deduplicated
        $occurrences = array_count_values($bcmRefs)['8.3'] ?? 0;
        self::assertSame(1, $occurrences, '8.3 must appear only once across continuity + backup topics');
    }
}

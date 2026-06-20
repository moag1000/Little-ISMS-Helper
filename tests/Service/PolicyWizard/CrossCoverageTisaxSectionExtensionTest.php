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
use App\Service\PolicyWizard\SectionExtension\TisaxSectionCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see CrossCoverageCalculator} includes TISAX control numbers
 * from {@see TisaxSectionCatalogue} in the coverage report when a WizardRun
 * adopts both 'iso27001' and 'tisax'.
 *
 * Mirrors {@see CrossCoverageBsiSectionExtensionTest} for the BSI path.
 * Framework code in FRAMEWORK_DEFAULTS / SECTION_EXTENSION_FRAMEWORK_MAP: 'TISAX'.
 */
final class CrossCoverageTisaxSectionExtensionTest extends TestCase
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
     * Core case: template has no TISAX-specific field, but the topic IS covered
     * by TisaxSectionCatalogue → TISAX control refs must appear in TISAX coverage.
     */
    #[Test]
    public function tisaxSectionExtensionRefsCountedForAccessControl(): void
    {
        // 'access_control' → TisaxSectionCatalogue returns ['4.1.1', '4.1.2']
        $template = $this->makeTemplate('access_control');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'tisax'], [999]);

        $registry = new SectionExtensionRegistry([
            new GdprSectionCatalogue(),
            new DoraExtensionCatalogue(),
            new TisaxSectionCatalogue(),
        ]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $coverage = $report->coverageByFramework;

        self::assertArrayHasKey('TISAX', $coverage, 'TISAX must appear in coverageByFramework when tisax standard is adopted');
        self::assertGreaterThan(
            0,
            $coverage['TISAX']['covered_requirements'],
            'covered_requirements for TISAX must be > 0 when section-extension refs are present',
        );
        self::assertContains(
            '4.1.1',
            $coverage['TISAX']['covered_refs'],
            '4.1.1 (from TisaxSectionCatalogue access_control entry) must be in TISAX covered_refs',
        );
    }

    /**
     * Verifies the label and total from FRAMEWORK_DEFAULTS are propagated correctly.
     * CrossCoverageCalculator is pre-seeded: 'TISAX' => ['label' => 'TISAX / VDA-ISA', 'total' => 80].
     */
    #[Test]
    public function tisaxCoverageReportContainsExpectedMetadata(): void
    {
        $template = $this->makeTemplate('incident_management');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'tisax'], [999]);

        $registry = new SectionExtensionRegistry([new TisaxSectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $tisax = $report->coverageByFramework['TISAX'];

        self::assertSame('TISAX', $tisax['code']);
        self::assertSame('TISAX / VDA-ISA', $tisax['label']);
        self::assertSame(80, $tisax['total_requirements'], 'Total should be 80 (heuristic from FRAMEWORK_DEFAULTS — matches 80 VDA-ISA controls)');
        self::assertContains('1.6.1', $tisax['covered_refs'], 'incident_management topic maps to 1.6.1');
    }

    /**
     * Without 'tisax' in standardsAdopted, the TISAX key must NOT appear
     * in coverageByFramework even when the catalogue is registered.
     */
    #[Test]
    public function tisaxNotInStandardsAdoptedProducesNoCoverage(): void
    {
        $template = $this->makeTemplate('access_control');
        $document = $this->makeDocument($template);
        // Only iso27001 adopted — NOT tisax
        $run = $this->makeRun(['iso27001'], [999]);

        $registry = new SectionExtensionRegistry([new TisaxSectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        if (isset($report->coverageByFramework['TISAX'])) {
            self::assertEmpty(
                $report->coverageByFramework['TISAX']['covered_refs'],
                'TISAX covered_refs must be empty when tisax is not in standardsAdopted',
            );
        } else {
            self::assertArrayNotHasKey('TISAX', $report->coverageByFramework);
        }
    }

    /**
     * TISAX refs must be deduplicated across multiple documents for the same topic.
     */
    #[Test]
    public function tisaxRefsAreDeduplicatedAcrossDocumentsWithSameTopic(): void
    {
        $template1 = $this->makeTemplate('access_control');
        $template2 = $this->makeTemplate('access_control');
        $doc1 = $this->makeDocument($template1);
        $doc2 = $this->makeDocument($template2);
        $run = $this->makeRun(['iso27001', 'tisax'], [998, 999]);

        $registry = new SectionExtensionRegistry([new TisaxSectionCatalogue()]);

        $calculator = $this->makeCalculator([$doc1, $doc2], $registry);
        $report = $calculator->calculateForRun($run);

        $tisaxRefs = $report->coverageByFramework['TISAX']['covered_refs'];
        $occurrences = array_count_values($tisaxRefs)['4.1.1'] ?? 0;

        self::assertSame(1, $occurrences, '4.1.1 must appear exactly once (no double-count across documents)');
    }

    /**
     * Prototype-protection topic is surfaced with ch.8 TISAX refs.
     */
    #[Test]
    public function prototypeProtectionTopicYieldsTisaxCh8Refs(): void
    {
        $template = $this->makeTemplate('prototype_protection');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'tisax'], [999]);

        $registry = new SectionExtensionRegistry([new TisaxSectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        self::assertArrayHasKey('TISAX', $report->coverageByFramework);
        self::assertContains('8.1.1', $report->coverageByFramework['TISAX']['covered_refs']);
    }
}

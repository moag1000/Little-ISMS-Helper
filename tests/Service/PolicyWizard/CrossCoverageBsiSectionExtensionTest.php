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
use App\Service\PolicyWizard\SectionExtension\BsiSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\Nis2SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtensionRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see CrossCoverageCalculator} includes BSI IT-Grundschutz
 * Baustein refs from {@see BsiSectionCatalogue} in the coverage report when
 * a WizardRun adopts both 'iso27001' and 'bsi'.
 *
 * Mirrors {@see CrossCoverageNis2SectionExtensionTest} for the NIS2 path.
 * Framework code in FRAMEWORK_DEFAULTS / SECTION_EXTENSION_FRAMEWORK_MAP: 'BSI_GRUNDSCHUTZ'.
 */
final class CrossCoverageBsiSectionExtensionTest extends TestCase
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
        // BSI refs are NOT stored in a PolicyTemplate field — they come
        // exclusively from BsiSectionCatalogue via the registry path.
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
     * Core case: template has no BSI-specific field, but the topic IS covered
     * by BsiSectionCatalogue → BSI Baustein refs must appear in BSI_GRUNDSCHUTZ coverage.
     */
    #[Test]
    public function bsiSectionExtensionRefsCountedForIncidentManagement(): void
    {
        // 'incident_management' → BsiSectionCatalogue returns ['DER.2.1']
        $template = $this->makeTemplate('incident_management');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'bsi'], [999]);

        $registry = new SectionExtensionRegistry([
            new GdprSectionCatalogue(),
            new DoraExtensionCatalogue(),
            new Nis2SectionCatalogue(),
            new BsiSectionCatalogue(),
        ]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $coverage = $report->coverageByFramework;

        self::assertArrayHasKey('BSI_GRUNDSCHUTZ', $coverage, 'BSI_GRUNDSCHUTZ must appear in coverageByFramework when bsi standard is adopted');
        self::assertGreaterThan(
            0,
            $coverage['BSI_GRUNDSCHUTZ']['covered_requirements'],
            'covered_requirements for BSI_GRUNDSCHUTZ must be > 0 when section-extension refs are present',
        );
        self::assertContains(
            'DER.2.1',
            $coverage['BSI_GRUNDSCHUTZ']['covered_refs'],
            'DER.2.1 (from BsiSectionCatalogue incident_management entry) must be in BSI_GRUNDSCHUTZ covered_refs',
        );
    }

    /**
     * Verifies the label and total from FRAMEWORK_DEFAULTS are propagated correctly.
     */
    #[Test]
    public function bsiCoverageReportContainsExpectedMetadata(): void
    {
        $template = $this->makeTemplate('cryptography');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'bsi'], [999]);

        $registry = new SectionExtensionRegistry([new BsiSectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $bsi = $report->coverageByFramework['BSI_GRUNDSCHUTZ'];

        self::assertSame('BSI_GRUNDSCHUTZ', $bsi['code']);
        self::assertSame('BSI IT-Grundschutz', $bsi['label']);
        self::assertSame(100, $bsi['total_requirements'], 'Total should be 100 (heuristic from FRAMEWORK_DEFAULTS)');
        self::assertContains('CON.1', $bsi['covered_refs'], 'cryptography topic maps to CON.1');
    }

    /**
     * Without 'bsi' in standardsAdopted, the BSI_GRUNDSCHUTZ key must NOT appear
     * in coverageByFramework even when the catalogue is registered.
     */
    #[Test]
    public function bsiNotInStandardsAdoptedProducesNoBsiCoverage(): void
    {
        $template = $this->makeTemplate('incident_management');
        $document = $this->makeDocument($template);
        // Only iso27001 adopted — NOT bsi
        $run = $this->makeRun(['iso27001'], [999]);

        $registry = new SectionExtensionRegistry([new BsiSectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        // BSI_GRUNDSCHUTZ key must either be absent or have 0 covered_refs
        if (isset($report->coverageByFramework['BSI_GRUNDSCHUTZ'])) {
            self::assertEmpty(
                $report->coverageByFramework['BSI_GRUNDSCHUTZ']['covered_refs'],
                'BSI_GRUNDSCHUTZ covered_refs must be empty when bsi is not in standardsAdopted',
            );
        } else {
            // Absence is also acceptable
            self::assertArrayNotHasKey('BSI_GRUNDSCHUTZ', $report->coverageByFramework);
        }
    }

    /**
     * BSI refs are not double-counted across multiple documents for the same topic.
     */
    #[Test]
    public function bsiRefsAreDeduplicatedAcrossDocumentsWithSameTopic(): void
    {
        // Two documents with the same topic → DER.2.1 should appear only once
        $template1 = $this->makeTemplate('incident_management');
        $template2 = $this->makeTemplate('incident_management');
        $doc1 = $this->makeDocument($template1);
        $doc2 = $this->makeDocument($template2);
        $run = $this->makeRun(['iso27001', 'bsi'], [998, 999]);

        $registry = new SectionExtensionRegistry([new BsiSectionCatalogue()]);

        $calculator = $this->makeCalculator([$doc1, $doc2], $registry);
        $report = $calculator->calculateForRun($run);

        $bsiRefs = $report->coverageByFramework['BSI_GRUNDSCHUTZ']['covered_refs'];
        $occurrences = array_count_values($bsiRefs)['DER.2.1'] ?? 0;

        self::assertSame(1, $occurrences, 'DER.2.1 must appear exactly once (no double-count across documents)');
    }
}

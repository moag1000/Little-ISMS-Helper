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
use App\Service\PolicyWizard\SectionExtension\C5SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\Nis2SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtensionRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see CrossCoverageCalculator} includes BSI C5:2020
 * criteria refs from {@see C5SectionCatalogue} in the coverage report when
 * a WizardRun adopts both 'iso27001' and 'c5'.
 *
 * Mirrors {@see CrossCoverageBsiSectionExtensionTest} for the C5 path.
 * Framework code in FRAMEWORK_DEFAULTS / SECTION_EXTENSION_FRAMEWORK_MAP: 'BSI-C5'.
 */
final class CrossCoverageC5SectionExtensionTest extends TestCase
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
        // C5 refs are NOT stored in a PolicyTemplate field — they come
        // exclusively from C5SectionCatalogue via the registry path.
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
     * Core case: template has no C5-specific field, but the topic IS covered
     * by C5SectionCatalogue → C5 criteria refs must appear in BSI-C5 coverage.
     */
    #[Test]
    public function c5SectionExtensionRefsCountedForIncidentManagement(): void
    {
        // 'incident_management' → C5SectionCatalogue returns ['SIM-01']
        $template = $this->makeTemplate('incident_management');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'c5'], [999]);

        $registry = new SectionExtensionRegistry([
            new GdprSectionCatalogue(),
            new DoraExtensionCatalogue(),
            new Nis2SectionCatalogue(),
            new BsiSectionCatalogue(),
            new C5SectionCatalogue(),
        ]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $coverage = $report->coverageByFramework;

        self::assertArrayHasKey('BSI-C5', $coverage, 'BSI-C5 must appear in coverageByFramework when c5 standard is adopted');
        self::assertGreaterThan(
            0,
            $coverage['BSI-C5']['covered_requirements'],
            'covered_requirements for BSI-C5 must be > 0 when section-extension refs are present',
        );
        self::assertContains(
            'SIM-01',
            $coverage['BSI-C5']['covered_refs'],
            'SIM-01 (from C5SectionCatalogue incident_management entry) must be in BSI-C5 covered_refs',
        );
    }

    /**
     * Verifies the label and total from FRAMEWORK_DEFAULTS are propagated correctly.
     */
    #[Test]
    public function c5CoverageReportContainsExpectedMetadata(): void
    {
        $template = $this->makeTemplate('cryptography');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'c5'], [999]);

        $registry = new SectionExtensionRegistry([new C5SectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $c5 = $report->coverageByFramework['BSI-C5'];

        self::assertSame('BSI-C5', $c5['code']);
        self::assertSame('BSI C5:2020', $c5['label']);
        self::assertSame(114, $c5['total_requirements'], 'Total should be 114 (from FRAMEWORK_DEFAULTS)');
        self::assertContains('CRY-01', $c5['covered_refs'], 'cryptography topic maps to CRY-01');
    }

    /**
     * Without 'c5' in standardsAdopted, the BSI-C5 key must NOT appear
     * in coverageByFramework even when the catalogue is registered.
     */
    #[Test]
    public function c5NotInStandardsAdoptedProducesNoC5Coverage(): void
    {
        $template = $this->makeTemplate('incident_management');
        $document = $this->makeDocument($template);
        // Only iso27001 adopted — NOT c5
        $run = $this->makeRun(['iso27001'], [999]);

        $registry = new SectionExtensionRegistry([new C5SectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        // BSI-C5 key must either be absent or have 0 covered_refs
        if (isset($report->coverageByFramework['BSI-C5'])) {
            self::assertEmpty(
                $report->coverageByFramework['BSI-C5']['covered_refs'],
                'BSI-C5 covered_refs must be empty when c5 is not in standardsAdopted',
            );
        } else {
            // Absence is also acceptable
            self::assertArrayNotHasKey('BSI-C5', $report->coverageByFramework);
        }
    }

    /**
     * C5 refs are not double-counted across multiple documents for the same topic.
     */
    #[Test]
    public function c5RefsAreDeduplicatedAcrossDocumentsWithSameTopic(): void
    {
        // Two documents with the same topic → SIM-01 should appear only once
        $template1 = $this->makeTemplate('incident_management');
        $template2 = $this->makeTemplate('incident_management');
        $doc1 = $this->makeDocument($template1);
        $doc2 = $this->makeDocument($template2);
        $run = $this->makeRun(['iso27001', 'c5'], [998, 999]);

        $registry = new SectionExtensionRegistry([new C5SectionCatalogue()]);

        $calculator = $this->makeCalculator([$doc1, $doc2], $registry);
        $report = $calculator->calculateForRun($run);

        $c5Refs = $report->coverageByFramework['BSI-C5']['covered_refs'];
        $occurrences = array_count_values($c5Refs)['SIM-01'] ?? 0;

        self::assertSame(1, $occurrences, 'SIM-01 must appear exactly once (no double-count across documents)');
    }
}

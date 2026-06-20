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
use App\Service\PolicyWizard\SectionExtension\Nis2SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtensionRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see CrossCoverageCalculator} includes NIS2 Art.21 control-refs
 * from {@see Nis2SectionCatalogue} in the coverage report when a WizardRun adopts
 * both 'iso27001' and 'nis2'.
 *
 * Mirrors {@see CrossCoverageSectionExtensionTest} for the DORA path.
 */
final class CrossCoverageNis2SectionExtensionTest extends TestCase
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
        // NIS2 refs are NOT stored in a PolicyTemplate field — they come
        // exclusively from Nis2SectionCatalogue via the registry path.
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
     * Core case: template has no NIS2-specific field (there is none), but the
     * topic IS covered by Nis2SectionCatalogue → NIS2 Art.21 refs must appear
     * in NIS2 coverage.
     */
    #[Test]
    public function nis2SectionExtensionRefsCountedForIncidentManagement(): void
    {
        // 'incident_management' → Nis2SectionCatalogue returns ['NIS2-ART21-B']
        $template = $this->makeTemplate('incident_management');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'nis2'], [999]);

        $registry = new SectionExtensionRegistry([
            new GdprSectionCatalogue(),
            new DoraExtensionCatalogue(),
            new Nis2SectionCatalogue(),
        ]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $coverage = $report->coverageByFramework;

        self::assertArrayHasKey('NIS2', $coverage, 'NIS2 must appear in coverageByFramework when nis2 standard is adopted');
        self::assertGreaterThan(
            0,
            $coverage['NIS2']['covered_requirements'],
            'covered_requirements for NIS2 must be > 0 when section-extension refs are present',
        );
        self::assertContains(
            'NIS2-ART21-B',
            $coverage['NIS2']['covered_refs'],
            'NIS2-ART21-B (from Nis2SectionCatalogue incident_management entry) must be in NIS2 covered_refs',
        );
    }

    /**
     * Verifies the label and total from FRAMEWORK_DEFAULTS are propagated correctly.
     */
    #[Test]
    public function nis2CoverageReportContainsExpectedMetadata(): void
    {
        $template = $this->makeTemplate('cryptography');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'nis2'], [999]);

        $registry = new SectionExtensionRegistry([new Nis2SectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $nis2 = $report->coverageByFramework['NIS2'];

        self::assertSame('NIS2', $nis2['code']);
        self::assertSame('EU-NIS2 (Art. 21)', $nis2['label']);
        self::assertSame(10, $nis2['total_requirements'], 'Total should be 10 (measures a–j)');
        self::assertContains('NIS2-ART21-H', $nis2['covered_refs'], 'cryptography topic maps to NIS2-ART21-H');
    }

    /**
     * Without 'nis2' in standardsAdopted, the NIS2 key must NOT appear in
     * coverageByFramework even when the catalogue is registered.
     */
    #[Test]
    public function nis2NotInStandardsAdoptedProducesNoNis2Coverage(): void
    {
        $template = $this->makeTemplate('incident_management');
        $document = $this->makeDocument($template);
        // Only iso27001 adopted — NOT nis2
        $run = $this->makeRun(['iso27001'], [999]);

        $registry = new SectionExtensionRegistry([new Nis2SectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        // NIS2 key must either be absent or have 0 covered_refs
        if (isset($report->coverageByFramework['NIS2'])) {
            self::assertEmpty(
                $report->coverageByFramework['NIS2']['covered_refs'],
                'NIS2 covered_refs must be empty when nis2 is not in standardsAdopted',
            );
        } else {
            // Absence is also acceptable
            self::assertArrayNotHasKey('NIS2', $report->coverageByFramework);
        }
    }

    /**
     * NIS2 refs are not double-counted across multiple documents for the same topic.
     */
    #[Test]
    public function nis2RefsAreDeduplicatedAcrossDocumentsWithSameTopic(): void
    {
        // Two documents with the same topic → NIS2-ART21-B should appear only once
        $template1 = $this->makeTemplate('incident_management');
        $template2 = $this->makeTemplate('incident_management');
        $doc1 = $this->makeDocument($template1);
        $doc2 = $this->makeDocument($template2);
        $run = $this->makeRun(['iso27001', 'nis2'], [998, 999]);

        $registry = new SectionExtensionRegistry([new Nis2SectionCatalogue()]);

        $calculator = $this->makeCalculator([$doc1, $doc2], $registry);
        $report = $calculator->calculateForRun($run);

        $nis2Refs = $report->coverageByFramework['NIS2']['covered_refs'];
        $occurrences = array_count_values($nis2Refs)['NIS2-ART21-B'] ?? 0;

        self::assertSame(1, $occurrences, 'NIS2-ART21-B must appear exactly once (no double-count across documents)');
    }
}

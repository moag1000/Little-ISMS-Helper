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
use App\Service\PolicyWizard\SectionExtension\Soc2SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtensionRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see CrossCoverageCalculator} includes SOC 2 TSC control-refs
 * from {@see Soc2SectionCatalogue} in the coverage report when a WizardRun adopts
 * both 'iso27001' and 'soc2'.
 *
 * Mirrors {@see CrossCoverageNis2SectionExtensionTest} for the NIS2 path and
 * {@see CrossCoverageBsiSectionExtensionTest} for the BSI path.
 *
 * Framework code in FRAMEWORK_DEFAULTS / SECTION_EXTENSION_FRAMEWORK_MAP: 'SOC2'.
 */
final class CrossCoverageSoc2SectionExtensionTest extends TestCase
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
        // SOC 2 TSC refs come exclusively from Soc2SectionCatalogue, not from
        // a PolicyTemplate field — the catalogue is the single source of truth.
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
     * Core case: the 'access_control' topic is covered by Soc2SectionCatalogue
     * (CC6.1, CC6.2, CC6.3) → refs must appear in SOC2 coverageByFramework.
     */
    #[Test]
    public function soc2SectionExtensionRefsCountedForAccessControl(): void
    {
        $template = $this->makeTemplate('access_control');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'soc2'], [999]);

        $registry = new SectionExtensionRegistry([
            new GdprSectionCatalogue(),
            new DoraExtensionCatalogue(),
            new Soc2SectionCatalogue(),
        ]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $coverage = $report->coverageByFramework;

        self::assertArrayHasKey(
            'SOC2',
            $coverage,
            'SOC2 must appear in coverageByFramework when soc2 standard is adopted',
        );
        self::assertGreaterThan(
            0,
            $coverage['SOC2']['covered_requirements'],
            'covered_requirements for SOC2 must be > 0 when section-extension refs are present',
        );
        self::assertContains(
            'CC6.1',
            $coverage['SOC2']['covered_refs'],
            'CC6.1 (from Soc2SectionCatalogue access_control entry) must be in SOC2 covered_refs',
        );
    }

    /**
     * Verifies the label and total from FRAMEWORK_DEFAULTS are propagated correctly.
     */
    #[Test]
    public function soc2CoverageReportContainsExpectedMetadata(): void
    {
        $template = $this->makeTemplate('access_control');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'soc2'], [999]);

        $registry = new SectionExtensionRegistry([new Soc2SectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $soc2 = $report->coverageByFramework['SOC2'];

        self::assertSame('SOC2', $soc2['code']);
        self::assertSame('SOC 2 (TSC)', $soc2['label']);
        self::assertSame(33, $soc2['total_requirements'], 'Total should be 33 (per FRAMEWORK_DEFAULTS)');
        self::assertContains('CC6.1', $soc2['covered_refs'], 'access_control topic maps to CC6.1');
    }

    /**
     * Without 'soc2' in standardsAdopted, the SOC2 key must NOT appear in
     * coverageByFramework even when the catalogue is registered.
     */
    #[Test]
    public function soc2NotInStandardsAdoptedProducesNoSoc2Coverage(): void
    {
        $template = $this->makeTemplate('access_control');
        $document = $this->makeDocument($template);
        // Only iso27001 adopted — NOT soc2
        $run = $this->makeRun(['iso27001'], [999]);

        $registry = new SectionExtensionRegistry([new Soc2SectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        // SOC2 key must either be absent or have 0 covered_refs
        if (isset($report->coverageByFramework['SOC2'])) {
            self::assertEmpty(
                $report->coverageByFramework['SOC2']['covered_refs'],
                'SOC2 covered_refs must be empty when soc2 is not in standardsAdopted',
            );
        } else {
            self::assertArrayNotHasKey('SOC2', $report->coverageByFramework);
        }
    }

    /**
     * SOC2 refs are not double-counted across multiple documents for the same topic.
     */
    #[Test]
    public function soc2RefsAreDeduplicatedAcrossDocumentsWithSameTopic(): void
    {
        $template1 = $this->makeTemplate('access_control');
        $template2 = $this->makeTemplate('access_control');
        $doc1 = $this->makeDocument($template1);
        $doc2 = $this->makeDocument($template2);
        $run = $this->makeRun(['iso27001', 'soc2'], [998, 999]);

        $registry = new SectionExtensionRegistry([new Soc2SectionCatalogue()]);

        $calculator = $this->makeCalculator([$doc1, $doc2], $registry);
        $report = $calculator->calculateForRun($run);

        $soc2Refs = $report->coverageByFramework['SOC2']['covered_refs'];
        $occurrences = array_count_values($soc2Refs)['CC6.1'] ?? 0;

        self::assertSame(1, $occurrences, 'CC6.1 must appear exactly once (no double-count across documents)');
    }

    /**
     * Backup topic maps to A1.2 — verifies Availability principle refs are covered.
     */
    #[Test]
    public function backupTopicMapsToAvailabilityTscRef(): void
    {
        $template = $this->makeTemplate('backup');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'soc2'], [999]);

        $registry = new SectionExtensionRegistry([new Soc2SectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        self::assertArrayHasKey('SOC2', $report->coverageByFramework);
        self::assertContains(
            'A1.2',
            $report->coverageByFramework['SOC2']['covered_refs'],
            'backup topic should produce A1.2 (Availability TSC) in SOC2 covered_refs',
        );
    }
}

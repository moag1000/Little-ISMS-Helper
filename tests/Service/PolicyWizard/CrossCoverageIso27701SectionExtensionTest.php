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
use App\Service\PolicyWizard\SectionExtension\Iso27701SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\Nis2SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtensionRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see CrossCoverageCalculator} includes ISO/IEC 27701 (PIMS)
 * clause refs from {@see Iso27701SectionCatalogue} in the coverage report when
 * a WizardRun adopts both 'iso27001' and 'iso27701'.
 *
 * Mirrors {@see CrossCoverageNis2SectionExtensionTest} for the NIS2 path
 * and {@see CrossCoverageBsiSectionExtensionTest} for the BSI path.
 *
 * Framework code in FRAMEWORK_DEFAULTS / SECTION_EXTENSION_FRAMEWORK_MAP: 'ISO27701'
 * (pre-seeded in CrossCoverageCalculator — DO NOT modify that file).
 */
final class CrossCoverageIso27701SectionExtensionTest extends TestCase
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
        // ISO 27701 refs come exclusively from Iso27701SectionCatalogue via the
        // registry path — NOT from a PolicyTemplate field.
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
     * Core case: template has no ISO 27701-specific field, but the topic IS covered
     * by Iso27701SectionCatalogue → ISO 27701 clause refs must appear in ISO27701 coverage.
     */
    #[Test]
    public function iso27701SectionExtensionRefsCountedForPrivacyPii(): void
    {
        // 'privacy_pii' → Iso27701SectionCatalogue returns
        // ['27701-A.7.2.1', '27701-A.7.3.1', '27701-A.7.4.1']
        $template = $this->makeTemplate('privacy_pii');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'iso27701'], [999]);

        $registry = new SectionExtensionRegistry([
            new GdprSectionCatalogue(),
            new DoraExtensionCatalogue(),
            new Nis2SectionCatalogue(),
            new Iso27701SectionCatalogue(),
        ]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $coverage = $report->coverageByFramework;

        self::assertArrayHasKey(
            'ISO27701',
            $coverage,
            'ISO27701 must appear in coverageByFramework when iso27701 standard is adopted',
        );
        self::assertGreaterThan(
            0,
            $coverage['ISO27701']['covered_requirements'],
            'covered_requirements for ISO27701 must be > 0 when section-extension refs are present',
        );
        self::assertContains(
            '27701-A.7.2.1',
            $coverage['ISO27701']['covered_refs'],
            '27701-A.7.2.1 (from Iso27701SectionCatalogue privacy_pii entry) must be in ISO27701 covered_refs',
        );
    }

    /**
     * Verifies the label and total from FRAMEWORK_DEFAULTS are propagated correctly.
     */
    #[Test]
    public function iso27701CoverageReportContainsExpectedMetadata(): void
    {
        $template = $this->makeTemplate('incident_management');
        $document = $this->makeDocument($template);
        $run = $this->makeRun(['iso27001', 'iso27701'], [999]);

        $registry = new SectionExtensionRegistry([new Iso27701SectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        $iso27701 = $report->coverageByFramework['ISO27701'];

        self::assertSame('ISO27701', $iso27701['code']);
        self::assertSame('ISO/IEC 27701 (PIMS)', $iso27701['label']);
        self::assertSame(49, $iso27701['total_requirements'], 'Total should be 49 (from FRAMEWORK_DEFAULTS)');
        self::assertContains(
            '27701-A.7.5.1',
            $iso27701['covered_refs'],
            'incident_management topic maps to 27701-A.7.5.1',
        );
    }

    /**
     * Without 'iso27701' in standardsAdopted, the ISO27701 key must NOT appear
     * in coverageByFramework even when the catalogue is registered.
     */
    #[Test]
    public function iso27701NotInStandardsAdoptedProducesNoIso27701Coverage(): void
    {
        $template = $this->makeTemplate('privacy_pii');
        $document = $this->makeDocument($template);
        // Only iso27001 adopted — NOT iso27701
        $run = $this->makeRun(['iso27001'], [999]);

        $registry = new SectionExtensionRegistry([new Iso27701SectionCatalogue()]);

        $calculator = $this->makeCalculator([$document], $registry);
        $report = $calculator->calculateForRun($run);

        // ISO27701 key must either be absent or have 0 covered_refs
        if (isset($report->coverageByFramework['ISO27701'])) {
            self::assertEmpty(
                $report->coverageByFramework['ISO27701']['covered_refs'],
                'ISO27701 covered_refs must be empty when iso27701 is not in standardsAdopted',
            );
        } else {
            self::assertArrayNotHasKey('ISO27701', $report->coverageByFramework);
        }
    }

    /**
     * ISO 27701 refs are not double-counted across multiple documents for the same topic.
     */
    #[Test]
    public function iso27701RefsAreDeduplicatedAcrossDocumentsWithSameTopic(): void
    {
        // Two documents with the same topic → 27701-A.7.2.1 should appear only once
        $template1 = $this->makeTemplate('privacy_pii');
        $template2 = $this->makeTemplate('privacy_pii');
        $doc1 = $this->makeDocument($template1);
        $doc2 = $this->makeDocument($template2);
        $run = $this->makeRun(['iso27001', 'iso27701'], [998, 999]);

        $registry = new SectionExtensionRegistry([new Iso27701SectionCatalogue()]);

        $calculator = $this->makeCalculator([$doc1, $doc2], $registry);
        $report = $calculator->calculateForRun($run);

        $iso27701Refs = $report->coverageByFramework['ISO27701']['covered_refs'];
        $occurrences = array_count_values($iso27701Refs)['27701-A.7.2.1'] ?? 0;

        self::assertSame(
            1,
            $occurrences,
            '27701-A.7.2.1 must appear exactly once (no double-count across documents)',
        );
    }
}

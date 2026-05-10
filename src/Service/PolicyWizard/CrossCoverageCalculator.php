<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\ComplianceFramework;
use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\WizardRun;
use App\Entity\WorkflowInstance;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\DocumentRepository;
use App\Repository\WorkflowInstanceRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Policy-Wizard — calculates cross-framework coverage for a finished
 * {@see WizardRun}.
 *
 * Background:
 * Compliance-Manager, ISB, Junior-Implementer and CISO walkthroughs
 * (May 2026) all flagged the result page as too thin: after the wizard
 * generated N documents, users want to see how those documents map back
 * into the active framework portfolio:
 *
 *   "Diese 7 Dokumente decken 23% ISO 27001 Annex A + 18% DORA + 4 von
 *    10 GDPR-Schwerpunkten + 12 BSI-Bausteine ab. Doc #4 hat zudem 3
 *    SoA-Controls auf in_progress angehoben und einen Approval-Workflow
 *    bei der Geschäftsführung gestartet."
 *
 * This service collects exactly that data structure and hands it to the
 * `policy_wizard/result.html.twig` template via {@see CrossCoverageReport}.
 *
 * Data sources:
 *  - {@see PolicyTemplate} — `linkedAnnexAControls`, `linkedBausteine`,
 *    `linkedDoraArticles`, `iso27701Clauses2025/2019`, plus topic-derived
 *    GDPR sections from {@see GdprSectionCatalogue}.
 *  - {@see ComplianceFrameworkRepository} — total requirement counts per
 *    framework (best-effort; falls back to documented heuristics when
 *    framework rows aren't seeded yet).
 *  - {@see WorkflowInstanceRepository} — pending approval workflows
 *    started for each generated document.
 */
final readonly class CrossCoverageCalculator
{
    /**
     * Heuristic fallbacks when {@see ComplianceFrameworkRepository}
     * isn't seeded (fresh dev tenants, unit-test setups). Drawn from
     * `docs/COMPLIANCE_FRAMEWORKS_GUIDE.md` §"Framework matrix".
     *
     * @var array<string, array{label: string, total: int}>
     */
    private const array FRAMEWORK_DEFAULTS = [
        'ISO27001'        => ['label' => 'ISO/IEC 27001:2022 (Annex A)', 'total' => 93],
        'BSI_GRUNDSCHUTZ' => ['label' => 'BSI IT-Grundschutz', 'total' => 100],
        'DORA'            => ['label' => 'EU-DORA', 'total' => 45],
        'GDPR'            => ['label' => 'EU-GDPR (Wizard-Schwerpunkte)', 'total' => 10],
        'ISO27701'        => ['label' => 'ISO/IEC 27701 (PIMS)', 'total' => 49],
    ];

    /**
     * Mapping from {@see PolicyTemplate} field accessor to framework
     * code, applied uniformly so adding a new framework only requires
     * touching this list + {@see FRAMEWORK_DEFAULTS}.
     *
     * The GDPR row is special-cased in {@see collectGdprRefs} because
     * it derives from the topic catalogue rather than a single field.
     *
     * @var array<string, string> framework_code => PolicyTemplate getter
     */
    private const array FIELD_ACCESSORS = [
        'ISO27001'        => 'getLinkedAnnexAControls',
        'BSI_GRUNDSCHUTZ' => 'getLinkedBausteine',
        'DORA'            => 'getLinkedDoraArticles',
        'ISO27701'        => 'getIso27701Clauses2025',
    ];

    public function __construct(
        private DocumentRepository $documentRepository,
        private WorkflowInstanceRepository $workflowInstanceRepository,
        private GdprSectionCatalogue $gdprSectionCatalogue,
        private ?ComplianceFrameworkRepository $complianceFrameworkRepository = null,
        private ?ComplianceRequirementRepository $complianceRequirementRepository = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Build the full cross-coverage report for the given run.
     *
     * Empty runs (sandbox, targeted re-run with zero diff, failed runs)
     * return a fully-populated DTO with all-zero coverage so the Twig
     * layer can render an "empty state" without null-checking every
     * sub-key.
     */
    public function calculateForRun(WizardRun $run): CrossCoverageReport
    {
        $documentIds = $run->getGeneratedDocumentIds() ?? [];
        $documents = $documentIds === []
            ? []
            : $this->documentRepository->findBy(['id' => $documentIds]);

        $coveredRefsByFramework = [];
        foreach (self::FIELD_ACCESSORS as $code => $_) {
            $coveredRefsByFramework[$code] = [];
        }
        $coveredRefsByFramework['GDPR'] = [];

        $documentToFrameworks = [];

        foreach ($documents as $document) {
            $template = $document->getGeneratedFromTemplate();
            if (!$template instanceof PolicyTemplate) {
                continue;
            }

            $perDocFrameworks = [];

            foreach (self::FIELD_ACCESSORS as $code => $accessor) {
                $refs = $this->normaliseRefs($template->{$accessor}() ?? []);
                if ($refs === []) {
                    continue;
                }
                $coveredRefsByFramework[$code] = array_values(array_unique(array_merge(
                    $coveredRefsByFramework[$code],
                    $refs,
                )));
                $perDocFrameworks[] = [
                    'code'  => $code,
                    'label' => self::FRAMEWORK_DEFAULTS[$code]['label'] ?? $code,
                    'refs'  => $refs,
                ];
            }

            $gdprRefs = $this->collectGdprRefs($template);
            if ($gdprRefs !== []) {
                $coveredRefsByFramework['GDPR'] = array_values(array_unique(array_merge(
                    $coveredRefsByFramework['GDPR'],
                    $gdprRefs,
                )));
                $perDocFrameworks[] = [
                    'code'  => 'GDPR',
                    'label' => self::FRAMEWORK_DEFAULTS['GDPR']['label'],
                    'refs'  => $gdprRefs,
                ];
            }

            $documentToFrameworks[(int) $document->getId()] = $perDocFrameworks;
        }

        $coverageByFramework = [];
        $gapsByFramework = [];
        foreach ($coveredRefsByFramework as $code => $refs) {
            $total = $this->resolveTotalRequirements($code);
            $covered = count($refs);
            $percent = $total > 0
                ? min(100.0, round(($covered / $total) * 100, 1))
                : 0.0;

            $coverageByFramework[$code] = [
                'code'                 => $code,
                'label'                => self::FRAMEWORK_DEFAULTS[$code]['label'] ?? $code,
                'total_requirements'   => $total,
                'covered_requirements' => $covered,
                'coverage_percent'     => (float) $percent,
                'covered_refs'         => $refs,
            ];

            // Gap list is populated when we can derive a real reference
            // universe — currently only for GDPR (catalogue is fully
            // enumerable). For ISO/BSI/DORA we'd need the seeded
            // ComplianceRequirement universe; left empty as documented.
            $gapsByFramework[$code] = $code === 'GDPR'
                ? $this->collectGdprGaps($refs)
                : [];
        }

        return new CrossCoverageReport(
            coverageByFramework: $coverageByFramework,
            documentToFrameworks: $documentToFrameworks,
            gapsByFramework: $gapsByFramework,
        );
    }

    /**
     * Resolve the active workflow-instance for a given document so the
     * result page can render a "Approval running → click here" badge.
     * Returns the most recently started instance (matches the
     * {@see WorkflowInstanceRepository::findByEntity} ordering).
     */
    public function findActiveWorkflowFor(Document $document): ?WorkflowInstance
    {
        $documentId = $document->getId();
        if ($documentId === null) {
            return null;
        }
        $instances = $this->workflowInstanceRepository->findByEntity('Document', $documentId);
        return $instances[0] ?? null;
    }

    /**
     * @param mixed $refs
     * @return list<string>
     */
    private function normaliseRefs(mixed $refs): array
    {
        if (!is_array($refs)) {
            return [];
        }
        $clean = [];
        foreach ($refs as $ref) {
            if (is_string($ref) && $ref !== '') {
                $clean[] = $ref;
            }
        }
        return array_values(array_unique($clean));
    }

    /**
     * GDPR coverage is derived from the ISO topic catalogue (see
     * {@see GdprSectionCatalogue}). Returns the section_keys that this
     * template touches based on its `topic`.
     *
     * @return list<string>
     */
    private function collectGdprRefs(PolicyTemplate $template): array
    {
        $topic = $template->getTopic();
        if ($topic === null || $topic === '') {
            return [];
        }
        $rows = $this->gdprSectionCatalogue->getSectionsFor($topic);
        $refs = [];
        foreach ($rows as $row) {
            if (isset($row['section_key']) && is_string($row['section_key'])) {
                $refs[] = $row['section_key'];
            }
        }
        return array_values(array_unique($refs));
    }

    /**
     * @param list<string> $coveredSectionKeys
     * @return list<string>
     */
    private function collectGdprGaps(array $coveredSectionKeys): array
    {
        $covered = array_flip($coveredSectionKeys);
        $gaps = [];
        foreach ($this->gdprSectionCatalogue->all() as $row) {
            $key = $row['section_key'] ?? null;
            if (is_string($key) && $key !== '' && !isset($covered[$key])) {
                $gaps[] = $key;
            }
        }
        return array_values(array_unique($gaps));
    }

    /**
     * Best-effort total-requirement count for a framework code:
     *   1. Ask the framework repository for the seeded row.
     *   2. Use {@see ComplianceRequirementRepository::countBy} when avail.
     *   3. Fall back to the documented heuristic in {@see FRAMEWORK_DEFAULTS}.
     */
    private function resolveTotalRequirements(string $code): int
    {
        if ($this->complianceFrameworkRepository !== null
            && $this->complianceRequirementRepository !== null) {
            try {
                $framework = $this->complianceFrameworkRepository->findOneBy(['code' => $code]);
                if ($framework instanceof ComplianceFramework) {
                    $stats = $this->complianceRequirementRepository->getFrameworkStatistics($framework);
                    $total = (int) ($stats['total'] ?? 0);
                    if ($total > 0) {
                        return $total;
                    }
                }
            } catch (\Throwable $error) {
                $this->logger->warning('CrossCoverageCalculator: framework total lookup failed', [
                    'code'  => $code,
                    'error' => $error->getMessage(),
                ]);
            }
        }

        return self::FRAMEWORK_DEFAULTS[$code]['total'] ?? 0;
    }
}

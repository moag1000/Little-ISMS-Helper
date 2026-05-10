<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Document;
use App\Entity\SoaSnapshot;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Repository\AssetRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\TenantBrandingRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\AuditLogger;
use App\Service\PdfExportService;
use App\Service\PolicyWizard\Export\PolicyPdfExporter;
use App\Service\Soa\SoaSnapshotService;
use App\Service\SoAReportService;
use App\Service\TenantContext;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * One-Click Certification Bundle Exporter
 *
 * Generates a comprehensive ZIP archive containing all ISMS documentation
 * required for a certification audit. Combines:
 * - Certification readiness overview PDF
 * - Statement of Applicability (SoA) PDF
 * - Risk Treatment Plan PDF
 * - Asset Register PDF
 * - Evidence documents mapped to compliance requirements
 * - Per-framework coverage CSVs (multi-framework support)
 * - Aggregated INDEX.csv with approver identity per document
 * - Gap analysis CSV
 * - Export metadata (JSON)
 *
 * The ZIP is tamper-evident: SHA-256 hash is computed over the final archive
 * and recorded in the audit log.
 *
 * Audit-V3 task #122: INDEX.csv enriched with approver identity (auditor MINOR-NC)
 * and multi-framework bundles (compliance-manager request — `frameworkCode` no
 * longer hardcoded; `addFrameworkBundle()` aggregates ISO27001/DORA/NIS2/BSI/GDPR).
 */
final class CertificationBundleExporter
{
    /**
     * @var list<string> Frameworks accumulated for the next export() call.
     */
    private array $frameworkBundles = [];

    public function __construct(
        private readonly PdfExportService $pdfExportService,
        private readonly SoAReportService $soAReportService,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly ControlRepository $controlRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly RiskTreatmentPlanRepository $treatmentPlanRepository,
        private readonly PolicyPdfExporter $pdfExporter,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?TenantBrandingRepository $brandingRepository = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?WorkflowInstanceRepository $workflowInstanceRepository = null,
        private readonly ?SoaSnapshotService $soaSnapshotService = null,
    ) {
    }

    /**
     * Add a framework code to the next export() call. Used to compose
     * multi-framework bundles instead of one bundle per framework. Unknown
     * codes are silently ignored at export time (only active frameworks
     * end up in the coverage section).
     *
     * @param string $framework Framework identifier (e.g. ISO27001, DORA, NIS2,
     *                          BSI_GRUNDSCHUTZ, GDPR).
     */
    public function addFrameworkBundle(string $framework): self
    {
        $framework = trim($framework);
        if ($framework !== '' && !in_array($framework, $this->frameworkBundles, true)) {
            $this->frameworkBundles[] = $framework;
        }
        return $this;
    }

    /**
     * Generate a complete certification bundle ZIP.
     *
     * Audit-V3 EF-3 + task #122: `frameworks` may be a single code (legacy
     * back-compat — keeps existing controllers working) OR a list of codes
     * for multi-framework bundles. Frameworks accumulated via
     * {@see addFrameworkBundle()} are merged in. Each framework contributes
     * a `02_FRAMEWORK_MAPPING/<framework>_coverage.csv`. The root
     * INDEX.csv aggregates every emitted document with approver identity.
     *
     * @param Tenant              $tenant
     * @param string|list<string> $frameworks Framework code(s). String for
     *                                        back-compat, list for the new
     *                                        multi-framework path.
     * @param ?DateTimeImmutable  $asOfDate   Optional point-in-time freeze.
     *                                        When set, the bundle includes a
     *                                        `00_SOA_SNAPSHOT/` section with
     *                                        the frozen SoA state + metadata.
     *                                        If a snapshot for the date does
     *                                        not yet exist for the tenant,
     *                                        one is created on-the-fly via
     *                                        {@see SoaSnapshotService}.
     * @return array{path: string, filename: string, sha256: string, document_count: int, frameworks: list<string>, snapshot_id?: int|null, as_of_date?: string|null}
     */
    public function export(Tenant $tenant, string|array $frameworks = 'ISO27001', ?DateTimeImmutable $asOfDate = null): array
    {
        $now = new DateTimeImmutable();
        $dateStr = $now->format('Y-m-d');
        $rootDir = 'ISMS_Certification_Bundle_' . $dateStr;

        // Normalise framework list: merge explicit arg + addFrameworkBundle()
        // accumulator. Default ISO27001 keeps legacy callers working.
        $frameworkList = is_string($frameworks) ? [$frameworks] : array_values($frameworks);
        foreach ($this->frameworkBundles as $extra) {
            if (!in_array($extra, $frameworkList, true)) {
                $frameworkList[] = $extra;
            }
        }
        if ($frameworkList === []) {
            $frameworkList = ['ISO27001'];
        }
        // Reset accumulator so subsequent export() calls are independent.
        $this->frameworkBundles = [];

        $tempPath = tempnam(sys_get_temp_dir(), 'cert_bundle_');
        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create temp file for ZIP.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to open ZIP for writing: ' . $tempPath);
        }

        $user = $this->security->getUser();
        $exportedBy = $user?->getUserIdentifier() ?? 'system';
        $documentCount = 0;

        // ── 00: ISMS Overview PDF ───────────────────────────────────────
        $overviewPdf = $this->generateOverviewPdf($tenant, $now);
        $zip->addFromString($rootDir . '/00_ISMS_OVERVIEW.pdf', $overviewPdf);

        // ── 00_SOA_SNAPSHOT: optional point-in-time freeze ──────────────
        // Persona-walkthrough gap: ISB + Auditor-External demanded a
        // bundle that reflects the SoA "as of <audit cut-off>" instead
        // of always showing live state. When `asOfDate` is set, look up
        // (or create on-the-fly) an immutable {@see SoaSnapshot} and
        // emit `snapshot_metadata.csv` + `soa_state_asof.csv` next to
        // the live SoA PDF so auditors can compare at a glance.
        $snapshot = null;
        if ($asOfDate !== null && $this->soaSnapshotService !== null && $user instanceof User) {
            $snapshot = $this->soaSnapshotService->findByTenantAndDate($tenant, $asOfDate);
            if ($snapshot === null) {
                $snapshot = $this->soaSnapshotService->createSnapshot(
                    $tenant,
                    $asOfDate,
                    $user,
                    'Certification bundle export ' . $now->format('Y-m-d'),
                    null,
                );
            }
            $zip->addFromString(
                $rootDir . '/00_SOA_SNAPSHOT/snapshot_metadata.csv',
                $this->soaSnapshotService->exportMetadataCsv($snapshot),
            );
            $zip->addFromString(
                $rootDir . '/00_SOA_SNAPSHOT/soa_state_asof.csv',
                $this->soaSnapshotService->exportPayloadCsv($snapshot),
            );
            $zip->addFromString(
                $rootDir . '/00_SOA_SNAPSHOT/payload.json',
                json_encode($snapshot->getPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }

        // ── 01: Statement of Applicability PDF ──────────────────────────
        $soaPdf = $this->soAReportService->generateSoAReport();
        $zip->addFromString($rootDir . '/01_STATEMENT_OF_APPLICABILITY.pdf', $soaPdf);

        // ── 02: Risk Treatment Plan PDF ─────────────────────────────────
        $rtpPdf = $this->generateRiskTreatmentPlanPdf($tenant, $now);
        $zip->addFromString($rootDir . '/02_RISK_TREATMENT_PLAN.pdf', $rtpPdf);

        // ── 03: Asset Register PDF ──────────────────────────────────────
        $assetPdf = $this->generateAssetRegisterPdf($tenant, $now);
        $zip->addFromString($rootDir . '/03_ASSET_REGISTER.pdf', $assetPdf);

        // ── 01b: Policy-Wizard generated Documents ──────────────────────
        $wizardResult = $this->addPolicyWizardDocuments($zip, $rootDir, $tenant);

        // ── 04: Evidence Documents ──────────────────────────────────────
        $evidenceResult = $this->addEvidenceDocuments($zip, $rootDir, $tenant);
        $documentCount = $evidenceResult['document_count'] + $wizardResult['document_count'];

        // ── 02_FRAMEWORK_MAPPING: per-framework coverage CSVs ───────────
        $coverageRows = [];
        foreach ($frameworkList as $code) {
            $coverage = $this->generateFrameworkCoverageCsv($tenant, $code);
            $zip->addFromString(
                $rootDir . '/02_FRAMEWORK_MAPPING/' . $this->safeFilename($code) . '_coverage.csv',
                $coverage['csv'],
            );
            $coverageRows[$code] = $coverage['row_count'];
        }

        // ── INDEX.csv at root: aggregated approver+sha256+run-id ────────
        $rootIndex = $this->buildRootIndexCsv($wizardResult['index_rows'], $evidenceResult['index_rows']);
        $zip->addFromString($rootDir . '/INDEX.csv', $rootIndex);

        // ── 05: Gap Analysis CSV ────────────────────────────────────────
        $gapResult = $this->generateGapAnalysisCsv($tenant);
        $zip->addFromString($rootDir . '/05_GAP_ANALYSIS.csv', $gapResult['csv']);
        $gapCount = $gapResult['gap_count'];

        // ── Counts for metadata ─────────────────────────────────────────
        $assets = $this->assetRepository->findByTenant($tenant);
        $risks = $this->riskRepository->findByTenant($tenant);
        $controlStats = $this->controlRepository->getImplementationStats($tenant);

        // ── METADATA.json ───────────────────────────────────────────────
        // Back-compat: keep `frameworkCode` populated with the first entry
        // so existing tooling that grep'd that key keeps working.
        $metadata = [
            'tenantName' => $tenant->getName(),
            'exportDate' => $now->format('c'),
            'exportedBy' => $exportedBy,
            'frameworkCode' => $frameworkList[0],
            'frameworks' => $frameworkList,
            'frameworkCoverageRowCounts' => $coverageRows,
            'counts' => [
                'controls_total' => $controlStats['total'],
                'controls_implemented' => $controlStats['implemented'],
                'risks' => count($risks),
                'assets' => count($assets),
                'documents' => $documentCount,
                'gaps' => $gapCount,
            ],
            'sha256' => '(computed after ZIP close)',
            'asOfDate' => $asOfDate?->format('Y-m-d'),
            'soaSnapshotId' => $snapshot?->getId(),
            'soaSnapshotChecksum' => $snapshot?->getChecksumSha256(),
        ];

        $zip->addFromString($rootDir . '/METADATA.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $zip->close();

        // Compute SHA-256 of the final ZIP
        $body = file_get_contents($tempPath);
        $sha256 = $body === false ? '' : hash('sha256', $body);

        // Re-open ZIP to update the SHA-256 in METADATA.json
        $zip2 = new \ZipArchive();
        if ($zip2->open($tempPath) === true) {
            $metadata['sha256'] = $sha256;
            $zip2->addFromString($rootDir . '/METADATA.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $zip2->close();

            // Recompute hash after metadata update
            $body = file_get_contents($tempPath);
            $sha256 = $body === false ? '' : hash('sha256', $body);
        }

        // Filename mirrors the framework selection: single → "ISO27001",
        // multi → "MULTI" + count, so users see at a glance they got the
        // composite bundle.
        $frameworkSlug = count($frameworkList) === 1
            ? $this->slug($frameworkList[0])
            : 'MULTI-' . count($frameworkList);

        $filename = sprintf(
            'ISMS_Certification_Bundle_%s_%s_%s.zip',
            $this->slug((string) $tenant->getName()),
            $frameworkSlug,
            $dateStr
        );

        // Log the export — task #122: action key flipped to
        // `cert_bundle_exported` with `frameworks` array so dashboards can
        // group by framework portfolio. AuditLogger remains the only path
        // (CLAUDE.md ISO 27001 Clause 7.5.3 requirement).
        if ($this->auditLogger !== null) {
            try {
                $this->auditLogger->logCustom(
                    action: 'cert_bundle_exported',
                    entityType: 'Tenant',
                    entityId: $tenant->getId(),
                    oldValues: null,
                    newValues: [
                        'tenant_id' => $tenant->getId(),
                        'tenant' => $tenant->getName(),
                        'frameworks' => $frameworkList,
                        'frameworkCode' => $frameworkList[0],
                        'documents' => $documentCount,
                        'gaps' => $gapCount,
                        'controls' => $controlStats['total'],
                        'risks' => count($risks),
                        'assets' => count($assets),
                        'exported_by_user_id' => $user instanceof User ? $user->getId() : null,
                        'exported_by_user_email' => $exportedBy,
                        'sha256' => $sha256,
                    ],
                    description: sprintf(
                        'Certification bundle exported (tenant=%s, frameworks=[%s], documents=%d, gaps=%d, sha256=%s)',
                        $tenant->getName(),
                        implode(',', $frameworkList),
                        $documentCount,
                        $gapCount,
                        substr($sha256, 0, 16) . '...'
                    ),
                );
            } catch (\Throwable) {
                // Audit log failure must not block the export.
            }
        }

        return [
            'path' => $tempPath,
            'filename' => $filename,
            'sha256' => $sha256,
            'document_count' => $documentCount,
            'frameworks' => $frameworkList,
            'snapshot_id' => $snapshot?->getId(),
            'as_of_date' => $asOfDate?->format('Y-m-d'),
        ];
    }

    /**
     * Get preview counts for the index page.
     *
     * @return array{assets: int, risks: int, controls_applicable: int, controls_implemented: int, evidence_documents: int, gaps: int}
     */
    public function getPreviewCounts(Tenant $tenant): array
    {
        $controlStats = $this->controlRepository->getImplementationStats($tenant);

        // Count evidence documents linked to compliance requirements
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $evidenceCount = 0;
        foreach ($frameworks as $framework) {
            $requirements = $this->complianceRequirementRepository->findByFramework($framework);
            foreach ($requirements as $req) {
                $evidenceCount += $req->getEvidenceDocuments()->count();
            }
        }

        // Count gaps
        $gapCount = 0;
        foreach ($frameworks as $framework) {
            $fulfillments = $this->fulfillmentRepository->findByFrameworkAndTenant($framework, $tenant);
            foreach ($fulfillments as $fulfillment) {
                if ($fulfillment->isApplicable() && $fulfillment->getFulfillmentPercentage() < 100) {
                    $gapCount++;
                }
            }
        }

        return [
            'assets' => count($this->assetRepository->findByTenant($tenant)),
            'risks' => count($this->riskRepository->findByTenant($tenant)),
            // getImplementationStats() filters applicable=true, so 'total' IS the applicable count.
            'controls_applicable' => $controlStats['total'],
            'controls_implemented' => $controlStats['implemented'],
            'evidence_documents' => $evidenceCount,
            'gaps' => $gapCount,
        ];
    }

    // ─── PDF Generators ─────────────────────────────────────────────────

    private function generateOverviewPdf(Tenant $tenant, DateTimeImmutable $now): string
    {
        $controlStats = $this->controlRepository->getImplementationStats($tenant);
        $risks = $this->riskRepository->findByTenant($tenant);
        $assets = $this->assetRepository->findByTenant($tenant);
        $documents = $this->documentRepository->findByTenant($tenant);
        $treatmentPlans = $this->treatmentPlanRepository->findActiveForTenant($tenant);

        $controlPercentage = $controlStats['total'] > 0
            ? round(($controlStats['implemented'] / $controlStats['total']) * 100, 1)
            : 0;

        $risksByLevel = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($risks as $risk) {
            $score = $risk->getRiskScore();
            $level = match (true) {
                $score >= 20 => 'critical',
                $score >= 12 => 'high',
                $score >= 6 => 'medium',
                default => 'low',
            };
            $risksByLevel[$level]++;
        }

        return $this->pdfExportService->generatePdf(
            'pdf/certification_bundle/overview.html.twig',
            [
                'tenant' => $tenant,
                'tenant_name' => $tenant->getName(),
                'generated_at' => $now,
                'version' => $now->format('Y.m.d'),
                'control_stats' => $controlStats,
                'control_percentage' => $controlPercentage,
                'risks' => $risks,
                'risks_by_level' => $risksByLevel,
                'assets' => $assets,
                'documents' => $documents,
                'treatment_plans' => $treatmentPlans,
            ],
            ['classification' => 'CONFIDENTIAL']
        );
    }

    private function generateRiskTreatmentPlanPdf(Tenant $tenant, DateTimeImmutable $now): string
    {
        $risks = $this->riskRepository->findByTenant($tenant);
        $treatmentPlans = $this->treatmentPlanRepository->findActiveForTenant($tenant);

        // Sort risks by score descending
        usort($risks, fn($a, $b) => $b->getRiskScore() - $a->getRiskScore());

        return $this->pdfExportService->generatePdf(
            'pdf/certification_bundle/risk_treatment_plan.html.twig',
            [
                'tenant_name' => $tenant->getName(),
                'generated_at' => $now,
                'version' => $now->format('Y.m.d'),
                'risks' => $risks,
                'treatment_plans' => $treatmentPlans,
            ],
            ['classification' => 'CONFIDENTIAL']
        );
    }

    private function generateAssetRegisterPdf(Tenant $tenant, DateTimeImmutable $now): string
    {
        $assets = $this->assetRepository->findByTenant($tenant);

        return $this->pdfExportService->generatePdf(
            'pdf/certification_bundle/asset_register.html.twig',
            [
                'tenant_name' => $tenant->getName(),
                'generated_at' => $now,
                'version' => $now->format('Y.m.d'),
                'assets' => $assets,
            ],
            ['classification' => 'CONFIDENTIAL']
        );
    }

    // ─── Policy-Wizard Documents (Bug #1 fix) ──────────────────────────

    /**
     * Render every Policy-Wizard generated Document for this tenant via
     * {@see PolicyPdfExporter} (W7-A) and pack into
     * `01_POLICIES/<standard>/<safe-filename>.pdf`. Emits an
     * `01_POLICIES/INDEX.csv` enriched with approver identity, wizard-run
     * id, template version and SHA-256 of the rendered body.
     *
     * Filters: archived docs are excluded so the user does not get both
     * the legacy and the replaced policy in the bundle.
     *
     * @return array{document_count: int, index_rows: list<list<string>>}
     */
    private function addPolicyWizardDocuments(\ZipArchive $zip, string $rootDir, Tenant $tenant): array
    {
        $policiesDir = $rootDir . '/01_POLICIES';
        $documentCount = 0;
        // Task #122: extra columns for Auditor evidence chain.
        $indexRows = [
            [
                'standard',
                'topic',
                'title',
                'document_id',
                'generated_at',
                'status',
                'drift_flag',
                'approved_by_user_email',
                'approved_by_user_id',
                'approved_at',
                'wizard_run_id',
                'template_version',
                'sha256',
            ],
        ];

        $branding = $this->brandingRepository?->findOneByTenant($tenant);

        $documents = $this->documentRepository->findByTenant($tenant);
        foreach ($documents as $doc) {
            $template = $doc->getGeneratedFromTemplate();
            if ($template === null) {
                continue;
            }
            if ($doc->isArchived() || $doc->getStatus() === 'archived') {
                continue;
            }

            $standard = $this->safeFilename((string) ($template->getStandard() ?: 'general'));
            $titleBase = $doc->getOriginalFilename() ?: ('policy-' . ($doc->getId() ?? 0));
            $filename = $this->safeFilename((string) $titleBase) . '.pdf';
            $zipPath = $policiesDir . '/' . $standard . '/' . $filename;

            try {
                $pdfBinary = $this->pdfExporter->exportDocument($doc, $branding);
                $zip->addFromString($zipPath, $pdfBinary);
                $documentCount++;

                $driftFlag = method_exists($doc, 'hasPostGenerationEdits') && $doc->hasPostGenerationEdits()
                    ? 'edited'
                    : 'pristine';

                $approver = $this->resolveDocumentApprover($doc);
                $sha256 = $this->computeDocumentSha256($doc, $pdfBinary);
                $wizardRun = $doc->getGeneratedFromWizardRun();

                $indexRows[] = [
                    $standard,
                    (string) ($template->getTopic() ?: ''),
                    $this->oneLine((string) ($doc->getOriginalFilename() ?? '')),
                    (string) ($doc->getId() ?? 0),
                    $doc->getUploadedAt()?->format('Y-m-d') ?? '',
                    (string) $doc->getStatus(),
                    $driftFlag,
                    $approver['user_email'],
                    $approver['user_id'] !== null ? (string) $approver['user_id'] : '',
                    $approver['approved_at'],
                    $wizardRun?->getId() !== null ? (string) $wizardRun->getId() : '',
                    (string) $template->getVersion(),
                    $sha256,
                ];
            } catch (\Throwable $e) {
                $this->logger?->warning(
                    'CertificationBundle: skipped wizard document due to PDF export error',
                    [
                        'document_id' => $doc->getId(),
                        'standard'    => $standard,
                        'error'       => $e->getMessage(),
                    ],
                );
            }
        }

        // Always emit INDEX.csv so auditors see "0 wizard policies" explicitly.
        $zip->addFromString($policiesDir . '/INDEX.csv', $this->rowsToCsv($indexRows));

        return [
            'document_count' => $documentCount,
            'index_rows'     => $indexRows,
        ];
    }

    // ─── Evidence Collection ────────────────────────────────────────────

    /**
     * Walk every active framework, dump each requirement's evidence
     * documents into `04_EVIDENCE/<category>/`, emit
     * `04_EVIDENCE/INDEX.csv` with approver + SHA-256 columns.
     *
     * @return array{document_count: int, index_rows: list<list<string>>}
     */
    private function addEvidenceDocuments(\ZipArchive $zip, string $rootDir, Tenant $tenant): array
    {
        $evidenceDir = $rootDir . '/04_EVIDENCE';
        $documentCount = 0;
        $indexRows = [
            [
                'requirement_id',
                'requirement_title',
                'framework',
                'category',
                'document_filename',
                'document_path_in_zip',
                'document_id',
                'approved_by_user_email',
                'approved_by_user_id',
                'approved_at',
                'wizard_run_id',
                'template_version',
                'sha256',
            ],
        ];

        $frameworks = $this->frameworkRepository->findActiveFrameworks();

        foreach ($frameworks as $framework) {
            $requirements = $this->complianceRequirementRepository->findByFramework($framework);

            foreach ($requirements as $req) {
                $evidence = $req->getEvidenceDocuments();
                if ($evidence->count() === 0) {
                    continue;
                }

                $category = $this->safeFilename((string) ($req->getCategory() ?: 'general'));
                $idx = 1;

                foreach ($evidence as $doc) {
                    if ($doc->isArchived() || $doc->getStatus() === 'archived') {
                        continue;
                    }
                    if ($doc->getGeneratedFromTemplate() !== null) {
                        continue;
                    }

                    $originalName = (string) ($doc->getOriginalFilename() ?? $doc->getFilename() ?? 'document-' . $doc->getId());
                    $zipName = sprintf('%02d_%s', $idx, $this->safeFilename($originalName));
                    $zipPath = $evidenceDir . '/' . $category . '/' . $zipName;

                    $filePath = (string) $doc->getFilePath();
                    if ($filePath !== '' && is_file($filePath) && is_readable($filePath)) {
                        $zip->addFile($filePath, $zipPath);
                    } else {
                        $stub = sprintf(
                            "Document id=%d is registered but the file could not be read from disk.\nPath: %s\n",
                            $doc->getId() ?? 0,
                            $filePath !== '' ? $filePath : '(empty)'
                        );
                        $zip->addFromString($zipPath . '.MISSING.txt', $stub);
                    }

                    $approver = $this->resolveDocumentApprover($doc);
                    $sha256 = $this->computeDocumentSha256($doc, null);
                    $wizardRun = $doc->getGeneratedFromWizardRun();
                    $template = $doc->getGeneratedFromTemplate();

                    $indexRows[] = [
                        (string) $req->getRequirementId(),
                        $this->oneLine((string) $req->getTitle()),
                        (string) $framework->getCode(),
                        (string) ($req->getCategory() ?: 'general'),
                        $originalName,
                        $category . '/' . $zipName,
                        (string) ($doc->getId() ?? 0),
                        $approver['user_email'],
                        $approver['user_id'] !== null ? (string) $approver['user_id'] : '',
                        $approver['approved_at'],
                        $wizardRun?->getId() !== null ? (string) $wizardRun->getId() : '',
                        $template !== null ? (string) $template->getVersion() : '',
                        $sha256,
                    ];

                    $documentCount++;
                    $idx++;
                }
            }
        }

        // Write INDEX.csv
        $zip->addFromString($evidenceDir . '/INDEX.csv', $this->rowsToCsv($indexRows));

        return [
            'document_count' => $documentCount,
            'index_rows' => $indexRows,
        ];
    }

    // ─── Approver Resolution (task #122 — Auditor MINOR-NC) ────────────

    /**
     * Resolve approver identity for a document.
     *
     * Priority chain:
     *   1) WorkflowInstance with entityType='Document' + status='approved'
     *      — most recent completedAt wins. Source of truth for documents
     *      that ran through a formal approval workflow.
     *   2) policyBodyEditedBy / policyBodyEditedAt — wizard docs that
     *      were customised + saved with status='approved' fall here.
     *   3) uploadedBy / uploadedAt — fallback for legacy upload-only flow.
     *
     * Empty fields ('') when we cannot establish identity, so auditors
     * see explicit gaps instead of silent fallbacks.
     *
     * @return array{user_email: string, user_id: ?int, approved_at: string}
     */
    private function resolveDocumentApprover(Document $doc): array
    {
        $documentId = $doc->getId();

        // 1) Workflow approval — highest evidentiary weight.
        if ($this->workflowInstanceRepository !== null && $documentId !== null) {
            try {
                $instances = $this->workflowInstanceRepository->findByEntity('Document', $documentId);
                $best = null;
                foreach ($instances as $instance) {
                    if (!$instance instanceof WorkflowInstance) {
                        continue;
                    }
                    if ($instance->getStatus() !== 'approved') {
                        continue;
                    }
                    if ($best === null || ($instance->getCompletedAt() !== null
                        && $best->getCompletedAt() !== null
                        && $instance->getCompletedAt() > $best->getCompletedAt())
                    ) {
                        $best = $instance;
                    }
                }
                if ($best !== null) {
                    $approver = $best->getInitiatedBy();
                    return [
                        'user_email' => $approver?->getEmail() ?? '',
                        'user_id' => $approver?->getId(),
                        'approved_at' => $best->getCompletedAt()?->format(DateTimeInterface::ATOM) ?? '',
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger?->debug(
                    'CertificationBundle: workflow lookup failed; falling through.',
                    ['document_id' => $documentId, 'error' => $e->getMessage()],
                );
            }
        }

        // 2) Wizard-policy edit signal — locked + edited == approved-by-editor.
        if ($doc->getStatus() === 'approved') {
            $editor = $doc->getPolicyBodyEditedBy();
            $editedAt = $doc->getPolicyBodyEditedAt();
            if ($editor !== null && $editedAt !== null) {
                return [
                    'user_email' => $editor->getEmail() ?? '',
                    'user_id' => $editor->getId(),
                    'approved_at' => $editedAt->format(DateTimeInterface::ATOM),
                ];
            }
        }

        // 3) Upload fallback — only treat as "approved by uploader" when
        // status actually says approved. Otherwise we leave it blank so
        // the auditor sees the gap rather than a misleading identity.
        if ($doc->getStatus() === 'approved') {
            $uploader = $doc->getUploadedBy();
            $uploadedAt = $doc->getUploadedAt();
            if ($uploader !== null) {
                return [
                    'user_email' => $uploader->getEmail() ?? '',
                    'user_id' => $uploader->getId(),
                    'approved_at' => $uploadedAt instanceof DateTimeInterface
                        ? $uploadedAt->format(DateTimeInterface::ATOM)
                        : '',
                ];
            }
        }

        return ['user_email' => '', 'user_id' => null, 'approved_at' => ''];
    }

    /**
     * Compute SHA-256 of the document body. Priority:
     *   1) Rendered binary (wizard PDF render path) — always exact.
     *   2) Existing `sha256Hash` column — set on upload by file-upload service.
     *   3) Hash the file on disk if readable.
     *   4) Hash the persisted policy body (wizard fallback when the
     *      column is blank and there is no on-disk file).
     *   5) '' — explicit empty so auditors can grep for gaps.
     */
    private function computeDocumentSha256(Document $doc, ?string $renderedBinary): string
    {
        if ($renderedBinary !== null && $renderedBinary !== '') {
            return hash('sha256', $renderedBinary);
        }

        $stored = (string) ($doc->getSha256Hash() ?? '');
        if ($stored !== '') {
            return $stored;
        }

        $filePath = (string) $doc->getFilePath();
        if ($filePath !== '' && is_file($filePath) && is_readable($filePath)) {
            $hash = hash_file('sha256', $filePath);
            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }

        $body = method_exists($doc, 'getPolicyBody') ? (string) ($doc->getPolicyBody() ?? '') : '';
        if ($body !== '') {
            return hash('sha256', $body);
        }

        return '';
    }

    // ─── Framework Coverage (task #122) ────────────────────────────────

    /**
     * Per-framework coverage CSV — one row per requirement with the
     * relative path of any covering documents and an aggregate
     * coverage status. Lives under
     * `02_FRAMEWORK_MAPPING/<framework>_coverage.csv` so the auditor can
     * jump from a control reference to the evidence file fast.
     *
     * @return array{csv: string, row_count: int}
     */
    private function generateFrameworkCoverageCsv(Tenant $tenant, string $frameworkCode): array
    {
        $rows = [
            ['requirement_id', 'requirement_title', 'document_path', 'coverage_status', 'fulfillment_pct'],
        ];

        $framework = $this->frameworkRepository->findOneBy(['code' => $frameworkCode]);
        if ($framework === null) {
            // Emit a header-only CSV + an explanatory row so auditors see the
            // explicit "we asked but the framework isn't loaded" signal.
            $rows[] = [
                '',
                sprintf('Framework code %s is not active or not loaded for this tenant.', $frameworkCode),
                '',
                'unknown_framework',
                '',
            ];
            return ['csv' => $this->rowsToCsv($rows), 'row_count' => 0];
        }

        $rowCount = 0;
        $requirements = $this->complianceRequirementRepository->findByFramework($framework);
        $fulfillments = $this->fulfillmentRepository->findByFrameworkAndTenant($framework, $tenant);

        // Index fulfillments by requirementId for O(1) lookup.
        $fulfillmentByReq = [];
        foreach ($fulfillments as $f) {
            $req = $f->getRequirement();
            if ($req === null) {
                continue;
            }
            $fulfillmentByReq[(string) $req->getRequirementId()] = $f;
        }

        foreach ($requirements as $req) {
            $reqId = (string) $req->getRequirementId();
            $f = $fulfillmentByReq[$reqId] ?? null;
            $pct = $f?->getFulfillmentPercentage() ?? 0;
            $status = match (true) {
                $f === null => 'no_assessment',
                !$f->isApplicable() => 'not_applicable',
                $pct >= 100 => 'fulfilled',
                $pct > 0 => 'partial',
                default => 'gap',
            };

            $evidence = $req->getEvidenceDocuments();
            if ($evidence->count() === 0) {
                $rows[] = [$reqId, $this->oneLine((string) $req->getTitle()), '', $status, (string) $pct];
                $rowCount++;
                continue;
            }

            foreach ($evidence as $doc) {
                if ($doc->isArchived() || $doc->getStatus() === 'archived') {
                    continue;
                }

                $category = $this->safeFilename((string) ($req->getCategory() ?: 'general'));
                if ($doc->getGeneratedFromTemplate() !== null) {
                    $template = $doc->getGeneratedFromTemplate();
                    $standard = $this->safeFilename((string) ($template->getStandard() ?: 'general'));
                    $titleBase = $doc->getOriginalFilename() ?: ('policy-' . ($doc->getId() ?? 0));
                    $path = '01_POLICIES/' . $standard . '/' . $this->safeFilename((string) $titleBase) . '.pdf';
                } else {
                    $name = (string) ($doc->getOriginalFilename() ?? $doc->getFilename() ?? 'document-' . $doc->getId());
                    $path = '04_EVIDENCE/' . $category . '/' . $this->safeFilename($name);
                }

                $rows[] = [$reqId, $this->oneLine((string) $req->getTitle()), $path, $status, (string) $pct];
                $rowCount++;
            }
        }

        return ['csv' => $this->rowsToCsv($rows), 'row_count' => $rowCount];
    }

    /**
     * Aggregate the wizard- and evidence-INDEX rows into a single root
     * INDEX.csv that auditors can open first to see every emitted
     * document with approver identity, SHA-256 and source section.
     *
     * @param list<list<string>> $wizardRows
     * @param list<list<string>> $evidenceRows
     */
    private function buildRootIndexCsv(array $wizardRows, array $evidenceRows): string
    {
        $rows = [
            [
                'section',
                'document_id',
                'title',
                'framework_or_standard',
                'category_or_topic',
                'document_path_in_zip',
                'approved_by_user_email',
                'approved_by_user_id',
                'approved_at',
                'wizard_run_id',
                'template_version',
                'sha256',
            ],
        ];

        // Skip header rows of the source CSVs.
        // Wizard rows: [standard, topic, title, doc_id, generated_at, status, drift_flag,
        //               approver_email, approver_id, approved_at, wizard_run_id, template_version, sha256]
        for ($i = 1; $i < count($wizardRows); $i++) {
            $r = $wizardRows[$i];
            // Reconstruct the policy doc path the wizard writer produced.
            $standard = $r[0] ?? '';
            $titleBase = $r[2] ?? ('policy-' . ($r[3] ?? '0'));
            $path = '01_POLICIES/' . $standard . '/' . $this->safeFilename((string) $titleBase) . '.pdf';
            $rows[] = [
                'POLICY',
                (string) ($r[3] ?? ''),
                (string) ($r[2] ?? ''),
                (string) ($r[0] ?? ''),
                (string) ($r[1] ?? ''),
                $path,
                (string) ($r[7] ?? ''),
                (string) ($r[8] ?? ''),
                (string) ($r[9] ?? ''),
                (string) ($r[10] ?? ''),
                (string) ($r[11] ?? ''),
                (string) ($r[12] ?? ''),
            ];
        }

        // Evidence rows: [requirement_id, requirement_title, framework, category,
        //                 doc_filename, doc_path_in_zip, doc_id, approver_email,
        //                 approver_id, approved_at, wizard_run_id, template_version, sha256]
        for ($i = 1; $i < count($evidenceRows); $i++) {
            $r = $evidenceRows[$i];
            $rows[] = [
                'EVIDENCE',
                (string) ($r[6] ?? ''),
                (string) ($r[4] ?? ''),
                (string) ($r[2] ?? ''),
                (string) ($r[3] ?? ''),
                '04_EVIDENCE/' . (string) ($r[5] ?? ''),
                (string) ($r[7] ?? ''),
                (string) ($r[8] ?? ''),
                (string) ($r[9] ?? ''),
                (string) ($r[10] ?? ''),
                (string) ($r[11] ?? ''),
                (string) ($r[12] ?? ''),
            ];
        }

        return $this->rowsToCsv($rows);
    }

    // ─── Gap Analysis ───────────────────────────────────────────────────

    /**
     * @return array{csv: string, gap_count: int}
     */
    private function generateGapAnalysisCsv(Tenant $tenant): array
    {
        $rows = [
            ['framework', 'requirement_id', 'requirement_title', 'category', 'priority', 'fulfillment_pct', 'status', 'notes'],
        ];
        $gapCount = 0;

        $frameworks = $this->frameworkRepository->findActiveFrameworks();

        foreach ($frameworks as $framework) {
            $fulfillments = $this->fulfillmentRepository->findByFrameworkAndTenant($framework, $tenant);

            foreach ($fulfillments as $fulfillment) {
                if (!$fulfillment->isApplicable()) {
                    continue;
                }

                $pct = $fulfillment->getFulfillmentPercentage();
                if ($pct >= 100) {
                    continue;
                }

                $req = $fulfillment->getRequirement();
                if ($req === null) {
                    continue;
                }

                $rows[] = [
                    (string) $framework->getCode(),
                    (string) $req->getRequirementId(),
                    $this->oneLine((string) $req->getTitle()),
                    (string) ($req->getCategory() ?: ''),
                    (string) ($req->getPriority() ?: 'medium'),
                    (string) $pct,
                    (string) ($fulfillment->getStatus() ?? 'in_progress'),
                    $this->oneLine((string) ($fulfillment->getApplicabilityJustification() ?? '')),
                ];
                $gapCount++;
            }
        }

        return [
            'csv' => $this->rowsToCsv($rows),
            'gap_count' => $gapCount,
        ];
    }

    // ─── Utility Methods (reused from AuditPackageExporter pattern) ─────

    /** Filesystem-safe slug -- alphanumerics, dot, dash, underscore only. */
    private function slug(string $value): string
    {
        $value = preg_replace('/[^\w.-]+/u', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function safeFilename(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? $name;
    }

    private function oneLine(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? $value;
    }

    /** @param list<list<string>> $rows */
    private function rowsToCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map([$this, 'sanitizeCsvValue'], $row), ',', '"', '\\');
        }
        rewind($handle);
        $out = stream_get_contents($handle);
        fclose($handle);
        return "\xEF\xBB\xBF" . ($out === false ? '' : $out);
    }

    /**
     * Sanitize a CSV cell value to prevent formula injection (OWASP - Injection).
     */
    private function sanitizeCsvValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}

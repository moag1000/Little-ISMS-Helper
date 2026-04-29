<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Service\AuditLogger;
use App\Service\PdfExportService;
use App\Service\SoAReportService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * One-Click Certification Bundle Exporter
 *
 * Generates a comprehensive ZIP archive containing all ISMS documentation
 * required for an ISO 27001 certification audit. Combines:
 * - Certification readiness overview PDF
 * - Statement of Applicability (SoA) PDF
 * - Risk Treatment Plan PDF
 * - Asset Register PDF
 * - Evidence documents mapped to compliance requirements
 * - Gap analysis CSV
 * - Export metadata (JSON)
 *
 * The ZIP is tamper-evident: SHA-256 hash is computed over the final archive
 * and recorded in the audit log.
 */
final class CertificationBundleExporter
{
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
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * Generate a complete certification bundle ZIP.
     *
     * @return array{path: string, filename: string, sha256: string, document_count: int}
     */
    public function export(Tenant $tenant): array
    {
        $now = new DateTimeImmutable();
        $dateStr = $now->format('Y-m-d');
        $rootDir = 'ISMS_Certification_Bundle_' . $dateStr;

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

        // ── 01: Statement of Applicability PDF ──────────────────────────
        $soaPdf = $this->soAReportService->generateSoAReport();
        $zip->addFromString($rootDir . '/01_STATEMENT_OF_APPLICABILITY.pdf', $soaPdf);

        // ── 02: Risk Treatment Plan PDF ─────────────────────────────────
        $rtpPdf = $this->generateRiskTreatmentPlanPdf($tenant, $now);
        $zip->addFromString($rootDir . '/02_RISK_TREATMENT_PLAN.pdf', $rtpPdf);

        // ── 03: Asset Register PDF ──────────────────────────────────────
        $assetPdf = $this->generateAssetRegisterPdf($tenant, $now);
        $zip->addFromString($rootDir . '/03_ASSET_REGISTER.pdf', $assetPdf);

        // ── 04: Evidence Documents ──────────────────────────────────────
        $evidenceResult = $this->addEvidenceDocuments($zip, $rootDir, $tenant);
        $documentCount = $evidenceResult['document_count'];

        // ── 05: Gap Analysis CSV ────────────────────────────────────────
        $gapResult = $this->generateGapAnalysisCsv($tenant);
        $zip->addFromString($rootDir . '/05_GAP_ANALYSIS.csv', $gapResult['csv']);
        $gapCount = $gapResult['gap_count'];

        // ── Counts for metadata ─────────────────────────────────────────
        $assets = $this->assetRepository->findByTenant($tenant);
        $risks = $this->riskRepository->findByTenant($tenant);
        $controlStats = $this->controlRepository->getImplementationStats($tenant);

        // ── METADATA.json ───────────────────────────────────────────────
        $metadata = [
            'tenantName' => $tenant->getName(),
            'exportDate' => $now->format('c'),
            'exportedBy' => $exportedBy,
            'frameworkCode' => 'ISO27001',
            'counts' => [
                'controls_total' => $controlStats['total'],
                'controls_implemented' => $controlStats['implemented'],
                'risks' => count($risks),
                'assets' => count($assets),
                'documents' => $documentCount,
                'gaps' => $gapCount,
            ],
            'sha256' => '(computed after ZIP close)',
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

        $filename = sprintf(
            'ISMS_Certification_Bundle_%s_%s.zip',
            $this->slug((string) $tenant->getName()),
            $dateStr
        );

        // Log the export
        if ($this->auditLogger !== null) {
            try {
                $this->auditLogger->logCustom(
                    action: 'certification_bundle_export',
                    entityType: 'Tenant',
                    entityId: $tenant->getId(),
                    oldValues: null,
                    newValues: [
                        'tenant' => $tenant->getName(),
                        'documents' => $documentCount,
                        'gaps' => $gapCount,
                        'controls' => $controlStats['total'],
                        'risks' => count($risks),
                        'assets' => count($assets),
                        'sha256' => $sha256,
                    ],
                    description: sprintf(
                        'Certification bundle exported (tenant=%s, documents=%d, gaps=%d, sha256=%s)',
                        $tenant->getName(),
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
            'controls_applicable' => $controlStats['total'] - ($controlStats['not_applicable'] ?? 0),
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

    // ─── Evidence Collection ────────────────────────────────────────────

    /**
     * @return array{document_count: int, index_rows: list<list<string>>}
     */
    private function addEvidenceDocuments(\ZipArchive $zip, string $rootDir, Tenant $tenant): array
    {
        $evidenceDir = $rootDir . '/04_EVIDENCE';
        $documentCount = 0;
        $indexRows = [
            ['requirement_id', 'requirement_title', 'framework', 'category', 'document_filename', 'document_path_in_zip'],
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

                    $indexRows[] = [
                        (string) $req->getRequirementId(),
                        $this->oneLine((string) $req->getTitle()),
                        (string) $framework->getCode(),
                        (string) ($req->getCategory() ?: 'general'),
                        $originalName,
                        $category . '/' . $zipName,
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

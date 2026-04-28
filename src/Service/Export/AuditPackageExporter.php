<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementRepository;
use App\Service\AuditLogger;
use App\Service\PdfExportService;
use DateTimeImmutable;

/**
 * Audit-Paket-Export — one-click ZIP containing every ComplianceRequirement
 * of a framework together with its linked Evidence documents and an auto-
 * generated PDF summary.
 *
 * Rationale: before any certification audit the compliance manager spends
 * 2–3 FTE-days assembling evidence per requirement. This service replaces
 * that manual step with a deterministic export.
 *
 * ZIP layout:
 *   {code}/INDEX.csv                  — master map: requirement → documents
 *   {code}/AUDIT_SUMMARY.pdf          — per-framework compliance summary
 *   {code}/{reqId}_{slug}/            — one folder per requirement
 *       README.txt                    — requirement text + control mapping +
 *                                       implementation status
 *       {N}_{original_filename}       — each linked evidence document
 *
 * SHA-256 of the final ZIP body is returned so the caller can record it in
 * the audit log (makes the export tamper-evident).
 */
final class AuditPackageExporter
{
    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly PdfExportService $pdfExportService,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * @return array{
     *     path: string,
     *     filename: string,
     *     sha256: string,
     *     document_count: int,
     *     requirement_count: int,
     *     missing_count: int
     * }
     */
    public function export(ComplianceFramework $framework, Tenant $tenant): array
    {
        $requirements = $this->requirementRepository->findByFramework($framework);

        $tempPath = tempnam(sys_get_temp_dir(), 'audit_pkg_');
        if ($tempPath === false) {
            throw new \RuntimeException('Unable to create temp file for ZIP.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to open ZIP for writing: ' . $tempPath);
        }

        $frameworkCode = $this->slug((string) $framework->getCode());
        $rootDir = $frameworkCode;

        $indexRows = [
            ['requirement_id', 'title', 'status', 'fulfilment_pct', 'evidence_count', 'evidence_files'],
        ];
        $documentCount = 0;
        $missingCount = 0;

        foreach ($requirements as $req) {
            $reqFolder = $rootDir . '/' . $this->requirementFolderName($req);
            $readme = $this->buildReadme($req);
            $zip->addFromString($reqFolder . '/README.txt', $readme);

            $files = [];
            $evidence = $req->getEvidenceDocuments();
            if ($evidence->count() === 0) {
                $missingCount++;
            }

            $idx = 1;
            foreach ($evidence as $doc) {
                $written = $this->addDocumentToZip($zip, $reqFolder, $idx, $doc);
                if ($written !== null) {
                    $files[] = $written;
                    $documentCount++;
                    $idx++;
                }
            }

            $indexRows[] = [
                (string) $req->getRequirementId(),
                $this->oneLine((string) $req->getTitle()),
                (string) ($req->calculateFulfillmentFromControls() >= 80 ? 'fulfilled' : 'open'),
                (string) $req->calculateFulfillmentFromControls(),
                (string) count($files),
                implode('|', $files),
            ];
        }

        // Master INDEX
        $zip->addFromString($rootDir . '/INDEX.csv', $this->rowsToCsv($indexRows));

        // PDF summary
        $summaryPdf = $this->pdfExportService->generatePdf(
            'audit_package/summary.html.twig',
            [
                'framework' => $framework,
                'tenant' => $tenant,
                'requirements' => $requirements,
                'generated_at' => new DateTimeImmutable(),
                'document_count' => $documentCount,
                'missing_count' => $missingCount,
            ],
            ['classification' => $tenant->getName()]
        );
        $zip->addFromString($rootDir . '/AUDIT_SUMMARY.pdf', $summaryPdf);

        $zip->close();

        $body = file_get_contents($tempPath);
        $sha = $body === false ? '' : hash('sha256', $body);

        $filename = sprintf(
            'audit-package_%s_%s_%s.zip',
            $this->slug((string) $tenant->getName()),
            $frameworkCode,
            (new DateTimeImmutable())->format('Y-m-d')
        );

        if ($this->auditLogger !== null) {
            try {
                $this->auditLogger->logCustom(
                    action: 'audit_package_export',
                    entityType: 'ComplianceFramework',
                    entityId: $framework->getId(),
                    oldValues: null,
                    newValues: [
                        'framework_code' => $framework->getCode(),
                        'tenant' => $tenant->getName(),
                        'requirements' => count($requirements),
                        'documents' => $documentCount,
                        'missing' => $missingCount,
                        'sha256' => $sha,
                    ],
                    description: sprintf(
                        'Audit-Paket exportiert (framework=%s, tenant=%s, requirements=%d, documents=%d, missing=%d)',
                        $framework->getCode(),
                        $tenant->getName(),
                        count($requirements),
                        $documentCount,
                        $missingCount
                    ),
                );
            } catch (\Throwable) {
                // Audit log failure must not block the export.
            }
        }

        return [
            'path' => $tempPath,
            'filename' => $filename,
            'sha256' => $sha,
            'document_count' => $documentCount,
            'requirement_count' => count($requirements),
            'missing_count' => $missingCount,
        ];
    }

    private function addDocumentToZip(\ZipArchive $zip, string $folder, int $idx, Document $doc): ?string
    {
        $originalName = (string) ($doc->getOriginalFilename() ?? $doc->getFilename() ?? 'document-' . $doc->getId());
        $zipName = sprintf('%02d_%s', $idx, $this->safeFilename($originalName));

        $path = (string) $doc->getFilePath();
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            // File missing on disk — record a stub so the auditor sees the gap.
            $stub = sprintf(
                "Document id=%d is registered in the database but the underlying file\n" .
                "could not be read from disk (path: %s).\n",
                $doc->getId() ?? 0,
                $path !== '' ? $path : '(empty)'
            );
            $zip->addFromString($folder . '/' . $zipName . '.MISSING.txt', $stub);
            return $zipName . '.MISSING.txt';
        }

        $zip->addFile($path, $folder . '/' . $zipName);
        return $zipName;
    }

    private function buildReadme(ComplianceRequirement $req): string
    {
        $lines = [
            'Requirement: ' . (string) $req->getRequirementId(),
            'Title: ' . (string) $req->getTitle(),
            'Category: ' . (string) $req->getCategory(),
            'Priority: ' . (string) $req->getPriority(),
            'Fulfillment (%): ' . $req->calculateFulfillmentFromControls(),
            '',
            'Description:',
            (string) $req->getDescription(),
            '',
            'Mapped Controls:',
        ];

        if ($req->getMappedControls()->count() === 0) {
            $lines[] = '  (none)';
        } else {
            foreach ($req->getMappedControls() as $control) {
                $lines[] = sprintf(
                    '  - %s — %s (status: %s, %d%%)',
                    (string) $control->getControlId(),
                    (string) $control->getName(),
                    (string) $control->getImplementationStatus(),
                    (int) ($control->getImplementationPercentage() ?? 0)
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Evidence Documents:';
        if ($req->getEvidenceDocuments()->count() === 0) {
            $lines[] = '  (none)';
        } else {
            foreach ($req->getEvidenceDocuments() as $doc) {
                $lines[] = '  - ' . (string) ($doc->getOriginalFilename() ?? 'document-' . $doc->getId());
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function requirementFolderName(ComplianceRequirement $req): string
    {
        $id = $this->slug((string) $req->getRequirementId());
        $title = $this->slug(substr((string) $req->getTitle(), 0, 60));
        return $title !== '' ? $id . '_' . $title : $id;
    }

    /** Filesystem-safe slug — alphanumerics, dot, dash, underscore only. */
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
     * Prefixes values starting with =, +, -, @, TAB or CR with a single quote.
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

<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Enum\RegisterType;
use App\Repository\IsmsRegisterEntryRepository;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\AuditLogger;
use App\Service\Compliance\Fulfillment\FulfillmentRuleEngine;
use App\Service\PdfExportService;
use DateTimeImmutable;
use App\Util\CsvSanitizer;

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
 *   {code}/REGISTERS.csv              — norm registers (roles, legal requirements, …)
 *   {code}/{reqId}_{slug}/            — one folder per requirement
 *       README.txt                    — requirement text + control mapping +
 *                                       implementation status
 *       {N}_{original_filename}       — each evidence document of the
 *                                       organisation: on the requirement, its
 *                                       fulfilment and the controls behind it
 *
 * SHA-256 of the final ZIP body is returned so the caller can record it in
 * the audit log (makes the export tamper-evident).
 */
final class AuditPackageExporter
{
    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly PdfExportService $pdfExportService,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly EvidenceDocuments $evidence,
        private readonly FulfillmentRuleEngine $ruleEngine,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?IsmsRegisterEntryRepository $registerEntries = null,
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
        $percentages = $this->fulfillmentRepository->percentagesFor($tenant, $requirements);

        $tempPath = tempnam(sys_get_temp_dir(), 'audit_pkg_');
        if ($tempPath === false) {
            throw new \App\Exception\Io\IoException('Unable to create temp file for ZIP.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \App\Exception\Io\IoException('Unable to open ZIP for writing: ' . $tempPath);
        }

        $frameworkCode = $this->slug((string) $framework->getCode());
        $rootDir = $frameworkCode;

        $indexRows = [
            ['requirement_id', 'title', 'status', 'fulfilment_pct', 'evidence_count', 'evidence_files'],
        ];
        $documentCount = 0;
        $missingCount = 0;
        $evidenceCounts = [];

        foreach ($requirements as $req) {
            $evidence = $this->evidence->forRequirement($req, $tenant);
            $evidenceCounts[(int) $req->getId()] = count($evidence);
            $reqFolder = $rootDir . '/' . $this->requirementFolderName($req);
            $readme = $this->buildReadme($req, $tenant, $evidence, $percentages[$req->getId()] ?? 0);
            $zip->addFromString($reqFolder . '/README.txt', $readme);

            $files = [];
            if ($evidence === []) {
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
                (string) (($percentages[$req->getId()] ?? 0) >= 80 ? 'fulfilled' : 'open'),
                (string) ($percentages[$req->getId()] ?? 0),
                (string) count($files),
                implode('|', $files),
            ];
        }

        // Master INDEX
        $zip->addFromString($rootDir . '/INDEX.csv', $this->rowsToCsv($indexRows));

        // Norm registers (ISO 27001 4.1, 4.2 b, 4.3 c, 5.3, 6.1.1, 7.2, 7.4) — Paket 9
        if ($this->registerEntries !== null) {
            $zip->addFromString($rootDir . '/REGISTERS.csv', $this->rowsToCsv($this->registerRows($tenant)));
        }

        // PDF summary
        $summaryPdf = $this->pdfExportService->generatePdf(
            'audit_package/summary.html.twig',
            [
                'framework' => $framework,
                'tenant' => $tenant,
                'requirements' => $requirements,
                'fulfillment_percentages' => $percentages,
                'evidence_counts' => $evidenceCounts,
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

    /**
     * @param list<Document> $evidence
     */
    private function buildReadme(ComplianceRequirement $req, Tenant $tenant, array $evidence, int $fulfillmentPct): string
    {
        $lines = [
            'Requirement: ' . (string) $req->getRequirementId(),
            'Title: ' . (string) $req->getTitle(),
            'Category: ' . (string) $req->getCategory(),
            'Priority: ' . (string) $req->getPriority(),
            'Fulfillment (%): ' . $fulfillmentPct,
            '',
            'Description:',
            (string) $req->getDescription(),
            '',
            'Mapped Controls:',
        ];

        $controls = $this->ruleEngine->controlsFor($req, $tenant);
        if ($controls === []) {
            $lines[] = '  (none)';
        } else {
            foreach ($controls as $control) {
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
        if ($evidence === []) {
            $lines[] = '  (none)';
        } else {
            foreach ($evidence as $doc) {
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

    /** @return list<list<string>> */
    private function registerRows(Tenant $tenant): array
    {
        $rows = [['register', 'norm', 'title', 'category', 'reference', 'owner', 'next_review', 'last_reviewed']];
        foreach (RegisterType::cases() as $type) {
            foreach ($this->registerEntries?->findByType($tenant, $type) ?? [] as $entry) {
                $rows[] = [
                    $type->value,
                    $type->normReference(),
                    $this->oneLine((string) $entry->getTitle()),
                    (string) $entry->getCategory(),
                    (string) $entry->getReference(),
                    (string) $entry->getEffectiveOwner(),
                    (string) $entry->getNextReviewDate()?->format('Y-m-d'),
                    (string) $entry->getLastReviewedAt()?->format('Y-m-d'),
                ];
            }
        }

        return $rows;
    }

    /** @param list<list<string>> $rows */
    private function rowsToCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map([CsvSanitizer::class, 'sanitize'], $row), ',', '"', '\\');
        }
        rewind($handle);
        $out = stream_get_contents($handle);
        fclose($handle);
        return "\xEF\xBB\xBF" . ($out === false ? '' : $out);
    }
}

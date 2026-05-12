<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;
use App\Repository\BusinessProcessRepository;
use App\Service\AuditLogger;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F19.2 — EntityListExporter
 *
 * Exports the currently-filtered entity list as XLSX, CSV, or JSON.
 * Filter-state is passed in as an array from FilterStateService.
 *
 * Supported entity types: asset, risk, supplier, control, business_process,
 *                         document, incident, audit_finding
 *
 * All exports are logged via AuditLogger for ISO 27001 Cl. 7.5.3 traceability.
 */
final class EntityListExporter
{
    public const SUPPORTED_ENTITY_TYPES = [
        'asset',
        'risk',
        'supplier',
        'control',
        'business_process',
        'document',
        'incident',
        'audit_finding',
    ];

    public const SUPPORTED_FORMATS = ['xlsx', 'csv', 'json'];

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly ControlRepository $controlRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly AuditFindingRepository $auditFindingRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Export filtered entity list. Returns a StreamedResponse for XLSX/CSV,
     * or a plain JSON-serialisable array for JSON format.
     *
     * @param array<string, string|array<string>> $filters
     */
    public function exportFiltered(
        string $entityType,
        array $filters,
        Tenant $tenant,
        string $format,
    ): StreamedResponse|array {
        if (!in_array($entityType, self::SUPPORTED_ENTITY_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported entity type: %s', $entityType));
        }
        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported format: %s', $format));
        }

        $rows = $this->fetchRows($entityType, $filters, $tenant);

        $this->auditLogger->logExport(
            sprintf('FilteredList-%s', ucfirst($entityType)),
            null,
            sprintf('Filtered %s export (%s) — %d rows — filters: %s', $entityType, $format, count($rows), json_encode($filters)),
        );

        return match ($format) {
            'xlsx' => $this->buildXlsxResponse($rows, $entityType),
            'csv' => $this->buildCsvResponse($rows, $entityType),
            'json' => $rows,
            default => throw new \InvalidArgumentException('Unsupported format: ' . $format),
        };
    }

    // ── Row Fetchers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, string|array<string>> $filters
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(string $entityType, array $filters, Tenant $tenant): array
    {
        return match ($entityType) {
            'asset' => $this->fetchAssets($tenant, $filters),
            'risk' => $this->fetchRisks($tenant, $filters),
            'supplier' => $this->fetchSuppliers($tenant, $filters),
            'control' => $this->fetchControls($tenant, $filters),
            'business_process' => $this->fetchBusinessProcesses($tenant, $filters),
            'document' => $this->fetchDocuments($tenant, $filters),
            'incident' => $this->fetchIncidents($tenant, $filters),
            'audit_finding' => $this->fetchAuditFindings($tenant, $filters),
            default => [],
        };
    }

    /** @param array<string, string|array<string>> $filters @return array<int, array<string, mixed>> */
    private function fetchAssets(Tenant $tenant, array $filters): array
    {
        $entities = $this->assetRepository->findByTenant($tenant);
        return array_map(static fn ($e) => [
            'id' => $e->getId(),
            'name' => $e->getName(),
            'assetType' => $e->getAssetType(),
            'status' => $e->getStatus(),
            'owner' => $e->getEffectiveOwner(),
            'confidentialityValue' => $e->getConfidentialityValue(),
            'integrityValue' => $e->getIntegrityValue(),
            'availabilityValue' => $e->getAvailabilityValue(),
        ], $this->applyFilters($entities, $filters));
    }

    /** @param array<string, string|array<string>> $filters @return array<int, array<string, mixed>> */
    private function fetchRisks(Tenant $tenant, array $filters): array
    {
        $entities = $this->riskRepository->findByTenant($tenant);
        return array_map(static fn ($e) => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'status' => $e->getStatus(),
            'likelihood' => $e->getLikelihood(),
            'impact' => $e->getImpact(),
            'inherentScore' => $e->getInherentScore(),
            'owner' => $e->getEffectiveRiskOwner(),
        ], $this->applyFilters($entities, $filters));
    }

    /** @param array<string, string|array<string>> $filters @return array<int, array<string, mixed>> */
    private function fetchSuppliers(Tenant $tenant, array $filters): array
    {
        $entities = $this->supplierRepository->findByTenant($tenant);
        return array_map(static fn ($e) => [
            'id' => $e->getId(),
            'name' => $e->getName(),
            'status' => $e->getStatus(),
            'contactEmail' => $e->getEmail(),
            'criticality' => $e->getCriticality(),
        ], $this->applyFilters($entities, $filters));
    }

    /** @param array<string, string|array<string>> $filters @return array<int, array<string, mixed>> */
    private function fetchControls(Tenant $tenant, array $filters): array
    {
        $entities = $this->controlRepository->findByTenant($tenant);
        return array_map(static fn ($e) => [
            'id' => $e->getId(),
            'controlId' => $e->getControlId(),
            'name' => $e->getName(),
            'implementationStatus' => $e->getImplementationStatus(),
            'owner' => $e->getEffectiveOwnerName(),
        ], $this->applyFilters($entities, $filters));
    }

    /** @param array<string, string|array<string>> $filters @return array<int, array<string, mixed>> */
    private function fetchBusinessProcesses(Tenant $tenant, array $filters): array
    {
        $entities = $this->businessProcessRepository->findByTenant($tenant);
        return array_map(static fn ($e) => [
            'id' => $e->getId(),
            'name' => $e->getName(),
            'criticality' => $e->getCriticality(),
            'processOwner' => $e->getProcessOwner(),
            'rtoHours' => $e->getRto(),
            'rpoHours' => $e->getRpo(),
        ], $this->applyFilters($entities, $filters));
    }

    /** @param array<string, string|array<string>> $filters @return array<int, array<string, mixed>> */
    private function fetchDocuments(Tenant $tenant, array $filters): array
    {
        $entities = $this->documentRepository->findByTenant($tenant);
        return array_map(static fn ($e) => [
            'id' => $e->getId(),
            'filename' => $e->getOriginalFilename(),
            'status' => $e->getStatus(),
            'category' => $e->getCategory(),
            'owner' => $e->getEffectiveOwnerName(),
        ], $this->applyFilters($entities, $filters));
    }

    /** @param array<string, string|array<string>> $filters @return array<int, array<string, mixed>> */
    private function fetchIncidents(Tenant $tenant, array $filters): array
    {
        $entities = $this->incidentRepository->findBy(['tenant' => $tenant], ['detectedAt' => 'DESC']);
        return array_map(static fn ($e) => [
            'id' => $e->getId(),
            'incidentNumber' => $e->getIncidentNumber(),
            'title' => $e->getTitle(),
            'severity' => (string) $e->getSeverity(),
            'status' => (string) $e->getStatus(),
            'detectedAt' => $e->getDetectedAt()?->format('Y-m-d H:i'),
        ], $this->applyFilters($entities, $filters));
    }

    /** @param array<string, string|array<string>> $filters @return array<int, array<string, mixed>> */
    private function fetchAuditFindings(Tenant $tenant, array $filters): array
    {
        $entities = $this->auditFindingRepository->findOpenByTenant($tenant);
        return array_map(static fn ($e) => [
            'id' => $e->getId(),
            'findingNumber' => $e->getFindingNumber(),
            'title' => $e->getTitle(),
            'type' => $e->getType(),
            'severity' => $e->getSeverity(),
            'status' => $e->getStatus(),
            'dueDate' => $e->getDueDate()?->format('Y-m-d'),
        ], $this->applyFilters($entities, $filters));
    }

    // ── Filter Application ────────────────────────────────────────────────────

    /**
     * Basic in-memory filter application. Filters by 'status' if provided.
     *
     * @param object[] $entities
     * @param array<string, string|array<string>> $filters
     * @return object[]
     */
    private function applyFilters(array $entities, array $filters): array
    {
        if (isset($filters['status']) && $filters['status'] !== '') {
            $statusFilter = (array) $filters['status'];
            $entities = array_filter($entities, static function ($e) use ($statusFilter): bool {
                if (!method_exists($e, 'getStatus')) {
                    return true;
                }
                return in_array((string) $e->getStatus(), $statusFilter, true);
            });
        }
        if (isset($filters['severity']) && $filters['severity'] !== '') {
            $severityFilter = (array) $filters['severity'];
            $entities = array_filter($entities, static function ($e) use ($severityFilter): bool {
                if (!method_exists($e, 'getSeverity')) {
                    return true;
                }
                return in_array((string) $e->getSeverity(), $severityFilter, true);
            });
        }
        return array_values($entities);
    }

    // ── Spreadsheet Builders ──────────────────────────────────────────────────

    /** @param array<int, array<string, mixed>> $rows */
    private function buildXlsxResponse(array $rows, string $entityType): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet($rows, $entityType);

        return new StreamedResponse(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => sprintf('attachment; filename="%s-export-%s.xlsx"', $entityType, date('Ymd')),
        ]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function buildCsvResponse(array $rows, string $entityType): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet($rows, $entityType);

        return new StreamedResponse(function () use ($spreadsheet): void {
            $writer = new Csv($spreadsheet);
            $writer->setDelimiter(',');
            $writer->setEnclosure('"');
            $writer->setUseBOM(true);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s-export-%s.csv"', $entityType, date('Ymd')),
        ]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function buildSpreadsheet(array $rows, string $entityType): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(ucfirst($entityType));

        if (empty($rows)) {
            $sheet->setCellValue('A1', 'No data');
            return $spreadsheet;
        }

        // Header row
        $headers = array_keys($rows[0]);
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $col++;
        }

        // Data rows
        foreach ($rows as $rowIndex => $row) {
            $col = 'A';
            foreach ($row as $value) {
                $sheet->setCellValue($col . ($rowIndex + 2), is_array($value) ? implode(', ', $value) : (string) ($value ?? ''));
                $col++;
            }
        }

        return $spreadsheet;
    }
}

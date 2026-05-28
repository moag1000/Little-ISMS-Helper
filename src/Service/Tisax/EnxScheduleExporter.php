<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Service\ExcelExportService;
use App\Service\Tisax\TisaxMaturityAssessmentService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ENX Assessment Schedule Exporter
 *
 * Emits a 3-tier ENX-compatible XLSX schedule:
 *   Sheet 1 — Information Security (IS)
 *   Sheet 2 — Prototype Protection (Proto)
 *   Sheet 3 — Data Protection (DataPro)
 *
 * The export includes current Reifegrad scores and gap analysis
 * against the must/should/high/veryHigh target levels sourced
 * from the VDA-ISA workbook's `dataSourceMapping` JSON.
 */
final class EnxScheduleExporter
{
    /** Tier → sheet tab label */
    private const TIER_LABELS = [
        'information_security'   => 'Information Security (IS)',
        'prototype_protection'   => 'Prototype Protection (Proto)',
        'data_protection'        => 'Data Protection (DataPro)',
    ];

    /** Column definitions for each tier sheet */
    private const COLUMNS = [
        'Control ID',
        'Question / Anforderung',
        'Must',
        'Should',
        'High',
        'Very High',
        'ISO 27001 Ref',
        'Audit Evidence Hint',
        'Current Reifegrad',
        'Target Reifegrad',
        'Gap',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExcelExportService $excelService,
        private readonly TisaxMaturityAssessmentService $maturityService,
    ) {}

    /**
     * Build and stream the ENX schedule XLSX as a download response.
     */
    public function exportAsResponse(ComplianceFramework $framework, Tenant $tenant): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet($framework, $tenant);

        $response = new StreamedResponse(static function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $filename = sprintf(
            'ENX-Schedule-%s-%s.xlsx',
            $tenant->getName() ?? 'tenant',
            date('Y-m-d'),
        );

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

    /**
     * Build the 3-sheet spreadsheet.
     */
    public function buildSpreadsheet(ComplianceFramework $framework, Tenant $tenant): Spreadsheet
    {
        $spreadsheet = $this->excelService->createSpreadsheet(
            sprintf('ENX Assessment Schedule — %s', $tenant->getName() ?? 'tenant'),
        );

        $repo = $this->em->getRepository(ComplianceRequirement::class);
        /** @var list<ComplianceRequirement> $rows */
        $rows = $repo->findBy([
            'framework'         => $framework,
            'uploadTenant'      => $tenant,
            'requirementSource' => 'tenant_upload',
        ]);

        // Group rows by tier
        $byTier = [];
        foreach ($rows as $req) {
            $tier = $req->getCategory() ?? 'information_security';
            $byTier[$tier][] = $req;
        }

        $sheetIndex = 0;
        foreach (self::TIER_LABELS as $tier => $sheetTitle) {
            if ($sheetIndex === 0) {
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle($sheetTitle);
            } else {
                $sheet = $spreadsheet->createSheet($sheetIndex);
                $sheet->setTitle($sheetTitle);
            }

            $spreadsheet->setActiveSheetIndex($sheetIndex);
            $this->fillSheet($spreadsheet, $byTier[$tier] ?? []);
            $sheetIndex++;
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param list<ComplianceRequirement> $reqs
     */
    private function fillSheet(Spreadsheet $spreadsheet, array $reqs): void
    {
        $this->excelService->addHeaderRow($spreadsheet, self::COLUMNS, 1);

        $levelToInt = array_flip(TisaxMaturityAssessmentService::LEVEL_MAP);
        $row = 2;

        foreach ($reqs as $req) {
            $mapping = $req->getDataSourceMapping() ?? [];
            $current = $req->getMaturityCurrent();
            $target  = $req->getMaturityTarget();

            $currentInt = $current !== null ? ($levelToInt[$current] ?? '') : '';
            $targetInt  = $target  !== null ? ($levelToInt[$target]  ?? '') : '';
            $gap        = ($currentInt !== '' && $targetInt !== '') ? ($targetInt - $currentInt) : '';

            $worksheet = $spreadsheet->getActiveSheet();
            $worksheet->setCellValue('A' . $row, $req->getRequirementId());
            $worksheet->setCellValue('B' . $row, $req->getTitle());
            $worksheet->setCellValue('C' . $row, $mapping['tisax_must'] ?? '');
            $worksheet->setCellValue('D' . $row, $mapping['tisax_should'] ?? '');
            $worksheet->setCellValue('E' . $row, $mapping['tisax_high'] ?? '');
            $worksheet->setCellValue('F' . $row, $mapping['tisax_veryHigh'] ?? '');
            $worksheet->setCellValue('G' . $row, $mapping['iso27001'] ?? '');
            $worksheet->setCellValue('H' . $row, $mapping['auditEvidence'] ?? '');
            $worksheet->setCellValue('I' . $row, $currentInt);
            $worksheet->setCellValue('J' . $row, $targetInt);
            $worksheet->setCellValue('K' . $row, $gap);

            // Highlight gap rows in orange
            if ($gap !== '' && $gap > 0) {
                $worksheet->getStyle('A' . $row . ':K' . $row)->applyFromArray([
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'FFF3CD'],
                    ],
                ]);
            }

            $row++;
        }

        // Auto-size columns A-K
        foreach (range('A', 'K') as $col) {
            $spreadsheet->getActiveSheet()->getColumnDimension($col)->setAutoSize(true);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Service\ExcelExportService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ENX Assessment Schedule Exporter
 *
 * Emits a 3-tier ENX-compatible XLSX schedule:
 *   Sheet 1 — Information Security (IS)       — Reifegrad 0-5
 *   Sheet 2 — Prototype Protection (Proto)    — Reifegrad 0-5
 *   Sheet 3 — Data Protection (DataPro)       — tristate NA / OK / Nicht OK
 *
 * IS and PP tiers use Reifegrad scoring (current + target + gap analysis).
 * DP tier uses 3-state GDPR-conformance (NA / OK / Nicht OK) per ENX workbook Ch. 9.
 */
final class EnxScheduleExporter
{
    /** Tier => translation key suffix for sheet tab label (resolved at runtime via TranslatorInterface) */
    private const TIER_TRANSLATION_KEYS = [
        'information_security' => 'tisax.enx.sheet.information_security',
        'prototype_protection' => 'tisax.enx.sheet.prototype_protection',
        'data_protection'      => 'tisax.enx.sheet.data_protection',
    ];

    /** Columns for IS / PP tier sheets (Reifegrad 0-5) */
    private const COLUMNS_REIFEGRAD = [
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

    /** Columns for DP tier sheet (tristate: NA / OK / Nicht OK) */
    private const COLUMNS_DP = [
        'Control ID',
        'Question / Anforderung',
        'ISO 27001 Ref',
        'Audit Evidence Hint',
        'Konformitaet (NA / OK / Nicht OK)',
        'Ziel (AL1/AL2/AL3)',
    ];

    /** DP state => human-readable display value (ENX sheet format) */
    private const DP_STATE_LABELS = [
        'not_applicable' => 'NA',
        'compliant'      => 'OK',
        'non_compliant'  => 'Nicht OK',
    ];

    /** DP state => cell background colour (hex) for traffic-light styling */
    private const DP_STATE_COLOURS = [
        'not_applicable' => 'D9D9D9', // neutral gray
        'compliant'      => 'C6EFCE', // green
        'non_compliant'  => 'FFC7CE', // red
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExcelExportService $excelService,
        private readonly TisaxMaturityAssessmentService $maturityService,
        private readonly TranslatorInterface $translator,
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
        foreach (self::TIER_TRANSLATION_KEYS as $tier => $translationKey) {
            // Resolve localised sheet title; truncate to Excel's 31-character sheet-name limit
            $sheetTitle = mb_substr(
                $this->translator->trans($translationKey, [], 'tisax_isa'),
                0,
                31
            );

            if ($sheetIndex === 0) {
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle($sheetTitle);
            } else {
                $sheet = $spreadsheet->createSheet($sheetIndex);
                $sheet->setTitle($sheetTitle);
            }

            $spreadsheet->setActiveSheetIndex($sheetIndex);

            if ($tier === 'data_protection') {
                $this->fillDpSheet($spreadsheet, $byTier[$tier] ?? []);
            } else {
                $this->fillReifegradSheet($spreadsheet, $byTier[$tier] ?? []);
            }

            $sheetIndex++;
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Fill an IS or PP tier sheet with Reifegrad 0-5 values.
     *
     * @param list<ComplianceRequirement> $reqs
     */
    private function fillReifegradSheet(Spreadsheet $spreadsheet, array $reqs): void
    {
        $this->excelService->addHeaderRow($spreadsheet, self::COLUMNS_REIFEGRAD, 1);

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
                        'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
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

    /**
     * Fill the Data Protection (Ch. 9) sheet with tristate NA / OK / Nicht OK values.
     *
     * DP tier does NOT use Reifegrad — each requirement is assessed as:
     *   NA (Nicht anwendbar), OK (Erfuellt), Nicht OK (Nicht erfuellt)
     *
     * @param list<ComplianceRequirement> $reqs
     */
    private function fillDpSheet(Spreadsheet $spreadsheet, array $reqs): void
    {
        $this->excelService->addHeaderRow($spreadsheet, self::COLUMNS_DP, 1);

        $row = 2;

        foreach ($reqs as $req) {
            $mapping   = $req->getDataSourceMapping() ?? [];
            $dpState   = $req->getAssessmentStateDp();
            $stateLabel = $dpState !== null ? (self::DP_STATE_LABELS[$dpState] ?? $dpState) : '';

            $worksheet = $spreadsheet->getActiveSheet();
            $worksheet->setCellValue('A' . $row, $req->getRequirementId());
            $worksheet->setCellValue('B' . $row, $req->getTitle());
            $worksheet->setCellValue('C' . $row, $mapping['iso27001'] ?? '');
            $worksheet->setCellValue('D' . $row, $mapping['auditEvidence'] ?? '');
            $worksheet->setCellValue('E' . $row, $stateLabel);
            $worksheet->setCellValue('F' . $row, 'OK'); // target is always compliant per assessmentModels

            // Traffic-light cell fill on the Konformitaet column (E)
            if ($dpState !== null && isset(self::DP_STATE_COLOURS[$dpState])) {
                $worksheet->getStyle('E' . $row)->applyFromArray([
                    'fill' => [
                        'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::DP_STATE_COLOURS[$dpState]],
                    ],
                ]);
            }

            $row++;
        }

        // Auto-size columns A-F
        foreach (range('A', 'F') as $col) {
            $spreadsheet->getActiveSheet()->getColumnDimension($col)->setAutoSize(true);
        }
    }
}

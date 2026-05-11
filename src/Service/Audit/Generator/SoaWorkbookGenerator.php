<?php

declare(strict_types=1);

namespace App\Service\Audit\Generator;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Statement-of-Applicability (SoA) workbook generator.
 *
 * Produces an audit-ready XLSX per ISO 27001:2022 Annex A with five tabs:
 *
 *   Cover                 — Tenant info, framework, generated-at, provenance stamp
 *   Controls              — All 93 Annex-A controls: ID, title, domain, applicability, justification, status
 *   Implementation-Status — Per control: completeness %, last review date, evidence count, effectiveness
 *   Evidence-Links        — Linked evidence documents per control
 *   Auditor-Notes         — Empty columns reserved for the external auditor
 */
final class SoaWorkbookGenerator implements AuditWorkbookGeneratorInterface
{
    public function __construct(
        private readonly ControlRepository $controlRepository,
    ) {
    }

    public function supportsExportType(string $exportType): bool
    {
        return $exportType === 'soa';
    }

    public function generate(Tenant $tenant, array $options = []): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        WorkbookStyleHelper::setDocumentProperties($spreadsheet, (string) $tenant->getName(), 'soa');

        $controls = $this->controlRepository->findBy(
            ['tenant' => $tenant],
            ['controlId' => 'ASC']
        );

        $this->buildCoverSheet($spreadsheet, $tenant, count($controls));
        $this->buildControlsSheet($spreadsheet, $controls);
        $this->buildImplementationStatusSheet($spreadsheet, $controls);
        $this->buildEvidenceLinksSheet($spreadsheet, $controls);
        $this->buildAuditorNotesSheet($spreadsheet, $controls);

        // Activate Cover sheet on open
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildCoverSheet(Spreadsheet $spreadsheet, Tenant $tenant, int $controlCount): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cover');

        WorkbookStyleHelper::writeCoverSheet($sheet, 'Statement of Applicability (SoA)', [
            'Organisation'     => (string) $tenant->getName(),
            'Framework'        => 'ISO/IEC 27001:2022 Annex A',
            'Generated at'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s T'),
            'Total controls'   => (string) $controlCount,
            'Generator'        => WorkbookStyleHelper::GENERATOR_VERSION,
            'Classification'   => 'CONFIDENTIAL — Internal Audit Use Only',
        ]);
    }

    /**
     * @param Control[] $controls
     */
    private function buildControlsSheet(Spreadsheet $spreadsheet, array $controls): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Controls');

        $headers = [
            'Control ID',
            'Control Title',
            'Domain / Category',
            'Applicable (Yes/No)',
            'Justification (incl. exclusion reason)',
            'Implementation Status',
            'Implementation Notes',
        ];

        WorkbookStyleHelper::applyHeaderRow($sheet, $headers);

        $row = 2;
        foreach ($controls as $control) {
            // Explicitly set as string to prevent PhpSpreadsheet auto-casting
            // ISO control IDs like "5.1" to floats.
            $sheet->setCellValueExplicit('A' . $row, $control->getControlId(), DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $row, $control->getName());
            $sheet->setCellValue('C' . $row, $control->getCategory());
            $sheet->setCellValue('D' . $row, $control->isApplicable() ? 'Yes' : 'No');
            $sheet->setCellValue('E' . $row, $control->getJustification() ?? '');
            $sheet->setCellValue('F' . $row, $control->getImplementationStatus() ?? 'not_started');
            $sheet->setCellValue('G' . $row, $control->getImplementationNotes() ?? '');

            // Shade non-applicable rows in light grey
            if (!$control->isApplicable()) {
                $sheet->getStyle('A' . $row . ':G' . $row)->applyFromArray([
                    'fill' => [
                        'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['argb' => 'FFE8E8E8'],
                    ],
                ]);
            }

            $row++;
        }

        WorkbookStyleHelper::autoWidthColumns($sheet);
    }

    /**
     * @param Control[] $controls
     */
    private function buildImplementationStatusSheet(Spreadsheet $spreadsheet, array $controls): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Implementation-Status');

        $headers = [
            'Control ID',
            'Control Title',
            'Owner (Effective)',
            'Completeness (%)',
            'Last Review Date',
            'Evidence Count',
            'Effectiveness',
            'Control Maturity (1-5)',
            'Target Date',
            'Next Review Date',
        ];

        WorkbookStyleHelper::applyHeaderRow($sheet, $headers);

        $row = 2;
        foreach ($controls as $control) {
            $sheet->setCellValueExplicit('A' . $row, $control->getControlId(), DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $row, $control->getName());
            $sheet->setCellValue('C' . $row, $control->getEffectiveResponsiblePerson() ?? '');
            $sheet->setCellValue('D' . $row, $control->getImplementationPercentage() ?? 0);
            $sheet->setCellValue('E' . $row, $control->getLastReviewDate()?->format('Y-m-d') ?? '');
            $sheet->setCellValue('F' . $row, $control->getEvidenceDocuments()->count());
            $sheet->setCellValue('G' . $row, $control->getEffectiveness() ?? '');
            $sheet->setCellValue('H' . $row, $control->getControlMaturity() ?? '');
            $sheet->setCellValue('I' . $row, $control->getTargetDate()?->format('Y-m-d') ?? '');
            $sheet->setCellValue('J' . $row, $control->getNextReviewDate()?->format('Y-m-d') ?? '');

            // Colour completeness cell: green ≥80, yellow 50-79, red <50
            $pct = (int) ($control->getImplementationPercentage() ?? 0);
            $color = match (true) {
                $pct >= 80 => WorkbookStyleHelper::RISK_GREEN,
                $pct >= 50 => WorkbookStyleHelper::RISK_YELLOW,
                default    => WorkbookStyleHelper::RISK_RED,
            };
            $sheet->getStyle('D' . $row)->applyFromArray([
                'fill' => [
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF' . $color],
                ],
            ]);

            $row++;
        }

        WorkbookStyleHelper::autoWidthColumns($sheet);
    }

    /**
     * @param Control[] $controls
     */
    private function buildEvidenceLinksSheet(Spreadsheet $spreadsheet, array $controls): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Evidence-Links');

        $headers = [
            'Control ID',
            'Control Title',
            'Document Title',
            'Document Type',
            'Document Category',
            'Created At',
        ];

        WorkbookStyleHelper::applyHeaderRow($sheet, $headers);

        $row = 2;
        foreach ($controls as $control) {
            $evidenceDocs = $control->getEvidenceDocuments();
            if ($evidenceDocs->isEmpty()) {
                $sheet->setCellValueExplicit('A' . $row, $control->getControlId(), DataType::TYPE_STRING);
                $sheet->setCellValue('B' . $row, $control->getName());
                $sheet->setCellValue('C' . $row, '(no evidence linked)');
                $row++;
                continue;
            }

            foreach ($evidenceDocs as $doc) {
                $sheet->setCellValueExplicit('A' . $row, $control->getControlId(), DataType::TYPE_STRING);
                $sheet->setCellValue('B' . $row, $control->getName());
                $sheet->setCellValue('C' . $row, $doc->getTitle());
                $sheet->setCellValue('D' . $row, method_exists($doc, 'getDocumentType') ? (string) $doc->getDocumentType() : '');
                $sheet->setCellValue('E' . $row, method_exists($doc, 'getCategory') ? (string) $doc->getCategory() : '');
                $sheet->setCellValue('F' . $row, method_exists($doc, 'getCreatedAt') ? $doc->getCreatedAt()?->format('Y-m-d') ?? '' : '');
                $row++;
            }
        }

        WorkbookStyleHelper::autoWidthColumns($sheet);
    }

    /**
     * @param Control[] $controls
     */
    private function buildAuditorNotesSheet(Spreadsheet $spreadsheet, array $controls): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Auditor-Notes');

        $headers = [
            'Control ID',
            'Control Title',
            'Applicable',
            'Status',
            'Auditor Observation',
            'Evidence Reviewed (Yes/No)',
            'Conformity Decision',
            'NC Reference',
            'Auditor Initials',
        ];

        WorkbookStyleHelper::applyHeaderRow($sheet, $headers);

        $row = 2;
        foreach ($controls as $control) {
            $sheet->setCellValueExplicit('A' . $row, $control->getControlId(), DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $row, $control->getName());
            $sheet->setCellValue('C' . $row, $control->isApplicable() ? 'Yes' : 'No');
            $sheet->setCellValue('D' . $row, $control->getImplementationStatus() ?? 'not_started');
            // Columns E-I left empty for the auditor
            $row++;
        }

        // Light-blue tint on auditor columns
        if ($row > 2) {
            $lastRow = $row - 1;
            $sheet->getStyle('E2:I' . $lastRow)->applyFromArray([
                'fill' => [
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFDDEEFF'],
                ],
            ]);
        }

        WorkbookStyleHelper::autoWidthColumns($sheet);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Audit\Generator;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Control-Implementation workbook generator.
 *
 * Produces an XLSX with two tabs:
 *
 *   Cover           — Tenant info and provenance stamp
 *   Implementations — One row per Control: ID, title, owner, status,
 *                     completeness%, verification date, evidence count,
 *                     effectiveness, overdue flag
 */
final class ControlImplementationWorkbookGenerator implements AuditWorkbookGeneratorInterface
{
    public function __construct(
        private readonly ControlRepository $controlRepository,
    ) {
    }

    public function supportsExportType(string $exportType): bool
    {
        return $exportType === 'control-implementation';
    }

    public function generate(Tenant $tenant, array $options = []): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        WorkbookStyleHelper::setDocumentProperties($spreadsheet, (string) $tenant->getName(), 'control-implementation');

        $controls = $this->controlRepository->findBy(
            ['tenant' => $tenant],
            ['controlId' => 'ASC']
        );

        $this->buildCoverSheet($spreadsheet, $tenant, count($controls));
        $this->buildImplementationsSheet($spreadsheet, $controls);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildCoverSheet(Spreadsheet $spreadsheet, Tenant $tenant, int $count): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cover');

        WorkbookStyleHelper::writeCoverSheet($sheet, 'Control Implementation Report', [
            'Organisation'   => (string) $tenant->getName(),
            'Framework'      => 'ISO/IEC 27001:2022 — Control Implementation',
            'Generated at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s T'),
            'Total controls' => (string) $count,
            'Generator'      => WorkbookStyleHelper::GENERATOR_VERSION,
            'Classification' => 'CONFIDENTIAL — Internal Use Only',
        ]);
    }

    /**
     * @param Control[] $controls
     */
    private function buildImplementationsSheet(Spreadsheet $spreadsheet, array $controls): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Implementations');

        $headers = [
            'Control ID',
            'Control Title',
            'Category',
            'Applicable',
            'Owner (Effective)',
            'Implementation Status',
            'Completeness (%)',
            'Last Review Date',
            'Verification Date',
            'Evidence Count',
            'Effectiveness',
            'Control Maturity (1-5)',
            'Overdue?',
            'Target Date',
        ];

        WorkbookStyleHelper::applyHeaderRow($sheet, $headers);

        $today = new \DateTimeImmutable('today');
        $row   = 2;

        foreach ($controls as $control) {
            $isOverdue = false;
            $nextReview = $control->getNextReviewDate();
            if ($nextReview instanceof \DateTimeInterface && $nextReview < $today) {
                $isOverdue = true;
            }

            $sheet->setCellValueExplicit('A' . $row, $control->getControlId(), DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $row, $control->getName());
            $sheet->setCellValue('C' . $row, $control->getCategory());
            $sheet->setCellValue('D' . $row, $control->isApplicable() ? 'Yes' : 'No');
            $sheet->setCellValue('E' . $row, $control->getEffectiveResponsiblePerson() ?? '');
            $sheet->setCellValue('F' . $row, $control->getImplementationStatus() ?? 'not_started');
            $sheet->setCellValue('G' . $row, $control->getImplementationPercentage() ?? 0);
            $sheet->setCellValue('H' . $row, $control->getLastReviewDate()?->format('Y-m-d') ?? '');
            $sheet->setCellValue('I' . $row, $control->getLastEffectivenessTest()?->format('Y-m-d') ?? '');
            $sheet->setCellValue('J' . $row, $control->getEvidenceDocuments()->count());
            $sheet->setCellValue('K' . $row, $control->getEffectiveness() ?? '');
            $sheet->setCellValue('L' . $row, $control->getControlMaturity() ?? '');
            $sheet->setCellValue('M' . $row, $isOverdue ? 'YES' : 'no');
            $sheet->setCellValue('N' . $row, $control->getTargetDate()?->format('Y-m-d') ?? '');

            // Colour completeness cell
            $pct = (int) ($control->getImplementationPercentage() ?? 0);
            $bgColor = match (true) {
                $pct >= 80 => WorkbookStyleHelper::RISK_GREEN,
                $pct >= 50 => WorkbookStyleHelper::RISK_YELLOW,
                default    => WorkbookStyleHelper::RISK_RED,
            };
            $sheet->getStyle('G' . $row)->applyFromArray([
                'fill' => [
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF' . $bgColor],
                ],
            ]);

            // Highlight overdue flag cell in red
            if ($isOverdue) {
                $sheet->getStyle('M' . $row)->applyFromArray([
                    'font' => [
                        'bold'  => true,
                        'color' => ['argb' => 'FFCC0000'],
                    ],
                ]);
            }

            $row++;
        }

        WorkbookStyleHelper::autoWidthColumns($sheet);
    }
}

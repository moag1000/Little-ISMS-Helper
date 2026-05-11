<?php

declare(strict_types=1);

namespace App\Service\Audit\Generator;

use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Compliance-Fulfillment workbook generator.
 *
 * Produces an XLSX with two tabs:
 *
 *   Cover        — Tenant info and provenance stamp
 *   Fulfillments — One row per ComplianceRequirementFulfillment, grouped by
 *                  framework: framework name, requirement ID, requirement title,
 *                  applicable, fulfillment status, completeness%, evidence
 *                  description, last review date, next review date
 */
final class ComplianceFulfillmentWorkbookGenerator implements AuditWorkbookGeneratorInterface
{
    public function __construct(
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
    ) {
    }

    public function supportsExportType(string $exportType): bool
    {
        return $exportType === 'compliance-fulfillment';
    }

    public function generate(Tenant $tenant, array $options = []): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        WorkbookStyleHelper::setDocumentProperties($spreadsheet, (string) $tenant->getName(), 'compliance-fulfillment');

        $fulfillments = $this->fulfillmentRepository->findBy(
            ['tenant' => $tenant],
        );

        // Group by framework for ordered output
        $byFramework = $this->groupByFramework($fulfillments);

        $this->buildCoverSheet($spreadsheet, $tenant, count($fulfillments), array_keys($byFramework));
        $this->buildFulfillmentsSheet($spreadsheet, $byFramework);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildCoverSheet(
        Spreadsheet $spreadsheet,
        Tenant $tenant,
        int $totalRows,
        array $frameworks,
    ): void {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cover');

        WorkbookStyleHelper::writeCoverSheet($sheet, 'Compliance Fulfillment Report', [
            'Organisation'         => (string) $tenant->getName(),
            'Frameworks covered'   => implode(', ', $frameworks),
            'Generated at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s T'),
            'Total fulfillments'   => (string) $totalRows,
            'Generator'            => WorkbookStyleHelper::GENERATOR_VERSION,
            'Classification'       => 'CONFIDENTIAL — Internal Audit Use Only',
        ]);
    }

    /**
     * @param array<string, ComplianceRequirementFulfillment[]> $byFramework
     */
    private function buildFulfillmentsSheet(Spreadsheet $spreadsheet, array $byFramework): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Fulfillments');

        $headers = [
            'Framework',
            'Requirement ID',
            'Requirement Title',
            'Category',
            'Applicable',
            'Fulfillment Status',
            'Completeness (%)',
            'Evidence Description',
            'Last Review Date',
            'Next Review Date',
            'Responsible Person',
            'Overdue Review?',
        ];

        WorkbookStyleHelper::applyHeaderRow($sheet, $headers);

        $row    = 2;
        $today  = new \DateTimeImmutable('today');
        $lastFw = null;

        foreach ($byFramework as $frameworkName => $fulfillments) {
            foreach ($fulfillments as $fulfillment) {
                $requirement = $fulfillment->getRequirement();

                $isOverdue = false;
                $nextReview = $fulfillment->getNextReviewDate();
                if ($nextReview instanceof \DateTimeInterface && $nextReview < $today) {
                    $isOverdue = true;
                }

                $sheet->setCellValue('A' . $row, $frameworkName);
                $sheet->setCellValue('B' . $row, $requirement?->getRequirementId() ?? '');
                $sheet->setCellValue('C' . $row, $requirement?->getTitle() ?? '');
                $sheet->setCellValue('D' . $row, $requirement?->getCategory() ?? '');
                $sheet->setCellValue('E' . $row, $fulfillment->isApplicable() ? 'Yes' : 'No');
                $sheet->setCellValue('F' . $row, $fulfillment->getStatus());
                $sheet->setCellValue('G' . $row, $fulfillment->getFulfillmentPercentage());
                $sheet->setCellValue('H' . $row, $fulfillment->getEvidenceDescription() ?? '');
                $sheet->setCellValue('I' . $row, $fulfillment->getLastReviewDate()?->format('Y-m-d') ?? '');
                $sheet->setCellValue('J' . $row, $fulfillment->getNextReviewDate()?->format('Y-m-d') ?? '');
                $sheet->setCellValue('K' . $row, $fulfillment->getEffectiveResponsiblePerson() ?? '');
                $sheet->setCellValue('L' . $row, $isOverdue ? 'YES' : 'no');

                // Colour completeness cell
                $pct = $fulfillment->getFulfillmentPercentage();
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

                // Shade framework separator rows (first row of each new framework)
                if ($frameworkName !== $lastFw) {
                    $sheet->getStyle('A' . $row)->applyFromArray([
                        'font' => ['bold' => true],
                    ]);
                    $lastFw = $frameworkName;
                }

                if ($isOverdue) {
                    $sheet->getStyle('L' . $row)->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['argb' => 'FFCC0000']],
                    ]);
                }

                $row++;
            }
        }

        WorkbookStyleHelper::autoWidthColumns($sheet);
    }

    /**
     * Group fulfillments by framework name (alphabetically sorted).
     *
     * @param ComplianceRequirementFulfillment[] $fulfillments
     * @return array<string, ComplianceRequirementFulfillment[]>
     */
    private function groupByFramework(array $fulfillments): array
    {
        $byFramework = [];
        foreach ($fulfillments as $fulfillment) {
            $req = $fulfillment->getRequirement();
            $fwName = $req?->getFramework()?->getName() ?? 'Unknown Framework';
            $byFramework[$fwName][] = $fulfillment;
        }

        ksort($byFramework);

        return $byFramework;
    }
}

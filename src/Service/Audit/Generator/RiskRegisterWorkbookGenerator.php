<?php

declare(strict_types=1);

namespace App\Service\Audit\Generator;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\RiskRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Risk-Register workbook generator.
 *
 * Produces an XLSX with two tabs:
 *
 *   Cover  — Tenant info and provenance stamp
 *   Risks  — One row per Risk: ID, name, category, inherent impact/likelihood/score,
 *             residual impact/likelihood/score, treatment strategy, risk owner,
 *             status, financial impact, requires DPIA, next review date
 *
 * Residual-score cells are colour-coded per the 5×5 risk matrix thresholds:
 *   green ≤6  |  yellow 7-12  |  orange 13-19  |  red 20-25
 */
final class RiskRegisterWorkbookGenerator implements AuditWorkbookGeneratorInterface
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
    ) {
    }

    public function supportsExportType(string $exportType): bool
    {
        return $exportType === 'risk-register';
    }

    public function generate(Tenant $tenant, array $options = []): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        WorkbookStyleHelper::setDocumentProperties($spreadsheet, (string) $tenant->getName(), 'risk-register');

        $risks = $this->riskRepository->findBy(
            ['tenant' => $tenant],
            ['id' => 'ASC']
        );

        $this->buildCoverSheet($spreadsheet, $tenant, count($risks));
        $this->buildRisksSheet($spreadsheet, $risks);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function buildCoverSheet(Spreadsheet $spreadsheet, Tenant $tenant, int $count): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cover');

        WorkbookStyleHelper::writeCoverSheet($sheet, 'Risk Register', [
            'Organisation'  => (string) $tenant->getName(),
            'Standard'      => 'ISO/IEC 27001:2022 Clause 6.1.2 — Risk Assessment',
            'Generated at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s T'),
            'Total risks'   => (string) $count,
            'Score matrix'  => '5×5 (Probability × Impact), residual colour-coded',
            'Generator'     => WorkbookStyleHelper::GENERATOR_VERSION,
            'Classification' => 'CONFIDENTIAL — Internal Audit Use Only',
        ]);
    }

    /**
     * @param Risk[] $risks
     */
    private function buildRisksSheet(Spreadsheet $spreadsheet, array $risks): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Risks');

        $headers = [
            'Risk ID',
            'Risk Name / Title',
            'Category',
            'Inherent Likelihood (1-5)',
            'Inherent Impact (1-5)',
            'Inherent Score',
            'Residual Likelihood (1-5)',
            'Residual Impact (1-5)',
            'Residual Score',
            'Treatment Strategy',
            'Treatment Description',
            'Risk Owner',
            'Status',
            'Formally Accepted',
            'Requires DPIA',
            'Next Review Date',
            'Involves Personal Data',
        ];

        WorkbookStyleHelper::applyHeaderRow($sheet, $headers);

        $row = 2;
        foreach ($risks as $risk) {
            $inherentScore = $risk->getInherentRiskLevel();
            $residualScore = $risk->getResidualRiskLevel();

            $sheet->setCellValue('A' . $row, $risk->getId());
            $sheet->setCellValue('B' . $row, $risk->getTitle());
            $sheet->setCellValue('C' . $row, $risk->getCategory());
            $sheet->setCellValue('D' . $row, $risk->getProbability());
            $sheet->setCellValue('E' . $row, $risk->getImpact());
            $sheet->setCellValue('F' . $row, $inherentScore);
            $sheet->setCellValue('G' . $row, $risk->getResidualProbability());
            $sheet->setCellValue('H' . $row, $risk->getResidualImpact());
            $sheet->setCellValue('I' . $row, $residualScore);
            $sheet->setCellValue('J' . $row, $risk->getTreatmentStrategy()?->value ?? '');
            $sheet->setCellValue('K' . $row, $risk->getTreatmentDescription() ?? '');
            $sheet->setCellValue('L' . $row, $risk->getEffectiveRiskOwner() ?? '');
            $sheet->setCellValue('M' . $row, $risk->getStatus()?->value ?? '');
            $sheet->setCellValue('N' . $row, $risk->isFormallyAccepted() ? 'Yes' : 'No');
            $sheet->setCellValue('O' . $row, $risk->isRequiresDPIA() ? 'Yes' : 'No');
            $sheet->setCellValue('P' . $row, $risk->getReviewDate()?->format('Y-m-d') ?? '');
            $sheet->setCellValue('Q' . $row, $risk->isInvolvesPersonalData() ? 'Yes' : 'No');

            // Colour-code inherent score (column F)
            WorkbookStyleHelper::applyRiskScoreColor($sheet, 'F' . $row, $inherentScore);

            // Colour-code residual score (column I) — primary visual indicator
            WorkbookStyleHelper::applyRiskScoreColor($sheet, 'I' . $row, $residualScore);

            $row++;
        }

        WorkbookStyleHelper::autoWidthColumns($sheet);
    }
}

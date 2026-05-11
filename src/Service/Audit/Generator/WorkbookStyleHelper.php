<?php

declare(strict_types=1);

namespace App\Service\Audit\Generator;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Shared formatting utilities for all ALVA audit-workbook generators.
 *
 * Extracted into a helper so every generator produces a consistent look
 * without duplicating styling code.
 */
final class WorkbookStyleHelper
{
    // ── Brand colours (Aurora-compatible neutrals) ───────────────────────────

    /** Header background: Aurora primary-dark equivalent (dark slate-blue). */
    public const HEADER_BG   = '1E3A5F';
    public const HEADER_FONT = 'FFFFFF';

    /** Section sub-header background. */
    public const SUBHEADER_BG   = '2F6EA3';
    public const SUBHEADER_FONT = 'FFFFFF';

    /** Cover-sheet accent colour. */
    public const COVER_ACCENT = '0D6EFD';

    /** Risk colour coding (residual score cells). */
    public const RISK_GREEN  = 'C6EFCE'; // score ≤ 6
    public const RISK_YELLOW = 'FFEB9C'; // score 7–12
    public const RISK_ORANGE = 'FFBF86'; // score 13–19
    public const RISK_RED    = 'FF8080'; // score 20–25

    // ── Provenance ───────────────────────────────────────────────────────────

    /** Generator version stamp embedded in every cover sheet. */
    public const GENERATOR_VERSION = 'ALVA-Audit-Workbook v1';

    // ── Public helpers ───────────────────────────────────────────────────────

    /**
     * Apply bold white-on-dark-blue header style to a row.
     *
     * @param string[] $headers Column headers (1 per column, starting at column A)
     * @param int      $row     1-based row number
     */
    public static function applyHeaderRow(Worksheet $sheet, array $headers, int $row = 1): void
    {
        foreach ($headers as $col => $header) {
            $cellCoord = Coordinate::stringFromColumnIndex($col + 1) . $row;
            $sheet->setCellValue($cellCoord, $header);
        }

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $range = 'A' . $row . ':' . $lastCol . $row;

        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['argb' => 'FF' . self::HEADER_FONT],
                'size'  => 11,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF' . self::HEADER_BG],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical'   => Alignment::VERTICAL_CENTER,
                'wrapText'   => false,
            ],
        ]);

        // Freeze the header row
        $sheet->freezePane('A' . ($row + 1));
    }

    /**
     * Set auto-width for all used columns in the sheet.
     * Caps column width at 60 chars to prevent excessively wide columns.
     */
    public static function autoWidthColumns(Worksheet $sheet, int $minWidth = 10, int $maxWidth = 60): void
    {
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        // PhpSpreadsheet's auto-size is calculated lazily on save; we can set
        // reasonable defaults as a fallback for columns with no data yet.
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $colLetter = Coordinate::stringFromColumnIndex($col);
            $dim = $sheet->getColumnDimension($colLetter);
            if ($dim->getWidth() > $maxWidth) {
                $dim->setAutoSize(false);
                $dim->setWidth($maxWidth);
            }
        }
    }

    /**
     * Write a labelled info row on a Cover sheet and return the next row index.
     */
    public static function writeCoverRow(Worksheet $sheet, int $row, string $label, string $value): int
    {
        $sheet->setCellValue('A' . $row, $label);
        $sheet->setCellValue('B' . $row, $value);

        $sheet->getStyle('A' . $row)->applyFromArray([
            'font' => ['bold' => true],
        ]);

        return $row + 1;
    }

    /**
     * Write a standard ALVA cover sheet onto the given worksheet.
     *
     * @param array<string, string> $meta  Key-value pairs rendered as info rows
     */
    public static function writeCoverSheet(Worksheet $sheet, string $title, array $meta): void
    {
        // Title row
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->applyFromArray([
            'font' => [
                'bold'  => true,
                'size'  => 16,
                'color' => ['argb' => 'FF' . self::COVER_ACCENT],
            ],
        ]);
        $sheet->mergeCells('A1:D1');

        // Provenance stamp
        $sheet->setCellValue('A2', 'Generated by');
        $sheet->setCellValue('B2', self::GENERATOR_VERSION);
        $sheet->getStyle('A2')->applyFromArray(['font' => ['bold' => true, 'italic' => true]]);
        $sheet->getStyle('B2')->applyFromArray(['font' => ['italic' => true, 'color' => ['argb' => 'FF666666']]]);

        $row = 4; // blank row 3 as spacer
        foreach ($meta as $label => $value) {
            $row = self::writeCoverRow($sheet, $row, $label, $value);
        }

        // Auto-width A and B columns
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(55);
    }

    /**
     * Apply risk-score fill colour to a single cell.
     * Thresholds: ≤6 green, 7-12 yellow, 13-19 orange, 20-25 red.
     */
    public static function applyRiskScoreColor(Worksheet $sheet, string $cellCoord, int $score): void
    {
        $bgColor = match (true) {
            $score <= 6  => self::RISK_GREEN,
            $score <= 12 => self::RISK_YELLOW,
            $score <= 19 => self::RISK_ORANGE,
            default      => self::RISK_RED,
        };

        $sheet->getStyle($cellCoord)->applyFromArray([
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF' . $bgColor],
            ],
        ]);
    }

    /**
     * Set document properties on the Spreadsheet for audit-trail provenance.
     */
    public static function setDocumentProperties(
        Spreadsheet $spreadsheet,
        string $tenantName,
        string $exportType,
    ): void {
        $props = $spreadsheet->getProperties();
        $props->setCreator('ALVA Audit-Workbook Generator');
        $props->setLastModifiedBy('ALVA Audit-Workbook Generator');
        $props->setTitle(sprintf('%s — %s', strtoupper($exportType), $tenantName));
        $props->setSubject(sprintf('Audit workbook: %s', $exportType));
        $props->setDescription(sprintf(
            'Generated by %s on %s for tenant: %s',
            self::GENERATOR_VERSION,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $tenantName
        ));
        $props->setKeywords('audit isms compliance ' . $exportType);
        $props->setCategory('Audit');
    }
}

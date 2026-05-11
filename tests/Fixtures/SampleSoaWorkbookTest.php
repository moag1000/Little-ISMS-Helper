<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates the SoA reference XLSX created for F40.5.
 *
 * The reference workbook lives in fixtures/audit-workbooks/sample-soa.xlsx.
 * It documents the expected sheet-layout produced by SoaWorkbookGenerator and
 * serves as a regression baseline for future generator changes.
 */
class SampleSoaWorkbookTest extends TestCase
{
    private static string $fixturePath;

    public static function setUpBeforeClass(): void
    {
        self::$fixturePath = dirname(__DIR__, 2) . '/fixtures/audit-workbooks/sample-soa.xlsx';
    }

    #[Test]
    public function testSampleSoaXlsxHasExpected5Sheets(): void
    {
        $this->assertFileExists(self::$fixturePath, 'sample-soa.xlsx must exist (F40.5).');

        $spreadsheet = IOFactory::load(self::$fixturePath);

        $this->assertSame(
            5,
            $spreadsheet->getSheetCount(),
            'SoA workbook must contain exactly 5 sheets.',
        );

        $expectedSheets = ['Cover', 'Controls', 'Implementation-Status', 'Evidence-Links', 'Auditor-Notes'];

        foreach ($expectedSheets as $index => $expectedTitle) {
            $sheet = $spreadsheet->getSheet($index);
            $this->assertNotNull($sheet, sprintf('Sheet at index %d must exist.', $index));
            $this->assertSame(
                $expectedTitle,
                $sheet->getTitle(),
                sprintf('Sheet %d must be named "%s".', $index, $expectedTitle),
            );
        }
    }

    #[Test]
    public function testSampleSoaCoverSheetHasTitle(): void
    {
        $spreadsheet = IOFactory::load(self::$fixturePath);
        $coverSheet  = $spreadsheet->getSheetByName('Cover');

        $this->assertNotNull($coverSheet, 'Cover sheet must exist.');

        $title = (string) $coverSheet->getCell('A1')->getValue();
        $this->assertStringContainsString(
            'Statement of Applicability',
            $title,
            'Cover sheet cell A1 must contain "Statement of Applicability".',
        );
    }

    #[Test]
    public function testSampleSoaControlsSheetHasExpectedHeaders(): void
    {
        $spreadsheet    = IOFactory::load(self::$fixturePath);
        $controlsSheet  = $spreadsheet->getSheetByName('Controls');

        $this->assertNotNull($controlsSheet, 'Controls sheet must exist.');

        $expectedHeaders = ['Control ID', 'Title', 'Domain', 'Applicability', 'Justification', 'Implementation Status'];
        foreach ($expectedHeaders as $colIdx => $expectedHeader) {
            $col    = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
            $actual = (string) $controlsSheet->getCell($col . '1')->getValue();
            $this->assertSame(
                $expectedHeader,
                $actual,
                sprintf('Controls sheet column %s header mismatch.', $col),
            );
        }
    }

    #[Test]
    public function testSampleSoaImplementationStatusSheetHasExpectedHeaders(): void
    {
        $spreadsheet = IOFactory::load(self::$fixturePath);
        $implSheet   = $spreadsheet->getSheetByName('Implementation-Status');

        $this->assertNotNull($implSheet, 'Implementation-Status sheet must exist.');

        $expectedHeaders = ['Control ID', 'Title', 'Completeness (%)', 'Last Review Date', 'Evidence Count', 'Effectiveness'];
        foreach ($expectedHeaders as $colIdx => $expectedHeader) {
            $col    = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
            $actual = (string) $implSheet->getCell($col . '1')->getValue();
            $this->assertSame(
                $expectedHeader,
                $actual,
                sprintf('Implementation-Status sheet column %s header mismatch.', $col),
            );
        }
    }
}

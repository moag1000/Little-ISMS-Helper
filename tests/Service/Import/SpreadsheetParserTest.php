<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\Dto\ParsedSpreadsheet;
use App\Service\Import\SpreadsheetParser;
use ErrorException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for SpreadsheetParser.
 *
 * XLSX fixtures are generated on-the-fly via phpspreadsheet so no binary
 * files need to be committed. CSV fixtures are written as strings.
 */
#[AllowMockObjectsWithoutExpectations]
final class SpreadsheetParserTest extends TestCase
{
    private SpreadsheetParser $parser;

    /** @var string[] Temp files to clean up after each test */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->parser = new SpreadsheetParser(new NullLogger());
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // -------------------------------------------------------------------------
    // XLSX tests
    // -------------------------------------------------------------------------

    #[Test]
    public function testXlsxBasicHeadersAndRowsExtracted(): void
    {
        $xlsxPath = $this->buildXlsx([
            ['Name', 'Type', 'Owner'],
            ['Server A', 'Hardware', 'IT-Team'],
            ['Switch B', 'Network', 'NOC'],
        ]);

        $result = $this->parser->parse($xlsxPath);

        self::assertInstanceOf(ParsedSpreadsheet::class, $result);
        self::assertSame(['Name', 'Type', 'Owner'], $result->headers);
        self::assertCount(2, $result->rows);
        self::assertSame('Server A', $result->rows[0]['Name']);
        self::assertSame('Switch B', $result->rows[1]['Name']);
        self::assertSame(2, $result->totalRowCount);
        self::assertFalse($result->isEmpty());
    }

    #[Test]
    public function testXlsxEmptyRowsAreSkipped(): void
    {
        $xlsxPath = $this->buildXlsx([
            ['Name', 'Type'],
            ['Server A', 'Hardware'],
            ['', ''],         // empty row — should be skipped
            ['Firewall C', 'Network'],
        ]);

        $result = $this->parser->parse($xlsxPath);

        self::assertCount(2, $result->rows);
        self::assertSame(2, $result->totalRowCount);
    }

    #[Test]
    public function testXlsxEmptySheetReturnsEmptyDto(): void
    {
        $xlsxPath = $this->buildXlsx([
            ['', '', ''],
            ['', '', ''],
        ]);

        $result = $this->parser->parse($xlsxPath);

        self::assertTrue($result->isEmpty());
        self::assertCount(0, $result->headers);
        self::assertCount(0, $result->rows);
        self::assertTrue($result->hasWarnings());
    }

    #[Test]
    public function testXlsxSpecificSheetName(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Assets');
        $sheet1->fromArray([['Name', 'Type'], ['Server A', 'Hardware']], null, 'A1', true);

        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Risks');
        $sheet2->fromArray([['Risk', 'Likelihood'], ['Breach', 'High']], null, 'A1', true);

        $xlsxPath = $this->writeXlsx($spreadsheet);

        $result = $this->parser->parse($xlsxPath, 'Risks');

        self::assertSame('Risks', $result->sheetName);
        self::assertSame(['Risk', 'Likelihood'], $result->headers);
        self::assertSame('Breach', $result->rows[0]['Risk']);
    }

    #[Test]
    public function testXlsxUnknownSheetThrows(): void
    {
        $xlsxPath = $this->buildXlsx([['Col1'], ['Val1']]);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageMatches('/sheet.*not found/i');

        $this->parser->parse($xlsxPath, 'NonExistentSheet');
    }

    #[Test]
    public function testXlsxOverHardLimitThrows(): void
    {
        // Build header row + (MAX + 1) data rows
        $rows = [['Name', 'Value']];
        $limit = SpreadsheetParser::MAX_ROWS_HARD_LIMIT;
        for ($i = 1; $i <= $limit + 1; $i++) {
            $rows[] = ['Row ' . $i, (string) $i];
        }

        $xlsxPath = $this->buildXlsx($rows);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageMatches('/hard limit/i');

        $this->parser->parse($xlsxPath);
    }

    #[Test]
    public function testFileNotFoundThrows(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageMatches('/not found or not readable/i');

        $this->parser->parse('/tmp/this_file_does_not_exist_9876543210.xlsx');
    }

    #[Test]
    public function testUnsupportedExtensionThrows(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sp_test_') . '.docx';
        file_put_contents($tmpFile, 'not a spreadsheet');
        $this->tempFiles[] = $tmpFile;

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessageMatches('/unsupported file extension/i');

        $this->parser->parse($tmpFile);
    }

    // -------------------------------------------------------------------------
    // CSV tests
    // -------------------------------------------------------------------------

    #[Test]
    public function testCsvCommaDelimiterParsed(): void
    {
        $csvPath = $this->writeCsvFile("Name,Type,Owner\nServer A,Hardware,IT-Team\nSwitch B,Network,NOC\n");

        $result = $this->parser->parse($csvPath);

        self::assertSame(['Name', 'Type', 'Owner'], $result->headers);
        self::assertCount(2, $result->rows);
        self::assertSame('Server A', $result->rows[0]['Name']);
        self::assertSame('NOC', $result->rows[1]['Owner']);
        self::assertSame('CSV', $result->sheetName);
    }

    #[Test]
    public function testCsvSemicolonDelimiterAutoDetected(): void
    {
        $csvPath = $this->writeCsvFile("Name;Typ;Verantwortlich\nServer A;Hardware;IT-Team\nSwitch B;Netzwerk;NOC\n");

        $result = $this->parser->parse($csvPath);

        self::assertSame(['Name', 'Typ', 'Verantwortlich'], $result->headers);
        self::assertCount(2, $result->rows);
        self::assertSame('Hardware', $result->rows[0]['Typ']);
    }

    #[Test]
    public function testCsvTabDelimiterAutoDetected(): void
    {
        $csvPath = $this->writeCsvFile("Name\tType\tOwner\nServer A\tHardware\tIT-Team\n");

        $result = $this->parser->parse($csvPath);

        self::assertSame(['Name', 'Type', 'Owner'], $result->headers);
        self::assertCount(1, $result->rows);
        self::assertSame('IT-Team', $result->rows[0]['Owner']);
    }

    #[Test]
    public function testCsvUtf8BomIsStripped(): void
    {
        // UTF-8 BOM: 0xEF 0xBB 0xBF
        $bomContent = "\xEF\xBB\xBFName,Type\nServer A,Hardware\n";
        $csvPath = $this->writeCsvFile($bomContent);

        $result = $this->parser->parse($csvPath);

        // Headers should NOT contain the BOM prefix
        self::assertSame('Name', $result->headers[0]);
        self::assertTrue($result->hasWarnings(), 'BOM detection should add a warning.');
        $allWarnings = implode(' ', $result->warnings);
        self::assertStringContainsString('BOM', $allWarnings);
    }

    #[Test]
    public function testCsvPipeDelimiterAutoDetected(): void
    {
        $csvPath = $this->writeCsvFile("Name|Type|Owner\nServer A|Hardware|IT-Team\n");

        $result = $this->parser->parse($csvPath);

        self::assertSame(['Name', 'Type', 'Owner'], $result->headers);
        self::assertCount(1, $result->rows);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an XLSX file from a 2D array and return its path.
     *
     * @param array<int, array<int, string|int|float|null>> $data
     */
    private function buildXlsx(array $data): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($data, null, 'A1', true);

        return $this->writeXlsx($spreadsheet);
    }

    /**
     * Write a Spreadsheet object to a temp XLSX file and return its path.
     */
    private function writeXlsx(Spreadsheet $spreadsheet): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sp_test_') . '.xlsx';
        $this->tempFiles[] = $tmpFile;

        $writer = new XlsxWriter($spreadsheet);
        $writer->save($tmpFile);

        return $tmpFile;
    }

    /**
     * Write a CSV string to a temp file and return its path.
     */
    private function writeCsvFile(string $content): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sp_csv_test_') . '.csv';
        $this->tempFiles[] = $tmpFile;
        file_put_contents($tmpFile, $content);

        return $tmpFile;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\Import\SafeSpreadsheetReader;
use ErrorException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S11: uploaded workbooks are opened only through SafeSpreadsheetReader — fixed reader
 * type, zip limits before inflating, read filter on rows/columns.
 */
final class SpreadsheetUploadReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    #[Test]
    public function noCodeInSrcPicksTheReaderFromTheFileContent(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php'
                || str_ends_with($file->getPathname(), 'src/Service/Import/SafeSpreadsheetReader.php')
            ) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/IOFactory::(createReaderForFile|load|identify)\s*\(/', $source) === 1) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders, 'Open uploaded workbooks via App\Service\Import\SafeSpreadsheetReader.');
    }

    #[Test]
    public function aTypeTheCallerDoesNotAcceptIsRefused(): void
    {
        $path = $this->workbook(3);

        $this->expectException(ErrorException::class);
        new SafeSpreadsheetReader()->load($path, ['xlsx'], 10, type: 'ods');
    }

    #[Test]
    public function contentThatIsNotTheDeclaredTypeIsRefused(): void
    {
        $path = $this->tmp('.xlsx');
        file_put_contents($path, '<html><body><table><tr><td>x</td></tr></table></body></html>');

        $this->expectException(ErrorException::class);
        new SafeSpreadsheetReader()->load($path, ['xlsx'], 10);
    }

    #[Test]
    public function rowsBeyondTheLimitAreNotRead(): void
    {
        $path = $this->workbook(50);

        $sheet = new SafeSpreadsheetReader()->load($path, ['xlsx'], 10)->getActiveSheet();

        self::assertSame(10, $sheet->getHighestDataRow());
    }

    #[Test]
    public function anArchiveThatInflatesFarBeyondItsSizeIsRefusedBeforeLoading(): void
    {
        $path = $this->tmp('.xlsx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat('0', 20 * 1024 * 1024));
        $zip->close();

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('unpacks');
        SafeSpreadsheetReader::assertArchiveWithinLimits($path);
    }

    private function workbook(int $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        for ($i = 1; $i <= $rows; $i++) {
            $sheet->setCellValue('A' . $i, 'row ' . $i);
        }
        $path = $this->tmp('.xlsx');
        $writer = new XlsxWriter($spreadsheet);
        $writer->save($path);

        return $path;
    }

    private function tmp(string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ssr_') . $suffix;
        $this->files[] = $path;

        return $path;
    }
}

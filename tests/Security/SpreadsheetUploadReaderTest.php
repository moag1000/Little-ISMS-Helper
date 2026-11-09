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
            if (self::opensWorkbooksItself((string) file_get_contents($file->getPathname()))) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders, 'Open uploaded workbooks via App\Service\Import\SafeSpreadsheetReader.');
    }

    /**
     * T2: `new Reader\Xlsx()->load()` and `IOFactory::createReader('Xlsx')` passed the scan, a reader
     * built by hand skips the zip limits and the read filter just the same. CSV has no archive and
     * stays allowed.
     */
    #[Test]
    public function theScanRecognisesEveryWayToBuildAReader(): void
    {
        foreach ([
            '$s = IOFactory::load($path);',
            '$r = IOFactory::createReaderForFile($path);',
            '$r = IOFactory::createReader(\'Xlsx\');',
            '$s = new \\PhpOffice\\PhpSpreadsheet\\Reader\\Xlsx()->load($path);',
            "use PhpOffice\\PhpSpreadsheet\\Reader\\Ods;\n\$r = new Ods();",
            "use PhpOffice\\PhpSpreadsheet\\Reader\\Xls as LegacyReader;\n\$r = new LegacyReader();",
            "use PhpOffice\\PhpSpreadsheet\\Reader;\n\$r = new Reader\\Xlsx();",
        ] as $source) {
            self::assertTrue(self::opensWorkbooksItself("<?php\n" . $source), $source);
        }
        foreach ([
            "use PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx;\n\$w = new Xlsx(\$s);",
            "use PhpOffice\\PhpSpreadsheet\\Reader\\Csv as CsvReader;\n\$r = new CsvReader();",
        ] as $source) {
            self::assertFalse(self::opensWorkbooksItself("<?php\n" . $source), $source);
        }
    }

    private static function opensWorkbooksItself(string $source): bool
    {
        if (preg_match('/IOFactory::(createReaderForFile|createReader|load|identify)\s*\(/', $source) === 1) {
            return true;
        }
        $readers = 'Xlsx|Xls|Ods|Html|Slk|Gnumeric|Xml';
        if (preg_match('/new\s+\\\\?(?:PhpOffice\\\\PhpSpreadsheet\\\\)?Reader\\\\(' . $readers . ')\s*\(/', $source) === 1) {
            return true;
        }
        preg_match_all('/^use\s+PhpOffice\\\\PhpSpreadsheet\\\\Reader\\\\(' . $readers . ')(?:\s+as\s+(\w+))?\s*;/m', $source, $uses, PREG_SET_ORDER);
        foreach ($uses as $use) {
            if (preg_match('/new\s+' . preg_quote((($use[2] ?? '') !== '') ? $use[2] : $use[1], '/') . '\s*\(/', $source) === 1) {
                return true;
            }
        }

        return false;
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

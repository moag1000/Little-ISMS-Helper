<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\Import\SafeSpreadsheetReader;
use ErrorException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use App\Tests\Support\PhpSourceScanner;
use PhpParser\Node;
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
            "use PhpOffice\\PhpSpreadsheet\\IOFactory as IO;\n\$s = IO::load(\$p);",
            "use PhpOffice\\PhpSpreadsheet\\Reader\\{Xlsx, Csv};\n\$r = new Xlsx();",
            "use PhpOffice\\PhpSpreadsheet\\Reader\\Xlsx;\n\$r = new Xlsx;",
            "use PhpOffice\\PhpSpreadsheet\\Reader\\Xlsx;\n\$cls = Xlsx::class;\n\$r = new \$cls();",
            "use PhpOffice\\PhpSpreadsheet\\IOFactory;\n\$cls = \$this->readerFor(\$t);\n\$r = new \$cls();",
            "\$cls = 'PhpOffice\\\\PhpSpreadsheet\\\\Reader\\\\Xlsx';\n\$r = new \$cls();",
            "\$r = new\n    \\PhpOffice\\PhpSpreadsheet\\Reader\\Xlsx\n();",
        ] as $source) {
            self::assertTrue(self::opensWorkbooksItself("<?php\n" . $source), $source);
        }
        foreach ([
            "use PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx;\n\$w = new Xlsx(\$s);",
            "use PhpOffice\\PhpSpreadsheet\\Reader\\Csv as CsvReader;\n\$r = new CsvReader();",
            "// IOFactory::load(\$p) and new Xlsx() in a comment\n\$x = 1;",
            "use PhpOffice\\PhpSpreadsheet\\Reader\\Csv;\n\$o = new \\ArrayObject();",
        ] as $source) {
            self::assertFalse(self::opensWorkbooksItself("<?php\n" . $source), $source);
        }
    }

    /**
     * Parsed, not matched: aliases (`use … as IO`), group use, `new Xlsx;` without parentheses, comments and
     * line breaks are resolved by the parser; a reader class chosen at run time is refused in any file that
     * knows PhpSpreadsheet's readers.
     */
    private static function opensWorkbooksItself(string $source): bool
    {
        $readers = ['xlsx', 'xls', 'ods', 'html', 'slk', 'gnumeric', 'xml'];
        $ast = PhpSourceScanner::parseCode($source);

        foreach (PhpSourceScanner::find($ast, Node\Expr\StaticCall::class) as $call) {
            if ($call->class instanceof Node\Name && strtolower($call->class->getLast()) === 'iofactory'
                && $call->name instanceof Node\Identifier
                && in_array(strtolower($call->name->toString()), ['createreaderforfile', 'createreader', 'load', 'identify'], true)) {
                return true;
            }
        }
        $knowsReaders = false;
        foreach (PhpSourceScanner::find($ast, Node\Name::class) as $name) {
            $full = ltrim($name->toString(), '\\');
            if ((str_starts_with($full, 'PhpOffice\\PhpSpreadsheet\\Reader\\') && in_array(strtolower($name->getLast()), $readers, true))
                || strtolower($name->getLast()) === 'iofactory') {
                $knowsReaders = true;
            }
        }
        foreach (PhpSourceScanner::stringLiterals($ast) as $string) {
            if (str_contains($string, 'PhpSpreadsheet\\Reader\\')) {
                return true; // a reader class spelled as a string is built by name
            }
        }
        foreach (PhpSourceScanner::find($ast, Node\Expr\New_::class) as $new) {
            if ($new->class instanceof Node\Name) {
                $full = ltrim($new->class->toString(), '\\');
                if (str_starts_with($full, 'PhpOffice\\PhpSpreadsheet\\Reader\\')
                    && in_array(strtolower($new->class->getLast()), $readers, true)) {
                    return true;
                }
            } elseif ($knowsReaders) {
                return true; // new $cls() next to the readers
            }
        }
        foreach (PhpSourceScanner::find($ast, Node\Expr\ClassConstFetch::class) as $fetch) {
            if ($fetch->class instanceof Node\Name && $fetch->name instanceof Node\Identifier && $fetch->name->toString() === 'class'
                && str_starts_with(ltrim($fetch->class->toString(), '\\'), 'PhpOffice\\PhpSpreadsheet\\Reader\\')
                && in_array(strtolower($fetch->class->getLast()), $readers, true)) {
                return true; // Xlsx::class handed to a factory or `new $cls`
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

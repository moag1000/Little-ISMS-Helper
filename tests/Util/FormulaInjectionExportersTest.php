<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Controller\Trait\BulkActionTrait;
use App\Service\Audit\Generator\WorkbookStyleHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exporters that bypass ExcelExportService must neutralise leading formula characters.
 */
final class FormulaInjectionExportersTest extends TestCase
{
    #[Test]
    public function bulkCsvExportPrefixesFormulaCellsWithAQuote(): void
    {
        $exporter = new class {
            use BulkActionTrait;

            public function run(): string
            {
                $response = $this->streamCsvExport(
                    [new \stdClass()],
                    ['title', 'count'],
                    static fn (object $e): array => ['=1+1', 5],
                    'risks',
                    'Risk',
                );
                ob_start();
                $response->sendContent();

                return (string) ob_get_clean();
            }
        };

        $csv = $exporter->run();

        self::assertStringContainsString("'=1+1,5", $csv);
        self::assertStringNotContainsString("\n=1+1", $csv);
    }

    #[Test]
    public function coverRowValueIsNotStoredAsAFormula(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        WorkbookStyleHelper::writeCoverRow($sheet, 1, 'Tenant', '=1+1');

        self::assertSame("'=1+1", $sheet->getCell('B1')->getValue());
        self::assertFalse($sheet->getCell('B1')->isFormula());
    }
}

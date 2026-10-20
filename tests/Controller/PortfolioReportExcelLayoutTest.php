<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PortfolioReportController;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Regression: the portfolio Excel header styled the range up to `--$col`,
 * but decrementing a letter string is a no-op in PHP — the blue header fill
 * ran one column past the last framework. Auto-size used range('A', $col),
 * which also overshot (and breaks beyond column Z).
 */
final class PortfolioReportExcelLayoutTest extends KernelTestCase
{
    /**
     * @param list<array{code: string, name: string}> $frameworks
     */
    private function build(array $frameworks): Spreadsheet
    {
        self::bootKernel();
        $controller = static::getContainer()->get(PortfolioReportController::class);
        $method = new \ReflectionMethod($controller, 'buildWorkbook');

        $cells = [];
        foreach ($frameworks as $fw) {
            $cells[$fw['code']] = ['pct' => 90, 'delta' => 0, 'count' => 3, 'gaps' => 0];
        }

        return $method->invoke($controller, [
            'rows' => [['category' => 'Govern', 'cells' => $cells]],
            'frameworks' => $frameworks,
            'stichtag' => new \DateTimeImmutable('2026-06-30'),
            'vorperiode' => null,
        ], 'Tenant', new \DateTimeImmutable('2026-06-30'), null);
    }

    #[Test]
    public function headerFillCoversExactlyTheFrameworkColumns(): void
    {
        $sheet = $this->build([
            ['code' => 'ISO27001', 'name' => 'ISO 27001'],
            ['code' => 'NIS2', 'name' => 'NIS2'],
        ])->getActiveSheet();

        foreach (['A6', 'B6', 'C6'] as $cell) {
            self::assertSame(Fill::FILL_SOLID, $sheet->getStyle($cell)->getFill()->getFillType(), $cell);
            self::assertSame('0D6EFD', $sheet->getStyle($cell)->getFill()->getStartColor()->getRGB(), $cell);
        }
        self::assertNotSame(Fill::FILL_SOLID, $sheet->getStyle('D6')->getFill()->getFillType(), 'D6 is past the last framework');
        self::assertFalse($sheet->getColumnDimension('D')->getAutoSize());
        self::assertTrue($sheet->getColumnDimension('C')->getAutoSize());
    }

    #[Test]
    public function wideMatrixAutoSizesBeyondColumnZ(): void
    {
        $frameworks = [];
        for ($i = 1; $i <= 27; $i++) {
            $frameworks[] = ['code' => 'FW' . $i, 'name' => 'Framework ' . $i];
        }
        $sheet = $this->build($frameworks)->getActiveSheet();

        // 1 category column + 27 frameworks = A..AB
        self::assertTrue($sheet->getColumnDimension('AB')->getAutoSize());
        self::assertSame('0D6EFD', $sheet->getStyle('AB6')->getFill()->getStartColor()->getRGB());
        self::assertNotSame(Fill::FILL_SOLID, $sheet->getStyle('AC6')->getFill()->getFillType());
    }
}

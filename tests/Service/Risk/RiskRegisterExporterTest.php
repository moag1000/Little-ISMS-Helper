<?php

declare(strict_types=1);

namespace App\Tests\Service\Risk;

use App\Entity\Risk;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Service\ExcelExportService;
use App\Service\PdfExportService;
use App\Service\Risk\RiskRegisterExporter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class RiskRegisterExporterTest extends TestCase
{
    private function risk(string $title, int $p, int $i, ?RiskStatus $status = null, ?string $owner = null): Risk
    {
        $risk = (new Risk())->setTitle($title)->setProbability($p)->setImpact($i)
            ->setResidualProbability(1)->setResidualImpact(2)
            ->setStatus($status)->setTreatmentStrategy(TreatmentStrategy::Mitigate);
        if ($owner !== null) {
            $risk->setRiskOwner((new User())->setFirstName($owner)->setLastName('Owner'));
        }

        return $risk;
    }

    private ?Spreadsheet $generated = null;

    private function exporter(?PdfExportService $pdf = null): RiskRegisterExporter
    {
        $test = $this;
        // Captures the workbook instead of zipping it (no ext-zip needed to inspect it).
        $excel = new class ($test) extends ExcelExportService {
            public function __construct(private readonly RiskRegisterExporterTest $test)
            {
            }

            public function generateExcel(Spreadsheet $spreadsheet): string
            {
                $this->test->capture($spreadsheet);

                return 'xlsx-bytes';
            }
        };

        return new RiskRegisterExporter(
            new IdentityTranslator(),
            $excel,
            $pdf ?? $this->createStub(PdfExportService::class),
        );
    }

    public function capture(Spreadsheet $spreadsheet): void
    {
        $this->generated = $spreadsheet;
    }

    #[Test]
    public function filterAppliesEveryNonEmptyCriterionAndReindexes(): void
    {
        $critical = $this->risk('critical', 5, 4, RiskStatus::Identified, 'Alice');
        $low = $this->risk('low', 1, 1, RiskStatus::Identified, 'Bob');
        $treated = $this->risk('treated', 5, 5, RiskStatus::Treated, 'Alice');
        $all = [$low, $critical, $treated];

        $exporter = $this->exporter();
        self::assertSame($all, $exporter->filter($all, null, '', null, ''));
        self::assertSame([$critical, $treated], $exporter->filter($all, 'critical', null, null, null));
        self::assertSame([$critical], $exporter->filter($all, 'critical', 'identified', 'mitigate', 'ali'));
        self::assertSame([], $exporter->filter($all, null, null, 'accept', null));
    }

    #[Test]
    public function csvHasBomHeaderAndOneSanitisedRowPerRisk(): void
    {
        $csv = $this->exporter()->buildCsv([
            $this->risk('=HYPERLINK("x")', 5, 4, RiskStatus::Accepted),
            $this->risk('plain', 1, 2),
        ]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_map(
            static fn(string $l): array => str_getcsv($l, ';', '"', '\\'),
            explode("\n", trim(substr($csv, 3))),
        );
        self::assertCount(3, $lines);
        self::assertSame('risk.export.header.id', $lines[0][0]);
        self::assertCount(19, $lines[0]);
        // Formula injection neutralised (CsvSanitizer).
        self::assertStringStartsNotWith('=', $lines[1][1]);
        self::assertSame('20', $lines[1][8]);
        self::assertSame('risk.export.level.critical', $lines[1][9]);
        // No translation available → raw enum values.
        self::assertSame('mitigate', $lines[1][14]);
        self::assertSame('accepted', $lines[1][15]);
        self::assertSame('', $lines[2][15]);
        self::assertSame('risk.export.level.low', $lines[2][9]);
    }

    #[Test]
    public function excelHasSummaryAllRisksAndCriticalHighTabs(): void
    {
        $content = $this->exporter()->buildExcel([
            $this->risk('high', 4, 2, RiskStatus::InTreatment),
            $this->risk('low', 1, 1, RiskStatus::Identified),
        ]);

        self::assertSame('xlsx-bytes', $content);
        $spreadsheet = $this->generated;
        self::assertNotNull($spreadsheet);

        self::assertSame(
            ['Zusammenfassung', 'Alle Risiken', 'Kritische & Hohe Risiken'],
            $spreadsheet->getSheetNames(),
        );
        $all = $spreadsheet->getSheetByName('Alle Risiken');
        self::assertSame('high', $all->getCell('B2')->getValue());
        self::assertSame('Hoch', $all->getCell('G2')->getValue());
        self::assertSame('Mindern', $all->getCell('L2')->getValue());
        self::assertSame('In Behandlung', $all->getCell('M2')->getValue());
        self::assertSame('Niedrig', $all->getCell('G3')->getValue());
        $criticalHigh = $spreadsheet->getSheetByName('Kritische & Hohe Risiken');
        self::assertSame('high', $criticalHigh->getCell('B2')->getValue());
        self::assertNull($criticalHigh->getCell('B3')->getValue());
    }

    #[Test]
    public function excelOmitsCriticalHighTabWhenNoneQualify(): void
    {
        $this->exporter()->buildExcel([$this->risk('low', 1, 1)]);
        self::assertSame(['Zusammenfassung', 'Alle Risiken'], $this->generated?->getSheetNames());
    }

    #[Test]
    public function pdfReceivesLevelCountsAndNonZeroStatusBreakdown(): void
    {
        $risks = [
            $this->risk('critical', 5, 3, RiskStatus::Open),
            $this->risk('high', 4, 2, RiskStatus::Open),
            $this->risk('medium', 2, 2, RiskStatus::Closed),
            $this->risk('low', 1, 1),
        ];
        $pdf = $this->createMock(PdfExportService::class);
        $pdf->expects(self::once())->method('generatePdf')
            ->with('pdf/risk_report.html.twig', self::callback(static function (array $ctx) use ($risks): bool {
                self::assertSame($risks, $ctx['risks']);
                self::assertSame(4, $ctx['total_risks']);
                self::assertSame(1, $ctx['critical_risks']);
                self::assertSame(1, $ctx['high_risks']);
                self::assertSame(1, $ctx['medium_risks']);
                self::assertSame(1, $ctx['low_risks']);
                self::assertSame(['closed' => 1, 'open' => 2], $ctx['status_breakdown']);
                self::assertSame('Level: high', $ctx['filter_info']);

                return true;
            }))
            ->willReturn('%PDF');

        self::assertSame('%PDF', $this->exporter($pdf)->buildPdf($risks, 'Level: high'));
    }
}

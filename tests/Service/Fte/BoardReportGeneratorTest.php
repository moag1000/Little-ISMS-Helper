<?php

declare(strict_types=1);

namespace App\Tests\Service\Fte;

use App\Entity\Tenant;
use App\Repository\Fte\FteTrackingMetricRepository;
use App\Service\Fte\BoardReportGenerator;
use App\Service\PdfExportService;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

class BoardReportGeneratorTest extends TestCase
{
    private FteTrackingMetricRepository $metricRepo;
    private PdfExportService $pdfExportService;
    private Environment $twig;
    private BoardReportGenerator $generator;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->metricRepo = $this->createMock(FteTrackingMetricRepository::class);
        $this->pdfExportService = $this->createMock(PdfExportService::class);
        $this->twig = $this->createMock(Environment::class);
        $this->tenant = $this->createStub(Tenant::class);

        $this->generator = new BoardReportGenerator(
            $this->metricRepo,
            $this->pdfExportService,
            $this->twig,
        );
    }

    #[Test]
    public function generateMonthlyReturnsStructuredData(): void
    {
        $month = new DateTimeImmutable('2026-05-01');

        $this->metricRepo->method('getSavingsAggregate')->willReturn(300);
        $this->metricRepo->method('getSavingsBySource')->willReturn(['sso_jit' => 180, 'bulk_import' => 120]);
        $this->metricRepo->method('getMonthlyTrend')->willReturn(['2026-04' => 150, '2026-05' => 300]);

        $data = $this->generator->generateMonthly($this->tenant, $month);

        $this->assertArrayHasKey('tenant', $data);
        $this->assertArrayHasKey('month_label', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertArrayHasKey('by_source', $data);
        $this->assertArrayHasKey('monthly_trend', $data);
        $this->assertSame(300, $data['totals']['savings_minutes']);
        $this->assertEqualsWithDelta(5.0, $data['totals']['savings_hours'], 0.01);
        $this->assertSame('May 2026', $data['month_label']);
    }

    #[Test]
    public function renderAsCsvProducesRfc4180Format(): void
    {
        $data = [
            'tenant' => $this->tenant,
            'month_label' => 'May 2026',
            'generated_at' => new DateTimeImmutable('2026-05-15 10:00'),
            'totals' => ['savings_minutes' => 300, 'savings_hours' => 5.0],
            'by_source' => ['sso_jit' => 180, 'bulk_import' => 120],
            'monthly_trend' => ['2026-05' => 300],
        ];

        $csv = $this->generator->renderAsCsv($data);

        $this->assertStringContainsString('FTE Savings Report', $csv);
        $this->assertStringContainsString('May 2026', $csv);
        $this->assertStringContainsString('300', $csv);
        $this->assertStringContainsString('sso_jit', $csv);
        $this->assertStringContainsString("\r\n", $csv);
    }

    #[Test]
    public function renderAsPdfDelegatesToPdfExportService(): void
    {
        $data = ['tenant' => $this->tenant];

        $this->pdfExportService
            ->expects($this->once())
            ->method('generatePdf')
            ->with('dashboard/fte_tracking/board_report.html.twig', $data, $this->anything())
            ->willReturn('%PDF-1.4 fake');

        $result = $this->generator->renderAsPdf($data);

        $this->assertSame('%PDF-1.4 fake', $result);
    }

    #[Test]
    public function renderAsHtmlDelegatesToTwig(): void
    {
        $data = ['tenant' => $this->tenant];

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('dashboard/fte_tracking/board_report.html.twig', $data)
            ->willReturn('<html>report</html>');

        $result = $this->generator->renderAsHtml($data);

        $this->assertSame('<html>report</html>', $result);
    }
}

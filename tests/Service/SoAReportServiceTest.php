<?php

namespace App\Tests\Service;

use App\Entity\Control;
use App\Repository\ControlRepository;
use App\Service\PdfExportService;
use App\Service\SoAReportService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class SoAReportServiceTest extends TestCase
{
    private MockObject $controlRepository;
    private MockObject $pdfExportService;
    private MockObject $twig;
    private SoAReportService $service;

    protected function setUp(): void
    {
        $this->controlRepository = $this->createMock(ControlRepository::class);
        $this->pdfExportService = $this->createMock(PdfExportService::class);
        $this->twig = $this->createMock(Environment::class);

        $this->service = new SoAReportService(
            $this->controlRepository,
            $this->pdfExportService,
            $this->twig
        );
    }

    public function testGenerateSoAReport(): void
    {
        $control1 = $this->createMock(Control::class);
        $control1->method('getId')->willReturn(1);
        $control1->method('getCategory')->willReturn('A.5');

        $control2 = $this->createMock(Control::class);
        $control2->method('getId')->willReturn(2);
        $control2->method('getCategory')->willReturn('A.5');

        $controls = [$control1, $control2];

        $stats = [
            'total' => 93,
            'implemented' => 50,
            'partially_implemented' => 20,
            'not_implemented' => 23,
        ];

        $categoryStats = [
            'A.5' => 37,
            'A.6' => 8,
            'A.7' => 14,
            'A.8' => 34,
        ];

        $this->controlRepository->method('findAllInIsoOrder')->willReturn($controls);
        $this->controlRepository->method('getImplementationStats')->willReturn($stats);
        $this->controlRepository->method('countByCategory')->willReturn($categoryStats);

        $this->pdfExportService->expects($this->once())
            ->method('generatePdf')
            ->with(
                'soa/report_pdf_v2.html.twig',
                $this->callback(function ($data) {
                    return isset($data['controls'])
                        && isset($data['controlsByCategory'])
                        && isset($data['stats'])
                        && isset($data['categoryStats'])
                        && isset($data['generatedAt'])
                        && $data['totalControls'] === 93;
                }),
                ['orientation' => 'portrait', 'paper' => 'A4']
            )
            ->willReturn('PDF_CONTENT');

        $result = $this->service->generateSoAReport();

        $this->assertEquals('PDF_CONTENT', $result);
    }

    public function testGenerateSoAReportWithCustomOptions(): void
    {
        $this->controlRepository->method('findAllInIsoOrder')->willReturn([]);
        $this->controlRepository->method('getImplementationStats')->willReturn([
            'total' => 93,
            'implemented' => 0,
        ]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->pdfExportService->expects($this->once())
            ->method('generatePdf')
            ->with(
                'soa/report_pdf_v2.html.twig',
                $this->anything(),
                ['orientation' => 'landscape', 'paper' => 'A3']
            )
            ->willReturn('PDF_CONTENT');

        $result = $this->service->generateSoAReport([
            'orientation' => 'landscape',
            'paper' => 'A3'
        ]);

        $this->assertEquals('PDF_CONTENT', $result);
    }

    public function testDownloadSoAReport(): void
    {
        $this->controlRepository->method('findAllInIsoOrder')->willReturn([]);
        $this->controlRepository->method('getImplementationStats')->willReturn(['total' => 93]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->pdfExportService->method('generatePdf')->willReturn('PDF_CONTENT');

        $response = $this->service->downloadSoAReport();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        $this->assertEquals('PDF_CONTENT', $response->getContent());
    }

    public function testDownloadSoAReportWithCustomFilename(): void
    {
        $this->controlRepository->method('findAllInIsoOrder')->willReturn([]);
        $this->controlRepository->method('getImplementationStats')->willReturn(['total' => 93]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->pdfExportService->method('generatePdf')->willReturn('PDF_CONTENT');

        $response = $this->service->downloadSoAReport('Custom_SoA_Report');

        $this->assertStringContainsString('Custom_SoA_Report.pdf', $response->headers->get('Content-Disposition'));
    }

    public function testDownloadSoAReportAutoAddsExtension(): void
    {
        $this->controlRepository->method('findAllInIsoOrder')->willReturn([]);
        $this->controlRepository->method('getImplementationStats')->willReturn(['total' => 93]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->pdfExportService->method('generatePdf')->willReturn('PDF_CONTENT');

        $response = $this->service->downloadSoAReport('MyReport');

        $this->assertStringContainsString('MyReport.pdf', $response->headers->get('Content-Disposition'));
    }

    public function testDownloadSoAReportSanitizesFilename(): void
    {
        $this->controlRepository->method('findAllInIsoOrder')->willReturn([]);
        $this->controlRepository->method('getImplementationStats')->willReturn(['total' => 93]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->pdfExportService->method('generatePdf')->willReturn('PDF_CONTENT');

        $response = $this->service->downloadSoAReport('Report<script>alert("XSS")</script>');

        // Should sanitize special characters
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringNotContainsString('<script>', $disposition);
        $this->assertStringNotContainsString('<', $disposition);
        $this->assertStringNotContainsString('>', $disposition);
    }

    public function testStreamSoAReport(): void
    {
        $this->controlRepository->method('findAllInIsoOrder')->willReturn([]);
        $this->controlRepository->method('getImplementationStats')->willReturn(['total' => 93]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->pdfExportService->method('generatePdf')->willReturn('PDF_CONTENT');

        $response = $this->service->streamSoAReport();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
    }

    public function testStreamSoAReportWithCustomFilename(): void
    {
        $this->controlRepository->method('findAllInIsoOrder')->willReturn([]);
        $this->controlRepository->method('getImplementationStats')->willReturn(['total' => 93]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->pdfExportService->method('generatePdf')->willReturn('PDF_CONTENT');

        $response = $this->service->streamSoAReport('Inline_Report');

        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Inline_Report.pdf', $response->headers->get('Content-Disposition'));
    }

    public function testGetSoAStatistics(): void
    {
        $stats = [
            'total' => 93,
            'implemented' => 60,
            'partially_implemented' => 20,
            'not_implemented' => 13,
        ];

        $this->controlRepository->method('getImplementationStats')->willReturn($stats);

        $result = $this->service->getSoAStatistics();

        $this->assertEquals($stats, $result);
    }

    public function testGetCategoryStatistics(): void
    {
        $categoryStats = [
            'A.5' => 37,
            'A.6' => 8,
            'A.7' => 14,
            'A.8' => 34,
        ];

        $this->controlRepository->method('countByCategory')->willReturn($categoryStats);

        $result = $this->service->getCategoryStatistics();

        $this->assertEquals($categoryStats, $result);
    }

    public function testGetImplementationProgress(): void
    {
        $this->controlRepository->method('getImplementationStats')->willReturn([
            'total' => 93,
            'implemented' => 46,
        ]);

        $progress = $this->service->getImplementationProgress();

        $this->assertEquals(49.46, $progress);
    }

    public function testGetImplementationProgressWithZeroTotal(): void
    {
        $this->controlRepository->method('getImplementationStats')->willReturn([
            'total' => 0,
            'implemented' => 0,
        ]);

        $progress = $this->service->getImplementationProgress();

        $this->assertEquals(0.0, $progress);
    }

    public function testGetImplementationProgressRoundsCorrectly(): void
    {
        $this->controlRepository->method('getImplementationStats')->willReturn([
            'total' => 93,
            'implemented' => 30,
        ]);

        $progress = $this->service->getImplementationProgress();

        $this->assertEquals(32.26, $progress); // 30/93 * 100 = 32.258...
    }

    public function testGetControlsRequiringAttentionFindsNotApplicableWithoutJustification(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('isApplicable')->willReturn(false);
        $control->method('getJustification')->willReturn('');
        $control->method('getTargetDate')->willReturn(null);
        $control->method('getImplementationStatus')->willReturn('not_implemented');

        $this->controlRepository->method('findAllInIsoOrder')->willReturn([$control]);

        $result = $this->service->getControlsRequiringAttention();

        $this->assertCount(1, $result);
        $this->assertEquals('not_applicable_no_justification', $result[0]['reason']);
        $this->assertSame($control, $result[0]['control']);
    }

    public function testGetControlsRequiringAttentionFindsOverdueControls(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('isApplicable')->willReturn(true);
        $control->method('getTargetDate')->willReturn(new \DateTime('-10 days'));
        $control->method('getImplementationStatus')->willReturn('in_progress');

        $this->controlRepository->method('findAllInIsoOrder')->willReturn([$control]);

        $result = $this->service->getControlsRequiringAttention();

        $this->assertCount(1, $result);
        $this->assertEquals('overdue', $result[0]['reason']);
    }

    public function testGetControlsRequiringAttentionIgnoresImplementedOverdue(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('isApplicable')->willReturn(true);
        $control->method('getTargetDate')->willReturn(new \DateTime('-10 days'));
        $control->method('getImplementationStatus')->willReturn('implemented');

        $this->controlRepository->method('findAllInIsoOrder')->willReturn([$control]);

        $result = $this->service->getControlsRequiringAttention();

        $this->assertEmpty($result); // Implemented controls don't need attention
    }

    public function testGetControlsRequiringAttentionIgnoresNotApplicableWithJustification(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('isApplicable')->willReturn(false);
        $control->method('getJustification')->willReturn('Not applicable because...');
        $control->method('getTargetDate')->willReturn(null);

        $this->controlRepository->method('findAllInIsoOrder')->willReturn([$control]);

        $result = $this->service->getControlsRequiringAttention();

        $this->assertEmpty($result);
    }

    public function testGetControlsRequiringAttentionIgnoresFutureDates(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('isApplicable')->willReturn(true);
        $control->method('getTargetDate')->willReturn(new \DateTime('+30 days'));
        $control->method('getImplementationStatus')->willReturn('not_implemented');

        $this->controlRepository->method('findAllInIsoOrder')->willReturn([$control]);

        $result = $this->service->getControlsRequiringAttention();

        $this->assertEmpty($result);
    }

    public function testGetControlsRequiringAttentionWithMultipleIssues(): void
    {
        $control1 = $this->createMock(Control::class);
        $control1->method('isApplicable')->willReturn(false);
        $control1->method('getJustification')->willReturn('');
        $control1->method('getTargetDate')->willReturn(null);
        $control1->method('getImplementationStatus')->willReturn('not_implemented');

        $control2 = $this->createMock(Control::class);
        $control2->method('isApplicable')->willReturn(true);
        $control2->method('getTargetDate')->willReturn(new \DateTime('-5 days'));
        $control2->method('getImplementationStatus')->willReturn('in_progress');

        $this->controlRepository->method('findAllInIsoOrder')->willReturn([$control1, $control2]);

        $result = $this->service->getControlsRequiringAttention();

        $this->assertCount(2, $result);
    }

    public function testGroupControlsByCategoryCorrectly(): void
    {
        $control1 = $this->createMock(Control::class);
        $control1->method('getCategory')->willReturn('A.5');

        $control2 = $this->createMock(Control::class);
        $control2->method('getCategory')->willReturn('A.5');

        $control3 = $this->createMock(Control::class);
        $control3->method('getCategory')->willReturn('A.6');

        $this->controlRepository->method('findAllInIsoOrder')->willReturn([$control1, $control2, $control3]);
        $this->controlRepository->method('getImplementationStats')->willReturn(['total' => 93]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->pdfExportService->method('generatePdf')
            ->with(
                $this->anything(),
                $this->callback(function ($data) {
                    return count($data['controlsByCategory']['A.5']) === 2
                        && count($data['controlsByCategory']['A.6']) === 1;
                }),
                $this->anything()
            )
            ->willReturn('PDF');

        $this->service->generateSoAReport();
    }

    public function testDownloadSoAReportSetsContentLength(): void
    {
        $this->controlRepository->method('findAllInIsoOrder')->willReturn([]);
        $this->controlRepository->method('getImplementationStats')->willReturn(['total' => 93]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $pdfContent = str_repeat('A', 1000);
        $this->pdfExportService->method('generatePdf')->willReturn($pdfContent);

        $response = $this->service->downloadSoAReport();

        $this->assertEquals('1000', $response->headers->get('Content-Length'));
    }

}

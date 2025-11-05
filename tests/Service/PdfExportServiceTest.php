<?php

namespace App\Tests\Service;

use App\Service\PdfExportService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class PdfExportServiceTest extends TestCase
{
    private PdfExportService $service;
    private Environment $twig;

    protected function setUp(): void
    {
        $loader = new ArrayLoader([
            'test.html.twig' => '<h1>{{ title }}</h1><p>{{ content }}</p>',
        ]);
        $this->twig = new Environment($loader);
        $this->service = new PdfExportService($this->twig);
    }

    public function testGeneratePdf(): void
    {
        $pdf = $this->service->generatePdf('test.html.twig', [
            'title' => 'Test Title',
            'content' => 'Test Content',
        ]);

        $this->assertIsString($pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(0, strlen($pdf));
    }

    public function testGeneratePdfWithOptions(): void
    {
        $pdf = $this->service->generatePdf('test.html.twig', [
            'title' => 'Test',
            'content' => 'Content',
        ], [
            'orientation' => 'landscape',
            'paper' => 'A4',
        ]);

        $this->assertIsString($pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Pdf\PdfRenderer;
use Twig\Environment;

/**
 * PDF Export Service
 *
 * Provides secure PDF generation from Twig templates using DomPDF.
 * Implements security controls to prevent SSRF and header injection attacks.
 *
 * Features:
 * - Template-based PDF generation
 * - Download and inline streaming support
 * - Configurable page orientation and paper size
 * - UTF-8 support with DejaVu Sans font
 *
 * Security:
 * - Remote resources disabled (SSRF prevention - OWASP #10)
 * - Filename sanitization (Header injection prevention - OWASP #3)
 * - HTML5 parser enabled for safe rendering
 */
class PdfExportService
{
    public function __construct(
        private readonly Environment $twigEnvironment,
        private readonly ?PdfRenderer $pdfRenderer = null,
    ) {
    }

    public function generatePdf(string $template, array $data, array $options = []): string
    {
        return $this->generateFromHtml($this->twigEnvironment->render($template, $data), $options);
    }

    /**
     * Generate a PDF from already-rendered HTML (no Twig template step).
     *
     * Same security controls and watermark/classification handling as
     * generatePdf(); used when the caller has already produced the markup.
     */
    public function generateFromHtml(string $html, array $options = []): string
    {
        // Add watermark/classification if specified
        $watermark = $options['watermark'] ?? null;
        $classification = $options['classification'] ?? null;

        if ($watermark || $classification) {
            $watermarkCss = '';
            if ($watermark) {
                $safeWatermark = htmlspecialchars($watermark, ENT_QUOTES, 'UTF-8');
                $watermarkCss .= "
                    body::before {
                        content: '{$safeWatermark}';
                        position: fixed;
                        top: 50%;
                        left: 50%;
                        transform: translate(-50%, -50%) rotate(-45deg);
                        font-size: 80px;
                        color: rgba(200, 200, 200, 0.3);
                        z-index: -1;
                        pointer-events: none;
                    }
                ";
            }
            if ($classification) {
                $safeClassification = htmlspecialchars($classification, ENT_QUOTES, 'UTF-8');
                $watermarkCss .= "
                    @page {
                        @top-center { content: '{$safeClassification}'; font-size: 10px; color: red; }
                        @bottom-center { content: '{$safeClassification}'; font-size: 10px; color: red; }
                    }
                ";
            }
            $html = str_replace('</head>', "<style>{$watermarkCss}</style></head>", $html);
        }

        return $this->renderer()->render(
            $html,
            $options['paper'] ?? 'A4',
            $options['orientation'] ?? 'portrait',
        );
    }

    private function renderer(): PdfRenderer
    {
        return $this->pdfRenderer ?? new PdfRenderer();
    }

    public function downloadPdf(string $template, array $data, string $filename, array $options = []): void
    {
        $pdf = $this->generatePdf($template, $data, $options);

        // Security: Sanitize filename to prevent header injection (OWASP Top 10 #3)
        $safeFilename = preg_replace('/[^\w\s\.\-]/', '', $filename);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . strlen($pdf));

        echo $pdf;
    }

    public function streamPdf(string $template, array $data, string $filename, array $options = []): void
    {
        $pdf = $this->generatePdf($template, $data, $options);

        // Security: Sanitize filename to prevent header injection (OWASP Top 10 #3)
        $safeFilename = preg_replace('/[^\w\s\.\-]/', '', $filename);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $safeFilename . '"');
        header('Content-Length: ' . strlen($pdf));

        echo $pdf;
    }
}

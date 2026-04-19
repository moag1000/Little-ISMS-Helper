<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
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
        private readonly Environment $twigEnvironment
    ) {
    }

    public function generatePdf(string $template, array $data, array $options = []): string
    {
        $html = $this->twigEnvironment->render($template, $data);

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

        $pdfOptions = new Options();
        $pdfOptions->set('defaultFont', 'DejaVu Sans');
        // Security: Disable remote resources to prevent SSRF attacks (OWASP Top 10 #10)
        $pdfOptions->set('isRemoteEnabled', false);
        $pdfOptions->set('isHtml5ParserEnabled', true);

        if (isset($options['orientation'])) {
            $pdfOptions->set('orientation', $options['orientation']);
        }

        $dompdf = new Dompdf($pdfOptions);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($options['paper'] ?? 'A4', $options['orientation'] ?? 'portrait');
        $dompdf->render();

        return $dompdf->output();
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

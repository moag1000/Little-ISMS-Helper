<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class PdfExportService
{
    public function __construct(
        private readonly Environment $twig
    ) {
    }

    public function generatePdf(string $template, array $data, array $options = []): string
    {
        $html = $this->twig->render($template, $data);

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

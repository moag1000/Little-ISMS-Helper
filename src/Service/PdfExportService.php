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
        $pdfOptions->set('isRemoteEnabled', true);
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

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));

        echo $pdf;
    }

    public function streamPdf(string $template, array $data, string $filename, array $options = []): void
    {
        $pdf = $this->generatePdf($template, $data, $options);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));

        echo $pdf;
    }
}

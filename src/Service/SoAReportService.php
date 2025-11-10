<?php

namespace App\Service;

use App\Repository\ControlRepository;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Statement of Applicability (SoA) Report Service
 *
 * Generates professional PDF reports for ISO 27001:2022 SoA documentation.
 * Includes all 93 Annex A controls with implementation status, justifications,
 * linked risks, and responsibilities.
 *
 * Phase 6F-C: SoA PDF Generator for ISO 27001 compliance reporting
 */
class SoAReportService
{
    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly PdfExportService $pdfExportService,
        private readonly Environment $twig
    ) {
    }

    /**
     * Generate SoA PDF Report
     *
     * Creates a comprehensive Statement of Applicability document including:
     * - All 93 ISO 27001:2022 Annex A controls
     * - Implementation status and percentage
     * - Applicability justifications
     * - Linked risks and their severity
     * - Responsible persons
     * - Target completion dates
     *
     * @param array $options PDF generation options (orientation, paper size)
     * @return string Raw PDF content
     */
    public function generateSoAReport(array $options = []): string
    {
        $controls = $this->controlRepository->findAllInIsoOrder();
        $stats = $this->controlRepository->getImplementationStats();
        $categoryStats = $this->controlRepository->countByCategory();

        // Group controls by category for better PDF structure
        $controlsByCategory = $this->groupControlsByCategory($controls);

        $data = [
            'controls' => $controls,
            'controlsByCategory' => $controlsByCategory,
            'stats' => $stats,
            'categoryStats' => $categoryStats,
            'generatedAt' => new \DateTime(),
            'totalControls' => 93,
        ];

        return $this->pdfExportService->generatePdf(
            'soa/report_pdf.html.twig',
            $data,
            array_merge(['orientation' => 'portrait', 'paper' => 'A4'], $options)
        );
    }

    /**
     * Generate and download SoA PDF Report
     *
     * @param string|null $filename Custom filename (default: auto-generated with timestamp)
     * @param array $options PDF generation options
     * @return Response
     */
    public function downloadSoAReport(?string $filename = null, array $options = []): Response
    {
        $pdf = $this->generateSoAReport($options);

        if ($filename === null) {
            $filename = 'SoA_Report_' . date('Y-m-d_His') . '.pdf';
        }

        // Ensure .pdf extension
        if (!str_ends_with($filename, '.pdf')) {
            $filename .= '.pdf';
        }

        // Sanitize filename for security
        $safeFilename = preg_replace('/[^\w\s\.\-]/', '', $filename);

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $safeFilename . '"');
        $response->headers->set('Content-Length', (string)strlen($pdf));

        return $response;
    }

    /**
     * Generate and stream SoA PDF Report (inline display)
     *
     * @param string|null $filename Custom filename
     * @param array $options PDF generation options
     * @return Response
     */
    public function streamSoAReport(?string $filename = null, array $options = []): Response
    {
        $pdf = $this->generateSoAReport($options);

        if ($filename === null) {
            $filename = 'SoA_Report_' . date('Y-m-d_His') . '.pdf';
        }

        if (!str_ends_with($filename, '.pdf')) {
            $filename .= '.pdf';
        }

        $safeFilename = preg_replace('/[^\w\s\.\-]/', '', $filename);

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'inline; filename="' . $safeFilename . '"');
        $response->headers->set('Content-Length', (string)strlen($pdf));

        return $response;
    }

    /**
     * Group controls by their category (A.5, A.6, A.7, A.8)
     *
     * @param array $controls
     * @return array Associative array with category as key
     */
    private function groupControlsByCategory(array $controls): array
    {
        $grouped = [];

        foreach ($controls as $control) {
            $category = $control->getCategory();
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $control;
        }

        return $grouped;
    }

    /**
     * Get SoA summary statistics
     *
     * @return array Statistics array
     */
    public function getSoAStatistics(): array
    {
        return $this->controlRepository->getImplementationStats();
    }

    /**
     * Get category breakdown for dashboard widgets
     *
     * @return array Category statistics
     */
    public function getCategoryStatistics(): array
    {
        return $this->controlRepository->countByCategory();
    }

    /**
     * Get implementation progress percentage
     *
     * @return float Progress from 0-100
     */
    public function getImplementationProgress(): float
    {
        $stats = $this->getSoAStatistics();

        if ($stats['total'] === 0) {
            return 0.0;
        }

        return round(($stats['implemented'] / $stats['total']) * 100, 2);
    }

    /**
     * Get list of controls requiring attention (not applicable without justification, overdue)
     *
     * @return array List of controls needing review
     */
    public function getControlsRequiringAttention(): array
    {
        $allControls = $this->controlRepository->findAllInIsoOrder();
        $requiresAttention = [];

        foreach ($allControls as $control) {
            // Not applicable without justification
            if (!$control->isApplicable() && empty($control->getJustification())) {
                $requiresAttention[] = [
                    'control' => $control,
                    'reason' => 'not_applicable_no_justification',
                ];
                continue;
            }

            // Overdue implementation
            if ($control->getTargetDate() !== null && $control->getTargetDate() < new \DateTime()) {
                if ($control->getImplementationStatus() !== 'implemented') {
                    $requiresAttention[] = [
                        'control' => $control,
                        'reason' => 'overdue',
                    ];
                }
            }
        }

        return $requiresAttention;
    }
}

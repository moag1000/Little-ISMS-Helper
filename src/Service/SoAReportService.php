<?php

declare(strict_types=1);

namespace App\Service;

use DateTime;
use App\Repository\ControlRepository;
use App\Service\TenantContext;
use Symfony\Component\HttpFoundation\Response;

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
        private readonly TenantContext $tenantContext
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
        $tenant = $this->tenantContext->getCurrentTenant();
        $controls = $tenant ? $this->controlRepository->findAllInIsoOrder($tenant) : [];
        $stats = $tenant ? $this->controlRepository->getImplementationStats($tenant) : ['total' => 0, 'implemented' => 0, 'in_progress' => 0, 'not_started' => 0, 'not_applicable' => 0];
        $categoryStats = $tenant ? $this->controlRepository->countByCategory($tenant) : [];

        // Group controls by category for better PDF structure
        $controlsByCategory = $this->groupControlsByCategory($controls);

        // Calculate version from latest control update date (Format: Year.Month.Day)
        $latestUpdate = null;
        foreach ($controls as $control) {
            $updateDate = $control->getUpdatedAt() ?? $control->getCreatedAt();
            if ($latestUpdate === null || ($updateDate !== null && $updateDate > $latestUpdate)) {
                $latestUpdate = $updateDate;
            }
        }

        // Use current date if no controls have been updated
        if ($latestUpdate === null) {
            $latestUpdate = new DateTime();
        }

        // Format version as Year.Month.Day (e.g., 2025.11.20)
        $version = $latestUpdate->format('Y.m.d');

        $data = [
            'controls' => $controls,
            'controlsByCategory' => $controlsByCategory,
            'stats' => $stats,
            'categoryStats' => $categoryStats,
            'generatedAt' => new DateTime(),
            'totalControls' => 93,
            'version' => $version,
        ];

        return $this->pdfExportService->generatePdf(
            'soa/report_pdf_v2.html.twig',
            $data,
            array_merge(['orientation' => 'portrait', 'paper' => 'A4'], $options)
        );
    }

    /**
     * Generate and download SoA PDF Report
     *
     * @param string|null $filename Custom filename (default: auto-generated with timestamp)
     * @param array $options PDF generation options
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
        $tenant = $this->tenantContext->getCurrentTenant();
        return $tenant
            ? $this->controlRepository->getImplementationStats($tenant)
            : ['total' => 0, 'implemented' => 0, 'in_progress' => 0, 'not_started' => 0, 'not_applicable' => 0];
    }

    /**
     * Get category breakdown for dashboard widgets
     *
     * @return array Category statistics
     */
    public function getCategoryStatistics(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        return $tenant
            ? $this->controlRepository->countByCategory($tenant)
            : [];
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
        $tenant = $this->tenantContext->getCurrentTenant();
        $allControls = $tenant ? $this->controlRepository->findAllInIsoOrder($tenant) : [];
        $requiresAttention = [];

        foreach ($allControls as $allControl) {
            // Not applicable without justification
            if (!$allControl->isApplicable() && empty($allControl->getJustification())) {
                $requiresAttention[] = [
                    'control' => $allControl,
                    'reason' => 'not_applicable_no_justification',
                ];
                continue;
            }

            // Overdue implementation
            if ($allControl->getTargetDate() !== null && $allControl->getTargetDate() < new DateTime() && $allControl->getImplementationStatus() !== 'implemented') {
                $requiresAttention[] = [
                    'control' => $allControl,
                    'reason' => 'overdue',
                ];
            }
        }

        return $requiresAttention;
    }

    /**
     * M-02: SoA completeness validation. Returns a structured list of
     * violations; empty array means the SoA is ready for export.
     *
     * Rules:
     *  - Every control that is not applicable MUST have a justification
     *    (ISO 27001:2022 Annex A requirement).
     *  - Every applicable control MUST have an implementation status set.
     *  - Overdue applicable controls (target date in the past, not yet
     *    implemented) are flagged so they cannot slip through a sign-off.
     *
     * @return list<array{control_id: string, control_name: string, rule: string, severity: 'error'|'warning'}>
     */
    public function validateSoACompleteness(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $controls = $tenant ? $this->controlRepository->findAllInIsoOrder($tenant) : [];
        $issues = [];

        foreach ($controls as $control) {
            $controlId = (string) $control->getControlId();
            $controlName = (string) $control->getName();

            if ($control->isApplicable() === false) {
                $justification = trim((string) $control->getJustification());
                if ($justification === '') {
                    $issues[] = [
                        'control_id' => $controlId,
                        'control_name' => $controlName,
                        'rule' => 'not_applicable_without_justification',
                        'severity' => 'error',
                    ];
                }
                continue;
            }

            if ($control->isApplicable() === true) {
                $status = $control->getImplementationStatus();
                if ($status === null || $status === '' || $status === 'not_started') {
                    $issues[] = [
                        'control_id' => $controlId,
                        'control_name' => $controlName,
                        'rule' => 'applicable_without_implementation_status',
                        'severity' => 'warning',
                    ];
                }
                if ($control->getTargetDate() !== null
                    && $control->getTargetDate() < new DateTime()
                    && $status !== 'implemented'
                ) {
                    $issues[] = [
                        'control_id' => $controlId,
                        'control_name' => $controlName,
                        'rule' => 'overdue',
                        'severity' => 'warning',
                    ];
                }
            }
        }
        return $issues;
    }
}

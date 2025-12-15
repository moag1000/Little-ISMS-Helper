<?php

namespace App\Service;

use DateTime;
use App\Entity\ScheduledReport;
use App\Repository\ScheduledReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Scheduled Report Service
 *
 * Phase 7A: Handles generation and delivery of scheduled reports.
 * Reports are generated based on configuration and sent via email.
 */
class ScheduledReportService
{
    public function __construct(
        private readonly ScheduledReportRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagementReportService $reportService,
        private readonly PdfExportService $pdfExportService,
        private readonly ExcelExportService $excelExportService,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly TenantContext $tenantContext,
        private readonly string $senderEmail = 'noreply@little-isms-helper.local',
        private readonly string $senderName = 'Little ISMS Helper',
    ) {
    }

    /**
     * Process all due scheduled reports
     *
     * @return array Results of processing
     */
    public function processDueReports(): array
    {
        $dueReports = $this->repository->findDueReports();
        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($dueReports as $report) {
            try {
                $this->tenantContext->setTenantId($report->getTenantId());

                $this->processReport($report);

                $results['success']++;
                $results['details'][] = [
                    'id' => $report->getId(),
                    'name' => $report->getName(),
                    'status' => 'success',
                ];
            } catch (\Exception $e) {
                $results['failed']++;
                $results['details'][] = [
                    'id' => $report->getId(),
                    'name' => $report->getName(),
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];

                $this->logger->error('Failed to process scheduled report', [
                    'report_id' => $report->getId(),
                    'error' => $e->getMessage(),
                ]);

                // Update report with error status
                $report->setLastRunStatus(0);
                $report->setLastRunMessage($e->getMessage());
                $this->entityManager->flush();
            }

            $results['processed']++;
        }

        return $results;
    }

    /**
     * Process a single scheduled report
     */
    public function processReport(ScheduledReport $report): void
    {
        $this->logger->info('Processing scheduled report', [
            'report_id' => $report->getId(),
            'name' => $report->getName(),
            'type' => $report->getReportType(),
        ]);

        // Generate the report content
        $content = $this->generateReportContent($report);
        $filename = $this->generateFilename($report);
        $mimeType = $report->getFormat() === ScheduledReport::FORMAT_PDF
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        // Send to all recipients
        foreach ($report->getRecipients() as $recipient) {
            $this->sendReportEmail($report, $recipient, $content, $filename, $mimeType);
        }

        // Update report status
        $report->setLastRunAt(new DateTime());
        $report->setLastRunStatus(1);
        $report->setLastRunMessage('Report generated and sent successfully to ' . count($report->getRecipients()) . ' recipient(s)');
        $report->calculateNextRunAt();

        $this->entityManager->flush();

        $this->logger->info('Scheduled report processed successfully', [
            'report_id' => $report->getId(),
            'recipients' => count($report->getRecipients()),
        ]);
    }

    /**
     * Generate report content based on type and format
     */
    private function generateReportContent(ScheduledReport $report): string
    {
        $format = $report->getFormat();
        $type = $report->getReportType();

        if ($format === ScheduledReport::FORMAT_PDF) {
            return $this->generatePdfReport($type);
        }

        return $this->generateExcelReport($type);
    }

    /**
     * Generate PDF report
     */
    private function generatePdfReport(string $type): string
    {
        $data = $this->getReportData($type);
        $template = $this->getReportTemplate($type);
        $generatedAt = new DateTime();

        return $this->pdfExportService->generatePdf($template, array_merge($data, [
            'generated_at' => $generatedAt,
            'version' => $generatedAt->format('Y.m.d'),
        ]));
    }

    /**
     * Generate Excel report
     */
    private function generateExcelReport(string $type): string
    {
        $data = $this->getReportData($type);

        switch ($type) {
            case ScheduledReport::TYPE_RISK:
                return $this->generateRiskExcel($data);
            case ScheduledReport::TYPE_ASSETS:
                return $this->generateAssetsExcel($data);
            default:
                // For types without Excel support, generate a simple summary
                return $this->generateSummaryExcel($type, $data);
        }
    }

    /**
     * Get report data based on type
     */
    private function getReportData(string $type): array
    {
        return match ($type) {
            ScheduledReport::TYPE_EXECUTIVE => [
                'summary' => $this->reportService->getExecutiveSummary(),
                'risk_trends' => $this->reportService->getRiskTrendData(12),
                'incident_trends' => $this->reportService->getIncidentTrendData(12),
            ],
            ScheduledReport::TYPE_RISK => [
                'report' => $this->reportService->getRiskManagementReport(),
                'trends' => $this->reportService->getRiskTrendData(12),
            ],
            ScheduledReport::TYPE_BCM => [
                'report' => $this->reportService->getBCMReport(),
                'bia' => $this->reportService->getBIASummary(),
            ],
            ScheduledReport::TYPE_COMPLIANCE => [
                'report' => $this->reportService->getComplianceStatusReport(),
            ],
            ScheduledReport::TYPE_AUDIT => [
                'report' => $this->reportService->getAuditManagementReport(),
            ],
            ScheduledReport::TYPE_ASSETS => [
                'report' => $this->reportService->getAssetManagementReport(),
            ],
            ScheduledReport::TYPE_GDPR => [
                'report' => $this->reportService->getDataBreachReport(),
            ],
            default => throw new \InvalidArgumentException("Unknown report type: {$type}"),
        };
    }

    /**
     * Get PDF template path for report type
     */
    private function getReportTemplate(string $type): string
    {
        return match ($type) {
            ScheduledReport::TYPE_EXECUTIVE => 'management_reports/executive_pdf.html.twig',
            ScheduledReport::TYPE_RISK => 'management_reports/risk_pdf.html.twig',
            ScheduledReport::TYPE_BCM => 'management_reports/bcm_pdf.html.twig',
            ScheduledReport::TYPE_COMPLIANCE => 'management_reports/compliance_pdf.html.twig',
            ScheduledReport::TYPE_AUDIT => 'management_reports/audit_pdf.html.twig',
            ScheduledReport::TYPE_ASSETS => 'management_reports/assets_pdf.html.twig',
            ScheduledReport::TYPE_GDPR => 'management_reports/gdpr_pdf.html.twig',
            default => throw new \InvalidArgumentException("Unknown report type: {$type}"),
        };
    }

    /**
     * Generate filename for report
     */
    private function generateFilename(ScheduledReport $report): string
    {
        $date = date('Y-m-d');
        $type = str_replace('_', '-', $report->getReportType());
        $extension = $report->getFormat() === ScheduledReport::FORMAT_PDF ? 'pdf' : 'xlsx';

        return "{$type}_report_{$date}.{$extension}";
    }

    /**
     * Send report via email
     */
    private function sendReportEmail(
        ScheduledReport $report,
        string $recipient,
        string $content,
        string $filename,
        string $mimeType,
    ): void {
        $typeLabel = ScheduledReport::getReportTypes()[$report->getReportType()] ?? $report->getReportType();

        $email = (new Email())
            ->from("{$this->senderName} <{$this->senderEmail}>")
            ->to($recipient)
            ->subject("[ISMS Report] {$report->getName()} - {$typeLabel}")
            ->text($this->getEmailText($report, $typeLabel))
            ->html($this->getEmailHtml($report, $typeLabel))
            ->attach($content, $filename, $mimeType);

        $this->mailer->send($email);
    }

    /**
     * Get plain text email content
     */
    private function getEmailText(ScheduledReport $report, string $typeLabel): string
    {
        $date = date('Y-m-d H:i');

        return <<<TEXT
        Scheduled Report: {$report->getName()}

        Report Type: {$typeLabel}
        Generated: {$date}
        Schedule: {$report->getSchedule()}

        The report is attached to this email.

        --
        Little ISMS Helper
        Automated Report System
        TEXT;
    }

    /**
     * Get HTML email content
     */
    private function getEmailHtml(ScheduledReport $report, string $typeLabel): string
    {
        $date = date('Y-m-d H:i');

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #0f172a; color: #06b6d4; padding: 20px; }
                .content { padding: 20px; }
                .footer { background: #f8fafc; padding: 15px; font-size: 12px; color: #666; }
                .badge { display: inline-block; padding: 4px 8px; background: #06b6d4; color: white; border-radius: 4px; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1 style="margin: 0;">Scheduled Report</h1>
                <p style="margin: 5px 0 0;">{$report->getName()}</p>
            </div>
            <div class="content">
                <p><strong>Report Type:</strong> <span class="badge">{$typeLabel}</span></p>
                <p><strong>Generated:</strong> {$date}</p>
                <p><strong>Schedule:</strong> {$report->getSchedule()}</p>
                <hr>
                <p>The report is attached to this email.</p>
            </div>
            <div class="footer">
                <p>Little ISMS Helper - Automated Report System</p>
                <p>This is an automated message. Please do not reply directly to this email.</p>
            </div>
        </body>
        </html>
        HTML;
    }

    /**
     * Generate Risk Excel report
     */
    private function generateRiskExcel(array $data): string
    {
        $report = $data['report'];
        $headers = ['ID', 'Title', 'Category', 'Likelihood', 'Impact', 'Score', 'Level', 'Treatment', 'Status', 'Owner'];
        $rows = [];

        foreach ($report['risks'] as $risk) {
            $score = $risk->getRiskScore();
            $level = match (true) {
                $score >= 20 => 'Critical',
                $score >= 12 => 'High',
                $score >= 6 => 'Medium',
                default => 'Low',
            };

            $rows[] = [
                $risk->getId(),
                $risk->getTitle(),
                $risk->getCategory(),
                $risk->getProbability(),
                $risk->getImpact(),
                $score,
                $level,
                $risk->getTreatmentStrategy() ?? '-',
                $risk->getStatus(),
                $risk->getRiskOwner() ? $risk->getRiskOwner()->getEmail() : '-',
            ];
        }

        $spreadsheet = $this->excelExportService->exportArray($rows, $headers, 'Risk Management Report');
        return $this->excelExportService->generateExcel($spreadsheet);
    }

    /**
     * Generate Assets Excel report
     */
    private function generateAssetsExcel(array $data): string
    {
        $report = $data['report'];
        $headers = ['ID', 'Name', 'Type', 'Classification', 'Owner', 'Status'];
        $rows = [];

        foreach ($report['assets'] as $asset) {
            $rows[] = [
                $asset->getId(),
                $asset->getName(),
                $asset->getAssetType(),
                $asset->getClassification() ?? 'Unclassified',
                $asset->getOwner() ? $asset->getOwner()->getEmail() : '-',
                $asset->getStatus() ?? 'Active',
            ];
        }

        $spreadsheet = $this->excelExportService->exportArray($rows, $headers, 'Asset Inventory');
        return $this->excelExportService->generateExcel($spreadsheet);
    }

    /**
     * Generate Summary Excel for types without specific Excel support
     */
    private function generateSummaryExcel(string $type, array $data): string
    {
        $typeLabel = ScheduledReport::getReportTypes()[$type] ?? $type;
        $headers = ['Metric', 'Value'];
        $rows = $this->flattenDataForExcel($data);

        $spreadsheet = $this->excelExportService->exportArray($rows, $headers, $typeLabel);
        return $this->excelExportService->generateExcel($spreadsheet);
    }

    /**
     * Flatten nested data for Excel export
     */
    private function flattenDataForExcel(array $data, string $prefix = ''): array
    {
        $rows = [];

        foreach ($data as $key => $value) {
            $label = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                if ($this->isAssociativeArray($value)) {
                    $rows = array_merge($rows, $this->flattenDataForExcel($value, $label));
                } else {
                    $rows[] = [$label, count($value) . ' items'];
                }
            } elseif (is_object($value)) {
                if ($value instanceof \DateTimeInterface) {
                    $rows[] = [$label, $value->format('Y-m-d H:i')];
                }
            } elseif (is_bool($value)) {
                $rows[] = [$label, $value ? 'Yes' : 'No'];
            } else {
                $rows[] = [$label, (string) $value];
            }
        }

        return $rows;
    }

    /**
     * Check if array is associative
     */
    private function isAssociativeArray(array $arr): bool
    {
        if ([] === $arr) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Manually trigger a scheduled report (for testing)
     */
    public function triggerReport(ScheduledReport $report): void
    {
        $this->tenantContext->setTenantId($report->getTenantId());
        $this->processReport($report);
    }

    /**
     * Preview report content (without sending)
     */
    public function previewReport(ScheduledReport $report): string
    {
        $this->tenantContext->setTenantId($report->getTenantId());
        return $this->generateReportContent($report);
    }
}

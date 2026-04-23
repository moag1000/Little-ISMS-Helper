<?php

namespace App\Service;

use DateTime;
use App\Entity\ScheduledReport;
use App\Repository\RiskRepository;
use App\Repository\ScheduledReportRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\Mail\MailerTlsChecker;
use App\Service\Mail\RecipientFilter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly MailerTlsChecker $tlsChecker,
        private readonly RecipientFilter $recipientFilter,
        private readonly UserRepository $userRepository,
        private readonly TranslatorInterface $translator,
        private readonly AuditLogger $auditLogger,
        private readonly PortfolioReportService $portfolioReportService,
        private readonly DashboardStatisticsService $dashboardStatisticsService,
        private readonly RoleDashboardService $roleDashboardService,
        private readonly ComplianceAnalyticsService $complianceAnalyticsService,
        private readonly RiskRepository $riskRepository,
        private readonly Security $security,
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
        // ISB MINOR-4: verify mailer TLS ONCE before batch run. If the DSN is
        // plain SMTP without encryption, refuse to process anything and surface
        // the failure on each due report's run record.
        try {
            $this->tlsChecker->assertTlsConfigured();
        } catch (\RuntimeException $tlsException) {
            return $this->recordBatchTlsFailure($tlsException);
        }

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

        // ISB MINOR-4: evidence that the TLS pre-flight succeeded before send.
        $this->tlsChecker->assertTlsConfigured();
        $report->setTlsVerifiedAt(new DateTime());

        // ISB MINOR-4: enforce tenant + role filter, audit-log every drop.
        $filterResult = $this->recipientFilter->filter($report);
        foreach ($filterResult['dropped'] as $entry) {
            $this->auditDroppedRecipient($report, $entry['email'], $entry['reason']);
        }

        if ($filterResult['valid'] === []) {
            throw new \RuntimeException('No qualifying recipients after role/tenant check.');
        }

        // Generate the report content
        $content = $this->generateReportContent($report);
        $filename = $this->generateFilename($report);
        $mimeType = $report->getFormat() === ScheduledReport::FORMAT_PDF
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        foreach ($filterResult['valid'] as $recipient) {
            $this->sendReportEmail($report, $recipient, $content, $filename, $mimeType);
        }

        // Update report status
        $report->setLastRunAt(new DateTime());
        $report->setLastRunStatus(1);
        $report->setLastRunMessage(sprintf(
            'Report generated and sent successfully to %d qualifying recipient(s); %d dropped.',
            count($filterResult['valid']),
            count($filterResult['dropped']),
        ));
        $report->calculateNextRunAt();

        $this->entityManager->flush();

        $this->logger->info('Scheduled report processed successfully', [
            'report_id' => $report->getId(),
            'recipients_sent' => count($filterResult['valid']),
            'recipients_dropped' => count($filterResult['dropped']),
        ]);
    }

    /**
     * Mark all due reports as failed when the mailer DSN fails the TLS check.
     * The TLS assertion ran exactly once at the top of processDueReports().
     */
    private function recordBatchTlsFailure(\RuntimeException $tlsException): array
    {
        $dueReports = $this->repository->findDueReports();
        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($dueReports as $report) {
            $report->setLastRunAt(new DateTime());
            $report->setLastRunStatus(0);
            $report->setLastRunMessage($tlsException->getMessage());
            $results['failed']++;
            $results['processed']++;
            $results['details'][] = [
                'id' => $report->getId(),
                'name' => $report->getName(),
                'status' => 'failed',
                'error' => $tlsException->getMessage(),
            ];
        }

        if ($dueReports !== []) {
            $this->entityManager->flush();
        }

        $this->logger->error('Scheduled report batch aborted: mailer DSN fails TLS policy', [
            'error' => $tlsException->getMessage(),
            'affected_reports' => count($dueReports),
        ]);

        return $results;
    }

    private function auditDroppedRecipient(ScheduledReport $report, string $email, string $reason): void
    {
        $this->logger->warning('Scheduled report recipient dropped', [
            'report_id' => $report->getId(),
            'email' => $email,
            'reason' => $reason,
        ]);

        try {
            $this->auditLogger->logCustom(
                action: 'scheduled_report.recipient_dropped',
                entityType: 'ScheduledReport',
                entityId: $report->getId(),
                oldValues: ['email' => $email, 'reason' => $reason],
                newValues: null,
                description: sprintf('Recipient %s dropped: %s', $email, $reason),
            );
        } catch (\Throwable $e) {
            // Audit-log failures must not break report sending; they are already logged above.
            $this->logger->error('Audit log write failed for dropped recipient', [
                'error' => $e->getMessage(),
            ]);
        }
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
            ScheduledReport::TYPE_PORTFOLIO => $this->getPortfolioReportData(),
            ScheduledReport::TYPE_BOARD => $this->getBoardReportData(),
            default => throw new \InvalidArgumentException("Unknown report type: {$type}"),
        };
    }

    /**
     * Get portfolio report data using PortfolioReportService
     */
    private function getPortfolioReportData(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw new \RuntimeException('Portfolio report requires a tenant context.');
        }

        $stichtag = new \DateTimeImmutable();
        $matrix = $this->portfolioReportService->buildMatrix($tenant, $stichtag, null);

        return [
            'matrix' => $matrix,
            'tenant' => $tenant,
            'stichtag' => $stichtag,
            'vorperiode' => null,
            'thresholds' => ['green' => 80, 'amber' => 60],
        ];
    }

    /**
     * Get board one-pager report data using the same sources as ManagementReportController::boardOnePagerPdf()
     */
    private function getBoardReportData(): array
    {
        $kpis = $this->dashboardStatisticsService->getManagementKPIs();
        $boardData = $this->roleDashboardService->getBoardDashboard();

        // Top 5 risks sorted by inherent risk level
        $tenant = $this->security->getUser()?->getTenant()
            ?? $this->tenantContext->getCurrentTenant();
        $allRisks = $tenant ? $this->riskRepository->findByTenant($tenant) : [];
        usort($allRisks, fn($a, $b) => $b->getInherentRiskLevel() - $a->getInherentRiskLevel());
        $topRiskEntities = array_slice($allRisks, 0, 5);

        $topRisks = [];
        foreach ($topRiskEntities as $risk) {
            $score = $risk->getInherentRiskLevel();
            $level = match (true) {
                $score >= 20 => 'Critical',
                $score >= 12 => 'High',
                $score >= 6 => 'Medium',
                default => 'Low',
            };
            $topRisks[] = [
                'title' => $risk->getTitle(),
                'level' => $level,
                'score' => $score,
            ];
        }

        // Framework compliance
        $frameworkCompliance = [];
        $comparison = $this->complianceAnalyticsService->getFrameworkComparison();
        foreach ($comparison['frameworks'] ?? [] as $fw) {
            $frameworkCompliance[] = [
                'name' => $fw['name'],
                'percentage' => round($fw['compliance_percentage'] ?? 0),
            ];
        }

        return [
            'board_data' => $boardData,
            'kpis' => $kpis,
            'top_risks' => $topRisks,
            'framework_compliance' => $frameworkCompliance,
            'prepared_by' => $this->security->getUser()?->getFullName() ?? 'System',
        ];
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
            ScheduledReport::TYPE_PORTFOLIO => 'portfolio_report/pdf.html.twig',
            ScheduledReport::TYPE_BOARD => 'reports/board_one_pager.html.twig',
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
     * Send report via email.
     *
     * ISB MINOR-4: subject and body are generic — no inline report name or
     * type — per DSGVO Art. 32 data-minimisation. Content remains in the
     * attachment only, for tenant-internal recipients.
     */
    private function sendReportEmail(
        ScheduledReport $report,
        string $recipient,
        string $content,
        string $filename,
        string $mimeType,
    ): void {
        $locale = $report->getLocale() ?? 'de';
        $subject = $this->translator->trans(
            'email.subject_generic',
            ['{period}' => $report->getSchedule() ?? ''],
            'scheduled_reports',
            $locale,
        );
        $bodyText = $this->translator->trans('email.body_generic', [], 'scheduled_reports', $locale);

        $email = (new Email())
            ->from("{$this->senderName} <{$this->senderEmail}>")
            ->to($recipient)
            ->subject($subject)
            ->text($bodyText)
            ->html($this->getGenericEmailHtml($bodyText))
            ->attach($content, $filename, $mimeType);

        $this->mailer->send($email);
    }

    /**
     * Minimal HTML wrapper for the generic body. No report name/type inline.
     */
    private function getGenericEmailHtml(string $bodyText): string
    {
        $safe = htmlspecialchars($bodyText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; padding: 20px; }
                .footer { font-size: 12px; color: #666; margin-top: 20px; }
            </style>
        </head>
        <body>
            <p>{$safe}</p>
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
                $asset->getDataClassification() ?? 'Unclassified',
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

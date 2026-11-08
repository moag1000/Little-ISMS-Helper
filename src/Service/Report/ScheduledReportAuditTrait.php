<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Entity\ScheduledReport;
use App\Entity\Tenant;

/**
 * Audit-trail writes of ScheduledReportService (sent reports, dropped
 * recipients). Expects $this->auditLogger and $this->logger on the using class.
 */
trait ScheduledReportAuditTrait
{
    /**
     * A report leaves the system by mail: that is a data export, one trail row
     * per recipient, stamped with the report's tenant (cron has no tenant context).
     */
    private function auditSentReport(ScheduledReport $report, string $recipient, ?Tenant $tenant): void
    {
        try {
            $this->auditLogger->withTenant($tenant, fn () => $this->auditLogger->logExport(
                'ScheduledReport',
                $report->getId(),
                sprintf(
                    'Scheduled report "%s" (%s, %s) sent to %s',
                    $report->getName(),
                    $report->getReportType(),
                    $report->getFormat(),
                    $recipient,
                ),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Audit log write failed for sent scheduled report', ['error' => $e->getMessage()]);
        }
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
}

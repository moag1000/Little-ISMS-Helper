<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\InternalAudit;

/**
 * Finding backlog of a set of audits — the one definition shared by the
 * audit KPI (DashboardStatisticsService) and the management-review report
 * (ManagementReportService → ManagementReviewAutoCollector).
 *
 * Counts the structured AuditFinding records (getFindings() is the
 * deprecated free-text field). Open = not done
 * ({@see \App\Enum\AuditFindingStatus::isDone()}: resolved / verified /
 * closed), whatever year the audit belongs to — a backlog, not a period.
 */
final class AuditFindingBacklog
{
    /**
     * @param iterable<InternalAudit> $audits
     *
     * @return array{total_findings: int, open_findings: int}
     */
    public static function count(iterable $audits): array
    {
        $total = 0;
        $open = 0;
        foreach ($audits as $audit) {
            foreach ($audit->getStructuredFindings() as $finding) {
                ++$total;
                if (!$finding->isDone()) {
                    ++$open;
                }
            }
        }

        return ['total_findings' => $total, 'open_findings' => $open];
    }
}

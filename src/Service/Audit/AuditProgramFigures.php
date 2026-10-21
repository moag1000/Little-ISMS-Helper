<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\InternalAudit;

/**
 * Audit-programme figures of the current calendar year — the one definition
 * shared by the audit KPI (DashboardStatisticsService) and the
 * management-review report (ManagementReportService →
 * ManagementReviewAutoCollector "audit results"): audits planned this year
 * (by planned date) and, of those, the completed ones.
 */
final class AuditProgramFigures
{
    /**
     * @param iterable<InternalAudit> $audits
     * @param \DateTimeInterface|null $now Clock for the year window (default: now); injectable for tests
     *
     * @return array{planned_audits: int, completed_audits: int}
     */
    public static function calendarYear(iterable $audits, ?\DateTimeInterface $now = null): array
    {
        $thisYear = ($now ?? new \DateTimeImmutable())->format('Y');
        $planned = 0;
        $completed = 0;
        foreach ($audits as $audit) {
            if ($audit->getPlannedDate()?->format('Y') !== $thisYear) {
                continue;
            }
            ++$planned;
            if ($audit->getStatus() === 'completed') {
                ++$completed;
            }
        }

        return ['planned_audits' => $planned, 'completed_audits' => $completed];
    }
}

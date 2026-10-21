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

    /**
     * Audits completed in the current calendar year: status "completed" and the
     * year of the completion date. InternalAudit records no completion
     * timestamp; `actualDate` (the date the audit was conducted) is the
     * completion date, falling back to the planned date when it is unset.
     *
     * @param iterable<InternalAudit> $audits
     * @param \DateTimeInterface|null $now clock (default: now)
     */
    public static function completedInYear(iterable $audits, ?\DateTimeInterface $now = null): int
    {
        $thisYear = ($now ?? new \DateTimeImmutable())->format('Y');
        $count = 0;
        foreach ($audits as $audit) {
            if ($audit->getStatus() !== 'completed') {
                continue;
            }
            $date = $audit->getActualDate() ?? $audit->getPlannedDate();
            if ($date?->format('Y') === $thisYear) {
                ++$count;
            }
        }

        return $count;
    }
}

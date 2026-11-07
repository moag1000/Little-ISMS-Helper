<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Tenant;
use App\Enum\SlaDeadlineType;
use App\Repository\DataBreachRepository;
use App\Repository\IncidentRepository;
use App\Service\Incident\ReportingDuty;
use App\Service\Incident\ReportingPlanner;

/**
 * The `reporting_duty` compliance-wizard check: were the statutory reports of
 * the configured `deadline_types` (NIS-2 Art. 23, DORA Art. 19, GDPR Art. 33)
 * made in time? Reads the duties the {@see ReportingPlanner} plans for the
 * organisation's incidents and data breaches — the same ones My Day and the
 * reminder mails show.
 *
 * Counts duties that are due (deadline passed) or already reported; a duty
 * still running is not judged yet. No such duty is "no data".
 */
final class ReportingDutyCheck
{
    public function __construct(
        private readonly ReportingPlanner $planner,
        private readonly IncidentRepository $incidents,
        private readonly DataBreachRepository $breaches,
    ) {
    }

    /**
     * @param array<string, mixed> $check config key `deadline_types` (list of SlaDeadlineType values)
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function check(array $check, ?Tenant $tenant, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $types = array_values(array_filter(array_map(
            static fn (mixed $value): ?SlaDeadlineType => is_string($value) ? SlaDeadlineType::tryFrom($value) : null,
            (array) ($check['deadline_types'] ?? []),
        )));

        $judged = 0;
        $inTime = 0;
        $overdue = 0;
        foreach ($this->duties($tenant, $types) as $duty) {
            if (!$duty->isReported() && $duty->deadline >= $now) {
                continue; // still running
            }
            ++$judged;
            $inTime += $duty->isReportedInTime() ? 1 : 0;
            $overdue += $duty->isOverdue($now) ? 1 : 0;
        }

        $typeValues = array_map(static fn (SlaDeadlineType $type): string => $type->value, $types);
        if ($judged === 0) {
            return NoDataResult::build('wizard.gap.no_reporting_duty', 'app_incident_index', $check, [
                'type' => 'reporting_duty',
                'total' => 0,
                'deadline_types' => $typeValues,
            ]);
        }

        $score = round($inTime / $judged * 100, 1);
        $gap = null;
        if ($score < 100.0) {
            $gap = [
                'title' => 'wizard.gap.reporting_late',
                'description' => 'wizard.gap.reporting_late_desc',
                'description_params' => ['%late%' => $judged - $inTime, '%total%' => $judged, '%overdue%' => $overdue],
                'priority' => $check['priority'] ?? 'critical',
                'route' => $check['route'] ?? 'app_incident_index',
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'type' => 'reporting_duty',
                'total' => $judged,
                'in_time' => $inTime,
                'overdue' => $overdue,
                'deadline_types' => $typeValues,
            ],
            'gap' => $gap,
        ];
    }

    /**
     * @param list<SlaDeadlineType> $types
     * @return iterable<ReportingDuty>
     */
    private function duties(?Tenant $tenant, array $types): iterable
    {
        if (!$tenant instanceof Tenant || $types === []) {
            return;
        }
        $records = in_array(SlaDeadlineType::GdprNotification72h, $types, true)
            ? [...$this->incidents->findByTenant($tenant), ...$this->breaches->findByTenant($tenant)]
            : $this->incidents->findByTenant($tenant);
        foreach ($records as $record) {
            foreach ($this->planner->dutiesFor($record) as $duty) {
                if (in_array($duty->type, $types, true)) {
                    yield $duty;
                }
            }
        }
    }
}

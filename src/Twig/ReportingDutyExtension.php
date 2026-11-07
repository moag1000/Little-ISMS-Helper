<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Incident\ReportingDuty;
use App\Service\Incident\ReportingPlanner;
use Twig\Attribute\AsTwigFunction;

/**
 * `reporting_duties(incident|breach)` — the statutory reports of a record
 * (NIS-2, DORA, GDPR) from the one planner, for the detail pages.
 */
final readonly class ReportingDutyExtension
{
    public function __construct(
        private ReportingPlanner $planner,
    ) {
    }

    /** @return list<ReportingDuty> */
    #[AsTwigFunction('reporting_duties')]
    public function reportingDuties(object $record): array
    {
        return $this->planner->dutiesFor($record);
    }
}

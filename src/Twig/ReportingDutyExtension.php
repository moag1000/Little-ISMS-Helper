<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Repository\DataBreachRepository;
use App\Service\Incident\ReportingDuty;
use App\Service\Incident\ReportingPlanner;
use Twig\Attribute\AsTwigFunction;

/**
 * `reporting_duties(incident|breach)` — the statutory reports of a record
 * (NIS-2, DORA, GDPR) from the one planner, for the detail pages.
 * `data_breach_of(incident)` — the data breach recorded for an incident, so
 * the incident page shows the breach's status and its GDPR report.
 */
final readonly class ReportingDutyExtension
{
    public function __construct(
        private ReportingPlanner $planner,
        private DataBreachRepository $breaches,
    ) {
    }

    /** @return list<ReportingDuty> */
    #[AsTwigFunction('reporting_duties')]
    public function reportingDuties(object $record): array
    {
        return $this->planner->dutiesFor($record);
    }

    #[AsTwigFunction('data_breach_of')]
    public function dataBreachOf(Incident $incident): ?DataBreach
    {
        return $incident->getId() === null ? null : $this->breaches->findOneBy(['incident' => $incident]);
    }
}

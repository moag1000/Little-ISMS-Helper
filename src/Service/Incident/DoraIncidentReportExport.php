<?php

declare(strict_types=1);

namespace App\Service\Incident;

use App\Entity\BusinessProcess;
use App\Entity\Incident;
use App\Enum\SlaDeadlineType;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The DORA report of a major ICT-related incident (DORA Art. 19 (4),
 * content per Commission Delegated Regulation (EU) 2025/301 and the reporting
 * template of Implementing Regulation (EU) 2025/302) as JSON: initial
 * notification, intermediate report and final report. Each phase carries the
 * fields known by then; deadlines come from the {@see ReportingPlanner}, so
 * the export and the incident page never disagree. The text the authority
 * reads is the incident's report summary when one is written.
 */
final readonly class DoraIncidentReportExport
{
    public const array PHASES = ['initial', 'intermediate', 'final'];

    private const array DUTY = [
        'initial' => SlaDeadlineType::DoraInitialNotification4h,
        'intermediate' => SlaDeadlineType::DoraIntermediate72h,
        'final' => SlaDeadlineType::DoraFinalReport1mo,
    ];

    public function __construct(
        private ReportingPlanner $planner,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(Incident $incident, string $phase): array
    {
        $tenant = $incident->getTenant();
        $duty = isset(self::DUTY[$phase]) ? $this->planner->duty($incident, self::DUTY[$phase]) : null;

        $payload = [
            'meta' => [
                'schema' => 'dora-art19/eu-2025-302/v1',
                'phase' => $phase,
                'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'deadline' => $duty?->deadline->format(DATE_ATOM),
                'submitted_at' => $duty?->reportedAt?->format(DATE_ATOM),
            ],
            'financial_entity' => [
                'name' => $tenant?->getName(),
                'legal_name' => $tenant?->getLegalName(),
                'lei' => $tenant?->getLeiCode(),
            ],
            'incident' => [
                'reference' => $incident->getIncidentNumber(),
                'classification' => $incident->getIctIncidentClassification(),
                'occurred_at' => self::date($incident->getOccurredAt()),
                'detected_at' => self::date($incident->getDetectedAt()),
                'description' => $this->description($incident),
                'recurring' => $incident->isRecurringIncident(),
            ],
            'impact' => [
                'clients_affected' => $incident->getClientsAffected(),
                'clients_affected_financial_volume_eur' => $incident->getClientsAffectedFinancialVolume(),
                'data_loss' => $incident->isDataLossOccurred(),
                'data_leakage' => $incident->isDataLeakageOccurred(),
                'reputational_impact' => $incident->getReputationalImpact(),
                'cross_border' => $incident->isCrossBorderImpact(),
                'economic_impact_eur' => $incident->getEstimatedFinancialImpact(),
                'critical_services_affected' => array_values(array_map(
                    static fn (BusinessProcess $p): ?string => $p->getName(),
                    $incident->getCriticalServicesAffected()->toArray(),
                )),
            ],
        ];

        if ($phase !== 'initial') {
            $payload['handling'] = [
                'containment' => $incident->getContainmentActions(),
                'replication_of_impact' => $incident->isReplicationOfImpact(),
                'data_recovery' => $incident->getDataRecoveryStrategy(),
            ];
        }
        if ($phase === 'final') {
            $payload['closure'] = [
                'root_cause' => $incident->getRootCause(),
                'corrective_actions' => $incident->getCorrectiveActions(),
                'lessons_learned' => $incident->getLessonsLearned(),
                'resolved_at' => self::date($incident->getResolvedAt()),
            ];
        }

        return $payload;
    }

    private function description(Incident $incident): ?string
    {
        $summary = trim((string) $incident->getReportSummary());

        return $summary !== '' ? $summary : $incident->getDescription();
    }

    private static function date(?DateTimeInterface $value): ?string
    {
        return $value?->format(DATE_ATOM);
    }
}

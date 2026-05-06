<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Incident;
use DateTimeImmutable;

/**
 * BSI MUS (Meldeumgebung) export service for NIS2 Article 23 incident reporting.
 *
 * Produces machine-readable JSON payloads for the three mandatory
 * reporting phases:
 *   - Early Warning   — 24h after becoming aware of a significant incident
 *   - Detailed Notification — 72h after becoming aware
 *   - Final Report    — at most 1 month after the detailed notification
 *
 * Maps the existing Incident entity fields into the payload shape the
 * BSI MUS portal expects. Operators copy the JSON into their MUS upload,
 * or a downstream integration POSTs it directly.
 */
class Nis2MusExportService
{
    private const int EARLY_WARNING_DEADLINE_HOURS = 24;
    private const int DETAILED_NOTIFICATION_DEADLINE_HOURS = 72;
    private const int FINAL_REPORT_DEADLINE_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public function buildEarlyWarningPayload(Incident $incident): array
    {
        return [
            'meta' => $this->buildMeta($incident, 'early_warning'),
            'entity' => $this->buildEntity($incident),
            'incident' => [
                'incident_id' => $incident->getIncidentNumber(),
                'detected_at' => $incident->getDetectedAt()?->format(DATE_ATOM),
                'occurred_at' => $incident->getOccurredAt()?->format(DATE_ATOM),
                'preliminary_severity' => $incident->getSeverity()?->value,
                'nis2_category' => $incident->getNis2Category(),
                'suspected_malicious' => true,
                'cross_border_impact' => $incident->isCrossBorderImpact(),
                'significant_incident_indicator' => $this->isSignificant($incident),
                'description_short' => $this->shortDescription($incident),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDetailedNotificationPayload(Incident $incident): array
    {
        $base = $this->buildEarlyWarningPayload($incident);
        $base['meta']['phase'] = 'detailed_notification';
        $base['incident']['severity_assessment'] = $incident->getSeverity()?->value;
        $base['incident']['affected_users_count'] = $incident->getAffectedUsersCount();
        $base['incident']['estimated_financial_impact_eur'] = $incident->getEstimatedFinancialImpact() !== null
            ? (float) $incident->getEstimatedFinancialImpact()
            : null;
        $base['incident']['mitigation_taken'] = $incident->getCorrectiveActions();
        $base['incident']['updated_description'] = $incident->getDescription();
        $base['incident']['indicators_of_compromise'] = $this->indicators($incident);

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFinalReportPayload(Incident $incident): array
    {
        $base = $this->buildDetailedNotificationPayload($incident);
        $base['meta']['phase'] = 'final_report';
        $base['incident']['root_cause'] = $incident->getRootCause();
        $base['incident']['mitigation_measures_implemented'] = $incident->getPreventiveActions() ?? $incident->getCorrectiveActions();
        $base['incident']['lessons_learned'] = $incident->getLessonsLearned();
        $base['incident']['cross_border_impact_details'] = $incident->isCrossBorderImpact()
            ? $incident->getDescription()
            : null;
        $base['incident']['remediation_completion_date'] = $incident->getResolvedAt()?->format(DATE_ATOM);

        return $base;
    }

    /**
     * Compute deadline status for all three reporting phases. Each entry
     * carries the deadline timestamp, whether it is overdue, and whether
     * the corresponding submission has been recorded.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getDeadlineStatus(Incident $incident, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $detectedAt = $incident->getDetectedAt();
        $detected = $detectedAt instanceof DateTimeImmutable
            ? $detectedAt
            : ($detectedAt instanceof \DateTimeInterface
                ? DateTimeImmutable::createFromInterface($detectedAt)
                : null);

        if ($detected === null) {
            return [
                'early_warning' => $this->emptyDeadline(),
                'detailed_notification' => $this->emptyDeadline(),
                'final_report' => $this->emptyDeadline(),
            ];
        }

        $earlyWarningDue = $detected->modify('+' . self::EARLY_WARNING_DEADLINE_HOURS . ' hours');
        $detailedDue = $detected->modify('+' . self::DETAILED_NOTIFICATION_DEADLINE_HOURS . ' hours');
        $finalDue = ($incident->getDetailedNotificationReportedAt() ?? $detailedDue)
            ->modify('+' . self::FINAL_REPORT_DEADLINE_DAYS . ' days');

        return [
            'early_warning' => $this->deadlineEntry(
                $earlyWarningDue,
                $now,
                $incident->getEarlyWarningReportedAt(),
            ),
            'detailed_notification' => $this->deadlineEntry(
                $detailedDue,
                $now,
                $incident->getDetailedNotificationReportedAt(),
            ),
            'final_report' => $this->deadlineEntry(
                $finalDue,
                $now,
                $incident->getFinalReportSubmittedAt(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMeta(Incident $incident, string $phase): array
    {
        return [
            'schema' => 'bsi-mus/nis2-art23/v1',
            'phase' => $phase,
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'incident_status' => $incident->getStatus()?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntity(Incident $incident): array
    {
        $tenant = $incident->getTenant();

        return [
            'name' => $tenant?->getName(),
            'legal_name' => $tenant?->getLegalName(),
            'nace_code' => $tenant?->getNaceCode(),
            'nis2_classification' => $tenant?->getNis2Classification(),
            'nis2_sector' => $tenant?->getNis2Sector(),
            'contact_point' => $tenant?->getNis2ContactPoint(),
        ];
    }

    private function shortDescription(Incident $incident): ?string
    {
        $description = $incident->getDescription();
        if ($description === null) {
            return null;
        }

        return mb_substr($description, 0, 500);
    }

    /**
     * @return array<int, string>
     */
    private function indicators(Incident $incident): array
    {
        $iocs = [];
        $title = $incident->getTitle();
        if ($title !== null && $title !== '') {
            $iocs[] = $title;
        }

        return $iocs;
    }

    private function isSignificant(Incident $incident): bool
    {
        return $incident->isCrossBorderImpact()
            || ($incident->getAffectedUsersCount() ?? 0) > 0
            || ($incident->getEstimatedFinancialImpact() !== null && (float) $incident->getEstimatedFinancialImpact() > 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function deadlineEntry(
        DateTimeImmutable $due,
        DateTimeImmutable $now,
        ?DateTimeImmutable $submitted,
    ): array {
        $isSubmitted = $submitted instanceof DateTimeImmutable;
        $isOverdue = !$isSubmitted && $now > $due;

        return [
            'due_at' => $due->format(DATE_ATOM),
            'submitted_at' => $submitted?->format(DATE_ATOM),
            'submitted' => $isSubmitted,
            'overdue' => $isOverdue,
            'remaining_seconds' => $isSubmitted ? null : ($due->getTimestamp() - $now->getTimestamp()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDeadline(): array
    {
        return [
            'due_at' => null,
            'submitted_at' => null,
            'submitted' => false,
            'overdue' => false,
            'remaining_seconds' => null,
        ];
    }
}

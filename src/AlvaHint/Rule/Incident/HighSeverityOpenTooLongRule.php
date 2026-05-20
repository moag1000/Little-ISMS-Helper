<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Incident;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Incident;
use App\Entity\User;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Tier-1 hint: ISO 27001 A.5.26 / NIS2 Art. 23 — high/critical
 * incidents that remain open longer than SLA_HOURS hours without
 * reaching Resolved or Closed status require escalation. Tier-1
 * because the hint is non-dismissible: a stale high-severity incident
 * is always a hard finding at internal or external audit.
 */
final class HighSeverityOpenTooLongRule extends AbstractAlvaHintRule
{
    private const int SLA_HOURS = 48;

    public function key(): string
    {
        return 'incident.high_severity_open_too_long';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        return ['incidents'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Incident) {
            return false;
        }

        if (
            !in_array(
                $entity->getSeverity(),
                [IncidentSeverity::High, IncidentSeverity::Critical],
                true,
            )
        ) {
            return false;
        }

        // Only open statuses
        if (
            in_array(
                $entity->getStatus(),
                [IncidentStatus::Resolved, IncidentStatus::Closed],
                true,
            )
        ) {
            return false;
        }

        $detectedAt = $entity->getDetectedAt();
        if (!$detectedAt instanceof DateTimeInterface) {
            return false;
        }

        $deadline = DateTimeImmutable::createFromInterface($detectedAt)
            ->modify(sprintf('+%d hours', self::SLA_HOURS));

        return new DateTimeImmutable() > $deadline;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Incident);

        $detectedAt = $entity->getDetectedAt();
        $hoursOpen = 0;
        if ($detectedAt instanceof DateTimeInterface) {
            $diff = (new DateTimeImmutable())->diff(
                DateTimeImmutable::createFromInterface($detectedAt),
            );
            $hoursOpen = $diff->days * 24 + $diff->h;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'incident.high_severity_open_too_long.title',
            bodyTranslationKey: 'incident.high_severity_open_too_long.body',
            bodyTranslationParams: [
                '%hours%' => $hoursOpen,
                '%sla%' => self::SLA_HOURS,
            ],
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'Incident',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'incident.high_severity_open_too_long.action',
            actionRoute: 'app_incident_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'warning',
        );
    }
}

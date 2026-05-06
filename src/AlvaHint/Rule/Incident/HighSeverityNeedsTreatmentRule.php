<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Incident;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\User;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;

/**
 * Cross-domain hint: ISO 27001 A.5.27 — incidents are inputs into the
 * risk-treatment process. Hint fires for high/critical incidents at
 * critical-CIA assets that have moved past investigation but have not
 * yet seeded a Risk-Treatment-Plan.
 */
final class HighSeverityNeedsTreatmentRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'incident.needs_treatment_plan';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['incidents', 'risks'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Incident) {
            return false;
        }
        if (!in_array($entity->getSeverity(), [IncidentSeverity::High, IncidentSeverity::Critical], true)) {
            return false;
        }
        if (!in_array($entity->getStatus(), [IncidentStatus::InResolution, IncidentStatus::Resolved, IncidentStatus::Closed], true)) {
            return false;
        }
        if ($entity->getRealizedRisks()->count() > 0) {
            return false;
        }

        foreach ($entity->getAffectedAssets() as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $cia = max(
                (int) ($asset->getConfidentialityValue() ?? 0),
                (int) ($asset->getIntegrityValue() ?? 0),
                (int) ($asset->getAvailabilityValue() ?? 0),
            );
            if ($cia >= 4) {
                return true;
            }
        }

        return false;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Incident);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'incident.needs_treatment.title',
            bodyTranslationKey: 'incident.needs_treatment.body',
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Incident',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'incident.needs_treatment.action',
            actionRoute: 'app_risk_treatment_plan_new',
            actionRouteParams: [],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}

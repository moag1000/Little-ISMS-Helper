<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\CorrectiveAction;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\CorrectiveAction;
use App\Entity\User;

/**
 * S3 P0-30 — Tier-1 Pflicht-Hinweis.
 *
 * Sobald eine CAPA als `verified_ineffective` markiert wird, fordert
 * ISO 27001 Cl. 10.1 (b) eine erneute Bewertung und Behandlung. Diese
 * Regel surfacet einen non-dismissible Tier-1-Banner auf der Show-View
 * der unwirksamen CAPA mit Direct-Link zum vorbefüllten Folge-CAPA-
 * Form (Controller liest `?from_ineffective_capa=ID`).
 *
 * Skip-Condition: wenn die CAPA bereits eine nachgelagerte CAPA hat,
 * die sie als `previousCapa` referenziert, ist die Folgeschleife
 * abgehakt — Banner verschwindet (zumindest reduktion auf Audit-Trail-
 * Link statt CTA, hier weggelassen, das wird auf Show-Seite gelöst).
 */
final class IneffectiveFollowUpRequiredRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'corrective_action.ineffective_followup_required';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        return ['audits'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof CorrectiveAction) {
            return false;
        }
        return $entity->isVerifiedIneffective();
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof CorrectiveAction);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'corrective_action.alva_hint.ineffective_followup.title',
            bodyTranslationKey: 'corrective_action.alva_hint.ineffective_followup.body',
            bodyTranslationParams: [
                '%id%' => (string) ($entity->getId() ?? 0),
                '%title%' => (string) $entity->getTitle(),
            ],
            translationDomain: 'audits',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'CorrectiveAction',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'corrective_action.alva_hint.ineffective_followup.cta',
            actionRoute: 'app_corrective_action_new',
            actionRouteParams: [
                'from_ineffective_capa' => $entity->getId() ?? 0,
            ],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'alert',
        );
    }
}

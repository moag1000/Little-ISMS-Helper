<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\CorrectiveAction;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\CorrectiveAction;
use App\Entity\User;
use DateTimeImmutable;

/**
 * Junior-ISB-Audit C4-03 — Tier-2 hint:
 * ISO 27001 Cl. 10.1 d + Cl. 9.1.1 — Wirksamkeitsprüfung der CAPA.
 *
 * Service-Layer kennt `effectivenessReviewDate` schon (Field auf der
 * Entity, Validator beim Übergang completed → verified_*), aber das
 * Frontend gibt dem Anwender bisher keinen proaktiven Hinweis, dass
 * die Prüfung ansteht. Diese Regel surfacet einen warnenden Hint,
 * sobald das Datum im 7-Tage-Fenster liegt UND die CAPA noch nicht
 * verifiziert ist (Status `completed`).
 *
 * Skip-Condition:
 *   - kein `effectivenessReviewDate` gesetzt → no hint
 *   - bereits `verified_effective` / `verified_ineffective` → no hint
 *   - mehr als 7 Tage in der Zukunft → no hint
 *
 * CTA: Edit-Page der CAPA (dort kann der Anwender Evidence + verifiedBy
 * eintragen und über das Lifecycle-Menü transitionieren).
 *
 * Tier-2 (audit gap closer) + dismissible: Benutzer kann den Hint pro
 * Tag wegklicken, aber er kommt am Folgetag wieder bis das Datum
 * erreicht ist oder die Prüfung erfolgt.
 */
final class EffectivenessReviewDueRule extends AbstractAlvaHintRule
{
    /** Zeitfenster (Tage) in dem die Wirksamkeitsprüfung als "due soon" gilt. */
    private const REMINDER_WINDOW_DAYS = 7;

    public function key(): string
    {
        return 'corrective_action.effectiveness_review_due';
    }

    public function priorityTier(): int
    {
        return 2;
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
        // Bereits verifiziert? Dann ist nichts mehr zu tun.
        $status = $entity->getStatus();
        if ($status === CorrectiveAction::STATUS_VERIFIED_EFFECTIVE
            || $status === CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE) {
            return false;
        }
        return $entity->getEffectivenessReviewDate() !== null
            && $entity->isEffectivenessReviewDueWithin(self::REMINDER_WINDOW_DAYS);
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof CorrectiveAction);

        $reviewDate = $entity->getEffectivenessReviewDate();
        $daysUntil = 0;
        if ($reviewDate !== null) {
            $diff = (new DateTimeImmutable())->diff($reviewDate);
            // negative invert means: review date already in the past.
            $daysUntil = $diff->invert === 1 ? -$diff->days : $diff->days;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'corrective_action.alva_hint.effectiveness_review_due.title',
            bodyTranslationKey: 'corrective_action.alva_hint.effectiveness_review_due.body',
            bodyTranslationParams: [
                '%id%'        => (string) ($entity->getId() ?? 0),
                '%title%'     => (string) $entity->getTitle(),
                '%date%'      => $reviewDate?->format('Y-m-d') ?? '',
                '%daysUntil%' => (string) $daysUntil,
            ],
            translationDomain: 'audits',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'CorrectiveAction',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'corrective_action.alva_hint.effectiveness_review_due.cta',
            actionRoute: 'app_corrective_action_edit',
            actionRouteParams: [
                'id' => $entity->getId() ?? 0,
            ],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}

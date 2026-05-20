<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\DataBreach;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\DataBreach;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Tier-1 hint: GDPR Art. 33 — the 72h window has already closed for
 * a high/critical data breach without a supervisory-authority
 * notification. The existing Gdpr72hCountdownRule handles the window-
 * open case; this rule fires once the deadline is missed and
 * remains non-dismissible because the exposure is ongoing.
 */
final class NotificationOverdueRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'data_breach.notification_overdue';
    }

    public function priorityTier(): int
    {
        return 1;
    }

    public function requiredModules(): array
    {
        return ['compliance'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof DataBreach) {
            return false;
        }

        if (!in_array($entity->getSeverity(), ['high', 'critical'], true)) {
            return false;
        }

        // Already notified — no hint needed
        if ($entity->getSupervisoryAuthorityNotifiedAt() instanceof DateTimeInterface) {
            return false;
        }

        $deadline = $this->deadline($entity);
        if ($deadline === null) {
            return false;
        }

        // Hint fires only AFTER the deadline has passed
        return new DateTimeImmutable() >= $deadline;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof DataBreach);

        $deadline = $this->deadline($entity);
        $overdueDiff = $deadline !== null ? (new DateTimeImmutable())->diff($deadline) : null;
        $overdueHours = $overdueDiff !== null ? ($overdueDiff->days * 24 + $overdueDiff->h) : 0;

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'data_breach.notification_overdue.title',
            bodyTranslationKey: 'data_breach.notification_overdue.body',
            bodyTranslationParams: [
                '%hours%' => $overdueHours,
            ],
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'DataBreach',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'data_breach.notification_overdue.action',
            actionRoute: 'app_data_breach_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            mood: 'warning',
        );
    }

    private function deadline(DataBreach $entity): ?DateTimeImmutable
    {
        $detected = $entity->getDetectedAt();
        if (!$detected instanceof DateTimeInterface) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($detected)->modify('+72 hours');
    }
}

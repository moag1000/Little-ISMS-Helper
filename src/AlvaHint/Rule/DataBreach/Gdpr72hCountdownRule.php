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
 * Tier-1 hint: GDPR Art. 33 72h supervisory-authority notification.
 *
 * Trigger: severity high/critical, no authority notification yet,
 * and the deadline (detectedAt + 72h) has not passed yet — once it
 * has, the hint converts to a different (overdue) tier-1 hint we add
 * later. Non-dismissible by tier-1 contract.
 */
final class Gdpr72hCountdownRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'data_breach.gdpr_72h_countdown';
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
        if ($entity->getSupervisoryAuthorityNotifiedAt() instanceof DateTimeInterface) {
            return false;
        }

        $deadline = $this->deadline($entity);
        if ($deadline === null) {
            return false;
        }

        return new DateTimeImmutable() < $deadline;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof DataBreach);
        $deadline = $this->deadline($entity);
        $remaining = $deadline?->diff(new DateTimeImmutable());
        $remainingHours = $remaining !== null ? ($remaining->days * 24 + $remaining->h) : 0;
        $remainingMinutes = $remaining !== null ? $remaining->i : 0;

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'data_breach.gdpr_72h.title',
            bodyTranslationKey: 'data_breach.gdpr_72h.body',
            bodyTranslationParams: [
                '%hours%' => $remainingHours,
                '%minutes%' => $remainingMinutes,
            ],
            translationDomain: 'alva',
            variant: 'danger',
            priorityTier: 1,
            dismissible: false,
            entityType: 'DataBreach',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'data_breach.gdpr_72h.action',
            actionRoute: 'app_data_breach_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
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

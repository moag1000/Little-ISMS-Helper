<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Consent;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Consent;
use App\Entity\User;
use DateTimeImmutable;

/**
 * Tier-3 hint: GDPR Art. 7(3) — consent stays valid only as long as
 * the controller can demonstrate it. Hint fires when an active,
 * non-revoked consent expires within 30 days so the responder can
 * trigger a re-consent flow before the legal basis evaporates.
 */
final class ExpiringSoonRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'consent.expiring_soon';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['compliance'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Consent) {
            return false;
        }
        if ($entity->isRevoked()) {
            return false;
        }
        if ($entity->getStatus() !== 'active') {
            return false;
        }

        $expires = $entity->getExpiresAt();
        if (!$expires instanceof \DateTimeInterface) {
            return false;
        }

        $now = new DateTimeImmutable();
        $threshold = $now->modify('+30 days');

        return $expires > $now && $expires <= $threshold;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Consent);
        $expires = $entity->getExpiresAt();
        $days = 0;
        if ($expires instanceof \DateTimeInterface) {
            $diff = (new DateTimeImmutable())->diff($expires);
            $days = $diff->days ?? 0;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'consent.expiring_soon.title',
            bodyTranslationKey: 'consent.expiring_soon.body',
            bodyTranslationParams: [
                '%days%' => (string) $days,
            ],
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Consent',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'consent.expiring_soon.action',
            actionRoute: 'app_consent_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            mood: 'thinking',
        );
    }
}

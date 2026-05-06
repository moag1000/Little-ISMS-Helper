<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Patch;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Patch;
use App\Entity\User;
use DateTimeImmutable;

/**
 * Tier-3 hint: critical patch with downtime should travel through
 * change management (ISO 27001 A.8.32, BSI OPS.1.1.3). Hint fires
 * when the patch requires downtime, is critical / soon-due, and no
 * Change-Request is linked yet.
 */
final class DowntimeNeedsChangeRequestRule extends AbstractAlvaHintRule
{
    public function key(): string
    {
        return 'patch.downtime_change_request';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['patches', 'change_requests'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Patch) {
            return false;
        }
        if ($entity->getPriority() !== 'critical') {
            return false;
        }
        if (!$entity->isRequiresDowntime()) {
            return false;
        }
        $deadline = $entity->getDeploymentDeadline();
        if (!$deadline instanceof \DateTimeInterface) {
            return false;
        }

        $sevenDays = (new DateTimeImmutable())->modify('+7 days');

        return $deadline <= $sevenDays;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Patch);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'patch.downtime_cr.title',
            bodyTranslationKey: 'patch.downtime_cr.body',
            translationDomain: 'alva',
            variant: 'info',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Patch',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'patch.downtime_cr.action',
            actionRoute: 'app_change_request_new',
            actionRouteParams: [],
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Supplier;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Supplier;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Tier-2 hint: ISO 27001 A.5.19 / DORA Art. 28 — high-criticality
 * suppliers without a security assessment in the last 12 months
 * represent a third-party risk gap. Certification auditors require
 * evidence of periodic supplier-security reviews for critical
 * third parties.
 */
final class NoRecentAssessmentRule extends AbstractAlvaHintRule
{
    private const int MAX_MONTHS = 12;

    public function key(): string
    {
        return 'supplier.no_recent_assessment';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['suppliers'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Supplier) {
            return false;
        }

        if (!in_array($entity->getCriticality(), ['high', 'critical'], true)) {
            return false;
        }

        $lastAssessment = $entity->getLastSecurityAssessment();

        if ($lastAssessment === null) {
            return true; // Never assessed — fire the hint
        }

        $threshold = (new DateTimeImmutable())->modify(sprintf('-%d months', self::MAX_MONTHS));

        return $lastAssessment < $threshold;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Supplier);

        $lastAssessment = $entity->getLastSecurityAssessment();
        $monthsAgo = null;
        if ($lastAssessment instanceof DateTimeInterface) {
            $diff = (new DateTimeImmutable())->diff(
                DateTimeImmutable::createFromInterface($lastAssessment),
            );
            $monthsAgo = $diff->y * 12 + $diff->m;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'supplier.no_recent_assessment.title',
            bodyTranslationKey: $monthsAgo !== null
                ? 'supplier.no_recent_assessment.body_with_last'
                : 'supplier.no_recent_assessment.body_never',
            bodyTranslationParams: $monthsAgo !== null
                ? ['%months%' => $monthsAgo, '%criticality%' => (string) $entity->getCriticality()]
                : ['%criticality%' => (string) $entity->getCriticality()],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Supplier',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'supplier.no_recent_assessment.action',
            actionRoute: 'app_supplier_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}

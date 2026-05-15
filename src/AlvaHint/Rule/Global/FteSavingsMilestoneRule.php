<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Fte\FteTrackingMetricRepository;
use DateInterval;

/**
 * F11 Alva-Hint — FTE savings milestone celebration (18th end-to-end criterion).
 *
 * Fires when cumulative savings for the tenant exceed 1,000 minutes (≈17 hours).
 * Encourages continued use of F2 Bulk-Import and F4 Evidence-Reuse to keep the
 * savings graph climbing. Dismissible after first view.
 *
 * Tier: 3 (efficiency / insight)
 * Module: analytics
 * Role: ROLE_MANAGER
 */
final class FteSavingsMilestoneRule extends AbstractGlobalAlvaHintRule
{
    private const int THRESHOLD_MINUTES = 1000;

    public function __construct(
        private readonly FteTrackingMetricRepository $metricRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.fte_savings_milestone';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['analytics'];
    }

    public function appliesToPages(): array
    {
        return ['analytics_fte_index', 'app_dashboard'];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        // Rolling 365-day window for milestone check
        $window = new DateInterval('P365D');
        $totalSavings = $this->metricRepository->getSavingsAggregate($tenant, $window);

        if ($totalSavings < self::THRESHOLD_MINUTES) {
            return null;
        }

        $hours = round($totalSavings / 60, 1);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.fte_savings_milestone.title',
            bodyTranslationKey: 'global.fte_savings_milestone.body',
            bodyTranslationParams: [
                '%hours%' => (string) $hours,
                '%minutes%' => (string) $totalSavings,
            ],
            translationDomain: 'alva',
            variant: 'success',
            priorityTier: 3,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.fte_savings_milestone.action',
            actionRoute: 'analytics_fte_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
            version: 1,
        );
    }
}

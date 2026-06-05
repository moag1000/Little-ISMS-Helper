<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Authority\OverdueAuthorityNotificationResolver;

/**
 * Tier-2 warning: DataBreach without authority-export > 24h after detectedAt (DSGVO 72h deadline).
 *
 * GDPR Art. 33 requires notification to the supervisory authority within 72 hours
 * of becoming aware of a personal data breach. When a high/critical DataBreach
 * has been open for more than 24 hours without a documented authority-export event
 * this rule fires as a warning-tier hint to remind the DPO to use the
 * EU-Behörden-Export feature (F26).
 *
 * Trigger  : dashboard_ciso / dashboard_compliance_manager / inbox,
 *            DataBreach count > 0 for tenant, breach detected > 24h ago without export
 * Module   : privacy + eu_authority_reporting
 * Role     : ROLE_DPO (fallback ROLE_MANAGER)
 * Dismiss  : authority_template_overdue@v1
 */
final class AuthorityTemplateOverdueRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly OverdueAuthorityNotificationResolver $resolver,
    ) {
    }

    public function key(): string
    {
        return 'global.authority_template_overdue';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['privacy', 'eu_authority_reporting'];
    }

    public function appliesToPages(): array
    {
        return [
            'dashboard_ciso',
            'dashboard_compliance_manager',
            'inbox',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        // Single source of truth shared with the notification index
        // `focus=overdue` filter, so the hint deep-links to EXACTLY the breaches
        // it counts. The index is the action surface (per-breach export buttons),
        // so the filtered index is the correct target for one or many.
        $overdueCount = count($this->resolver->findOverdueBreaches($tenant));

        if ($overdueCount === 0) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.authority_template_overdue.title',
            bodyTranslationKey: 'global.authority_template_overdue.body',
            bodyTranslationParams: ['%count%' => (string) $overdueCount],
            translationDomain: 'eu_authorities',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.authority_template_overdue.action',
            actionRoute: 'app_authority_notification_index',
            actionRouteParams: ['focus' => 'overdue'],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_DPO', 'ROLE_MANAGER'],
            mood: 'warning',
            version: 1,
        );
    }
}

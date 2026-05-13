<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Notification\NotificationChannelRepository;

/**
 * Tier-2 hint: the notifications module is active but the tenant has no active channel.
 *
 * Without at least one active NotificationChannel, notification rules are silent —
 * no email, no webhook, no in-app delivery is possible. This hint surfaces the gap
 * before it becomes an audit finding (ISO 27001 Cl. 9.1 monitoring capability).
 *
 * Trigger  : any page, notifications module active, 0 active channels for tenant
 * Module   : notifications
 * Role     : ROLE_MANAGER
 * Tier     : 2 (audit-gap)
 */
class NoNotificationChannelRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly NotificationChannelRepository $channelRepository,
    ) {}

    public function key(): string
    {
        return 'global.no_notification_channel';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['notifications'];
    }

    public function appliesToPages(): array
    {
        return []; // fires on all pages when module is active
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        $activeChannels = $this->channelRepository->findBy([
            'tenant'   => $tenant,
            'isActive' => true,
        ]);

        if (!empty($activeChannels)) {
            return null;
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.no_notification_channel.title',
            bodyTranslationKey: 'global.no_notification_channel.body',
            bodyTranslationParams: [],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.no_notification_channel.action',
            actionRoute: 'admin_notification_channel_index',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'warning',
            version: 1,
        );
    }
}

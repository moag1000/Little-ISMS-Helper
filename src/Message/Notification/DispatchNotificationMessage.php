<?php

declare(strict_types=1);

namespace App\Message\Notification;

/**
 * Async message dispatched by NotificationDispatcher.
 *
 * Carries only scalar IDs to avoid serialising entity graphs.
 * The handler re-fetches channel + rule from the database.
 */
readonly final class DispatchNotificationMessage
{
    /**
     * @param array<string, mixed> $entityState Snapshot of the entity state at dispatch time.
     */
    public function __construct(
        public int $ruleId,
        public int $channelId,
        public array $entityState,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Service\Notification\Event;

use App\Entity\Tenant;

/**
 * A detected domain event ready to be matched against NotificationRules.
 *
 * @phpstan-type EventState array<string, scalar|null>
 */
final class DomainEvent
{
    /**
     * @param array<string, scalar|null> $state field => value, matched against
     *                                           a rule's conditions by the evaluator
     */
    public function __construct(
        public readonly string $eventType,
        public readonly Tenant $tenant,
        public readonly array $state,
    ) {
    }
}

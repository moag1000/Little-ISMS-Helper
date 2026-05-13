<?php

declare(strict_types=1);

namespace App\Service\Notification\Channel;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Delivers in-app notifications by persisting the delivery row as "sent".
 *
 * The Sprint-6b notification center polls for NotificationDelivery rows
 * with status=sent and channel.type=in_app to render the bell dropdown.
 * No external I/O occurs in this channel.
 */
class InAppChannel
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<string, mixed> $entityState
     */
    public function deliver(
        NotificationRule $rule,
        NotificationChannel $channel,
        NotificationDelivery $delivery,
        array $entityState,
    ): void {
        $delivery->markSent([
            'rule_name'    => $rule->getName(),
            'event_type'   => $rule->getEventType(),
            'entity_state' => $entityState,
        ]);

        // Flush is handled by the caller (NotificationDispatcher / DispatchNotificationHandler)
        // to allow batching. We just mutate the delivery entity here.
    }
}

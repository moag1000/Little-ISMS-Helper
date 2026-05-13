<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Message\Notification\DispatchNotificationMessage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Creates a NotificationDelivery row and dispatches DispatchNotificationMessage
 * for each channel attached to the rule.
 *
 * When Messenger uses the sync transport (default until Sprint-6b configures
 * async queues), the handler executes inline in the same request.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {}

    /**
     * Dispatch notification for every channel attached to the rule.
     *
     * @param array<string, mixed> $entityState
     * @return NotificationDelivery[]
     */
    public function dispatch(NotificationRule $rule, array $entityState): array
    {
        $deliveries = [];

        foreach ($rule->getChannels() as $channel) {
            $delivery = $this->createDelivery($rule, $channel);
            $deliveries[] = $delivery;

            $this->messageBus->dispatch(
                new DispatchNotificationMessage(
                    ruleId:      (int) $rule->getId(),
                    channelId:   (int) $channel->getId(),
                    entityState: $entityState,
                ),
            );
        }

        return $deliveries;
    }

    private function createDelivery(NotificationRule $rule, NotificationChannel $channel): NotificationDelivery
    {
        $delivery = new NotificationDelivery();
        $delivery->setTenant($rule->getTenant());
        $delivery->setRule($rule);
        $delivery->setChannel($channel);
        $delivery->setStatus(NotificationDelivery::STATUS_PENDING);
        $delivery->setAttemptedAt(new DateTimeImmutable());

        $this->entityManager->persist($delivery);
        $this->entityManager->flush();

        return $delivery;
    }
}

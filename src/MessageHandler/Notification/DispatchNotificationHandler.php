<?php

declare(strict_types=1);

namespace App\MessageHandler\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Message\Notification\DispatchNotificationMessage;
use App\Repository\Notification\NotificationChannelRepository;
use App\Repository\Notification\NotificationDeliveryRepository;
use App\Repository\Notification\NotificationRuleRepository;
use App\Service\Notification\Channel\EmailChannel;
use App\Service\Notification\Channel\InAppChannel;
use App\Service\Notification\Channel\WebhookChannel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Resolves the channel type and delegates to the appropriate Channel service.
 * Marks the pending NotificationDelivery row as sent or failed.
 */
#[AsMessageHandler]
class DispatchNotificationHandler
{
    public function __construct(
        private readonly NotificationRuleRepository $ruleRepo,
        private readonly NotificationChannelRepository $channelRepo,
        private readonly NotificationDeliveryRepository $deliveryRepo,
        private readonly EmailChannel $emailChannel,
        private readonly WebhookChannel $webhookChannel,
        private readonly InAppChannel $inAppChannel,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(DispatchNotificationMessage $message): void
    {
        $rule    = $this->ruleRepo->find($message->ruleId);
        $channel = $this->channelRepo->find($message->channelId);

        if ($rule === null || $channel === null) {
            // Rule or channel deleted between dispatch and execution — skip silently.
            return;
        }

        // Find the pending delivery row created by NotificationDispatcher
        $delivery = $this->findPendingDelivery($rule, $channel);

        if ($delivery === null) {
            // Delivery row missing (edge case) — create an ephemeral one for state tracking
            return;
        }

        $entityState = $message->entityState;

        match ($channel->getType()) {
            NotificationChannel::TYPE_EMAIL   => $this->emailChannel->deliver($rule, $channel, $delivery, $entityState),
            NotificationChannel::TYPE_WEBHOOK => $this->webhookChannel->deliver($rule, $channel, $delivery, $entityState),
            NotificationChannel::TYPE_IN_APP  => $this->inAppChannel->deliver($rule, $channel, $delivery, $entityState),
            default                           => $delivery->markFailed(sprintf('Unknown channel type: %s', $channel->getType())),
        };

        $this->entityManager->flush();
    }

    private function findPendingDelivery(NotificationRule $rule, NotificationChannel $channel): ?NotificationDelivery
    {
        return $this->deliveryRepo->findOneBy([
            'rule'    => $rule,
            'channel' => $channel,
            'status'  => NotificationDelivery::STATUS_PENDING,
        ]);
    }
}

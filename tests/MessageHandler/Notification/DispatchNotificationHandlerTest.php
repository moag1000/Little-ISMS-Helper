<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Message\Notification\DispatchNotificationMessage;
use App\MessageHandler\Notification\DispatchNotificationHandler;
use App\Repository\Notification\NotificationChannelRepository;
use App\Repository\Notification\NotificationDeliveryRepository;
use App\Repository\Notification\NotificationRuleRepository;
use App\Service\Notification\Channel\EmailChannel;
use App\Service\Notification\Channel\InAppChannel;
use App\Service\Notification\Channel\WebhookChannel;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DispatchNotificationHandlerTest extends TestCase
{
    private NotificationRuleRepository&MockObject $ruleRepo;
    private NotificationChannelRepository&MockObject $channelRepo;
    private NotificationDeliveryRepository&MockObject $deliveryRepo;
    private EmailChannel&MockObject $emailChannel;
    private WebhookChannel&MockObject $webhookChannel;
    private InAppChannel&MockObject $inAppChannel;
    private EntityManagerInterface&MockObject $em;
    private DispatchNotificationHandler $handler;

    protected function setUp(): void
    {
        $this->ruleRepo     = $this->createMock(NotificationRuleRepository::class);
        $this->channelRepo  = $this->createMock(NotificationChannelRepository::class);
        $this->deliveryRepo = $this->createMock(NotificationDeliveryRepository::class);
        $this->emailChannel   = $this->createMock(EmailChannel::class);
        $this->webhookChannel = $this->createMock(WebhookChannel::class);
        $this->inAppChannel   = $this->createMock(InAppChannel::class);
        $this->em           = $this->createMock(EntityManagerInterface::class);

        $this->handler = new DispatchNotificationHandler(
            $this->ruleRepo,
            $this->channelRepo,
            $this->deliveryRepo,
            $this->emailChannel,
            $this->webhookChannel,
            $this->inAppChannel,
            $this->em,
        );
    }

    private function makeRule(): NotificationRule
    {
        $rule = new NotificationRule();
        $rule->setName('Test Rule');
        $rule->setEventType('incident.created');
        return $rule;
    }

    private function makeDelivery(): NotificationDelivery
    {
        $d = new NotificationDelivery();
        $d->setAttemptedAt(new DateTimeImmutable());
        return $d;
    }

    #[Test]
    public function testRoutesToEmailChannelForEmailType(): void
    {
        $rule    = $this->makeRule();
        $channel = new NotificationChannel();
        $channel->setType(NotificationChannel::TYPE_EMAIL);
        $delivery = $this->makeDelivery();

        $this->ruleRepo->method('find')->willReturn($rule);
        $this->channelRepo->method('find')->willReturn($channel);
        $this->deliveryRepo->method('findOneBy')->willReturn($delivery);

        $this->emailChannel->expects($this->once())->method('deliver')
            ->with($rule, $channel, $delivery, ['severity' => 'high']);
        $this->webhookChannel->expects($this->never())->method('deliver');
        $this->inAppChannel->expects($this->never())->method('deliver');
        $this->em->expects($this->once())->method('flush');

        ($this->handler)(new DispatchNotificationMessage(1, 2, ['severity' => 'high']));
    }

    #[Test]
    public function testRoutesToWebhookChannelForWebhookType(): void
    {
        $rule    = $this->makeRule();
        $channel = new NotificationChannel();
        $channel->setType(NotificationChannel::TYPE_WEBHOOK);
        $delivery = $this->makeDelivery();

        $this->ruleRepo->method('find')->willReturn($rule);
        $this->channelRepo->method('find')->willReturn($channel);
        $this->deliveryRepo->method('findOneBy')->willReturn($delivery);

        $this->webhookChannel->expects($this->once())->method('deliver');
        $this->emailChannel->expects($this->never())->method('deliver');
        $this->em->expects($this->once())->method('flush');

        ($this->handler)(new DispatchNotificationMessage(1, 2, []));
    }

    #[Test]
    public function testRoutesToInAppChannelForInAppType(): void
    {
        $rule    = $this->makeRule();
        $channel = new NotificationChannel();
        $channel->setType(NotificationChannel::TYPE_IN_APP);
        $delivery = $this->makeDelivery();

        $this->ruleRepo->method('find')->willReturn($rule);
        $this->channelRepo->method('find')->willReturn($channel);
        $this->deliveryRepo->method('findOneBy')->willReturn($delivery);

        $this->inAppChannel->expects($this->once())->method('deliver');
        $this->emailChannel->expects($this->never())->method('deliver');
        $this->em->expects($this->once())->method('flush');

        ($this->handler)(new DispatchNotificationMessage(1, 2, []));
    }

    #[Test]
    public function testNoopWhenRuleNotFound(): void
    {
        $this->ruleRepo->method('find')->willReturn(null);
        $this->channelRepo->method('find')->willReturn(new NotificationChannel());

        $this->emailChannel->expects($this->never())->method('deliver');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new DispatchNotificationMessage(999, 1, []));
    }

    #[Test]
    public function testNoopWhenChannelNotFound(): void
    {
        $this->ruleRepo->method('find')->willReturn($this->makeRule());
        $this->channelRepo->method('find')->willReturn(null);

        $this->emailChannel->expects($this->never())->method('deliver');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new DispatchNotificationMessage(1, 999, []));
    }

    #[Test]
    public function testNoopWhenDeliveryRowNotFound(): void
    {
        $this->ruleRepo->method('find')->willReturn($this->makeRule());
        $this->channelRepo->method('find')->willReturn(new NotificationChannel());
        $this->deliveryRepo->method('findOneBy')->willReturn(null);

        $this->emailChannel->expects($this->never())->method('deliver');
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new DispatchNotificationMessage(1, 2, []));
    }
}

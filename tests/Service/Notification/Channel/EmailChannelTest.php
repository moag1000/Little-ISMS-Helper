<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification\Channel;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Service\Notification\Channel\EmailChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;

#[AllowMockObjectsWithoutExpectations]
final class EmailChannelTest extends TestCase
{
    private MailerInterface&MockObject $mailer;
    private EmailChannel $emailChannel;

    protected function setUp(): void
    {
        $this->mailer       = $this->createMock(MailerInterface::class);
        $this->emailChannel = new EmailChannel($this->mailer);
    }

    private function makeRule(): NotificationRule
    {
        $rule = new NotificationRule();
        $rule->setName('Incident Alert');
        $rule->setEventType('incident.created');
        return $rule;
    }

    private function makeChannel(array $config): NotificationChannel
    {
        $ch = new NotificationChannel();
        $ch->setType(NotificationChannel::TYPE_EMAIL);
        $ch->setConfig($config);
        return $ch;
    }

    private function makeDelivery(): NotificationDelivery
    {
        $d = new NotificationDelivery();
        $d->setAttemptedAt(new DateTimeImmutable());
        return $d;
    }

    #[Test]
    public function testSuccessfulEmailDelivery(): void
    {
        $rule     = $this->makeRule();
        $channel  = $this->makeChannel(['recipients' => ['ciso@example.com']]);
        $delivery = $this->makeDelivery();

        $this->mailer->expects($this->once())->method('send');

        $this->emailChannel->deliver($rule, $channel, $delivery, ['severity' => 'high']);

        self::assertSame(NotificationDelivery::STATUS_SENT, $delivery->getStatus());
        self::assertNotNull($delivery->getDeliveredAt());
    }

    #[Test]
    public function testNoRecipientsMarksFailed(): void
    {
        $rule     = $this->makeRule();
        $channel  = $this->makeChannel(['recipients' => []]); // empty
        $delivery = $this->makeDelivery();

        $this->mailer->expects($this->never())->method('send');

        $this->emailChannel->deliver($rule, $channel, $delivery, []);

        self::assertSame(NotificationDelivery::STATUS_FAILED, $delivery->getStatus());
        self::assertStringContainsString('No recipients', (string) $delivery->getErrorMessage());
    }

    #[Test]
    public function testTransportExceptionMarksFailed(): void
    {
        $rule     = $this->makeRule();
        $channel  = $this->makeChannel(['recipients' => ['ciso@example.com']]);
        $delivery = $this->makeDelivery();

        $this->mailer->method('send')
            ->willThrowException(new TransportException('SMTP connection refused'));

        $this->emailChannel->deliver($rule, $channel, $delivery, []);

        self::assertSame(NotificationDelivery::STATUS_FAILED, $delivery->getStatus());
        self::assertStringContainsString('SMTP connection refused', (string) $delivery->getErrorMessage());
    }
}

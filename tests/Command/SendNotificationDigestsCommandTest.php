<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SendNotificationDigestsCommand;
use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Repository\Notification\NotificationDeliveryRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Unit tests for the F3 digest command — mocked, no DB.
 *
 * Covers:
 *   - No pending digests → success + no mailer calls
 *   - One channel with pending deliveries → one email sent + deliveries marked sent
 *   - Channel with no recipients → deliveries marked failed
 *   - Dry-run → no sends, no DB writes
 */
#[AllowMockObjectsWithoutExpectations]
final class SendNotificationDigestsCommandTest extends TestCase
{
    private NotificationDeliveryRepository&MockObject $deliveryRepo;
    private EntityManagerInterface&MockObject         $em;
    private MailerInterface&MockObject                $mailer;
    private AuditLogger&MockObject                    $auditLogger;
    private SymfonyStyle&MockObject                   $io;

    protected function setUp(): void
    {
        $this->deliveryRepo = $this->createMock(NotificationDeliveryRepository::class);
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->mailer       = $this->createMock(MailerInterface::class);
        $this->auditLogger  = $this->createMock(AuditLogger::class);
        $this->io           = $this->createMock(SymfonyStyle::class);
    }

    private function makeCommand(): SendNotificationDigestsCommand
    {
        return new SendNotificationDigestsCommand(
            $this->deliveryRepo,
            $this->em,
            $this->mailer,
            $this->auditLogger,
        );
    }

    private function makeChannel(int $id, array $config = []): NotificationChannel
    {
        $channel = new NotificationChannel();
        $channel->setType(NotificationChannel::TYPE_EMAIL);
        $channel->setName('Test Channel');
        $channel->setConfig($config);

        $ref = new \ReflectionProperty($channel, 'id');
        $ref->setValue($channel, $id);

        return $channel;
    }

    private function makeDelivery(NotificationChannel $channel, array $payload = []): NotificationDelivery
    {
        $rule = new NotificationRule();
        $rule->setName('Test Rule');
        $rule->setEventType('incident.created');

        $delivery = new NotificationDelivery();
        $delivery->setChannel($channel);
        $delivery->setRule($rule);
        $delivery->setTenant(new Tenant());
        $delivery->markQueuedForDigest($payload);
        $delivery->setAttemptedAt(new \DateTimeImmutable());

        return $delivery;
    }

    #[Test]
    public function testNoPendingDigests(): void
    {
        $this->deliveryRepo->method('findChannelsWithPendingDigests')->willReturn([]);
        $this->mailer->expects($this->never())->method('send');
        $this->em->expects($this->never())->method('flush');

        $command = $this->makeCommand();
        $result  = ($command)(false, $this->io);

        self::assertSame(Command::SUCCESS, $result);
    }

    #[Test]
    public function testDigestSentForChannelWithRecipients(): void
    {
        $channel  = $this->makeChannel(1, [
            'recipients'    => ['admin@example.com'],
            'delivery_mode' => 'digest_daily',
        ]);
        $delivery1 = $this->makeDelivery($channel, ['severity' => 'high']);
        $delivery2 = $this->makeDelivery($channel, ['severity' => 'medium']);

        $this->deliveryRepo->method('findChannelsWithPendingDigests')->willReturn([$channel]);
        $this->deliveryRepo->method('findPendingDigestByChannel')->with($channel)->willReturn([$delivery1, $delivery2]);

        $this->mailer->expects($this->once())->method('send');
        $this->em->expects($this->once())->method('flush');
        $this->auditLogger->expects($this->once())->method('logCustom');

        $command = $this->makeCommand();
        $result  = ($command)(false, $this->io);

        self::assertSame(Command::SUCCESS, $result);
        self::assertSame(NotificationDelivery::STATUS_SENT, $delivery1->getStatus());
        self::assertSame(NotificationDelivery::STATUS_SENT, $delivery2->getStatus());
    }

    #[Test]
    public function testDigestFailsForChannelWithNoRecipients(): void
    {
        $channel  = $this->makeChannel(2, ['delivery_mode' => 'digest_daily']);
        $delivery = $this->makeDelivery($channel);

        $this->deliveryRepo->method('findChannelsWithPendingDigests')->willReturn([$channel]);
        $this->deliveryRepo->method('findPendingDigestByChannel')->with($channel)->willReturn([$delivery]);

        $this->mailer->expects($this->never())->method('send');
        $this->em->expects($this->once())->method('flush');

        $command = $this->makeCommand();
        $result  = ($command)(false, $this->io);

        self::assertSame(Command::SUCCESS, $result);
        self::assertSame(NotificationDelivery::STATUS_FAILED, $delivery->getStatus());
    }

    #[Test]
    public function testDryRunDoesNotSendOrFlush(): void
    {
        $channel  = $this->makeChannel(3, [
            'recipients'    => ['admin@example.com'],
            'delivery_mode' => 'digest_daily',
        ]);
        $delivery = $this->makeDelivery($channel, ['key' => 'value']);

        $this->deliveryRepo->method('findChannelsWithPendingDigests')->willReturn([$channel]);
        $this->deliveryRepo->method('findPendingDigestByChannel')->with($channel)->willReturn([$delivery]);

        $this->mailer->expects($this->never())->method('send');
        $this->em->expects($this->never())->method('flush');

        $command = $this->makeCommand();
        $result  = ($command)(true, $this->io);

        self::assertSame(Command::SUCCESS, $result);
        // Status must remain pending_digest in dry-run
        self::assertSame(NotificationDelivery::STATUS_PENDING_DIGEST, $delivery->getStatus());
    }

    #[Test]
    public function testDeliveryPayloadIsStoredOnQueuedForDigest(): void
    {
        $channel  = $this->makeChannel(4, ['delivery_mode' => 'digest_daily']);
        $payload  = ['severity' => 'critical', 'entityId' => 99];
        $delivery = $this->makeDelivery($channel, $payload);

        self::assertSame(NotificationDelivery::STATUS_PENDING_DIGEST, $delivery->getStatus());
        self::assertSame($payload, $delivery->getPayload());
    }
}

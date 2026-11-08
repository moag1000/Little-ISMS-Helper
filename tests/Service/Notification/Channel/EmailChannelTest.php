<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification\Channel;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\Mail\RecipientFilter;
use App\Service\Notification\Channel\EmailChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AllowMockObjectsWithoutExpectations]
final class EmailChannelTest extends TestCase
{
    private MailerInterface&MockObject $mailer;
    private AuditLogger&MockObject $auditLogger;
    private EmailChannel $emailChannel;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->otherTenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->otherTenant, 8);

        // RecipientFilter is final: the real one over a repository with
        // ciso@ = manager here, user@ = plain user here, foreign@ = manager elsewhere.
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(fn (array $c): ?User => match ($c['email']) {
            'ciso@example.com' => (new User())->setEmail('ciso@example.com')->setRoles(['ROLE_MANAGER'])->setIsActive(true)->setTenant($this->tenant),
            'user@example.com' => (new User())->setEmail('user@example.com')->setRoles(['ROLE_USER'])->setIsActive(true)->setTenant($this->tenant),
            'foreign@example.com' => (new User())->setEmail('foreign@example.com')->setRoles(['ROLE_MANAGER'])->setIsActive(true)->setTenant($this->otherTenant),
            default => null,
        });

        $this->mailer = $this->createMock(MailerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->auditLogger->method('withTenant')->willReturnCallback(static fn (?Tenant $t, callable $cb): mixed => $cb());
        $this->emailChannel = new EmailChannel($this->mailer, new RecipientFilter($users), $this->auditLogger);
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
        $ch->setTenant($this->tenant);
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
        $delivery = $this->makeDelivery();
        $this->mailer->expects($this->once())->method('send');

        $this->emailChannel->deliver($this->makeRule(), $this->makeChannel(['recipients' => ['ciso@example.com']]), $delivery, ['severity' => 'high']);

        self::assertSame(NotificationDelivery::STATUS_SENT, $delivery->getStatus());
        self::assertNotNull($delivery->getDeliveredAt());
    }

    #[Test]
    public function onlyManagersOfTheChannelsOrganisationReceiveTheMail(): void
    {
        $sent = null;
        $this->mailer->expects($this->once())->method('send')->willReturnCallback(static function (Email $e) use (&$sent): void {
            $sent = $e;
        });
        $this->auditLogger->expects($this->exactly(3))->method('logCustom')
            ->with('notification.recipient_dropped');

        $channel = $this->makeChannel(['recipients' => ['ciso@example.com', 'user@example.com', 'foreign@example.com', 'stranger@evil.test']]);
        $this->emailChannel->deliver($this->makeRule(), $channel, $this->makeDelivery(), []);

        self::assertNotNull($sent);
        self::assertSame(['ciso@example.com'], array_map(static fn ($a) => $a->getAddress(), $sent->getTo()));
    }

    #[Test]
    public function theMailCarriesNoFullRecordState(): void
    {
        $sent = null;
        $this->mailer->method('send')->willReturnCallback(static function (Email $e) use (&$sent): void {
            $sent = $e;
        });

        $this->emailChannel->deliver($this->makeRule(), $this->makeChannel(['recipients' => ['ciso@example.com']]), $this->makeDelivery(), [
            'id' => 12,
            'title' => 'Ransomware Werk 2',
            'severity' => 'critical',
            'description' => 'Geheime Details zur Lücke',
            'reporterEmail' => 'jane@example.com',
        ]);

        $body = (string) $sent?->getTextBody();
        self::assertStringContainsString('Ransomware Werk 2', $body);
        self::assertStringContainsString('critical', $body);
        self::assertStringNotContainsString('Geheime Details', $body);
        self::assertStringNotContainsString('jane@example.com', $body);
    }

    #[Test]
    public function testNoRecipientsMarksFailed(): void
    {
        $delivery = $this->makeDelivery();
        $this->mailer->expects($this->never())->method('send');

        $this->emailChannel->deliver($this->makeRule(), $this->makeChannel(['recipients' => ['stranger@evil.test']]), $delivery, []);

        self::assertSame(NotificationDelivery::STATUS_FAILED, $delivery->getStatus());
        self::assertStringContainsString('No qualifying recipients', (string) $delivery->getErrorMessage());
    }

    #[Test]
    public function testTransportExceptionMarksFailed(): void
    {
        $delivery = $this->makeDelivery();
        $this->mailer->method('send')->willThrowException(new TransportException('SMTP connection refused'));

        $this->emailChannel->deliver($this->makeRule(), $this->makeChannel(['recipients' => ['ciso@example.com']]), $delivery, []);

        self::assertSame(NotificationDelivery::STATUS_FAILED, $delivery->getStatus());
        self::assertStringContainsString('SMTP connection refused', (string) $delivery->getErrorMessage());
    }
}

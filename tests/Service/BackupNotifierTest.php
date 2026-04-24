<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BackupNotifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class BackupNotifierTest extends TestCase
{
    private MockObject $mailer;
    private BackupNotifier $notifier;

    protected function setUp(): void
    {
        $this->mailer   = $this->createMock(MailerInterface::class);
        $this->notifier = new BackupNotifier(
            $this->mailer,
            'noreply@little-isms-helper.local',
            '[ISMS Backup]',
        );
    }

    // ------------------------------------------------------------------ //

    public function testNotifySuccessSendsEmailWithCorrectSubject(): void
    {
        $sentEmail = null;

        $this->mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(static function (Email $email) use (&$sentEmail): bool {
                $sentEmail = $email;
                return true;
            }));

        $result = [
            'path'         => '/var/backups/backup_2026-04-24_12-00-00.json',
            'size_bytes'   => 204800,
            'entity_count' => 42,
            'duration_ms'  => 1234,
            'sha256'       => 'abc123',
        ];

        $this->notifier->notifySuccess($result, 'admin@example.com');

        $this->assertNotNull($sentEmail);
        $this->assertStringContainsString('[ISMS Backup]', $sentEmail->getSubject());
        $this->assertStringContainsString('Success', $sentEmail->getSubject());
        $this->assertStringContainsString('backup_2026-04-24_12-00-00.json', $sentEmail->getSubject());
    }

    public function testNotifySuccessBodyContainsKeyData(): void
    {
        $sentEmail = null;

        $this->mailer
            ->method('send')
            ->with($this->callback(static function (Email $email) use (&$sentEmail): bool {
                $sentEmail = $email;
                return true;
            }));

        $result = [
            'path'         => '/var/backups/backup_test.json',
            'size_bytes'   => 512,
            'entity_count' => 17,
            'duration_ms'  => 999,
            'sha256'       => 'deadbeef',
        ];

        $this->notifier->notifySuccess($result, 'ops@example.com');

        $body = $sentEmail->getTextBody();
        $this->assertStringContainsString('17', $body);
        $this->assertStringContainsString('512', $body);
        $this->assertStringContainsString('999', $body);
        $this->assertStringContainsString('deadbeef', $body);
    }

    public function testNotifySuccessWithWarningsIncludesThemInBody(): void
    {
        $sentEmail = null;

        $this->mailer
            ->method('send')
            ->with($this->callback(static function (Email $email) use (&$sentEmail): bool {
                $sentEmail = $email;
                return true;
            }));

        $result = [
            'path'         => '/var/backups/backup.json',
            'size_bytes'   => 100,
            'entity_count' => 5,
            'duration_ms'  => 200,
            'sha256'       => 'hash',
            'warnings'     => ['Skipped encrypted field X', 'File not found: logo.png'],
        ];

        $this->notifier->notifySuccess($result, 'admin@example.com');

        $body = $sentEmail->getTextBody();
        $this->assertStringContainsString('Skipped encrypted field X', $body);
        $this->assertStringContainsString('File not found: logo.png', $body);
    }

    public function testNotifyFailureSendsEmailWithCorrectSubject(): void
    {
        $sentEmail = null;

        $this->mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(static function (Email $email) use (&$sentEmail): bool {
                $sentEmail = $email;
                return true;
            }));

        $exception = new \RuntimeException('Disk quota exceeded');
        $this->notifier->notifyFailure($exception, 'admin@example.com');

        $this->assertStringContainsString('[ISMS Backup]', $sentEmail->getSubject());
        $this->assertStringContainsString('Failed', $sentEmail->getSubject());
        $this->assertStringContainsString('RuntimeException', $sentEmail->getSubject());
    }

    public function testNotifyFailureBodyContainsExceptionMessage(): void
    {
        $sentEmail = null;

        $this->mailer
            ->method('send')
            ->with($this->callback(static function (Email $email) use (&$sentEmail): bool {
                $sentEmail = $email;
                return true;
            }));

        $exception = new \RuntimeException('Connection refused to database');
        $this->notifier->notifyFailure($exception, 'admin@example.com');

        $body = $sentEmail->getTextBody();
        $this->assertStringContainsString('Connection refused to database', $body);
        $this->assertStringContainsString('RuntimeException', $body);
    }

    public function testFromAddressIsCorrect(): void
    {
        $sentEmail = null;

        $this->mailer
            ->method('send')
            ->with($this->callback(static function (Email $email) use (&$sentEmail): bool {
                $sentEmail = $email;
                return true;
            }));

        $this->notifier->notifySuccess([
            'path' => '/tmp/b.json', 'size_bytes' => 0, 'entity_count' => 0, 'duration_ms' => 0, 'sha256' => '',
        ], 'recipient@example.com');

        $fromAddresses = $sentEmail->getFrom();
        $this->assertCount(1, $fromAddresses);
        $this->assertSame('noreply@little-isms-helper.local', $fromAddresses[0]->getAddress());
    }

    public function testRecipientIsCorrect(): void
    {
        $sentEmail = null;

        $this->mailer
            ->method('send')
            ->with($this->callback(static function (Email $email) use (&$sentEmail): bool {
                $sentEmail = $email;
                return true;
            }));

        $this->notifier->notifySuccess([
            'path' => '/tmp/b.json', 'size_bytes' => 0, 'entity_count' => 0, 'duration_ms' => 0, 'sha256' => '',
        ], 'ciso@corp.example');

        $toAddresses = $sentEmail->getTo();
        $this->assertCount(1, $toAddresses);
        $this->assertSame('ciso@corp.example', $toAddresses[0]->getAddress());
    }
}

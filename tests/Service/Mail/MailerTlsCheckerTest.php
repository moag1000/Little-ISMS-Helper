<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailerTlsChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ISB MINOR-4: DSN TLS gate coverage.
 *
 * Covers every DSN shape called out in docs/DATA_REUSE_PLAN_REVIEW_ISB.md
 * plus the extra transports the production env is likely to see.
 */
class MailerTlsCheckerTest extends TestCase
{
    #[DataProvider('dsnProvider')]
    #[Test]
    public function testIsTlsConfigured(string $dsn, bool $expected): void
    {
        $checker = new MailerTlsChecker($dsn);
        self::assertSame($expected, $checker->isTlsConfigured(), sprintf('DSN: %s', $dsn));
    }

    #[Test]
    public function testAssertThrowsOnPlainSmtp(): void
    {
        $checker = new MailerTlsChecker('smtp://mail.example.com:25');
        $this->expectException(\RuntimeException::class);
        $checker->assertTlsConfigured();
    }

    #[Test]
    public function testAssertDoesNotThrowOnSmtps(): void
    {
        $checker = new MailerTlsChecker('smtps://user:pass@mail.example.com:465');
        $checker->assertTlsConfigured();
        $this->addToAssertionCount(1);
    }

    public static function dsnProvider(): array
    {
        return [
            // Accepted ----------------------------------------------------
            'smtps implicit TLS' => ['smtps://user:pass@mail.example.com:465', true],
            'smtp+tls aliased' => ['smtp+tls://user:pass@mail.example.com:587', true],
            'smtp with encryption=tls' => ['smtp://user:pass@mail.example.com:587?encryption=tls', true],
            'smtp with encryption=TLS uppercase' => ['smtp://user:pass@mail.example.com:587?encryption=TLS', true],
            'smtp with encryption=ssl (legacy)' => ['smtp://user:pass@mail.example.com:465?encryption=ssl', true],
            'sendgrid api' => ['sendgrid+api://KEY@default', true],
            'ses api' => ['ses+api://ACCESS:SECRET@default?region=eu-central-1', true],
            'postmark api' => ['postmark+api://TOKEN@default', true],
            'mailgun api' => ['mailgun+api://KEY:DOMAIN@default', true],
            'null dev transport' => ['null://null', true],
            'mailer locally handled' => ['mailer://localhost', true],
            'sendmail local mta' => ['sendmail://default', true],

            // Rejected ----------------------------------------------------
            'plain smtp port 25' => ['smtp://mail.example.com:25', false],
            'plain smtp port 587 no params' => ['smtp://user:pass@mail.example.com:587', false],
            'smtp with unrelated query' => ['smtp://user:pass@mail.example.com:587?verify_peer=1', false],
            'smtp with encryption=none' => ['smtp://mail.example.com:25?encryption=none', false],
            'empty dsn' => ['', false],
            'unknown scheme' => ['http://example.com', false],
        ];
    }
}

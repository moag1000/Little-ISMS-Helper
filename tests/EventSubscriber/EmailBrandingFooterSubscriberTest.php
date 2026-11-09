<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\EmailBrandingFooterSubscriber;
use App\Service\EmailBranding;
use App\Service\TenantContext;
use App\Service\TenantEmailBrandingResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\EventListener\DkimSignedMessageListener;
use Symfony\Component\Mailer\EventListener\MessageListener;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Footer text and support address of Admin > E-Mail-Branding reach the mail (W8, lesson 10):
 * both were stored and shown in the preview only.
 */
final class EmailBrandingFooterSubscriberTest extends TestCase
{
    private function subscriber(?string $footer, ?string $support): EmailBrandingFooterSubscriber
    {
        $resolver = $this->createStub(TenantEmailBrandingResolver::class);
        $resolver->method('resolveFor')->willReturn(new EmailBranding('noreply@example.test', 'ISMS', $footer, $support));

        return new EmailBrandingFooterSubscriber($resolver, $this->createStub(TenantContext::class));
    }

    private function send(EmailBrandingFooterSubscriber $subscriber, Email $email): Email
    {
        $subscriber->onMessage(new MessageEvent($email, new Envelope(new Address('noreply@example.test'), [new Address('a@example.test')]), 'smtp'));

        return $email;
    }

    #[Test]
    public function theFooterAndSupportAddressReachTextAndHtml(): void
    {
        $email = $this->send(
            $this->subscriber("Muster GmbH · HRB 123\nGeschäftsführer <Erika>", 'support@example.test'),
            (new Email())->text('Hallo')->html('<html><body><p>Hallo</p></body></html>'),
        );

        self::assertStringEndsWith("Muster GmbH · HRB 123\nGeschäftsführer <Erika>\nsupport@example.test", (string) $email->getTextBody());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Geschäftsführer &lt;Erika&gt;', $html, 'footer text is escaped');
        self::assertMatchesRegularExpression('#email-branding-footer.*</body></html>$#s', $html, 'inserted before </body>');
        self::assertSame('support@example.test', $email->getReplyTo()[0]->getAddress());
    }

    #[Test]
    public function aMailBrandedOnceIsNotBrandedAgainAndOwnReplyToStays(): void
    {
        $subscriber = $this->subscriber('Footer', 'support@example.test');
        $email = (new Email())->text('Hallo')->replyTo('owner@example.test');
        $this->send($subscriber, $email);
        $this->send($subscriber, $email); // the worker's second event for a queued mail

        self::assertSame(1, substr_count((string) $email->getTextBody(), 'Footer'));
        self::assertSame('owner@example.test', $email->getReplyTo()[0]->getAddress());
    }

    #[Test]
    public function withoutBrandingTheMailStaysAsItIs(): void
    {
        $email = $this->send($this->subscriber(null, null), (new Email())->text('Hallo'));

        self::assertSame('Hallo', $email->getTextBody());
        self::assertSame([], $email->getReplyTo());
    }

    #[Test]
    public function itRunsAfterRenderingAndBeforeSigning(): void
    {
        $own = EmailBrandingFooterSubscriber::getSubscribedEvents()[MessageEvent::class][1];
        $render = MessageListener::getSubscribedEvents()[MessageEvent::class];
        $renderPriority = is_array($render) ? (int) ($render[1] ?? 0) : 0;
        $dkim = DkimSignedMessageListener::getSubscribedEvents()[MessageEvent::class][1];

        self::assertLessThan($renderPriority, $own, 'after Twig rendered the body');
        self::assertGreaterThan($dkim, $own, 'before the body is signed');
    }
}

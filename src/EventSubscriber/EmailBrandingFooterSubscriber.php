<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Tenant;
use App\Service\TenantContext;
use App\Service\TenantEmailBrandingResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Footer text and support address of the organisation's e-mail branding
 * (Admin > E-Mail-Branding, with parent and SystemSettings fallback) reach every outgoing
 * mail: the footer is appended to the rendered body (HTML and text), the support address
 * becomes the Reply-To unless the mail names one. A German company's mails need the
 * mandatory company details (§ 35a GmbHG) — that is what the footer is for.
 *
 * Runs after Twig rendered TemplatedEmail bodies (Symfony's MessageListener, priority 0), so it
 * works for every mail template, not only the ones extending emails/base.html.twig — and before
 * DKIM/S-MIME signing (-128), which a later body change would break.
 */
final class EmailBrandingFooterSubscriber implements EventSubscriberInterface
{
    private const string MARKER = 'X-ISMS-Branded';

    public function __construct(
        private readonly TenantEmailBrandingResolver $brandingResolver,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [MessageEvent::class => ['onMessage', -100]];
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        // First pass wins: a queued mail is branded while the sending request's organisation is
        // known; the worker's second event sees the marker and leaves the body alone.
        if (!$message instanceof Email || $message->getHeaders()->has(self::MARKER)) {
            return;
        }
        $message->getHeaders()->addTextHeader(self::MARKER, '1');

        $tenant = $this->tenantContext->getCurrentTenant();
        $branding = $this->brandingResolver->resolveFor($tenant instanceof Tenant ? $tenant : null);

        $support = trim((string) $branding->supportAddress);
        if ($support !== '' && $message->getReplyTo() === [] && filter_var($support, FILTER_VALIDATE_EMAIL) !== false) {
            $message->replyTo(new Address($support));
        }

        $footer = trim((string) $branding->footerText);
        if ($footer === '' && $support === '') {
            return;
        }
        $lines = array_values(array_filter([$footer, $support !== '' ? $support : null], static fn (?string $l): bool => $l !== null && $l !== ''));
        $this->appendFooter($message, $lines);
    }

    /** @param list<string> $lines */
    private function appendFooter(Email $message, array $lines): void
    {
        $text = $message->getTextBody();
        if (is_string($text)) {
            $message->text($text . "\n\n-- \n" . implode("\n", $lines));
        }

        $html = $message->getHtmlBody();
        if (!is_string($html)) {
            return;
        }
        $escaped = array_map(
            static fn (string $l): string => nl2br(htmlspecialchars($l, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            $lines,
        );
        $block = '<div class="email-branding-footer" style="margin:16px auto 0;max-width:600px;font-size:11px;'
            . 'line-height:1.6;color:#6d6b92;font-family:Arial,sans-serif;">' . implode('<br>', $escaped) . '</div>';
        $position = strripos($html, '</body>');
        $message->html($position === false ? $html . $block : substr_replace($html, $block, $position, 0));
    }
}

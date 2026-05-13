<?php

declare(strict_types=1);

namespace App\Service\Notification\Channel;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Delivers notifications via Symfony Mailer.
 *
 * Channel config keys (from NotificationChannel::getConfig()):
 *   recipients: array<string>   — list of email addresses
 *   from:       string           — sender address (optional; defaults to system default)
 *   subject:    string           — email subject template (optional)
 */
class EmailChannel
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {}

    /**
     * @param array<string, mixed> $entityState
     */
    public function deliver(
        NotificationRule $rule,
        NotificationChannel $channel,
        NotificationDelivery $delivery,
        array $entityState,
    ): void {
        $config     = $channel->getConfig();
        $recipients = (array) ($config['recipients'] ?? []);
        $from       = (string) ($config['from'] ?? 'noreply@little-isms-helper.local');
        $subject    = (string) ($config['subject'] ?? sprintf('[Notification] %s', $rule->getName()));

        if (empty($recipients)) {
            $delivery->markFailed('No recipients configured for email channel.', []);
            return;
        }

        $body = $this->buildBody($rule, $entityState);

        $email = (new Email())
            ->from($from)
            ->subject($subject)
            ->text($body);

        foreach ($recipients as $recipient) {
            $email->addTo((string) $recipient);
        }

        try {
            $this->mailer->send($email);
            $delivery->markSent(['recipients' => $recipients]);
        } catch (TransportExceptionInterface $e) {
            $delivery->markFailed($e->getMessage(), ['recipients' => $recipients]);
        }
    }

    /**
     * @param array<string, mixed> $entityState
     */
    private function buildBody(NotificationRule $rule, array $entityState): string
    {
        $lines = [
            sprintf('Notification rule "%s" was triggered.', $rule->getName()),
            sprintf('Event type: %s', $rule->getEventType()),
            '',
            'Entity state at time of notification:',
        ];

        foreach ($entityState as $key => $value) {
            $lines[] = sprintf('  %s: %s', $key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        return implode("\n", $lines);
    }
}

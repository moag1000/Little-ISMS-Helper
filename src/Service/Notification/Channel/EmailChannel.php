<?php

declare(strict_types=1);

namespace App\Service\Notification\Channel;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Service\AuditLogger;
use App\Service\Mail\RecipientFilter;
use App\Service\Notification\NotificationMailSummary;
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
 *
 * Recipients pass the same RecipientFilter as digests and scheduled reports
 * (active account of the channel's organisation, at least ROLE_MANAGER); a
 * dropped address is audit-logged. The body carries only
 * NotificationMailSummary::FIELDS, never the record's full state.
 */
class EmailChannel
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly RecipientFilter $recipientFilter,
        private readonly AuditLogger $auditLogger,
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
        $tenant     = $channel->getTenant();
        $filtered   = $this->recipientFilter->filterEmails((array) ($config['recipients'] ?? []), $tenant?->getId());
        $recipients = $filtered['valid'];
        foreach ($filtered['dropped'] as $drop) {
            $this->auditLogger->withTenant($tenant, fn () => $this->auditLogger->logCustom(
                'notification.recipient_dropped',
                'NotificationChannel',
                $channel->getId(),
                ['email' => $drop['email'], 'reason' => $drop['reason']],
                null,
                sprintf('Notification recipient %s dropped: %s', $drop['email'], $drop['reason']),
                'system:notification',
            ));
        }
        $from       = (string) ($config['from'] ?? 'noreply@little-isms-helper.local');
        $subject    = (string) ($config['subject'] ?? sprintf('[Notification] %s', $rule->getName()));

        if (empty($recipients)) {
            $delivery->markFailed('No qualifying recipients for email channel.', ['dropped' => count($filtered['dropped'])]);
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
            'Record:',
        ];

        foreach (NotificationMailSummary::fields($entityState) as $key => $value) {
            $lines[] = sprintf('  %s: %s', $key, $value);
        }

        return implode("\n", $lines);
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Repository\Notification\NotificationDeliveryRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * F3 email-digest command — collects pending_digest deliveries per email channel,
 * renders ONE batched email per channel, sends it via the existing Mailer, and
 * marks all collected deliveries as `sent`.
 *
 * This command is idempotent: running it twice will not double-send because the
 * first run transitions all rows from `pending_digest` → `sent`.
 *
 * Recommended cron (daily at 07:00 local time):
 *   0 7 * * * php /path/to/bin/console app:notification:send-digests
 *
 * For a dry-run preview without sending:
 *   php bin/console app:notification:send-digests --dry-run
 */
#[AsCommand(
    name: 'app:notification:send-digests',
    description: 'Send batched digest emails for notification channels configured in digest_daily mode',
    help: <<<'TXT'
Collects all <info>pending_digest</info> NotificationDelivery rows, groups them by email
channel, and sends ONE batched email per channel.  After a successful send every
delivery in the batch is transitioned to <info>sent</info>.

Delivery mode is set per channel via the <comment>delivery_mode</comment> key inside the channel's
JSON config field:
  <comment>{"recipients": ["security@example.com"], "delivery_mode": "digest_daily"}</comment>

Channels without <comment>delivery_mode</comment> (or with <comment>immediate</comment>) are never included.

Recommended cron setup (daily at 07:00):
  <info>0 7 * * * php /path/to/bin/console app:notification:send-digests</info>
TXT,
)]
final class SendNotificationDigestsCommand
{
    public function __construct(
        private readonly NotificationDeliveryRepository $deliveryRepo,
        private readonly EntityManagerInterface         $entityManager,
        private readonly MailerInterface                $mailer,
        private readonly AuditLogger                    $auditLogger,
    ) {}

    public function __invoke(
        #[Option(name: 'dry-run', description: 'Preview what would be sent without actually sending')]
        bool $dryRun = false,
        ?SymfonyStyle $symfonyStyle = null,
    ): int {
        $symfonyStyle->title('Notification Digest Sender');

        if ($dryRun) {
            $symfonyStyle->warning('DRY-RUN MODE — no emails will be sent, no rows updated');
        }

        /** @var NotificationChannel[] $channels */
        $channels = $this->deliveryRepo->findChannelsWithPendingDigests();

        if ($channels === []) {
            $symfonyStyle->success('No pending digest deliveries found — nothing to do.');
            return Command::SUCCESS;
        }

        $symfonyStyle->text(sprintf('Found %d channel(s) with pending digest deliveries.', count($channels)));

        $totalSent    = 0;
        $totalSkipped = 0;

        foreach ($channels as $channel) {
            $deliveries = $this->deliveryRepo->findPendingDigestByChannel($channel);

            if ($deliveries === []) {
                continue;
            }

            $symfonyStyle->section(sprintf(
                'Channel "%s" (%d deliveries)',
                $channel->getName(),
                count($deliveries),
            ));

            $config     = $channel->getConfig();
            $recipients = (array) ($config['recipients'] ?? []);
            $from       = (string) ($config['from'] ?? 'noreply@little-isms-helper.local');

            if ($recipients === []) {
                $symfonyStyle->warning(sprintf(
                    '  Skipping channel "%s" — no recipients configured.',
                    $channel->getName(),
                ));

                foreach ($deliveries as $delivery) {
                    $delivery->markFailed('No recipients configured for email channel (digest).');
                }

                if (!$dryRun) {
                    $this->entityManager->flush();
                }

                $totalSkipped += count($deliveries);
                continue;
            }

            $subject = (string) ($config['subject'] ?? sprintf('[ISMS Digest] %d new notifications', count($deliveries)));
            $body    = $this->buildDigestBody($channel->getName(), $deliveries);

            if ($dryRun) {
                $symfonyStyle->text(sprintf(
                    '  [DRY-RUN] Would send digest to: %s (subject: %s)',
                    implode(', ', $recipients),
                    $subject,
                ));
                $totalSent += count($deliveries);
                continue;
            }

            try {
                $email = (new Email())
                    ->from($from)
                    ->subject($subject)
                    ->text($body);

                foreach ($recipients as $recipient) {
                    $email->addTo((string) $recipient);
                }

                $this->mailer->send($email);

                $responsePayload = ['recipients' => $recipients, 'digest_count' => count($deliveries)];
                $sentAt          = new DateTimeImmutable();

                foreach ($deliveries as $delivery) {
                    $delivery->setStatus(NotificationDelivery::STATUS_SENT);
                    $delivery->setDeliveredAt($sentAt);
                    $delivery->setResponsePayload($responsePayload);
                }

                $this->entityManager->flush();

                // Single audit entry for the whole batch (ISO 27001 Cl. 7.5.3)
                $this->auditLogger->logCustom(
                    AuditLogger::ACTION_NOTIFICATION_DELIVERY_SUCCEEDED,
                    'NotificationDelivery',
                    null,
                    null,
                    [
                        'channel'     => $channel->getName(),
                        'channel_id'  => $channel->getId(),
                        'batch_count' => count($deliveries),
                        'recipients'  => $recipients,
                    ],
                    sprintf(
                        'Digest email sent for channel "%s": %d notifications batched.',
                        $channel->getName(),
                        count($deliveries),
                    ),
                    'system:digest-cron',
                );

                $symfonyStyle->text(sprintf(
                    '  Sent digest to %s (%d notifications batched).',
                    implode(', ', $recipients),
                    count($deliveries),
                ));

                $totalSent += count($deliveries);
            } catch (TransportExceptionInterface $e) {
                $symfonyStyle->error(sprintf(
                    '  Failed to send digest for channel "%s": %s',
                    $channel->getName(),
                    $e->getMessage(),
                ));

                foreach ($deliveries as $delivery) {
                    $delivery->markFailed(
                        sprintf('Digest send failed: %s', $e->getMessage()),
                        ['recipients' => $recipients],
                    );
                }

                $this->entityManager->flush();

                $this->auditLogger->logCustom(
                    AuditLogger::ACTION_NOTIFICATION_DELIVERY_FAILED,
                    'NotificationDelivery',
                    null,
                    null,
                    [
                        'channel'    => $channel->getName(),
                        'channel_id' => $channel->getId(),
                        'error'      => $e->getMessage(),
                    ],
                    sprintf('Digest email failed for channel "%s".', $channel->getName()),
                    'system:digest-cron',
                );

                $totalSkipped += count($deliveries);
            }
        }

        $symfonyStyle->success(sprintf(
            'Digest run complete — %d notifications sent, %d skipped/failed.',
            $totalSent,
            $totalSkipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * Render the plain-text body for one batched digest email.
     *
     * @param NotificationDelivery[] $deliveries
     */
    private function buildDigestBody(string $channelName, array $deliveries): string
    {
        $lines = [
            sprintf('ISMS Notification Digest — Channel: %s', $channelName),
            sprintf('Generated: %s', (new DateTimeImmutable())->format('Y-m-d H:i:s T')),
            str_repeat('-', 60),
            sprintf('%d notification(s) batched:', count($deliveries)),
            '',
        ];

        foreach ($deliveries as $index => $delivery) {
            $rule        = $delivery->getRule();
            $payload     = $delivery->getPayload() ?? [];
            $triggeredAt = $delivery->getAttemptedAt()?->format('Y-m-d H:i:s T') ?? 'unknown';

            $lines[] = sprintf('[%d] Rule: %s', $index + 1, $rule?->getName() ?? '(deleted)');
            $lines[] = sprintf('    Event type: %s', $rule?->getEventType() ?? 'unknown');
            $lines[] = sprintf('    Triggered:  %s', $triggeredAt);

            if ($payload !== []) {
                $lines[] = '    Entity state:';
                foreach ($payload as $key => $value) {
                    $lines[] = sprintf(
                        '      %s: %s',
                        $key,
                        is_scalar($value) ? (string) $value : json_encode($value),
                    );
                }
            }

            $lines[] = '';
        }

        $lines[] = str_repeat('-', 60);
        $lines[] = 'This digest was generated automatically by the ISMS notification system.';
        $lines[] = 'To switch back to immediate notifications, set delivery_mode to "immediate"';
        $lines[] = 'in the channel configuration.';

        return implode("\n", $lines);
    }
}

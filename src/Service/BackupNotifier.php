<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class BackupNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromEmail,
        private readonly string $subjectPrefix,
    ) {
    }

    public function notifySuccess(array $result, string $recipientEmail): void
    {
        $filename  = basename((string) ($result['path'] ?? 'unknown'));
        $subject   = sprintf('%s ✓ Success — %s', $this->subjectPrefix, $filename);

        $warnings = $result['warnings'] ?? [];
        $warningBlock = $warnings !== []
            ? "\nWarnings:\n" . implode("\n", array_map(static fn(string $w): string => '  - ' . $w, $warnings))
            : '';

        $body = sprintf(
            "Backup completed successfully.\n\n" .
            "Timestamp:    %s\n" .
            "File:         %s\n" .
            "Size:         %s bytes\n" .
            "Entities:     %d records\n" .
            "Duration:     %d ms\n" .
            "SHA-256:      %s\n" .
            "%s",
            (new \DateTimeImmutable())->format('Y-m-d H:i:s T'),
            $result['path'] ?? 'n/a',
            number_format((int) ($result['size_bytes'] ?? 0)),
            (int) ($result['entity_count'] ?? 0),
            (int) ($result['duration_ms'] ?? 0),
            (string) ($result['sha256'] ?? 'n/a'),
            $warningBlock,
        );

        $this->send($recipientEmail, $subject, $body);
    }

    public function notifyFailure(\Throwable $exception, string $recipientEmail): void
    {
        $subject = sprintf('%s ✗ Failed — %s', $this->subjectPrefix, $exception::class);

        $trace = implode("\n", array_slice(
            explode("\n", $exception->getTraceAsString()),
            0,
            10
        ));

        $body = sprintf(
            "Backup FAILED.\n\n" .
            "Timestamp:  %s\n" .
            "Exception:  %s\n" .
            "Message:    %s\n\n" .
            "Trace (first 10 frames):\n%s",
            (new \DateTimeImmutable())->format('Y-m-d H:i:s T'),
            $exception::class,
            $exception->getMessage(),
            $trace,
        );

        $this->send($recipientEmail, $subject, $body);
    }

    private function send(string $to, string $subject, string $body): void
    {
        $email = (new Email())
            ->from($this->fromEmail)
            ->to($to)
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);
    }
}

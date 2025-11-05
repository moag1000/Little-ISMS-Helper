<?php

namespace App\Service;

use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\Training;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class EmailNotificationService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromEmail = 'noreply@little-isms.local',
        private readonly string $fromName = 'Little ISMS Helper'
    ) {
    }

    public function sendIncidentNotification(Incident $incident, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            // Security: Sanitize subject to prevent email header injection (OWASP #3 - Injection)
            $safeTitle = $this->sanitizeEmailSubject($incident->getTitle());

            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject('[ISMS Alert] New Incident: ' . $safeTitle)
                ->htmlTemplate('emails/incident_notification.html.twig')
                ->context([
                    'incident' => $incident,
                ]);

            $this->mailer->send($email);
        }
    }

    public function sendIncidentUpdateNotification(Incident $incident, array $recipients, string $changeDescription): void
    {
        foreach ($recipients as $recipient) {
            // Security: Sanitize subject to prevent email header injection (OWASP #3 - Injection)
            $safeTitle = $this->sanitizeEmailSubject($incident->getTitle());

            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject('[ISMS Update] Incident Updated: ' . $safeTitle)
                ->htmlTemplate('emails/incident_update.html.twig')
                ->context([
                    'incident' => $incident,
                    'changeDescription' => $changeDescription,
                ]);

            $this->mailer->send($email);
        }
    }

    public function sendAuditDueNotification(InternalAudit $audit, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            // Security: Sanitize subject to prevent email header injection (OWASP #3 - Injection)
            $safeTitle = $this->sanitizeEmailSubject($audit->getTitle());

            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject('[ISMS Reminder] Upcoming Audit: ' . $safeTitle)
                ->htmlTemplate('emails/audit_due_notification.html.twig')
                ->context([
                    'audit' => $audit,
                ]);

            $this->mailer->send($email);
        }
    }

    public function sendTrainingDueNotification(Training $training, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            // Security: Sanitize subject to prevent email header injection (OWASP #3 - Injection)
            $safeTitle = $this->sanitizeEmailSubject($training->getTitle());

            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject('[ISMS Reminder] Upcoming Training: ' . $safeTitle)
                ->htmlTemplate('emails/training_due_notification.html.twig')
                ->context([
                    'training' => $training,
                ]);

            $this->mailer->send($email);
        }
    }

    public function sendGenericNotification(string $subject, string $template, array $context, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            // Security: Sanitize subject to prevent email header injection (OWASP #3 - Injection)
            $safeSubject = $this->sanitizeEmailSubject($subject);

            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject($safeSubject)
                ->htmlTemplate($template)
                ->context($context);

            $this->mailer->send($email);
        }
    }

    /**
     * Security: Sanitize email subject to prevent header injection
     * Removes newlines, carriage returns, and other control characters
     */
    private function sanitizeEmailSubject(string $subject): string
    {
        // Remove newlines, carriage returns, and control characters
        $subject = preg_replace('/[\r\n\x00-\x1F\x7F]/', '', $subject);

        // Limit length to prevent overly long subjects
        $subject = mb_substr($subject, 0, 255);

        return trim($subject);
    }
}

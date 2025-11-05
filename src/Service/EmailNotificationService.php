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
            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject('[ISMS Alert] New Incident: ' . $incident->getTitle())
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
            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject('[ISMS Update] Incident Updated: ' . $incident->getTitle())
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
            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject('[ISMS Reminder] Upcoming Audit: ' . $audit->getTitle())
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
            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject('[ISMS Reminder] Upcoming Training: ' . $training->getTitle())
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
            $email = (new TemplatedEmail())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject($subject)
                ->htmlTemplate($template)
                ->context($context);

            $this->mailer->send($email);
        }
    }
}

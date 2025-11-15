<?php

namespace App\Tests\Service;

use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\Training;
use App\Entity\Control;
use App\Entity\WorkflowInstance;
use App\Entity\Workflow;
use App\Entity\User;
use App\Service\EmailNotificationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class EmailNotificationServiceTest extends TestCase
{
    private EmailNotificationService $service;
    private MailerInterface $mailer;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->service = new EmailNotificationService(
            $this->mailer,
            'test@example.com',
            'Test ISMS'
        );
    }

    public function testSendIncidentNotificationWithStringRecipient(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getTitle')->willReturn('Test Incident');

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                return $email instanceof Email
                    && str_contains($email->getSubject(), '[ISMS Alert] New Incident: Test Incident');
            }));

        $this->service->sendIncidentNotification($incident, ['user@example.com']);
    }

    public function testSendIncidentNotificationWithUserObject(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getTitle')->willReturn('User Incident');

        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('user@example.com');

        $this->mailer->expects($this->once())
            ->method('send');

        $this->service->sendIncidentNotification($incident, [$user]);
    }

    public function testSendIncidentNotificationSanitizesSubject(): void
    {
        $incident = $this->createMock(Incident::class);
        // Subject with newlines and control characters
        $incident->method('getTitle')->willReturn("Malicious\r\nBcc: hacker@evil.com\x00Title");

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                $subject = $email->getSubject();
                // Should not contain newlines or control characters
                return !str_contains($subject, "\r")
                    && !str_contains($subject, "\n")
                    && !str_contains($subject, "\x00")
                    && !str_contains($subject, 'Bcc:');
            }));

        $this->service->sendIncidentNotification($incident, ['user@example.com']);
    }

    public function testSendIncidentNotificationToMultipleRecipients(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getTitle')->willReturn('Multi Recipient Test');

        $this->mailer->expects($this->exactly(3))
            ->method('send');

        $this->service->sendIncidentNotification($incident, [
            'user1@example.com',
            'user2@example.com',
            'user3@example.com'
        ]);
    }

    public function testSendIncidentUpdateNotification(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getTitle')->willReturn('Updated Incident');

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                return str_contains($email->getSubject(), '[ISMS Update] Incident Updated:');
            }));

        $this->service->sendIncidentUpdateNotification(
            $incident,
            ['user@example.com'],
            'Status changed to resolved'
        );
    }

    public function testSendAuditDueNotification(): void
    {
        $audit = $this->createMock(InternalAudit::class);
        $audit->method('getTitle')->willReturn('Q4 Compliance Audit');

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                return str_contains($email->getSubject(), '[ISMS Reminder] Upcoming Audit:')
                    && str_contains($email->getSubject(), 'Q4 Compliance Audit');
            }));

        $this->service->sendAuditDueNotification($audit, ['auditor@example.com']);
    }

    public function testSendTrainingDueNotification(): void
    {
        $training = $this->createMock(Training::class);
        $training->method('getTitle')->willReturn('Security Awareness Training');

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                return str_contains($email->getSubject(), '[ISMS Reminder] Upcoming Training:')
                    && str_contains($email->getSubject(), 'Security Awareness Training');
            }));

        $this->service->sendTrainingDueNotification($training, ['trainee@example.com']);
    }

    public function testSendControlDueNotification(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('getControlId')->willReturn('A.5.1');

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                return str_contains($email->getSubject(), '[ISMS Reminder] Control Target Date Approaching:')
                    && str_contains($email->getSubject(), 'A.5.1');
            }));

        $this->service->sendControlDueNotification($control, ['manager@example.com']);
    }

    public function testSendWorkflowOverdueNotification(): void
    {
        $workflow = $this->createMock(Workflow::class);
        $workflow->method('getName')->willReturn('Document Approval');

        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getWorkflow')->willReturn($workflow);

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                return str_contains($email->getSubject(), '[ISMS Alert] Overdue Workflow Approval:')
                    && str_contains($email->getSubject(), 'Document Approval');
            }));

        $this->service->sendWorkflowOverdueNotification($instance, ['approver@example.com']);
    }

    public function testSendGenericNotification(): void
    {
        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                return $email->getSubject() === 'Custom Subject'
                    && $email->getHtmlTemplate() === 'emails/custom.html.twig';
            }));

        $this->service->sendGenericNotification(
            'Custom Subject',
            'emails/custom.html.twig',
            ['key' => 'value'],
            ['recipient@example.com']
        );
    }

    public function testSendGenericNotificationSanitizesSubject(): void
    {
        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                $subject = $email->getSubject();
                // Should not contain injection attempt
                return !str_contains($subject, "\r\n");
            }));

        $this->service->sendGenericNotification(
            "Subject\r\nBcc: hacker@evil.com",
            'emails/test.html.twig',
            [],
            ['user@example.com']
        );
    }

    public function testSubjectIsTruncatedToMaxLength(): void
    {
        $incident = $this->createMock(Incident::class);
        // Create a very long title (300 characters)
        $longTitle = str_repeat('A', 300);
        $incident->method('getTitle')->willReturn($longTitle);

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                $subject = $email->getSubject();
                // Subject should be truncated (prefix + max 255 chars from title)
                // "[ISMS Alert] New Incident: " is 27 chars, so total should be <= 282
                return strlen($subject) <= 282;
            }));

        $this->service->sendIncidentNotification($incident, ['user@example.com']);
    }

    public function testControlCharactersAreRemoved(): void
    {
        $incident = $this->createMock(Incident::class);
        // Title with various control characters
        $incident->method('getTitle')->willReturn("Test\x00\x01\x1F\x7FIncident");

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                $subject = $email->getSubject();
                // Should only contain "TestIncident" after sanitization
                return str_contains($subject, 'TestIncident')
                    && !preg_match('/[\x00-\x1F\x7F]/', $subject);
            }));

        $this->service->sendIncidentNotification($incident, ['user@example.com']);
    }

    public function testEmailFromAddressIsCorrect(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getTitle')->willReturn('Test');

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                $from = $email->getFrom();
                return count($from) === 1
                    && $from[0]->getAddress() === 'test@example.com'
                    && $from[0]->getName() === 'Test ISMS';
            }));

        $this->service->sendIncidentNotification($incident, ['user@example.com']);
    }

    public function testEmptyRecipientsDoesNotSendEmail(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getTitle')->willReturn('Test');

        $this->mailer->expects($this->never())
            ->method('send');

        $this->service->sendIncidentNotification($incident, []);
    }

    public function testWhitespaceIsTrimmedFromSubject(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getTitle')->willReturn('  Test Title  ');

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                $subject = $email->getSubject();
                // Should not have leading/trailing whitespace in the title part
                return str_contains($subject, 'Test Title')
                    && !str_contains($subject, '  Test Title  ');
            }));

        $this->service->sendIncidentNotification($incident, ['user@example.com']);
    }

    public function testMultibyteCharactersArePreserved(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getTitle')->willReturn('Sicherheitsvorfall: Ü ö ä € 中文');

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                $subject = $email->getSubject();
                // Multibyte characters should be preserved
                return str_contains($subject, 'Sicherheitsvorfall')
                    && str_contains($subject, 'Ü')
                    && str_contains($subject, '中文');
            }));

        $this->service->sendIncidentNotification($incident, ['user@example.com']);
    }
}

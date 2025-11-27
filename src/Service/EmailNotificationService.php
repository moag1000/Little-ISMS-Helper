<?php

namespace App\Service;

use DateTime;
use DateTimeImmutable;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\Risk;
use App\Entity\Training;
use App\Entity\Control;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Email Notification Service
 *
 * Centralized service for sending ISMS-related email notifications.
 * Implements security best practices including email header injection prevention (OWASP #3).
 *
 * Features:
 * - Incident notifications (creation and updates)
 * - Audit reminders
 * - Training reminders
 * - Control target date warnings
 * - Workflow approval notifications
 * - Generic templated email support
 *
 * Security:
 * - Automatic email subject sanitization to prevent header injection
 * - Length limits on email subjects
 * - Control character removal
 */
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

            $email = new TemplatedEmail()
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

            $email = new TemplatedEmail()
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

    public function sendAuditDueNotification(InternalAudit $internalAudit, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            // Security: Sanitize subject to prevent email header injection (OWASP #3 - Injection)
            $safeTitle = $this->sanitizeEmailSubject($internalAudit->getTitle());

            $email = new TemplatedEmail()
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject('[ISMS Reminder] Upcoming Audit: ' . $safeTitle)
                ->htmlTemplate('emails/audit_due_notification.html.twig')
                ->context([
                    'audit' => $internalAudit,
                ]);

            $this->mailer->send($email);
        }
    }

    public function sendTrainingDueNotification(Training $training, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            // Security: Sanitize subject to prevent email header injection (OWASP #3 - Injection)
            $safeTitle = $this->sanitizeEmailSubject($training->getTitle());

            $email = new TemplatedEmail()
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

            $email = new TemplatedEmail()
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject($safeSubject)
                ->htmlTemplate($template)
                ->context($context);

            $this->mailer->send($email);
        }
    }

    public function sendControlDueNotification(Control $control, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            // Security: Sanitize subject to prevent email header injection (OWASP #3 - Injection)
            $safeControlId = $this->sanitizeEmailSubject($control->getControlId());

            $email = new TemplatedEmail()
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject('[ISMS Reminder] Control Target Date Approaching: ' . $safeControlId)
                ->htmlTemplate('emails/control_due_notification.html.twig')
                ->context([
                    'control' => $control,
                ]);

            $this->mailer->send($email);
        }
    }

    public function sendWorkflowOverdueNotification(WorkflowInstance $workflowInstance, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            // Security: Sanitize subject to prevent email header injection (OWASP #3 - Injection)
            $safeWorkflowName = $this->sanitizeEmailSubject($workflowInstance->getWorkflow()->getName());

            $email = new TemplatedEmail()
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient instanceof User ? $recipient->getEmail() : $recipient)
                ->subject('[ISMS Alert] Overdue Workflow Approval: ' . $safeWorkflowName)
                ->htmlTemplate('emails/workflow_overdue_notification.html.twig')
                ->context([
                    'instance' => $workflowInstance,
                ]);

            $this->mailer->send($email);
        }
    }

    /**
     * Send notification when a workflow step is assigned to approvers
     *
     * @param User[] $recipients
     */
    public function sendWorkflowAssignmentNotification(WorkflowInstance $workflowInstance, WorkflowStep $workflowStep, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            $safeWorkflowName = $this->sanitizeEmailSubject($workflowInstance->getWorkflow()->getName());
            $safeStepName = $this->sanitizeEmailSubject($workflowStep->getName());

            $email = new TemplatedEmail()
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient->getEmail())
                ->subject('[ISMS Action Required] Approval Needed: ' . $safeStepName . ' - ' . $safeWorkflowName)
                ->htmlTemplate('emails/workflow_assignment_notification.html.twig')
                ->context([
                    'instance' => $workflowInstance,
                    'step' => $workflowStep,
                    'recipient' => $recipient,
                ]);

            $this->mailer->send($email);
        }
    }

    /**
     * Send notification for notification-type workflow steps (informational, no approval required)
     *
     * @param User[] $recipients
     */
    public function sendWorkflowNotificationStepEmail(WorkflowInstance $workflowInstance, WorkflowStep $workflowStep, array $recipients): void
    {
        foreach ($recipients as $recipient) {
            $safeWorkflowName = $this->sanitizeEmailSubject($workflowInstance->getWorkflow()->getName());
            $safeStepName = $this->sanitizeEmailSubject($workflowStep->getName());

            $email = new TemplatedEmail()
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient->getEmail())
                ->subject('[ISMS Info] Workflow Update: ' . $safeStepName . ' - ' . $safeWorkflowName)
                ->htmlTemplate('emails/workflow_notification_step.html.twig')
                ->context([
                    'instance' => $workflowInstance,
                    'step' => $workflowStep,
                    'recipient' => $recipient,
                ]);

            $this->mailer->send($email);
        }
    }

    /**
     * Send deadline warning notification for workflows nearing their due date
     *
     * @param User[] $recipients
     */
    public function sendWorkflowDeadlineWarning(WorkflowInstance $workflowInstance, array $recipients, int $daysRemaining): void
    {
        foreach ($recipients as $recipient) {
            $safeWorkflowName = $this->sanitizeEmailSubject($workflowInstance->getWorkflow()->getName());

            $email = new TemplatedEmail()
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to($recipient->getEmail())
                ->subject('[ISMS Warning] Workflow Deadline in ' . $daysRemaining . ' days: ' . $safeWorkflowName)
                ->htmlTemplate('emails/workflow_deadline_warning.html.twig')
                ->context([
                    'instance' => $workflowInstance,
                    'daysRemaining' => $daysRemaining,
                    'recipient' => $recipient,
                ]);

            $this->mailer->send($email);
        }
    }

    /**
     * Send risk acceptance approval request notification
     * Priority 2.1 - Risk Acceptance Workflow
     *
     * @param Risk $risk Risk entity
     * @param User $user User who needs to approve
     * @param string $approvalLevel 'manager' or 'executive'
     */
    public function sendRiskAcceptanceRequest(Risk $risk, User $user, string $approvalLevel): void
    {
        $safeTitle = $this->sanitizeEmailSubject($risk->getTitle());

        $templatedEmail = new TemplatedEmail()
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($user->getEmail())
            ->subject('[ISMS Action Required] Risk Acceptance Approval: ' . $safeTitle)
            ->htmlTemplate('emails/risk_acceptance_request.html.twig')
            ->context([
                'risk' => $risk,
                'approver' => $user,
                'approval_level' => $approvalLevel,
                'risk_score' => $risk->getResidualRiskLevel(),
                'risk_justification' => $risk->getAcceptanceJustification(),
            ]);

        $this->mailer->send($templatedEmail);
    }

    /**
     * Send risk acceptance approval notification (informational)
     *
     * @param Risk $risk Risk entity
     * @param User $riskOwner Risk owner to notify
     * @param User $approver User who approved the acceptance
     */
    public function sendRiskAcceptanceApproved(Risk $risk, User $riskOwner, User $approver): void
    {
        $safeTitle = $this->sanitizeEmailSubject($risk->getTitle());

        $templatedEmail = new TemplatedEmail()
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($riskOwner->getEmail())
            ->subject('[ISMS Info] Risk Acceptance Approved: ' . $safeTitle)
            ->htmlTemplate('emails/risk_acceptance_approved.html.twig')
            ->context([
                'risk' => $risk,
                'risk_owner' => $riskOwner,
                'approved_by' => $risk->getAcceptanceApprovedBy(),
                'approved_at' => $risk->getAcceptanceApprovedAt(),
                'approver' => $approver,
            ]);

        $this->mailer->send($templatedEmail);
    }

    /**
     * Send risk acceptance rejection notification
     *
     * @param Risk $risk Risk entity
     * @param User $riskOwner Risk owner to notify
     * @param string $reason Rejection reason
     * @param User $rejector User who rejected the acceptance
     */
    public function sendRiskAcceptanceRejected(Risk $risk, User $riskOwner, string $reason, User $rejector): void
    {
        $safeTitle = $this->sanitizeEmailSubject($risk->getTitle());

        $templatedEmail = new TemplatedEmail()
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($riskOwner->getEmail())
            ->subject('[ISMS Alert] Risk Acceptance Rejected: ' . $safeTitle)
            ->htmlTemplate('emails/risk_acceptance_rejected.html.twig')
            ->context([
                'risk' => $risk,
                'risk_owner' => $riskOwner,
                'rejection_reason' => $reason,
                'rejector' => $rejector,
                'rejected_at' => new DateTime(),
            ]);

        $this->mailer->send($templatedEmail);
    }

    /**
     * Send incident escalation notification
     *
     * @param string $severity Escalation level (low, medium, high, critical)
     * @param DateTimeImmutable $slaDeadline SLA deadline for incident response
     * @param string $incidentUrl Absolute URL to incident detail page
     */
    public function sendIncidentEscalationNotification(
        User $user,
        Incident $incident,
        string $severity,
        DateTimeImmutable $slaDeadline,
        string $incidentUrl
    ): void {
        $safeTitle = $this->sanitizeEmailSubject($incident->getTitle());
        $severityLabel = strtoupper($severity);

        $templatedEmail = new TemplatedEmail()
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($user->getEmail())
            ->subject("[ISMS Alert - {$severityLabel}] Incident Escalation: {$safeTitle}")
            ->htmlTemplate('emails/incident_escalation.html.twig')
            ->context([
                'incident' => $incident,
                'recipient' => $user,
                'severity' => $severity,
                'escalation_level' => $severity,
                'sla_deadline' => $slaDeadline,
                'incident_url' => $incidentUrl,
            ]);

        $this->mailer->send($templatedEmail);
    }

    /**
     * Send data breach notification (GDPR Art. 33 + BDSG § 42)
     *
     * Critical: 72h notification deadline
     *
     * @param array $context Must include: incident, deadline, deadline_formatted, hours_remaining
     */
    public function sendDataBreachNotification(User $user, array $context): void
    {
        $incident = $context['incident'];
        $safeTitle = $this->sanitizeEmailSubject($incident->getTitle());

        $templatedEmail = new TemplatedEmail()
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($user->getEmail())
            ->subject("[URGENT - DATA BREACH] GDPR 72h Notification Required: {$safeTitle}")
            ->htmlTemplate('emails/data_breach_notification.html.twig')
            ->context(array_merge($context, [
                'recipient' => $user,
            ]));

        $this->mailer->send($templatedEmail);
    }

    /**
     * Security: Sanitize email subject to prevent header injection
     * Removes newlines, carriage returns, control characters, and email header keywords
     */
    private function sanitizeEmailSubject(string $subject): string
    {
        // Remove newlines, carriage returns, and control characters
        $subject = preg_replace('/[\r\n\x00-\x1F\x7F]/', '', $subject);

        // Remove potential email header injection keywords
        $subject = preg_replace('/(Bcc|Cc|To|From|Subject|Content-Type|MIME-Version):/i', '', (string) $subject);

        // Limit length to prevent overly long subjects
        $subject = mb_substr((string) $subject, 0, 255);

        return trim($subject);
    }
}

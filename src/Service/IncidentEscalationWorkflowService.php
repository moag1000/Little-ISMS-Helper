<?php

namespace App\Service;

use DateMalformedStringException;
use DateTimeImmutable;
use App\Entity\WorkflowInstance;
use App\Entity\Incident;
use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Incident Escalation Workflow Service
 *
 * ISO 27001:2022 Clause 8.3.2 (Incident response) compliant
 * GDPR/DSGVO Art. 33 + BDSG § 42 (72h breach notification) compliant
 *
 * Implements automatic incident escalation based on severity:
 * - Low/Medium: Incident Manager notification (automatic)
 * - High: Incident Manager + CISO notification (automatic)
 * - Critical: Full escalation → Incident Manager + CISO + Management (automatic)
 * - Data Breach: Automatic GDPR 72h workflow with DPO + CISO + CEO approval required
 *
 * Features:
 * - Automatic escalation based on incident severity
 * - GDPR 72h breach notification workflow
 * - Role-based notification to appropriate stakeholders
 * - SLA tracking for incident response times
 * - Audit trail for compliance
 *
 * Escalation Levels:
 * - Level 1 (Low/Medium): Incident Manager handles
 * - Level 2 (High): CISO involved for oversight
 * - Level 3 (Critical): Management notification required
 * - Level 4 (Data Breach): DPO + Regulatory compliance workflow
 */
class IncidentEscalationWorkflowService
{
    // Escalation thresholds based on ISO 27035-2:2016
    private const string SEVERITY_LOW = 'low';
    private const string SEVERITY_MEDIUM = 'medium';
    private const string SEVERITY_HIGH = 'high';
    private const string SEVERITY_CRITICAL = 'critical';

    // GDPR breach notification deadline (hours)
    private const int GDPR_BREACH_DEADLINE_HOURS = 72;

    // Response time SLAs (hours)
    private const int SLA_LOW = 48;        // 2 days
    private const int SLA_MEDIUM = 24;     // 1 day
    private const int SLA_HIGH = 8;        // 8 hours
    private const int SLA_CRITICAL = 2;    // 2 hours
    private const int SLA_BREACH = 1;      // 1 hour (immediate)

    public function __construct(
        private readonly WorkflowService $workflowService,
        private readonly EmailNotificationService $emailNotificationService,
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {}

    /**
     * Automatically escalate incident based on severity and breach status
     *
     * Called when new incident is created or severity changes
     *
     * @param Incident $incident The incident to escalate
     * @return array Escalation status and workflow information
     */
    public function autoEscalate(Incident $incident): array
    {
        $this->logger->info('Auto-escalating incident', [
            'incident_id' => $incident->getId(),
            'incident_number' => $incident->getIncidentNumber(),
            'severity' => $incident->getSeverity(),
            'data_breach' => $incident->isDataBreachOccurred(),
        ]);

        // Check if data breach - highest priority
        if ($incident->isDataBreachOccurred()) {
            return $this->escalateDataBreach($incident);
        }

        // Escalate based on severity
        return match ($incident->getSeverity()) {
            self::SEVERITY_CRITICAL => $this->escalateCritical($incident),
            self::SEVERITY_HIGH => $this->escalateHigh($incident),
            self::SEVERITY_MEDIUM => $this->escalateMedium($incident),
            self::SEVERITY_LOW => $this->escalateLow($incident),
            default => $this->escalateLow($incident),
        };
    }

    /**
     * Escalate data breach incident - GDPR 72h compliance
     *
     * ISO 27001:2022 Clause 8.3.2 + GDPR Art. 33 + BDSG § 42
     */
    private function escalateDataBreach(Incident $incident): array
    {
        $this->logger->critical('Data breach detected - initiating GDPR 72h workflow', [
            'incident_id' => $incident->getId(),
            'detected_at' => $incident->getDetectedAt()?->format('Y-m-d H:i:s'),
        ]);

        // Calculate 72h deadline
        $detectedAt = $incident->getDetectedAt() ?? new DateTimeImmutable();
        $deadline = $detectedAt->modify('+' . self::GDPR_BREACH_DEADLINE_HOURS . ' hours');

        // Calculate time remaining
        $now = new DateTimeImmutable();
        $hoursRemaining = ($deadline->getTimestamp() - $now->getTimestamp()) / 3600;

        // Find DPO, CISO, and CEO
        $dpo = $this->findUserByRole('ROLE_DPO');
        $ciso = $this->findUserByRole('ROLE_CISO');
        $ceo = $this->findUserByRole('ROLE_CEO');

        // Create workflow instance for breach notification approval
        $workflowInstance = $this->workflowService->startWorkflow(
            'Incident',
            $incident->getId(),
            'Data Breach Notification'
        );

        if (!$workflowInstance instanceof WorkflowInstance) {
            $this->logger->warning('No workflow found for Data Breach Notification', [
                'incident_id' => $incident->getId(),
            ]);
        }

        // Send immediate notifications
        $this->notifyDataBreach($incident, $dpo, $ciso, $ceo, $deadline);

        // Audit log
        $this->auditLogger->logCustom(
            'incident_escalation',
            'data_breach_workflow_started',
            $incident->getId(),
            null,
            [
                'incident_number' => $incident->getIncidentNumber(),
                'deadline' => $deadline->format('Y-m-d H:i:s'),
                'hours_remaining' => round($hoursRemaining, 1),
                'notified' => [
                    'dpo' => $dpo?->getEmail(),
                    'ciso' => $ciso?->getEmail(),
                    'ceo' => $ceo?->getEmail(),
                ],
            ]
        );

        return [
            'escalation_level' => 'data_breach',
            'workflow_started' => $workflowInstance instanceof WorkflowInstance,
            'workflow_instance' => $workflowInstance,
            'deadline' => $deadline,
            'hours_remaining' => round($hoursRemaining, 1),
            'notified_users' => array_filter([$dpo, $ciso, $ceo]),
            'requires_approval' => true,
            'approval_required_by' => ['DPO', 'CISO', 'CEO'],
        ];
    }

    /**
     * Escalate critical severity incident
     *
     * Notifies: Incident Manager + CISO + Management
     */
    private function escalateCritical(Incident $incident): array
    {
        $this->logger->error('Critical incident escalation', [
            'incident_id' => $incident->getId(),
            'incident_number' => $incident->getIncidentNumber(),
        ]);

        // Find responsible roles
        $incidentManager = $this->findUserByRole('ROLE_INCIDENT_MANAGER');
        $ciso = $this->findUserByRole('ROLE_CISO');
        $management = $this->findUsersByRole('ROLE_MANAGER');

        // Calculate SLA deadline
        $slaDeadline = new DateTimeImmutable()->modify('+' . self::SLA_CRITICAL . ' hours');

        // Create workflow for critical incident response
        $workflowInstance = $this->workflowService->startWorkflow(
            'Incident',
            $incident->getId(),
            'Critical Incident Response'
        );

        if (!$workflowInstance instanceof WorkflowInstance) {
            $this->logger->warning('No workflow found for Critical Incident Response', [
                'incident_id' => $incident->getId(),
            ]);
        }

        // Generate incident URL for email
        $incidentUrl = $this->urlGenerator->generate(
            'app_incident_show',
            ['id' => $incident->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Send notifications
        $notifiedUsers = [];
        if ($incidentManager instanceof User) {
            $this->emailNotificationService->sendIncidentEscalationNotification($incidentManager, $incident, 'critical', $slaDeadline, $incidentUrl);
            $notifiedUsers[] = $incidentManager;
        }
        if ($ciso instanceof User) {
            $this->emailNotificationService->sendIncidentEscalationNotification($ciso, $incident, 'critical', $slaDeadline, $incidentUrl);
            $notifiedUsers[] = $ciso;
        }
        foreach ($management as $manager) {
            $this->emailNotificationService->sendIncidentEscalationNotification($manager, $incident, 'critical', $slaDeadline, $incidentUrl);
            $notifiedUsers[] = $manager;
        }

        // Audit log
        $this->auditLogger->logCustom(
            'incident_escalation',
            'critical_incident_escalated',
            $incident->getId(),
            null,
            [
                'incident_number' => $incident->getIncidentNumber(),
                'sla_deadline' => $slaDeadline->format('Y-m-d H:i:s'),
                'notified_count' => count($notifiedUsers),
            ]
        );

        return [
            'escalation_level' => 'critical',
            'workflow_started' => $workflowInstance instanceof WorkflowInstance,
            'workflow_instance' => $workflowInstance,
            'sla_deadline' => $slaDeadline,
            'sla_hours' => self::SLA_CRITICAL,
            'notified_users' => $notifiedUsers,
            'requires_approval' => false,
            'auto_notification' => true,
        ];
    }

    /**
     * Escalate high severity incident
     *
     * Notifies: Incident Manager + CISO
     */
    private function escalateHigh(Incident $incident): array
    {
        $this->logger->warning('High severity incident escalation', [
            'incident_id' => $incident->getId(),
            'incident_number' => $incident->getIncidentNumber(),
        ]);

        $incidentManager = $this->findUserByRole('ROLE_INCIDENT_MANAGER');
        $ciso = $this->findUserByRole('ROLE_CISO');

        $slaDeadline = new DateTimeImmutable()->modify('+' . self::SLA_HIGH . ' hours');

        // Create workflow for high severity response
        $workflowInstance = $this->workflowService->startWorkflow(
            'Incident',
            $incident->getId(),
            'High Severity Incident'
        );

        if (!$workflowInstance instanceof WorkflowInstance) {
            $this->logger->warning('No workflow found for High Severity Incident', [
                'incident_id' => $incident->getId(),
            ]);
        }

        // Generate incident URL for email
        $incidentUrl = $this->urlGenerator->generate(
            'app_incident_show',
            ['id' => $incident->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $notifiedUsers = [];
        if ($incidentManager instanceof User) {
            $this->emailNotificationService->sendIncidentEscalationNotification($incidentManager, $incident, 'high', $slaDeadline, $incidentUrl);
            $notifiedUsers[] = $incidentManager;
        }
        if ($ciso instanceof User) {
            $this->emailNotificationService->sendIncidentEscalationNotification($ciso, $incident, 'high', $slaDeadline, $incidentUrl);
            $notifiedUsers[] = $ciso;
        }

        $this->auditLogger->logCustom(
            'incident_escalation',
            'high_incident_escalated',
            $incident->getId(),
            null,
            [
                'incident_number' => $incident->getIncidentNumber(),
                'sla_deadline' => $slaDeadline->format('Y-m-d H:i:s'),
            ]
        );

        return [
            'escalation_level' => 'high',
            'workflow_started' => $workflowInstance instanceof WorkflowInstance,
            'workflow_instance' => $workflowInstance,
            'sla_deadline' => $slaDeadline,
            'sla_hours' => self::SLA_HIGH,
            'notified_users' => $notifiedUsers,
            'requires_approval' => false,
            'auto_notification' => true,
        ];
    }

    /**
     * Escalate medium severity incident
     *
     * Notifies: Incident Manager
     */
    private function escalateMedium(Incident $incident): array
    {
        $incidentManager = $this->findUserByRole('ROLE_INCIDENT_MANAGER');
        $slaDeadline = new DateTimeImmutable()->modify('+' . self::SLA_MEDIUM . ' hours');

        $workflowInstance = $this->workflowService->startWorkflow(
            'Incident',
            $incident->getId(),
            'Medium Severity Incident'
        );

        if (!$workflowInstance instanceof WorkflowInstance) {
            $this->logger->warning('No workflow found for Medium Severity Incident', [
                'incident_id' => $incident->getId(),
            ]);
        }

        // Generate incident URL for email
        $incidentUrl = $this->urlGenerator->generate(
            'app_incident_show',
            ['id' => $incident->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        if ($incidentManager instanceof User) {
            $this->emailNotificationService->sendIncidentEscalationNotification($incidentManager, $incident, 'medium', $slaDeadline, $incidentUrl);
        }

        $this->auditLogger->logCustom(
            'incident_escalation',
            'medium_incident_escalated',
            $incident->getId(),
            null,
            ['incident_number' => $incident->getIncidentNumber()]
        );

        return [
            'escalation_level' => 'medium',
            'workflow_started' => $workflowInstance instanceof WorkflowInstance,
            'workflow_instance' => $workflowInstance,
            'sla_deadline' => $slaDeadline,
            'sla_hours' => self::SLA_MEDIUM,
            'notified_users' => $incidentManager instanceof User ? [$incidentManager] : [],
            'requires_approval' => false,
            'auto_notification' => true,
        ];
    }

    /**
     * Escalate low severity incident
     *
     * Notifies: Incident Manager (low priority)
     */
    private function escalateLow(Incident $incident): array
    {
        $incidentManager = $this->findUserByRole('ROLE_INCIDENT_MANAGER');
        $slaDeadline = new DateTimeImmutable()->modify('+' . self::SLA_LOW . ' hours');

        // Generate incident URL for email
        $incidentUrl = $this->urlGenerator->generate(
            'app_incident_show',
            ['id' => $incident->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // No workflow for low severity - just track SLA
        if ($incidentManager instanceof User) {
            $this->emailNotificationService->sendIncidentEscalationNotification($incidentManager, $incident, 'low', $slaDeadline, $incidentUrl);
        }

        $this->auditLogger->logCustom(
            'incident_escalation',
            'low_incident_logged',
            $incident->getId(),
            null,
            ['incident_number' => $incident->getIncidentNumber()]
        );

        return [
            'escalation_level' => 'low',
            'workflow_started' => false,
            'workflow_instance' => null,
            'sla_deadline' => $slaDeadline,
            'sla_hours' => self::SLA_LOW,
            'notified_users' => $incidentManager instanceof User ? [$incidentManager] : [],
            'requires_approval' => false,
            'auto_notification' => true,
        ];
    }

    /**
     * Send data breach notifications to DPO, CISO, CEO
     */
    private function notifyDataBreach(
        Incident $incident,
        ?User $dpo,
        ?User $ciso,
        ?User $ceo,
        DateTimeImmutable $deadline
    ): void {
        // Generate incident URL for email
        $incidentUrl = $this->urlGenerator->generate(
            'app_incident_show',
            ['id' => $incident->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $context = [
            'incident' => $incident,
            'deadline' => $deadline,
            'deadline_formatted' => $deadline->format('d.m.Y H:i'),
            'hours_remaining' => round(($deadline->getTimestamp() - time()) / 3600, 1),
            'incident_url' => $incidentUrl,
        ];

        if ($dpo instanceof User) {
            $this->emailNotificationService->sendDataBreachNotification($dpo, $context);
        }

        if ($ciso instanceof User) {
            $this->emailNotificationService->sendDataBreachNotification($ciso, $context);
        }

        if ($ceo instanceof User) {
            $this->emailNotificationService->sendDataBreachNotification($ceo, $context);
        }
    }

    /**
     * Find user by role
     */
    private function findUserByRole(string $role): ?User
    {
        $users = $this->userRepository->findByRole($role);
        return $users[0] ?? null;
    }

    /**
     * Find all users with a specific role
     */
    private function findUsersByRole(string $role): array
    {
        return $this->userRepository->findByRole($role);
    }

    /**
     * Check if incident requires immediate escalation
     */
    public function requiresImmediateEscalation(Incident $incident): bool
    {
        if ($incident->isDataBreachOccurred()) {
            return true;
        }
        return $incident->getSeverity() === self::SEVERITY_CRITICAL;
    }

    /**
     * Get escalation status for an incident
     */
    public function getEscalationStatus(Incident $incident): array
    {
        $workflowInstance = $this->workflowService->getWorkflowInstance('Incident', $incident->getId());

        if (!$workflowInstance instanceof WorkflowInstance) {
            return [
                'has_active_workflow' => false,
                'escalation_level' => null,
            ];
        }

        return [
            'has_active_workflow' => true,
            'escalation_level' => $this->determineEscalationLevel($incident),
            'workflow_instance' => $workflowInstance,
            'workflow_status' => $workflowInstance->getStatus(),
            'current_step' => $workflowInstance->getCurrentStep()?->getName(),
            'due_date' => $workflowInstance->getDueDate(),
            'is_overdue' => $workflowInstance->isOverdue(),
        ];
    }

    /**
     * Determine escalation level based on incident properties
     */
    private function determineEscalationLevel(Incident $incident): string
    {
        if ($incident->isDataBreachOccurred()) {
            return 'data_breach';
        }

        return match ($incident->getSeverity()) {
            self::SEVERITY_CRITICAL => 'critical',
            self::SEVERITY_HIGH => 'high',
            self::SEVERITY_MEDIUM => 'medium',
            default => 'low',
        };
    }

    /**
     * Generate escalation preview without actually triggering workflows
     *
     * Shows users what will happen BEFORE they create/update an incident,
     * so they understand workflow implications.
     *
     * @param Incident $incident Incident object (can be unsaved)
     * @return array Preview information
     *  - escalation_level: escalation level (data_breach, critical, high, medium, low)
     *  - workflow_started: whether a workflow was started
     *  - workflow_instance: workflow instance (if workflow was started)
     *  - hours_remaining: hours remaining until SLA deadline
     *  - notified_users: notified users (if workflow was started)
     *  - requires_approval: whether approval is required
     *  - approval_required_by: roles required for approval (if approval is required)
     *  - auto_notification: whether notifications are sent automatically
     *
     * @throws DateMalformedStringException
     */
    public function previewEscalation(Incident $incident): array
    {
        $severity = $incident->getSeverity();
        $isDataBreach = $incident->isDataBreachOccurred();
        $escalationLevel = $this->determineEscalationLevel($incident);

        // Determine workflow name based on escalation level
        $workflowName = match ($escalationLevel) {
            'data_breach' => 'Data Breach Notification',
            'critical' => 'Critical Incident Response',
            'high' => 'High Severity Incident',
            'medium' => 'Medium Severity Incident',
            default => null,
        };

        // Determine if escalation will occur
        $willEscalate = in_array($severity, [self::SEVERITY_MEDIUM, self::SEVERITY_HIGH, self::SEVERITY_CRITICAL]) || $isDataBreach;

        // Get SLA hours
        $slaHours = match ($escalationLevel) {
            'data_breach' => self::SLA_BREACH,
            'critical' => self::SLA_CRITICAL,
            'high' => self::SLA_HIGH,
            'medium' => self::SLA_MEDIUM,
            default => self::SLA_LOW,
        };

        // Build SLA description
        $slaDescription = match ($escalationLevel) {
            'data_breach' => 'Immediate response required (1 hour)',
            'critical' => 'Response within 2 hours',
            'high' => 'Response within 8 hours',
            'medium' => 'Response within 24 hours (1 day)',
            default => 'Response within 48 hours (2 days)',
        };

        // Determine notified roles and get actual users
        $notifiedRoles = [];
        $notifiedUsers = [];

        if ($isDataBreach) {
            $notifiedRoles = ['DPO', 'CISO', 'CEO'];
            $dpo = $this->findUserByRole('ROLE_DPO');
            $ciso = $this->findUserByRole('ROLE_CISO');
            $ceo = $this->findUserByRole('ROLE_CEO');
            $notifiedUsers = array_filter([$dpo, $ciso, $ceo]);
        } elseif ($severity === self::SEVERITY_CRITICAL) {
            $notifiedRoles = ['Incident Manager', 'CISO', 'Management'];
            $incidentManager = $this->findUserByRole('ROLE_INCIDENT_MANAGER');
            $ciso = $this->findUserByRole('ROLE_CISO');
            $management = $this->findUsersByRole('ROLE_MANAGER');
            $notifiedUsers = array_filter(array_merge(
                $incidentManager instanceof User ? [$incidentManager] : [],
                $ciso instanceof User ? [$ciso] : [],
                $management
            ));
        } elseif ($severity === self::SEVERITY_HIGH) {
            $notifiedRoles = ['Incident Manager', 'CISO'];
            $incidentManager = $this->findUserByRole('ROLE_INCIDENT_MANAGER');
            $ciso = $this->findUserByRole('ROLE_CISO');
            $notifiedUsers = array_filter([$incidentManager, $ciso]);
        } elseif ($severity === self::SEVERITY_MEDIUM) {
            $notifiedRoles = ['Incident Manager'];
            $incidentManager = $this->findUserByRole('ROLE_INCIDENT_MANAGER');
            $notifiedUsers = $incidentManager instanceof User ? [$incidentManager] : [];
        }

        // GDPR deadline calculation
        $gdprDeadline = null;
        if ($isDataBreach) {
            $detectedAt = $incident->getDetectedAt() ?? new DateTimeImmutable();
            $gdprDeadline = $detectedAt->modify('+' . self::GDPR_BREACH_DEADLINE_HOURS . ' hours');
        }

        // Approval requirements
        $requiresApproval = $isDataBreach;
        $approvalSteps = [];
        if ($isDataBreach) {
            $approvalSteps = [
                ['name' => 'DPO Review', 'role' => 'Data Protection Officer'],
                ['name' => 'CISO Approval', 'role' => 'Chief Information Security Officer'],
                ['name' => 'CEO Final Approval', 'role' => 'Chief Executive Officer'],
            ];
        }

        // Estimated completion time
        $estimatedCompletionTime = match ($escalationLevel) {
            'data_breach' => '72 hours (GDPR compliance required)',
            'critical' => '2-4 hours with immediate management attention',
            'high' => '8-24 hours with senior oversight',
            'medium' => '1-2 days standard process',
            default => '2-3 days standard process',
        };

        return [
            'will_escalate' => $willEscalate,
            'escalation_level' => $escalationLevel,
            'workflow_name' => $workflowName,
            'notified_roles' => $notifiedRoles,
            'notified_users' => $notifiedUsers,
            'sla_hours' => $slaHours,
            'sla_description' => $slaDescription,
            'is_gdpr_breach' => $isDataBreach,
            'gdpr_deadline' => $gdprDeadline,
            'requires_approval' => $requiresApproval,
            'approval_steps' => $approvalSteps,
            'estimated_completion_time' => $estimatedCompletionTime,
        ];
    }
}

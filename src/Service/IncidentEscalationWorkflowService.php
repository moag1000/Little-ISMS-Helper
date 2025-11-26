<?php

namespace App\Service;

use App\Entity\Incident;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
    private const SEVERITY_LOW = 'low';
    private const SEVERITY_MEDIUM = 'medium';
    private const SEVERITY_HIGH = 'high';
    private const SEVERITY_CRITICAL = 'critical';

    // GDPR breach notification deadline (hours)
    private const GDPR_BREACH_DEADLINE_HOURS = 72;

    // Response time SLAs (hours)
    private const SLA_LOW = 48;        // 2 days
    private const SLA_MEDIUM = 24;     // 1 day
    private const SLA_HIGH = 8;        // 8 hours
    private const SLA_CRITICAL = 2;    // 2 hours
    private const SLA_BREACH = 1;      // 1 hour (immediate)

    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkflowService $workflowService,
        private EmailNotificationService $emailService,
        private UserRepository $userRepository,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger
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
     *
     * @param Incident $incident
     * @return array
     */
    private function escalateDataBreach(Incident $incident): array
    {
        $this->logger->critical('Data breach detected - initiating GDPR 72h workflow', [
            'incident_id' => $incident->getId(),
            'detected_at' => $incident->getDetectedAt()?->format('Y-m-d H:i:s'),
        ]);

        // Calculate 72h deadline
        $detectedAt = $incident->getDetectedAt() ?? new \DateTimeImmutable();
        $deadline = $detectedAt->modify('+' . self::GDPR_BREACH_DEADLINE_HOURS . ' hours');

        // Calculate time remaining
        $now = new \DateTimeImmutable();
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

        // Send immediate notifications
        $this->notifyDataBreach($incident, $dpo, $ciso, $ceo, $deadline);

        // Audit log
        $this->auditLogger->log(
            'incident_escalation',
            'data_breach_workflow_started',
            $incident->getId(),
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
            'workflow_started' => $workflowInstance !== null,
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
     *
     * @param Incident $incident
     * @return array
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
        $slaDeadline = (new \DateTimeImmutable())->modify('+' . self::SLA_CRITICAL . ' hours');

        // Create workflow for critical incident response
        $workflowInstance = $this->workflowService->startWorkflow(
            'Incident',
            $incident->getId(),
            'Critical Incident Response'
        );

        // Send notifications
        $notifiedUsers = [];
        if ($incidentManager) {
            $this->emailService->sendIncidentEscalationNotification($incidentManager, $incident, 'critical');
            $notifiedUsers[] = $incidentManager;
        }
        if ($ciso) {
            $this->emailService->sendIncidentEscalationNotification($ciso, $incident, 'critical');
            $notifiedUsers[] = $ciso;
        }
        foreach ($management as $manager) {
            $this->emailService->sendIncidentEscalationNotification($manager, $incident, 'critical');
            $notifiedUsers[] = $manager;
        }

        // Audit log
        $this->auditLogger->log(
            'incident_escalation',
            'critical_incident_escalated',
            $incident->getId(),
            [
                'incident_number' => $incident->getIncidentNumber(),
                'sla_deadline' => $slaDeadline->format('Y-m-d H:i:s'),
                'notified_count' => count($notifiedUsers),
            ]
        );

        return [
            'escalation_level' => 'critical',
            'workflow_started' => $workflowInstance !== null,
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
     *
     * @param Incident $incident
     * @return array
     */
    private function escalateHigh(Incident $incident): array
    {
        $this->logger->warning('High severity incident escalation', [
            'incident_id' => $incident->getId(),
            'incident_number' => $incident->getIncidentNumber(),
        ]);

        $incidentManager = $this->findUserByRole('ROLE_INCIDENT_MANAGER');
        $ciso = $this->findUserByRole('ROLE_CISO');

        $slaDeadline = (new \DateTimeImmutable())->modify('+' . self::SLA_HIGH . ' hours');

        // Create workflow for high severity response
        $workflowInstance = $this->workflowService->startWorkflow(
            'Incident',
            $incident->getId(),
            'High Severity Incident'
        );

        $notifiedUsers = [];
        if ($incidentManager) {
            $this->emailService->sendIncidentEscalationNotification($incidentManager, $incident, 'high');
            $notifiedUsers[] = $incidentManager;
        }
        if ($ciso) {
            $this->emailService->sendIncidentEscalationNotification($ciso, $incident, 'high');
            $notifiedUsers[] = $ciso;
        }

        $this->auditLogger->log(
            'incident_escalation',
            'high_incident_escalated',
            $incident->getId(),
            [
                'incident_number' => $incident->getIncidentNumber(),
                'sla_deadline' => $slaDeadline->format('Y-m-d H:i:s'),
            ]
        );

        return [
            'escalation_level' => 'high',
            'workflow_started' => $workflowInstance !== null,
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
     *
     * @param Incident $incident
     * @return array
     */
    private function escalateMedium(Incident $incident): array
    {
        $incidentManager = $this->findUserByRole('ROLE_INCIDENT_MANAGER');
        $slaDeadline = (new \DateTimeImmutable())->modify('+' . self::SLA_MEDIUM . ' hours');

        $workflowInstance = $this->workflowService->startWorkflow(
            'Incident',
            $incident->getId(),
            'Medium Severity Incident'
        );

        if ($incidentManager) {
            $this->emailService->sendIncidentEscalationNotification($incidentManager, $incident, 'medium');
        }

        $this->auditLogger->log(
            'incident_escalation',
            'medium_incident_escalated',
            $incident->getId(),
            ['incident_number' => $incident->getIncidentNumber()]
        );

        return [
            'escalation_level' => 'medium',
            'workflow_started' => $workflowInstance !== null,
            'workflow_instance' => $workflowInstance,
            'sla_deadline' => $slaDeadline,
            'sla_hours' => self::SLA_MEDIUM,
            'notified_users' => $incidentManager ? [$incidentManager] : [],
            'requires_approval' => false,
            'auto_notification' => true,
        ];
    }

    /**
     * Escalate low severity incident
     *
     * Notifies: Incident Manager (low priority)
     *
     * @param Incident $incident
     * @return array
     */
    private function escalateLow(Incident $incident): array
    {
        $incidentManager = $this->findUserByRole('ROLE_INCIDENT_MANAGER');
        $slaDeadline = (new \DateTimeImmutable())->modify('+' . self::SLA_LOW . ' hours');

        // No workflow for low severity - just track SLA
        if ($incidentManager) {
            $this->emailService->sendIncidentEscalationNotification($incidentManager, $incident, 'low');
        }

        $this->auditLogger->log(
            'incident_escalation',
            'low_incident_logged',
            $incident->getId(),
            ['incident_number' => $incident->getIncidentNumber()]
        );

        return [
            'escalation_level' => 'low',
            'workflow_started' => false,
            'workflow_instance' => null,
            'sla_deadline' => $slaDeadline,
            'sla_hours' => self::SLA_LOW,
            'notified_users' => $incidentManager ? [$incidentManager] : [],
            'requires_approval' => false,
            'auto_notification' => true,
        ];
    }

    /**
     * Send data breach notifications to DPO, CISO, CEO
     *
     * @param Incident $incident
     * @param User|null $dpo
     * @param User|null $ciso
     * @param User|null $ceo
     * @param \DateTimeImmutable $deadline
     */
    private function notifyDataBreach(
        Incident $incident,
        ?User $dpo,
        ?User $ciso,
        ?User $ceo,
        \DateTimeImmutable $deadline
    ): void {
        $context = [
            'incident' => $incident,
            'deadline' => $deadline,
            'deadline_formatted' => $deadline->format('d.m.Y H:i'),
            'hours_remaining' => round(($deadline->getTimestamp() - time()) / 3600, 1),
        ];

        if ($dpo) {
            $this->emailService->sendDataBreachNotification($dpo, $context);
        }

        if ($ciso) {
            $this->emailService->sendDataBreachNotification($ciso, $context);
        }

        if ($ceo) {
            $this->emailService->sendDataBreachNotification($ceo, $context);
        }
    }

    /**
     * Find user by role
     *
     * @param string $role
     * @return User|null
     */
    private function findUserByRole(string $role): ?User
    {
        $users = $this->userRepository->findByRole($role);
        return $users[0] ?? null;
    }

    /**
     * Find all users with a specific role
     *
     * @param string $role
     * @return array
     */
    private function findUsersByRole(string $role): array
    {
        return $this->userRepository->findByRole($role);
    }

    /**
     * Check if incident requires immediate escalation
     *
     * @param Incident $incident
     * @return bool
     */
    public function requiresImmediateEscalation(Incident $incident): bool
    {
        return $incident->isDataBreachOccurred()
            || $incident->getSeverity() === self::SEVERITY_CRITICAL;
    }

    /**
     * Get escalation status for an incident
     *
     * @param Incident $incident
     * @return array
     */
    public function getEscalationStatus(Incident $incident): array
    {
        $workflowInstance = $this->workflowService->getWorkflowInstance('Incident', $incident->getId());

        if (!$workflowInstance) {
            return [
                'has_active_workflow' => false,
                'escalation_level' => null,
            ];
        }

        return [
            'has_active_workflow' => true,
            'escalation_level' => $this->determineEscalationLevel($incident),
            'workflow_status' => $workflowInstance->getStatus(),
            'current_step' => $workflowInstance->getCurrentStep()?->getName(),
            'due_date' => $workflowInstance->getDueDate(),
            'is_overdue' => $workflowInstance->isOverdue(),
        ];
    }

    /**
     * Determine escalation level based on incident properties
     *
     * @param Incident $incident
     * @return string
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
}

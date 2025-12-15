<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\Option;
use DateTime;
use DateTimeImmutable;
use App\Repository\InternalAuditRepository;
use App\Repository\TrainingRepository;
use App\Repository\IncidentRepository;
use App\Repository\ControlRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Repository\UserRepository;
use App\Service\EmailNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:send-notifications', description: 'Send scheduled email notifications for due dates and reminders', help: <<<'TXT'
This command sends scheduled email notifications for:
- Upcoming audits (within X days)
- Upcoming trainings (within X days)
- Open incidents (older than X days)
- Controls nearing target date
- Overdue workflow approvals

Usage:
  php bin/console app:send-notifications --type=audits --days-ahead=7
  php bin/console app:send-notifications --type=all --dry-run

Recommended cron setup (daily at 8 AM):
  0 8 * * * php /path/to/bin/console app:send-notifications --type=all

TXT)]
class SendNotificationsCommand
{
    public function __construct(private readonly InternalAuditRepository $internalAuditRepository, private readonly TrainingRepository $trainingRepository, private readonly IncidentRepository $incidentRepository, private readonly ControlRepository $controlRepository, private readonly WorkflowInstanceRepository $workflowInstanceRepository, private readonly UserRepository $userRepository, private readonly EmailNotificationService $emailNotificationService)
    {
    }

    public function __invoke(
        #[Option(name: 'type', shortcut: 't', description: 'Notification type (audits, trainings, incidents, controls, workflows, all)')]
        string $type = 'all',
        #[Option(name: 'days-ahead', shortcut: 'd', description: 'Days ahead to check for upcoming items')]
        int $daysAhead = 7,
        #[Option(name: 'dry-run', description: 'Dry run - show what would be sent without actually sending')]
        bool $dryRun = false,
        ?SymfonyStyle $symfonyStyle = null
    ): int
    {
        $symfonyStyle->title('ISMS Notification Service');
        if ($dryRun) {
            $symfonyStyle->warning('DRY RUN MODE - No emails will be sent');
        }
        $totalSent = 0;
        // Upcoming Audits
        if ($type === 'audits' || $type === 'all') {
            $symfonyStyle->section('Checking Upcoming Audits');
            $sent = $this->sendAuditNotifications($daysAhead, $dryRun, $symfonyStyle);
            $totalSent += $sent;
            $symfonyStyle->success(sprintf('Sent %d audit notifications', $sent));
        }
        // Upcoming Trainings
        if ($type === 'trainings' || $type === 'all') {
            $symfonyStyle->section('Checking Upcoming Trainings');
            $sent = $this->sendTrainingNotifications($daysAhead, $dryRun, $symfonyStyle);
            $totalSent += $sent;
            $symfonyStyle->success(sprintf('Sent %d training notifications', $sent));
        }
        // Open Incidents
        if ($type === 'incidents' || $type === 'all') {
            $symfonyStyle->section('Checking Open Incidents');
            $sent = $this->sendIncidentNotifications($daysAhead, $dryRun, $symfonyStyle);
            $totalSent += $sent;
            $symfonyStyle->success(sprintf('Sent %d incident notifications', $sent));
        }
        // Controls Nearing Target Date
        if ($type === 'controls' || $type === 'all') {
            $symfonyStyle->section('Checking Controls Nearing Target Date');
            $sent = $this->sendControlNotifications($daysAhead, $dryRun, $symfonyStyle);
            $totalSent += $sent;
            $symfonyStyle->success(sprintf('Sent %d control notifications', $sent));
        }
        // Overdue Workflow Approvals
        if ($type === 'workflows' || $type === 'all') {
            $symfonyStyle->section('Checking Overdue Workflow Approvals');
            $sent = $this->sendWorkflowNotifications($dryRun, $symfonyStyle);
            $totalSent += $sent;
            $symfonyStyle->success(sprintf('Sent %d workflow notifications', $sent));

            $symfonyStyle->section('Checking Workflows Approaching Deadline');
            $sent = $this->sendWorkflowDeadlineWarnings($daysAhead, $dryRun, $symfonyStyle);
            $totalSent += $sent;
            $symfonyStyle->success(sprintf('Sent %d workflow deadline warnings', $sent));
        }
        $symfonyStyle->success(sprintf('Total notifications sent: %d', $totalSent));
        return Command::SUCCESS;
    }

    private function sendAuditNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $symfonyStyle): int
    {
        $upcomingDate = new DateTime("+{$daysAhead} days");
        $today = new DateTime();

        $audits = $this->internalAuditRepository->createQueryBuilder('a')
            ->where('a.plannedDate BETWEEN :today AND :upcomingDate')
            ->andWhere('a.status IN (:statuses)')
            ->setParameter('today', $today)
            ->setParameter('upcomingDate', $upcomingDate)
            ->setParameter('statuses', ['planned', 'in_progress'])
            ->getQuery()
            ->getResult();

        $sent = 0;
        foreach ($audits as $audit) {
            $recipients = [];

            if ($audit->getLeadAuditor()) {
                $recipients[] = $audit->getLeadAuditor();
            }

            foreach ($audit->getAuditTeam() as $member) {
                $recipients[] = $member;
            }

            if ($recipients !== []) {
                $symfonyStyle->text(sprintf('  - Audit "%s" planned for %s → %d recipients',
                    $audit->getTitle(),
                    $audit->getPlannedDate()->format('d.m.Y'),
                    count($recipients)
                ));

                if (!$dryRun) {
                    $this->emailNotificationService->sendAuditDueNotification($audit, $recipients);
                }
                $sent++;
            }
        }

        return $sent;
    }

    private function sendTrainingNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $symfonyStyle): int
    {
        $upcomingDate = new DateTime("+{$daysAhead} days");
        $today = new DateTime();

        $trainings = $this->trainingRepository->createQueryBuilder('t')
            ->where('t.scheduledDate BETWEEN :today AND :upcomingDate')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('today', $today)
            ->setParameter('upcomingDate', $upcomingDate)
            ->setParameter('statuses', ['planned', 'confirmed'])
            ->getQuery()
            ->getResult();

        $sent = 0;
        foreach ($trainings as $training) {
            $recipients = [];

            // For mandatory trainings, send to all participants
            if ($training->isMandatory()) {
                $recipients = $training->getParticipants()->toArray();
            } else {
                // For non-mandatory, send to participants + trainer
                $recipients = $training->getParticipants()->toArray();
                if ($training->getTrainer()) {
                    $recipients[] = $training->getTrainer();
                }
            }

            if (!empty($recipients)) {
                $mandatoryLabel = $training->isMandatory() ? ' [MANDATORY]' : '';
                $symfonyStyle->text(sprintf('  - Training "%s"%s scheduled for %s → %d recipients',
                    $training->getTitle(),
                    $mandatoryLabel,
                    $training->getScheduledDate()->format('d.m.Y H:i'),
                    count($recipients)
                ));

                if (!$dryRun) {
                    $this->emailNotificationService->sendTrainingDueNotification($training, $recipients);
                }
                $sent++;
            }
        }

        return $sent;
    }

    private function sendIncidentNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $symfonyStyle): int
    {
        $thresholdDate = new DateTime("-{$daysAhead} days");

        $incidents = $this->incidentRepository->createQueryBuilder('i')
            ->where('i.detectedDate < :thresholdDate')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('thresholdDate', $thresholdDate)
            ->setParameter('statuses', ['new', 'investigating', 'in_progress'])
            ->getQuery()
            ->getResult();

        $sent = 0;
        foreach ($incidents as $incident) {
            // Send to incident owner and admins
            $recipients = [];

            if ($incident->getAssignedTo()) {
                $recipients[] = $incident->getAssignedTo();
            }

            // Get all admins
            $admins = $this->userRepository->findByRole('ROLE_ADMIN');
            foreach ($admins as $admin) {
                if (!in_array($admin, $recipients)) {
                    $recipients[] = $admin;
                }
            }

            if ($recipients !== []) {
                $daysOpen = $incident->getDetectedDate()->diff(new DateTime())->days;
                $symfonyStyle->text(sprintf('  - Incident "%s" [%s] open for %d days → %d recipients',
                    $incident->getTitle(),
                    strtoupper((string) $incident->getSeverity()),
                    $daysOpen,
                    count($recipients)
                ));

                if (!$dryRun) {
                    $this->emailNotificationService->sendIncidentNotification($incident, $recipients);
                }
                $sent++;
            }
        }

        return $sent;
    }

    private function sendControlNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $symfonyStyle): int
    {
        $upcomingDate = new DateTime("+{$daysAhead} days");
        $today = new DateTime();

        $controls = $this->controlRepository->createQueryBuilder('c')
            ->where('c.targetDate BETWEEN :today AND :upcomingDate')
            ->andWhere('c.implementationStatus != :implemented')
            ->setParameter('today', $today)
            ->setParameter('upcomingDate', $upcomingDate)
            ->setParameter('implemented', 'implemented')
            ->getQuery()
            ->getResult();

        $sent = 0;
        foreach ($controls as $control) {
            $recipients = [];

            if ($control->getResponsiblePerson()) {
                $recipients[] = $control->getResponsiblePerson();
            }

            if ($recipients !== []) {
                $symfonyStyle->text(sprintf('  - Control %s "%s" target date %s → %d recipients',
                    $control->getControlId(),
                    substr((string) $control->getName(), 0, 40),
                    $control->getTargetDate()->format('d.m.Y'),
                    count($recipients)
                ));

                if (!$dryRun) {
                    $this->emailNotificationService->sendControlDueNotification($control, $recipients);
                }
                $sent++;
            }
        }

        return $sent;
    }

    private function sendWorkflowNotifications(bool $dryRun, SymfonyStyle $symfonyStyle): int
    {
        $overdueInstances = $this->workflowInstanceRepository->findOverdue();

        $sent = 0;
        foreach ($overdueInstances as $overdueInstance) {
            $currentStep = $overdueInstance->getCurrentStep();
            if (!$currentStep) {
                continue;
            }

            $recipients = [];

            // Get approvers for current step by user IDs
            $approverUserIds = $currentStep->getApproverUsers() ?? [];
            if (!empty($approverUserIds)) {
                $usersByIds = $this->userRepository->findBy(['id' => $approverUserIds]);
                $recipients = array_merge($recipients, $usersByIds);
            }

            // Get approvers by role (Bug #3 fix)
            $approverRole = $currentStep->getApproverRole();
            if ($approverRole) {
                $usersByRole = $this->userRepository->findByRole($approverRole);
                $recipients = array_merge($recipients, $usersByRole);
            }

            // Remove duplicates
            $recipientIds = [];
            $uniqueRecipients = [];
            foreach ($recipients as $recipient) {
                if (!in_array($recipient->getId(), $recipientIds)) {
                    $recipientIds[] = $recipient->getId();
                    $uniqueRecipients[] = $recipient;
                }
            }

            if ($uniqueRecipients !== []) {
                $symfonyStyle->text(sprintf('  - Workflow "%s" for %s (ID: %d) overdue → %d recipients',
                    $overdueInstance->getWorkflow()->getName(),
                    $overdueInstance->getEntityType(),
                    $overdueInstance->getEntityId(),
                    count($uniqueRecipients)
                ));

                if (!$dryRun) {
                    $this->emailNotificationService->sendWorkflowOverdueNotification($overdueInstance, $uniqueRecipients);
                }
                $sent++;
            }
        }

        return $sent;
    }

    private function sendWorkflowDeadlineWarnings(int $daysAhead, bool $dryRun, SymfonyStyle $symfonyStyle): int
    {
        $today = new DateTimeImmutable();
        $warningDate = $today->modify("+{$daysAhead} days");

        // Get active workflows approaching deadline (but not yet overdue)
        $instances = $this->workflowInstanceRepository->createQueryBuilder('wi')
            ->where('wi.status IN (:statuses)')
            ->andWhere('wi.dueDate IS NOT NULL')
            ->andWhere('wi.dueDate > :today')
            ->andWhere('wi.dueDate <= :warningDate')
            ->setParameter('statuses', ['pending', 'in_progress'])
            ->setParameter('today', $today)
            ->setParameter('warningDate', $warningDate)
            ->getQuery()
            ->getResult();

        $sent = 0;
        foreach ($instances as $instance) {
            $currentStep = $instance->getCurrentStep();
            if (!$currentStep) {
                continue;
            }

            // Calculate days remaining
            $dueDate = $instance->getDueDate();
            $daysRemaining = (int) $today->diff($dueDate)->days;

            $recipients = [];

            // Get approvers for current step by user IDs
            $approverUserIds = $currentStep->getApproverUsers() ?? [];
            if (!empty($approverUserIds)) {
                $usersByIds = $this->userRepository->findBy(['id' => $approverUserIds]);
                $recipients = array_merge($recipients, $usersByIds);
            }

            // Get approvers by role
            $approverRole = $currentStep->getApproverRole();
            if ($approverRole) {
                $usersByRole = $this->userRepository->findByRole($approverRole);
                $recipients = array_merge($recipients, $usersByRole);
            }

            // Remove duplicates
            $recipientIds = [];
            $uniqueRecipients = [];
            foreach ($recipients as $recipient) {
                if (!in_array($recipient->getId(), $recipientIds)) {
                    $recipientIds[] = $recipient->getId();
                    $uniqueRecipients[] = $recipient;
                }
            }

            if ($uniqueRecipients !== []) {
                $symfonyStyle->text(sprintf('  - Workflow "%s" for %s (ID: %d) due in %d days → %d recipients',
                    $instance->getWorkflow()->getName(),
                    $instance->getEntityType(),
                    $instance->getEntityId(),
                    $daysRemaining,
                    count($uniqueRecipients)
                ));

                if (!$dryRun) {
                    $this->emailNotificationService->sendWorkflowDeadlineWarning($instance, $uniqueRecipients, $daysRemaining);
                }
                $sent++;
            }
        }

        return $sent;
    }
}

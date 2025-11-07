<?php

namespace App\Command;

use App\Repository\InternalAuditRepository;
use App\Repository\TrainingRepository;
use App\Repository\IncidentRepository;
use App\Repository\ControlRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Repository\UserRepository;
use App\Service\EmailNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:send-notifications',
    description: 'Send scheduled email notifications for due dates and reminders'
)]
class SendNotificationsCommand extends Command
{
    public function __construct(
        private InternalAuditRepository $auditRepository,
        private TrainingRepository $trainingRepository,
        private IncidentRepository $incidentRepository,
        private ControlRepository $controlRepository,
        private WorkflowInstanceRepository $workflowInstanceRepository,
        private UserRepository $userRepository,
        private EmailNotificationService $emailService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Notification type (audits, trainings, incidents, controls, workflows, all)', 'all')
            ->addOption('days-ahead', 'd', InputOption::VALUE_OPTIONAL, 'Days ahead to check for upcoming items', 7)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run - show what would be sent without actually sending')
            ->setHelp('This command sends scheduled email notifications for:
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
');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = $input->getOption('type');
        $daysAhead = (int) $input->getOption('days-ahead');
        $dryRun = $input->getOption('dry-run');

        $io->title('ISMS Notification Service');

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No emails will be sent');
        }

        $totalSent = 0;

        // Upcoming Audits
        if ($type === 'audits' || $type === 'all') {
            $io->section('Checking Upcoming Audits');
            $sent = $this->sendAuditNotifications($daysAhead, $dryRun, $io);
            $totalSent += $sent;
            $io->success(sprintf('Sent %d audit notifications', $sent));
        }

        // Upcoming Trainings
        if ($type === 'trainings' || $type === 'all') {
            $io->section('Checking Upcoming Trainings');
            $sent = $this->sendTrainingNotifications($daysAhead, $dryRun, $io);
            $totalSent += $sent;
            $io->success(sprintf('Sent %d training notifications', $sent));
        }

        // Open Incidents
        if ($type === 'incidents' || $type === 'all') {
            $io->section('Checking Open Incidents');
            $sent = $this->sendIncidentNotifications($daysAhead, $dryRun, $io);
            $totalSent += $sent;
            $io->success(sprintf('Sent %d incident notifications', $sent));
        }

        // Controls Nearing Target Date
        if ($type === 'controls' || $type === 'all') {
            $io->section('Checking Controls Nearing Target Date');
            $sent = $this->sendControlNotifications($daysAhead, $dryRun, $io);
            $totalSent += $sent;
            $io->success(sprintf('Sent %d control notifications', $sent));
        }

        // Overdue Workflow Approvals
        if ($type === 'workflows' || $type === 'all') {
            $io->section('Checking Overdue Workflow Approvals');
            $sent = $this->sendWorkflowNotifications($dryRun, $io);
            $totalSent += $sent;
            $io->success(sprintf('Sent %d workflow notifications', $sent));
        }

        $io->success(sprintf('Total notifications sent: %d', $totalSent));

        return Command::SUCCESS;
    }

    private function sendAuditNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $io): int
    {
        $upcomingDate = new \DateTime("+{$daysAhead} days");
        $today = new \DateTime();

        $audits = $this->auditRepository->createQueryBuilder('a')
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

            if (!empty($recipients)) {
                $io->text(sprintf('  - Audit "%s" planned for %s → %d recipients',
                    $audit->getTitle(),
                    $audit->getPlannedDate()->format('d.m.Y'),
                    count($recipients)
                ));

                if (!$dryRun) {
                    $this->emailService->sendAuditDueNotification($audit, $recipients);
                }
                $sent++;
            }
        }

        return $sent;
    }

    private function sendTrainingNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $io): int
    {
        $upcomingDate = new \DateTime("+{$daysAhead} days");
        $today = new \DateTime();

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
                $io->text(sprintf('  - Training "%s"%s scheduled for %s → %d recipients',
                    $training->getTitle(),
                    $mandatoryLabel,
                    $training->getScheduledDate()->format('d.m.Y H:i'),
                    count($recipients)
                ));

                if (!$dryRun) {
                    $this->emailService->sendTrainingDueNotification($training, $recipients);
                }
                $sent++;
            }
        }

        return $sent;
    }

    private function sendIncidentNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $io): int
    {
        $thresholdDate = new \DateTime("-{$daysAhead} days");

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

            if (!empty($recipients)) {
                $daysOpen = $incident->getDetectedDate()->diff(new \DateTime())->days;
                $io->text(sprintf('  - Incident "%s" [%s] open for %d days → %d recipients',
                    $incident->getTitle(),
                    strtoupper($incident->getSeverity()),
                    $daysOpen,
                    count($recipients)
                ));

                if (!$dryRun) {
                    $this->emailService->sendIncidentNotification($incident, $recipients);
                }
                $sent++;
            }
        }

        return $sent;
    }

    private function sendControlNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $io): int
    {
        $upcomingDate = new \DateTime("+{$daysAhead} days");
        $today = new \DateTime();

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

            if (!empty($recipients)) {
                $io->text(sprintf('  - Control %s "%s" target date %s → %d recipients',
                    $control->getControlId(),
                    substr($control->getName(), 0, 40),
                    $control->getTargetDate()->format('d.m.Y'),
                    count($recipients)
                ));

                if (!$dryRun) {
                    $this->emailService->sendControlDueNotification($control, $recipients);
                }
                $sent++;
            }
        }

        return $sent;
    }

    private function sendWorkflowNotifications(bool $dryRun, SymfonyStyle $io): int
    {
        $overdueInstances = $this->workflowInstanceRepository->findOverdue();

        $sent = 0;
        foreach ($overdueInstances as $instance) {
            $currentStep = $instance->getCurrentStep();
            if (!$currentStep) {
                continue;
            }

            $recipients = [];

            // Get approvers for current step
            if ($currentStep->getApproverUsers()) {
                $recipients = $currentStep->getApproverUsers()->toArray();
            }

            if (!empty($recipients)) {
                $io->text(sprintf('  - Workflow "%s" for %s (ID: %d) overdue → %d recipients',
                    $instance->getWorkflow()->getName(),
                    $instance->getEntityType(),
                    $instance->getEntityId(),
                    count($recipients)
                ));

                if (!$dryRun) {
                    $this->emailService->sendWorkflowOverdueNotification($instance, $recipients);
                }
                $sent++;
            }
        }

        return $sent;
    }
}

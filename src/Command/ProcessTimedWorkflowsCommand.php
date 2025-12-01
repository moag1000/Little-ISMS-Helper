<?php

namespace App\Command;

use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Repository\WorkflowInstanceRepository;
use App\Service\WorkflowAutoProgressionService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Process Time-Based Workflow Auto-Progression
 *
 * This command checks for workflow steps that should auto-progress based on time delays.
 * Run this via cron job for time-based automation.
 *
 * Example workflow step metadata:
 * {
 *   "autoProgressConditions": {
 *     "type": "time_based",
 *     "delay": "24 hours",
 *     "condition": "status = 'pending'"  // Optional
 *   }
 * }
 *
 * Recommended cron: every 15 minutes
 */
#[AsCommand(
    name: 'app:process-timed-workflows',
    description: 'Process time-based workflow auto-progression (run via cron)'
)]
class ProcessTimedWorkflowsCommand extends Command
{
    public function __construct(
        private readonly WorkflowInstanceRepository $workflowInstanceRepository,
        private readonly WorkflowAutoProgressionService $workflowAutoProgressionService,
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be processed without making changes')
            ->setHelp(<<<'HELP'
This command processes time-based workflow auto-progression.

<info>Time-Based Auto-Progression:</info>
Workflow steps can be configured to auto-progress after a time delay.
This is useful for:
  • Notification steps that auto-close after acknowledgment period
  • Review steps with automatic escalation after timeout
  • Compliance steps with mandatory waiting periods

<info>Examples:</info>
  # Process time-based workflows
  <comment>php bin/console app:process-timed-workflows</comment>

  # Dry run (see what would be processed)
  <comment>php bin/console app:process-timed-workflows --dry-run</comment>

<info>Cron Setup:</info>
Add to crontab to run every 15 minutes:
  <comment>0,15,30,45 * * * * cd /path/to/app && php bin/console app:process-timed-workflows</comment>

<info>Step Metadata Example:</info>
{
  "autoProgressConditions": {
    "type": "time_based",
    "delay": "24 hours",
    "condition": "status = 'pending'"
  }
}
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('DRY RUN MODE - No changes will be made');
        }

        $io->title('Time-Based Workflow Processor');

        // Get all active workflow instances
        $activeWorkflows = $this->workflowInstanceRepository->findBy([
            'status' => 'in_progress',
        ]);

        if (empty($activeWorkflows)) {
            $io->success('No active workflows to process');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d active workflow(s)', count($activeWorkflows)));

        $processed = 0;
        $skipped = 0;
        $escalated = 0;
        $errors = [];

        foreach ($activeWorkflows as $workflowInstance) {
            $currentStep = $workflowInstance->getCurrentStep();

            if (!$currentStep) {
                continue; // No current step
            }

            // Check for SLA breaches and escalation (GDPR 72h deadline)
            $slaStatus = $this->checkSLAStatus($workflowInstance, $currentStep);
            if ($slaStatus === 'escalate') {
                $io->text(sprintf(
                    '  ⚠️  ESCALATING: %s (Step: %s) - SLA threshold reached',
                    $workflowInstance->getWorkflow()->getName(),
                    $currentStep->getName()
                ));

                if (!$dryRun) {
                    try {
                        $this->escalateWorkflow($workflowInstance, $currentStep);
                        $escalated++;
                    } catch (Exception $e) {
                        $errors[] = sprintf(
                            'Error escalating workflow #%d: %s',
                            $workflowInstance->getId(),
                            $e->getMessage()
                        );
                    }
                } else {
                    $escalated++;
                }
                continue; // Don't auto-progress, escalation takes priority
            }

            // Check if step has time-based auto-progression
            if (!$this->shouldAutoProgressByTime($workflowInstance, $currentStep)) {
                $skipped++;
                continue;
            }

            $io->text(sprintf(
                '  ✓ Auto-progressing: %s (Step: %s)',
                $workflowInstance->getWorkflow()->getName(),
                $currentStep->getName()
            ));

            if (!$dryRun) {
                try {
                    // Get system user (or use workflow initiator as fallback)
                    $user = $workflowInstance->getInitiatedBy();

                    if (!$user) {
                        $errors[] = sprintf(
                            'Workflow #%d has no initiator - cannot auto-progress',
                            $workflowInstance->getId()
                        );
                        continue;
                    }

                    // Get the entity to check conditions
                    $entity = $this->getWorkflowEntity($workflowInstance);

                    if (!$entity) {
                        $errors[] = sprintf(
                            'Could not load entity for workflow #%d',
                            $workflowInstance->getId()
                        );
                        continue;
                    }

                    // Auto-progress using the service
                    $this->workflowAutoProgressionService->checkAndProgressWorkflow($entity, $user);

                    $processed++;
                } catch (Exception $e) {
                    $errors[] = sprintf(
                        'Error processing workflow #%d: %s',
                        $workflowInstance->getId(),
                        $e->getMessage()
                    );
                }
            } else {
                $processed++;
            }
        }

        $io->newLine();
        $io->horizontalTable(
            ['Metric', 'Count'],
            [
                ['Total Active Workflows', count($activeWorkflows)],
                ['Auto-Progressed', $processed],
                ['Escalated (SLA Breach)', $escalated],
                ['Skipped', $skipped],
                ['Errors', count($errors)],
            ]
        );

        if (!empty($errors)) {
            $io->error('Errors encountered:');
            $io->listing($errors);
            return Command::FAILURE;
        }

        if ($dryRun && $processed > 0) {
            $io->warning(sprintf('DRY RUN: Would have auto-progressed %d workflow(s)', $processed));
        } elseif ($processed > 0) {
            $io->success(sprintf('Successfully auto-progressed %d workflow(s)', $processed));
        } else {
            $io->success('No workflows needed time-based auto-progression');
        }

        return Command::SUCCESS;
    }

    /**
     * Check if workflow step should auto-progress based on time delay
     */
    private function shouldAutoProgressByTime(WorkflowInstance $workflowInstance, WorkflowStep $step): bool
    {
        $metadata = $step->getMetadata();

        if (!$metadata || !isset($metadata['autoProgressConditions'])) {
            return false;
        }

        $conditions = $metadata['autoProgressConditions'];

        if (!isset($conditions['type']) || $conditions['type'] !== 'time_based') {
            return false;
        }

        if (!isset($conditions['delay'])) {
            return false; // No delay specified
        }

        // Check if step has been active long enough
        $stepStartTime = $this->getStepStartTime($workflowInstance, $step);

        if (!$stepStartTime) {
            return false; // Cannot determine when step started
        }

        $delayString = $conditions['delay'];
        $requiredDelay = $this->parseDelay($delayString);

        if (!$requiredDelay) {
            return false; // Invalid delay format
        }

        $now = new DateTimeImmutable();
        $elapsed = $now->getTimestamp() - $stepStartTime->getTimestamp();

        return $elapsed >= $requiredDelay;
    }

    /**
     * Get the time when current step started
     */
    private function getStepStartTime(WorkflowInstance $workflowInstance, WorkflowStep $step): ?DateTimeImmutable
    {
        // Check approval history for when this step became current
        $history = $workflowInstance->getApprovalHistory() ?? [];

        // Find the most recent action for this step
        foreach (array_reverse($history) as $entry) {
            if (isset($entry['step_id']) && $entry['step_id'] === $step->getId()) {
                if (isset($entry['timestamp'])) {
                    return new DateTimeImmutable($entry['timestamp']);
                }
            }
        }

        // Fallback: use workflow start time if this is the first step
        $firstStep = $workflowInstance->getWorkflow()->getSteps()->first();
        if ($firstStep === $step) {
            return $workflowInstance->getStartedAt();
        }

        return null;
    }

    /**
     * Parse delay string (e.g., "24 hours", "2 days") into seconds
     */
    private function parseDelay(string $delayString): ?int
    {
        // Parse format: "X hours", "X days", "X minutes"
        if (preg_match('/^(\d+)\s+(hour|hours|day|days|minute|minutes)$/i', $delayString, $matches)) {
            $amount = (int)$matches[1];
            $unit = strtolower($matches[2]);

            return match (true) {
                str_starts_with($unit, 'minute') => $amount * 60,
                str_starts_with($unit, 'hour') => $amount * 3600,
                str_starts_with($unit, 'day') => $amount * 86400,
                default => null,
            };
        }

        return null;
    }

    /**
     * Get the actual entity object for a workflow instance
     */
    private function getWorkflowEntity(WorkflowInstance $workflowInstance): ?object
    {
        $entityType = $workflowInstance->getEntityType();
        $entityId = $workflowInstance->getEntityId();

        $entityClass = 'App\\Entity\\' . $entityType;

        if (!class_exists($entityClass)) {
            return null;
        }

        return $this->entityManager->find($entityClass, $entityId);
    }

    /**
     * Check SLA status for workflow instance
     *
     * For GDPR Data Breach workflows (72h deadline):
     * - Escalate at 60h (12h before deadline)
     * - Alert at 48h (warning threshold)
     *
     * @return string 'escalate', 'warning', or 'ok'
     */
    private function checkSLAStatus(WorkflowInstance $workflowInstance, WorkflowStep $step): string
    {
        $workflow = $workflowInstance->getWorkflow();
        $metadata = $workflow->getMetadata() ?? [];

        // Check if workflow has SLA enforcement enabled
        if (!isset($metadata['slaEnforcement']) || !$metadata['slaEnforcement']) {
            return 'ok';
        }

        // Get workflow SLA deadline (in hours)
        $slaDeadlineHours = $metadata['slaDeadlineHours'] ?? null;
        if (!$slaDeadlineHours) {
            return 'ok'; // No SLA deadline configured
        }

        // Get escalation threshold (default: 12h before deadline)
        $escalationThresholdHours = $metadata['escalationThresholdHours'] ?? ($slaDeadlineHours - 12);

        // Calculate elapsed time since workflow start
        $startedAt = $workflowInstance->getStartedAt();
        if (!$startedAt) {
            return 'ok';
        }

        $now = new DateTimeImmutable();
        $elapsedHours = ($now->getTimestamp() - $startedAt->getTimestamp()) / 3600;

        // Check escalation threshold
        if ($elapsedHours >= $escalationThresholdHours) {
            return 'escalate';
        }

        // Warning at 2/3 of deadline
        $warningThreshold = $slaDeadlineHours * 2 / 3;
        if ($elapsedHours >= $warningThreshold) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Escalate workflow to management/board
     *
     * For GDPR Data Breach: escalate to board if 60h elapsed without resolution
     */
    private function escalateWorkflow(WorkflowInstance $workflowInstance, WorkflowStep $step): void
    {
        $workflow = $workflowInstance->getWorkflow();
        $metadata = $workflow->getMetadata() ?? [];

        // Get escalation target role
        $escalationRole = $metadata['escalationRole'] ?? 'ROLE_ADMIN';

        // Add escalation entry to approval history
        $workflowInstance->addApprovalHistoryEntry([
            'step_id' => $step->getId(),
            'step_name' => $step->getName(),
            'action' => 'escalated',
            'escalation_role' => $escalationRole,
            'comments' => sprintf(
                'SLA threshold exceeded - escalated to %s (%.1f hours elapsed)',
                $escalationRole,
                ($this->getElapsedHours($workflowInstance))
            ),
            'timestamp' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
            'auto_escalation' => true,
            'trigger' => 'sla_enforcement',
        ]);

        // TODO: Send escalation notification to users with $escalationRole
        // This would typically use NotificationService to alert management/board

        $this->entityManager->flush();
    }

    /**
     * Get elapsed hours since workflow start
     */
    private function getElapsedHours(WorkflowInstance $workflowInstance): float
    {
        $startedAt = $workflowInstance->getStartedAt();
        if (!$startedAt) {
            return 0.0;
        }

        $now = new DateTimeImmutable();
        return ($now->getTimestamp() - $startedAt->getTimestamp()) / 3600;
    }
}

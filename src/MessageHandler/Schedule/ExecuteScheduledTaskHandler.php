<?php

declare(strict_types=1);

namespace App\MessageHandler\Schedule;

use App\Message\Schedule\ExecuteScheduledTaskMessage;
use App\Repository\ScheduledTaskRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Handles execution of database-defined scheduled tasks
 */
#[AsMessageHandler]
class ExecuteScheduledTaskHandler
{
    /**
     * Whitelist of allowed console commands to prevent command injection.
     * Only commands explicitly listed here may be executed by scheduled tasks.
     */
    private const ALLOWED_COMMANDS = [
        // Workflow automation
        'app:process-timed-workflows',
        'app:generate-regulatory-workflows',
        // Notifications & reminders
        'app:send-notifications',
        'app:review:send-reminders',
        'app:nis2-notification-check',
        'app:risk:monitor-treatment-plans',
        'app:risk:schedule-reviews',
        // Scheduled reports
        'app:process-scheduled-reports',
        // KPI & snapshots
        'app:kpi-snapshot',
        'app:portfolio:capture-snapshot',
        'app:reuse:capture-snapshot',
        // Backup management
        'app:backup:create',
        'app:backup:prune',
        // Audit log maintenance
        'app:audit-log:cleanup',
        'app:four-eyes:cleanup-expired',
        // Schema & migrations
        'app:schema:reconcile',
        'doctrine:migrations:migrate',
    ];

    public function __construct(
        private readonly ScheduledTaskRepository $taskRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {}

    public function __invoke(ExecuteScheduledTaskMessage $message): void
    {
        $task = $this->taskRepository->find($message->getTaskId());

        if (!$task) {
            $this->logger->warning('Scheduled task not found', [
                'task_id' => $message->getTaskId(),
            ]);
            return;
        }

        if (!$task->isEnabled()) {
            $this->logger->info('Scheduled task is disabled, skipping', [
                'task_id' => $task->getId(),
                'task_name' => $task->getName(),
            ]);
            return;
        }

        $this->logger->info('Executing scheduled task', [
            'task_id' => $task->getId(),
            'task_name' => $task->getName(),
            'command' => $task->getCommand(),
        ]);

        // Security: validate command against whitelist to prevent command injection
        if (!in_array($task->getCommand(), self::ALLOWED_COMMANDS, true)) {
            $this->logger->error('Blocked non-whitelisted scheduled task command', [
                'task_id' => $task->getId(),
                'task_name' => $task->getName(),
                'command' => $task->getCommand(),
            ]);
            return;
        }

        $task->setLastRunAt(new DateTime());
        $task->setLastStatus('running');

        // Build command with sanitized arguments
        $commandParts = ['php', $this->projectDir . '/bin/console', $task->getCommand()];

        if ($task->getArguments()) {
            foreach ($task->getArguments() as $arg) {
                // Block dangerous flags that could alter command behavior
                if (preg_match('/^--env=|^--kernel-class=|^-e\s/', (string) $arg)) {
                    $this->logger->warning('Blocked dangerous argument in scheduled task', [
                        'task_id' => $task->getId(),
                        'blocked_arg' => $arg,
                    ]);
                    continue;
                }
                $commandParts[] = (string) $arg;
            }
        }

        try {
            $process = new Process($commandParts);
            $process->setTimeout(3600); // 1 hour timeout
            $process->run();

            if ($process->isSuccessful()) {
                $task->setLastStatus('success');
                $task->setLastOutput($process->getOutput());

                $this->logger->info('Scheduled task completed successfully', [
                    'task_id' => $task->getId(),
                    'task_name' => $task->getName(),
                ]);
            } else {
                $task->setLastStatus('failed');
                $task->setLastOutput($process->getErrorOutput());

                $this->logger->error('Scheduled task failed', [
                    'task_id' => $task->getId(),
                    'task_name' => $task->getName(),
                    'exit_code' => $process->getExitCode(),
                    'error' => $process->getErrorOutput(),
                ]);
            }
        } catch (ProcessFailedException $e) {
            $task->setLastStatus('failed');
            $task->setLastOutput($e->getMessage());

            $this->logger->error('Scheduled task process failed', [
                'task_id' => $task->getId(),
                'task_name' => $task->getName(),
                'error' => $e->getMessage(),
            ]);
        } catch (Exception $e) {
            $task->setLastStatus('failed');
            $task->setLastOutput($e->getMessage());

            $this->logger->error('Scheduled task execution error', [
                'task_id' => $task->getId(),
                'task_name' => $task->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $this->em->flush();
    }
}

<?php

declare(strict_types=1);

namespace App;

use App\Message\Schedule\CleanupExpiredSessionsMessage;
use App\Message\Schedule\EnforceRetentionMessage;
use App\Message\Schedule\ExecuteScheduledTaskMessage;
use App\Message\Schedule\GenerateComplianceReportMessage;
use App\Message\Schedule\SyncEuvdFeedMessage;
use App\Repository\ScheduledTaskRepository;
use App\Service\RetentionEnforcementService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Throwable;

/**
 * Main schedule provider for Little ISMS Helper
 *
 * Combines built-in scheduled tasks with database-defined custom tasks
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    /**
     * Console commands built for periodic runs. They used to be documented as
     * separate crontab lines, which most installations never set up — the
     * scheduler is now the single place, run by one worker or one cron line
     * (docs/user-guide/HOSTING_WORKER.md). Each command scopes tenants itself.
     *
     * @var list<array{0: string, 1: string}> [cron expression, command line]
     */
    public const array COMMAND_TASKS = [
        ['*/15 * * * *', 'app:process-timed-workflows'],
        // The one reminder path: urgent hour deadlines every run, the daily
        // digest at each organisation's digest hour (default 07:00).
        ['*/15 * * * *', 'app:send-reminders'],
        ['0 * * * *', 'app:process-scheduled-reports'],
        ['45 0 * * *', 'app:compliance:refresh-fulfillments'],
        ['0 1 * * *', 'app:kpi-snapshot'],
        ['15 1 * * *', 'app:portfolio:capture-snapshot'],
        ['30 1 * * *', 'app:reuse:capture-snapshot'],
        ['0 2 * * *', 'app:backup:create'],
        ['30 2 * * *', 'app:backup:prune'],
        ['45 2 * * *', 'app:audit-log:cleanup'],
        ['0 3 * * *', 'app:four-eyes:cleanup-expired'],
        ['15 3 * * *', 'app:policy-wizard:purge-sandboxes'],
        ['0 2 * * 0', 'app:tisax:purge-old-ip-addresses'],
        ['30 5 * * *', 'app:suggestions:generate'],
        ['0 7 * * *', 'app:notification:send-digests'],
        ['0 5 * * *', 'app:evidence:expire'],
        ['30 5 * * *', 'app:risk:schedule-reviews'],
    ];

    public function __construct(
        private CacheInterface $cache,
        private ScheduledTaskRepository $taskRepository,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
        ;

        // Built-in scheduled tasks
        $this->addBuiltInTasks($schedule);

        // Database-defined custom tasks
        $this->addDatabaseTasks($schedule);

        return $schedule;
    }

    /**
     * Add built-in scheduled tasks
     */
    private function addBuiltInTasks(SymfonySchedule $schedule): void
    {
        // Cleanup expired sessions daily at 3:00 AM
        $schedule->add(
            RecurringMessage::cron('0 3 * * *', new CleanupExpiredSessionsMessage())
        );

        // Generate compliance report weekly (Mondays at 6:00 AM)
        $schedule->add(
            RecurringMessage::cron('0 6 * * 1', new GenerateComplianceReportMessage())
        );

        // Enforce data-retention weekly (Sundays at 4:00 AM) — GDPR Art. 5(1)(e):
        // per-tenant auto_delete policies + audit-log retention (NIS2 floor).
        $schedule->add(
            RecurringMessage::cron(
                sprintf('0 %d * * %d', RetentionEnforcementService::SWEEP_HOUR, RetentionEnforcementService::SWEEP_WEEKDAY),
                new EnforceRetentionMessage(),
            )
        );

        // Sync the ENISA EUVD feed daily at 5:00 AM (NIS2 Art. 12
        // coordinated vulnerability disclosure / EU single entry point).
        $schedule->add(
            RecurringMessage::cron('0 5 * * *', new SyncEuvdFeedMessage())
        );

        foreach (self::COMMAND_TASKS as [$cron, $command]) {
            $schedule->add(RecurringMessage::cron($cron, new RunCommandMessage($command . ' --no-interaction')));
        }

        $this->logger->info('Built-in scheduled tasks loaded', [
            'count' => 4 + count(self::COMMAND_TASKS),
        ]);
    }

    /**
     * Add database-defined custom tasks
     */
    private function addDatabaseTasks(SymfonySchedule $schedule): void
    {
        try {
            // Check if scheduled_task table exists before querying
            $connection = $this->entityManager->getConnection();
            $schemaManager = $connection->createSchemaManager();
            $tables = $schemaManager->listTableNames();

            if (!in_array('scheduled_task', $tables)) {
                $this->logger->info('scheduled_task table does not exist yet, skipping database tasks');
                return;
            }

            $tasks = $this->taskRepository->findEnabled();

            foreach ($tasks as $task) {
                $this->logger->debug('Adding database task to schedule', [
                    'task_id' => $task->getId(),
                    'task_name' => $task->getName(),
                    'cron' => $task->getCronExpression(),
                ]);

                $schedule->add(
                    RecurringMessage::cron(
                        $task->getCronExpression(),
                        new ExecuteScheduledTaskMessage($task->getId())
                    )
                );
            }

            $this->logger->info('Database scheduled tasks loaded', [
                'count' => count($tasks),
            ]);
        } catch (Throwable $e) {
            // Don't fail if database is not available (e.g., during installation).
            // \Throwable: an \Error here would otherwise kill the whole schedule
            // provider, including the built-in tasks.
            $this->logger->warning('Failed to load database scheduled tasks', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

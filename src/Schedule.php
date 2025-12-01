<?php

namespace App;

use App\Message\Schedule\CheckRiskReviewsMessage;
use App\Message\Schedule\CleanupExpiredSessionsMessage;
use App\Message\Schedule\ExecuteScheduledTaskMessage;
use App\Message\Schedule\GenerateComplianceReportMessage;
use App\Repository\ScheduledTaskRepository;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Main schedule provider for Little ISMS Helper
 *
 * Combines built-in scheduled tasks with database-defined custom tasks
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private ScheduledTaskRepository $taskRepository,
        private LoggerInterface $logger
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

        // Check for risks due for review daily at 8:00 AM
        // ISO 27001:2022 Clause 6.1.3.d compliance
        $schedule->add(
            RecurringMessage::cron('0 8 * * *', new CheckRiskReviewsMessage())
        );

        // Generate compliance report weekly (Mondays at 6:00 AM)
        $schedule->add(
            RecurringMessage::cron('0 6 * * 1', new GenerateComplianceReportMessage())
        );

        $this->logger->info('Built-in scheduled tasks loaded', [
            'count' => 3,
        ]);
    }

    /**
     * Add database-defined custom tasks
     */
    private function addDatabaseTasks(SymfonySchedule $schedule): void
    {
        try {
            // Check if scheduled_task table exists before querying
            $connection = $this->taskRepository->getEntityManager()->getConnection();
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
        } catch (Exception $e) {
            // Don't fail if database is not available (e.g., during installation)
            $this->logger->warning('Failed to load database scheduled tasks', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

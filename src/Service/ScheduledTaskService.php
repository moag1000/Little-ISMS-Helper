<?php

namespace App\Service;

use App\Entity\ScheduledTask;
use App\Repository\ScheduledTaskRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing scheduled tasks
 *
 * Provides API for creating, updating, and deleting scheduled tasks
 * Validates cron expressions and calculates next run times
 */
class ScheduledTaskService
{
    public function __construct(
        private readonly ScheduledTaskRepository $taskRepository,
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Create a new scheduled task
     */
    public function createTask(
        string $name,
        string $command,
        string $cronExpression,
        ?string $description = null,
        ?array $arguments = null
    ): ScheduledTask {
        // Validate cron expression
        if (!CronExpression::isValidExpression($cronExpression)) {
            throw new \InvalidArgumentException(sprintf('Invalid cron expression: %s', $cronExpression));
        }

        $task = new ScheduledTask();
        $task->setName($name);
        $task->setCommand($command);
        $task->setCronExpression($cronExpression);
        $task->setDescription($description);
        $task->setArguments($arguments);
        $task->setTenantId($this->tenantContext->getCurrentTenantId());
        $task->setEnabled(true);

        // Calculate next run time
        $cron = new CronExpression($cronExpression);
        $task->setNextRunAt($cron->getNextRunDate());

        $this->em->persist($task);
        $this->em->flush();

        $this->logger->info('Scheduled task created', [
            'task_id' => $task->getId(),
            'task_name' => $task->getName(),
            'cron' => $task->getCronExpression(),
        ]);

        return $task;
    }

    /**
     * Update an existing scheduled task
     */
    public function updateTask(
        ScheduledTask $task,
        ?string $name = null,
        ?string $command = null,
        ?string $cronExpression = null,
        ?string $description = null,
        ?array $arguments = null
    ): ScheduledTask {
        if ($name !== null) {
            $task->setName($name);
        }

        if ($command !== null) {
            $task->setCommand($command);
        }

        if ($cronExpression !== null) {
            if (!CronExpression::isValidExpression($cronExpression)) {
                throw new \InvalidArgumentException(sprintf('Invalid cron expression: %s', $cronExpression));
            }

            $task->setCronExpression($cronExpression);

            // Recalculate next run time
            $cron = new CronExpression($cronExpression);
            $task->setNextRunAt($cron->getNextRunDate());
        }

        if ($description !== null) {
            $task->setDescription($description);
        }

        if ($arguments !== null) {
            $task->setArguments($arguments);
        }

        $this->em->flush();

        $this->logger->info('Scheduled task updated', [
            'task_id' => $task->getId(),
            'task_name' => $task->getName(),
        ]);

        return $task;
    }

    /**
     * Enable or disable a scheduled task
     */
    public function toggleTask(ScheduledTask $task, bool $enabled): void
    {
        $task->setEnabled($enabled);
        $this->em->flush();

        $this->logger->info('Scheduled task toggled', [
            'task_id' => $task->getId(),
            'task_name' => $task->getName(),
            'enabled' => $enabled,
        ]);
    }

    /**
     * Delete a scheduled task
     */
    public function deleteTask(ScheduledTask $task): void
    {
        $taskId = $task->getId();
        $taskName = $task->getName();

        $this->em->remove($task);
        $this->em->flush();

        $this->logger->info('Scheduled task deleted', [
            'task_id' => $taskId,
            'task_name' => $taskName,
        ]);
    }

    /**
     * Validate a cron expression
     */
    public function validateCronExpression(string $expression): bool
    {
        return CronExpression::isValidExpression($expression);
    }

    /**
     * Get human-readable description of cron expression
     */
    public function describeCronExpression(string $expression): string
    {
        if (!CronExpression::isValidExpression($expression)) {
            return 'Invalid cron expression';
        }

        try {
            $cron = new CronExpression($expression);
            $nextRun = $cron->getNextRunDate();

            return sprintf(
                'Next run: %s',
                $nextRun->format('Y-m-d H:i:s')
            );
        } catch (\Exception $e) {
            return 'Unable to determine next run time';
        }
    }

    /**
     * Get all scheduled tasks for current tenant
     *
     * @return ScheduledTask[]
     */
    public function getTasksForCurrentTenant(): array
    {
        return $this->taskRepository->findBy([
            'tenantId' => $this->tenantContext->getCurrentTenantId(),
        ]);
    }

    /**
     * Get statistics about scheduled tasks
     */
    public function getStatistics(): array
    {
        $tasks = $this->getTasksForCurrentTenant();

        $stats = [
            'total' => count($tasks),
            'enabled' => 0,
            'disabled' => 0,
            'last_success' => 0,
            'last_failed' => 0,
            'running' => 0,
        ];

        foreach ($tasks as $task) {
            if ($task->isEnabled()) {
                $stats['enabled']++;
            } else {
                $stats['disabled']++;
            }

            switch ($task->getLastStatus()) {
                case 'success':
                    $stats['last_success']++;
                    break;
                case 'failed':
                    $stats['last_failed']++;
                    break;
                case 'running':
                    $stats['running']++;
                    break;
            }
        }

        return $stats;
    }
}
